# ClipTV WebSocket Migration — Feature Audit & Priority

**Date:** 2026-03-25
**Scope:** Classify every realtime/shared-session feature by WebSocket migration priority

---

## Priority 1 — Must move first (highest UX gain, lowest risk)

### 1A. Clip Change Broadcast

**Current mechanism:**
- Host writes to `sync_state` table via POST to `sync_state.php` (line 2444)
- All viewers poll GET `sync_state.php` every 3s (line 2748)
- Viewer detects `data.clip_id !== currentClipId`, plays new clip at calculated position
- Drift correction: if position differs by >3s, seeks to server position

**Why move first:**
- This is the single highest-volume poll (20 req/min/viewer) and the core product promise — synchronized viewing
- Polling creates a 0-3s delay between host changing the clip and viewers seeing it. WebSocket makes this instant (<100ms)
- Current drift correction (3s threshold, line 2814) exists because polling can't deliver sub-second sync. WS eliminates the root cause
- The `current_position` calculation (server computes `now - started_at`) is a workaround for not having a real broadcast moment. With WS, the server sends the clip change at `t=0` and every client starts from 0 simultaneously

**Expected benefit:** Instant clip transitions for all viewers. No more 1-3s "someone else is ahead" desync. Eliminates 20 req/min/viewer.

**Complexity:** Low. The WS message is the same data `sync_state.php` POST already sends. Host sends `clip_change`, server broadcasts to room, done. Server stores the clip state in memory for late joiners.

**Endpoints retired:** `sync_state.php` GET (polling), `sync_state.php` POST (write). Keep the POST available briefly as fallback during migration.

### 1B. Host Heartbeat / Stale Detection

**Current mechanism:**
- Host calls POST `sync_state_heartbeat.php` every 5s (line 4109) — updates `updated_at` column
- Viewers poll GET `sync_state.php` every 3s, check `controller_stale` (>8s since last update)
- If stale, viewer promotes itself to host (line 2764-2767)
- The heartbeat exists purely to prove the host's JS is still running

**Why move first:**
- This is tightly coupled to clip sync (1A) — can't move one without the other
- WebSocket ping/pong handles liveness at the protocol level. The server knows the instant a host disconnects (TCP close event) rather than discovering it 8s later via a stale timestamp
- Current failure mode: host closes laptop → 8-13s before any viewer detects it (5s heartbeat + 8s stale threshold). With WS: detection in ~30-40s (ping/pong timeout) but can be tuned to 10s. With TCP close (clean shutdown): instant.
- Eliminates 12 req/min from the host (heartbeat) plus removes the stale-detection logic from every viewer's sync poll

**Expected benefit:** Faster host failover. Eliminates heartbeat endpoint entirely.

**Complexity:** Very low. Built into every WS library. No application code needed for the heartbeat itself. Host promotion logic moves from client-side (poll result check) to server-side (connection close handler).

**Endpoint retired:** `sync_state_heartbeat.php`

### 1C. Viewer Presence

**Current mechanism:**
- Every viewer POSTs to `cliptv_viewers.php` every 5s (line 2908) with `login`, `viewer_id`, `clip_id`
- Server upserts row with `last_seen = NOW()`
- Server deletes stale viewers (`WHERE last_seen < NOW() - 18s`) on every request
- Response includes `viewer_count`, used by skip logic and solo-viewer fast paths
- The heartbeat is also the skip vote carrier and clip request piggyback

**Why move first:**
- 12 req/min/viewer. The second-highest volume poll
- Connection = presence is the native WebSocket model. No heartbeat needed, no cleanup queries, no ghost viewers
- Current ghost-viewer problem (18s timeout) inflates `viewerCount`, which breaks the solo-viewer fast-skip path. WS eliminates this: disconnect = immediate presence removal (or 15s grace for reconnect)
- The current heartbeat is overloaded — it carries skip votes, presence, and piggybacked clip requests. With WS, each concern gets its own message type

**Expected benefit:** Accurate real-time viewer count. No ghost viewers. Eliminates 12 req/min/viewer. Unblocks correct solo-viewer behavior.

**Complexity:** Low. Server tracks `room.viewers` as a Map keyed by connection. `viewerCount` is `room.viewers.size`. Broadcast on join/leave.

**Endpoint retired:** `cliptv_viewers.php` (both GET and POST)

---

## Priority 2 — Move second (high UX gain, moderate complexity)

### 2A. Skip Voting

**Current mechanism:**
- Voter sends skip flag via heartbeat POST to `cliptv_viewers.php` (line 2908, `skip` param)
- Server stores `wants_skip = TRUE` in `cliptv_viewers` row
- Server computes majority: `floor(count/2) + 1`
- Response includes `should_skip` — if true, the voter's client calls `triggerSkip()`
- `triggerSkip()` either calls `controllerAdvance()` (if host) or POSTs skip command to `cliptv_command.php` (if non-host)
- Host polls `cliptv_command.php` GET every sync cycle (line 2835) to pick up skip commands
- On new clip, host calls POST `cliptv_skip_reset.php` to clear all votes (line 2956)
- Race protection: `skipResetAt` timestamp prevents stale heartbeat responses from overriding local state for 5s

**Why move second (not first):**
- Depends on presence (1C) being correct — skip majority is `floor(viewerCount/2) + 1`
- The current skip flow has 4 separate HTTP calls (heartbeat with vote → check majority → command POST → command GET poll) and a race-condition guard. WS collapses this to: client sends `skip_vote` → server checks majority → server broadcasts `skip_triggered` → done
- The entire `cliptv_command.php` endpoint and `command_requests` table exist solely because non-host viewers can't directly tell the host to skip. WS makes this a direct server broadcast

**Expected benefit:** Skip feels instant instead of 5-10s delayed. Eliminates the command relay pattern entirely. Removes the skip race condition (no more `skipResetAt` guard needed). Eliminates `cliptv_command.php` polling.

**Complexity:** Moderate. The majority calculation moves to the server. Vote state is per-viewer in room memory. Reset is trivial (clear all votes when clip changes). But the solo-viewer fast path, the `canSkipClip` grace period, and the `skipTriggered` dedup all need to be replicated server-side or kept client-side with clear ownership.

**Endpoints retired:** `cliptv_command.php` (GET and POST), `cliptv_skip_reset.php`

### 2B. Clip Requests / Countdown

**Current mechanism:**
- Requester POSTs to `cliptv_request.php` with clip data (line 3458)
- Server writes to `cliptv_requests` table (login PK, `played = FALSE`, `created_at`)
- Other viewers discover the request via piggybacked data on heartbeat response (`clip_request` field)
- Requester shows local countdown banner (6s), then calls `playSpecificClip()`
- Other viewers see the banner within one heartbeat cycle (up to 5s delay)
- Collision prevention: server rejects if another active request exists (<15s)
- Cooldown: 30s per-user after a played request
- "Play Now" button ends countdown early for any viewer
- "Cancel" button clears the request

**Why move second:**
- Depends on presence (1C) — solo viewers skip the countdown entirely
- The 5s discovery delay is the worst part: viewer A requests a clip, viewer B doesn't see the banner for up to 5s. By then the countdown is nearly done. WS makes the banner appear instantly for all viewers
- The server-side collision/cooldown logic can stay in the WS service (simple in-memory checks)
- The countdown timer should be authoritative on the server, not per-client. Currently each client runs its own setTimeout, which can drift

**Expected benefit:** Instant request visibility for all viewers. Server-authoritative countdown (no drift). Cleaner collision handling.

**Complexity:** Moderate. The request lifecycle (submit → countdown → play/cancel) has several states. The server needs to manage the timer and broadcast the play event. But the logic is well-defined and the current implementation in `requestClip()` is a clean reference.

**Endpoint reduced:** `cliptv_request.php` POST no longer called from client (sent via WS). Keep GET for debug. Keep POST for backward compat / external API consumers.

---

## Priority 3 — Move later (moderate UX gain, higher complexity)

### 3A. Chat

**Current mechanism:**
- Send: POST to `cliptv_chat.php` with message, scope (stream/global), requires Twitch OAuth or Discord HMAC
- Poll: GET `cliptv_chat.php` every 5s (chat open) or 15s (chat closed), with `after=lastMessageId` for delta fetch
- Server stores in `cliptv_chat` table, auto-archives to JSONL after 24h
- Rate limit: 1 msg per 2s per user
- Banned words filter from `cliptv_banned_words` table
- Scope: `stream` (per-channel) or `global` (all channels)

**Why move later:**
- Chat already works acceptably at 5s polling. Users don't expect sub-second chat in a clip-watching context (this isn't Twitch chat)
- Chat has the most complex server-side logic: auth validation, banned words, rate limiting, scope routing, archival to Postgres. Moving this to the WS service means reimplementing all of it in Node
- The 24h archival pipeline (query expired → write JSONL → delete) would need a bridge: either the WS service calls the PHP endpoint to flush, or it writes to Postgres directly (adding a DB dependency to the WS service)
- The global scope complicates WS routing — a global message needs to reach all rooms, not just one

**Expected benefit:** Instant message delivery (current 5s delay eliminated). Badge/notification updates are immediate. But the perceived improvement is smaller than skip/sync because chat latency tolerance is higher.

**Complexity:** High. Auth, banned words, rate limiting, scope routing, archival — all need to be handled in the WS service or delegated to PHP via HTTP. Phase 3 in the architecture doc for good reason.

**Endpoint reduced:** `cliptv_chat.php` GET polling eliminated. POST kept for archival writes from WS service. GET kept for initial history load on room join.

### 3B. HUD Position

**Current mechanism:**
- Polled every 30s via GET `hud_position.php` (line 2017)
- Reads from `channel_settings` table
- Set by streamer/mod via POST (requires ADMIN_KEY)
- Value rarely changes — typically set once per session

**Why move later:**
- 2 req/min/viewer. Tiny volume
- Almost never changes. Polling at 30s is already fine
- Easy WS migration when the room is established: load setting on room create, push changes via WS if a mod updates it

**Expected benefit:** Minimal. Saves 2 req/min/viewer and eliminates 0-30s latency on HUD position changes (which almost never happen).

**Complexity:** Very low when it eventually moves. Just a field on room state, pushed on change.

**Endpoint unchanged for now.** Move in Phase 2 or 3 as a freebie.

---

## Should remain HTTP/PHP (do not move)

### Vote Submission

**Current:** POST to `vote_submit.php` or `api/vote.php` (line 2120). One-shot action, not polled.

**Why stay:** Votes are durable (permanent in `votes` + `vote_ledger` tables). They have complex dedup logic, rate limiting, suspicious voter detection. Voting is infrequent (once per clip per viewer) and latency-insensitive. No benefit from WS.

### Clip Pool / Refresh

**Current:** Fetch `twitch_reel_api.php` on init and periodically (line 2352, 4142). Loads the full clip pool for the channel.

**Why stay:** One-time load per session, not a polling concern. The clip pool is large (hundreds of clips) and benefits from HTTP caching. No realtime requirement.

### Clip Info / MP4 URL Resolution

**Current:** `clip_info.php` for metadata (line 3119), `clip_mp4_url.php` for playback URL.

**Why stay:** On-demand lookups, not polled. The MP4 URL resolution involves Twitch API calls that are better handled by the existing PHP caching layer.

### Clip Play Recording

**Current:** Fire-and-forget GET to `clip_played.php` when a clip starts (line 2562).

**Why stay:** Durable analytics write. Currently fire-and-forget from client. Will become fire-and-forget from WS service (same endpoint, different caller). No behavior change needed.

### Channel Info

**Current:** One-time fetch on init (line 1400).

**Why stay:** Static data, not a realtime concern.

### Dashboard, Admin, Auth, Archive, Search, Playlists, Bot, Extension

**Why stay:** None of these are in the realtime path. They're standard CRUD web pages/APIs. Moving them to WS would add complexity for zero benefit.

---

## Should be deleted after migration

| Endpoint/Table | When | Why |
|---|---|---|
| `sync_state.php` | After Phase 1 stable | Replaced by WS room state |
| `sync_state_heartbeat.php` | After Phase 1 stable | Replaced by WS ping/pong |
| `cliptv_viewers.php` | After Phase 1 stable | Replaced by WS connection tracking |
| `cliptv_command.php` | After Phase 2 (skip) stable | Replaced by direct WS broadcast |
| `cliptv_skip_reset.php` | After Phase 2 (skip) stable | Replaced by server-side vote reset |
| `skip_check.php` | After Phase 2 stable | Replaced by WS `skip_state` broadcast |
| `shuffle_check.php` | After Phase 2 stable | Replaced by direct WS message |
| `prev_check.php` | After Phase 2 stable | Replaced by direct WS message |
| `clear_playback_state.php` | After Phase 1 stable | Replaced by WS room reset |
| `force_play_get.php` | After Phase 1 stable | Replaced by WS `clip_change` |
| `force_play_clear.php` | After Phase 1 stable | Replaced by WS `clip_change` |
| `sync_state` table | After Phase 1 polling fallback removed | In-memory room state |
| `cliptv_viewers` table | After Phase 1 polling fallback removed | WS connection map |
| `cliptv_commands` table | After Phase 2 polling fallback removed | Direct WS messages |
| `cliptv_requests` table | After Phase 2 polling fallback removed | In-memory room state |

---

## Summary: Phase 1 migration targets

| Feature | Req/min saved per viewer | UX improvement | Complexity | Risk |
|---------|------------------------|---------------|-----------|------|
| **Clip change broadcast** | 20 (sync poll) | Instant clip transitions | Low | Low |
| **Host heartbeat** | 12 (heartbeat POST) | Faster failover | Very low | Very low |
| **Viewer presence** | 12 (viewer heartbeat) | Accurate count, no ghosts | Low | Low |
| **Total Phase 1** | **44 req/min/viewer** | — | — | — |

Phase 1 targets eliminate ~44 of ~50 req/min/viewer from the realtime path (the remaining ~6 are chat + HUD, which stay as polling until Phase 2/3). The three features are tightly coupled — they share the room concept and depend on each other — so they should move together.

Phase 2 (skip + clip requests) eliminates the command relay pattern and the remaining heartbeat-piggybacked data, but the UX is already dramatically improved by Phase 1.
