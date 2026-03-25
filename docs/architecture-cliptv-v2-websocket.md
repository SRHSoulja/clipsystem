# ClipTV v2 — WebSocket Architecture Proposal

**Status:** Proposal
**Date:** 2026-03-25
**Scope:** Evolve ClipTV shared-session behavior from polling to WebSocket without rewriting the product

---

## 1. Component Architecture

```
                        +---------------------------+
                        |     Existing PHP App      |
                        |  (clips, votes, playlists, |
                        |   dashboard, auth, archive, |
                        |   search, admin, bot, ext)  |
                        +---------------------------+
                                |           ^
                           Postgres          | HTTP (existing)
                                |           |
                        +-------v-----------+---+
                        |      PostgreSQL        |
                        |  (durable state only)  |
                        +-------+---------------+
                                ^
                                | read durable state
                                | (clips, settings, votes)
                                |
+----------+    WebSocket    +--v-----------------------+
|  Browser |<--------------->|  Realtime Service (Node) |
|  Player  |    (wss://)     |  - room state (in-memory)|
+----------+                 |  - presence              |
                             |  - broadcast             |
                             |  - chat relay             |
                             +-----+-------------------+
                                   |
                                   | HTTP POST (fire-and-forget)
                                   v
                             +-----+-------------------+
                             |  PHP write endpoints     |
                             |  (clip_played, vote,     |
                             |   chat archive, etc.)    |
                             +-------------------------+
```

**Three components. Clear boundaries:**

| Component | Runtime | Owns |
|-----------|---------|------|
| **PHP app** | Existing NGINX + PHP-FPM on Railway | All durable business logic: clips, votes, playlists, dashboard, auth, search, archive, admin, bot, extension |
| **Realtime service** | Node.js process (single file, ~500 LOC) on same Railway project or separate service | Ephemeral session state: rooms, presence, sync position, skip votes, clip requests, chat relay |
| **PostgreSQL** | Existing Railway Postgres | Durable data only. No more ephemeral polling tables |

**What is NOT a component:** Redis. Not justified in Phase 1. The realtime service holds room state in memory. A single Node process handles thousands of concurrent WebSocket connections. Redis becomes relevant only if the realtime service needs horizontal scaling across multiple processes — that's a Phase 3 concern at best.

---

## 2. Responsibility Matrix

### PHP App (unchanged except endpoint retirement)

- Clip CRUD, search, filtering, sequencing
- Vote submission and tallying (vote_submit.php, vote_ledger)
- Playlist management
- Dashboard, mod panel, admin panel
- Twitch OAuth login and session management
- Twitch API integration (clip fetching, backfill, user info)
- Discord Activity endpoints (token, favorites, browse)
- Twitch Extension JWT validation
- Bot settings and chat commands
- Archive jobs and cron tasks
- Channel settings (HUD position, command aliases, banned words)
- Clip play recording (clip_played.php — called by realtime service)
- Chat archival (batch write from realtime service every N minutes)
- Metrics report (metrics_report.php stays, tracks remaining PHP endpoints)

### PostgreSQL (reduced surface)

**Keep (durable):**
- `clips`, `votes`, `vote_ledger`, `blocklist`, `clip_plays`, `skip_events`
- `playlists`, `playlist_clips`, `playlist_active`
- `channel_settings`, `channel_mods`
- `known_users`, `viewer_peaks`
- `cliptv_chat` (archive target — batch-written by realtime service)
- `cliptv_banned_words`
- `games_cache`, `clips_live_cache`
- `archive_jobs`, `suspicious_voters`, `vote_rate_limits`
- All analytics/tracking tables

**Remove after migration (ephemeral — moved to realtime service memory):**
- `sync_state` — replaced by in-memory room state
- `cliptv_viewers` — replaced by WebSocket connection tracking
- `cliptv_requests` — replaced by in-memory room request queue
- `command_requests` / `cliptv_commands` — replaced by direct WebSocket messages

### Realtime Service (new)

**In-memory room state (per channel):**

```javascript
rooms = {
  "streamername": {
    // Playback state (replaces sync_state table)
    clip: { id, url, title, curator, duration, seq, created_at },
    startedAt: 1711360000.000,  // epoch with ms precision
    playlistIndex: 0,
    playlistIds: [],

    // Presence (replaces cliptv_viewers table)
    viewers: Map<viewerId, {
      socket,
      joinedAt,
      lastSeen,      // for disconnect detection
      skipVote: bool,
      isHost: bool,  // coordination role (not authority)
    }>,

    // Skip state
    skipTriggered: false,

    // Clip request (replaces cliptv_requests table)
    activeRequest: null | {
      clipId, clipSeq, clipTitle, clipGame, clipCreator,
      clipDuration, requesterId, createdAt
    },

    // Chat buffer (batched to Postgres every 60s)
    chatBuffer: [],
    lastChatFlush: Date.now(),

    // Room config (loaded from Postgres on first join)
    bannedWords: [],
    settings: {},  // from channel_settings
  }
}
```

**Responsibilities:**
1. Accept WebSocket connections, authenticate (Twitch session token or Discord HMAC)
2. Join viewer to channel room, broadcast updated presence
3. Receive and broadcast clip changes from the host
4. Handle skip vote counting and majority detection
5. Handle clip request lifecycle (submit, countdown, play/cancel)
6. Relay chat messages with banned-word filtering
7. Detect disconnects (socket close, ping/pong timeout) and update presence
8. Host failover: when host disconnects, promote longest-connected viewer
9. Batch-flush chat messages to PHP/Postgres periodically
10. Fire-and-forget POST to `clip_played.php` when clips change

---

## 3. Room/Session Model

### Channel Room Identity

One room per channel login. Room is created when the first viewer connects and destroyed when the last viewer disconnects (after a grace period of ~30s to survive reconnects).

```
Room key: channel login (lowercase, alphanumeric + underscore)
Room URL: wss://clips.gmgnrepeat.com/ws?channel={login}&token={auth_token}
```

### Viewer Presence

Connection = presence. No heartbeat needed.

```
viewer joins  → room.viewers.set(viewerId, { socket, ... })
              → broadcast: { type: "presence", viewers: count, viewerList: [...] }

viewer leaves → room.viewers.delete(viewerId)
              → if viewer was host: promote next viewer
              → broadcast: { type: "presence", viewers: count, viewerList: [...] }
              → if room empty: start 30s grace timer, destroy room if no rejoin
```

WebSocket ping/pong (protocol-level, not application-level) handles liveness. Most WS libraries do this automatically with a 30s interval.

### Host Coordination

**Host = coordination role, not authority.** The host is the viewer whose client drives clip advancement (picks next clip, resolves MP4 URL, broadcasts the change). Any viewer can become host.

```
First viewer joins  → becomes host
Host disconnects    → longest-connected viewer promoted
                    → broadcast: { type: "host_change", hostId: newHostId }
New host receives   → resumes from current room.clip state
```

The host is the only viewer that sends `clip_change` messages. All other viewers receive and apply them. This is the same controller model as today, just over WebSocket instead of polling.

**Host does NOT have special authority.** Skip, back, browse, request — all follow the same solo-vs-multi rules as today. The host just happens to be the one whose client executes `controllerAdvance()`.

### Skip Votes

```
viewer sends:    { type: "skip_vote", vote: true|false }
server updates:  room.viewers.get(viewerId).skipVote = vote
server checks:   if totalSkipVotes >= majority(viewerCount)
                   → broadcast: { type: "skip_triggered" }
                   → host client calls controllerAdvance()
                   → reset all skip votes
server broadcasts: { type: "skip_state", votes: N, needed: M, myVote: bool }
```

Solo viewer (count = 1): server sees majority immediately, triggers skip on first vote. No round-trip delay.

### Clip Requests / Countdown

```
viewer sends:    { type: "clip_request", clip: { id, seq, title, game, creator, duration } }
server checks:   viewerCount <= 1? → broadcast clip_play immediately
                 viewerCount > 1?  → check cooldowns, set activeRequest, broadcast countdown
server broadcasts: { type: "clip_requested", clip: {...}, remainingSeconds: 6, requesterId: "..." }
                   (all viewers show banner)

after countdown: { type: "clip_request_play", clip: {...} }
                 (host plays the clip, broadcasts clip_change)

any viewer sends: { type: "clip_request_play_now" }
                  → ends countdown early, broadcast play

requester sends:  { type: "clip_request_cancel" }
                  → broadcast: { type: "clip_request_cancelled" }
```

### Clip Change Broadcasts

```
host sends:      { type: "clip_change", clip: { id, url, title, curator, duration, seq, created_at } }
server updates:  room.clip = clip, room.startedAt = now
server broadcasts to all: { type: "clip_change", clip: {...}, startedAt: epoch }
server fire-and-forget: POST clip_played.php (analytics/rotation tracking)
```

Non-host viewers receive `clip_change` and play the clip at position 0 (or calculated position if they joined late — `now - startedAt`).

---

## 4. Endpoint Fate

### Retired (replaced by WebSocket)

| Endpoint | Replacement |
|----------|-------------|
| `sync_state.php` GET | Room state sent on connect + `clip_change` broadcast |
| `sync_state.php` POST | `clip_change` message from host |
| `sync_state_heartbeat.php` | WebSocket ping/pong (automatic) |
| `cliptv_viewers.php` GET/POST | Connection-based presence + `skip_vote` message |
| `cliptv_request.php` GET | `clip_requested` / `clip_request_play` broadcasts |
| `cliptv_command.php` GET/POST | Direct `skip_triggered` broadcast |
| `cliptv_skip_reset.php` | Server resets votes on clip change |
| `skip_check.php` | `skip_state` broadcast |
| `shuffle_check.php` | Direct message if needed |
| `prev_check.php` | Direct message if needed |
| `clear_playback_state.php` | `room_reset` message |
| `force_play_get.php` / `force_play_clear.php` | Direct `clip_change` |

### Reduced (still needed but less traffic)

| Endpoint | Change |
|----------|--------|
| `cliptv_chat.php` GET | No longer polled. Messages delivered via WS. GET kept for initial history load on connect. |
| `cliptv_chat.php` POST | No longer called from client. Chat sent via WS. PHP endpoint kept for batch archival from realtime service. |
| `cliptv_request.php` POST | No longer called from client. Requests sent via WS. PHP endpoint kept for backward compat / API consumers. |
| `hud_position.php` GET | Loaded once on room join (from channel_settings), pushed via WS on change. No more 30s polling. |
| `clip_played.php` | Called by realtime service (not client). Same endpoint, different caller. |

### Unchanged (stay in PHP)

Everything else: clip_search, dashboard, admin, archive, vote_submit, playlist_api, auth, bot, extension, Discord endpoints, cron, analytics, all pages. ~95 of ~112 endpoints are completely untouched.

---

## 5. Transport / Protocol Shape

### Connection

```
wss://clips.gmgnrepeat.com/ws?channel={login}

Headers:
  Cookie: PHPSESSID={session_id}    (Twitch OAuth — PHP validates)
  — OR —
  X-Discord-Token: {hmac_token}     (Discord HMAC — realtime service validates)
  X-Discord-User: {twitch_username}
```

### Message Format

JSON over WebSocket. Every message has a `type` field. No binary protocol — JSON is sufficient for this traffic volume and keeps debugging trivial.

**Client → Server:**

```typescript
// Viewer actions
{ type: "skip_vote", vote: boolean }
{ type: "chat", message: string, scope: "stream" | "global" }
{ type: "clip_request", clip: ClipData }
{ type: "clip_request_play_now" }
{ type: "clip_request_cancel" }

// Host-only (server validates sender is host)
{ type: "clip_change", clip: ClipData }
{ type: "host_heartbeat" }  // optional: explicit keepalive for host role

// Settings (mod/owner only)
{ type: "hud_position", position: string, hudType: string }
```

**Server → Client:**

```typescript
// Room state (sent on connect)
{ type: "room_state", clip: ClipData | null, startedAt: number,
  viewers: number, hostId: string, skipVotes: number, skipNeeded: number,
  mySkipVote: boolean, activeRequest: RequestData | null,
  hudPosition: string, chatHistory: Message[] }

// Broadcasts
{ type: "clip_change", clip: ClipData, startedAt: number }
{ type: "presence", viewers: number, viewerList: ViewerInfo[] }
{ type: "host_change", hostId: string }
{ type: "skip_state", votes: number, needed: number }
{ type: "skip_triggered" }
{ type: "chat_message", id: number, username: string, displayName: string,
  message: string, scope: string, createdAt: string }
{ type: "clip_requested", clip: ClipData, remainingSeconds: number,
  requesterId: string }
{ type: "clip_request_play", clip: ClipData }
{ type: "clip_request_cancelled" }
{ type: "hud_position", position: string }
{ type: "error", code: string, message: string }
```

**ClipData shape:**
```typescript
{ id: string, url: string, title: string, curator: string,
  duration: number, seq: number, created_at: string }
```

---

## 6. Failure Mode Handling

### Reconnects

Client maintains a reconnect loop with exponential backoff (1s, 2s, 4s, 8s, cap at 30s). On reconnect:

1. Client opens new WebSocket with same channel + auth
2. Server sends `room_state` with current clip, position, presence, skip state, active request
3. Client resumes playback at calculated position (`now - startedAt`)
4. If client was host before disconnect and hasn't been replaced (within grace window), they resume as host
5. If they were replaced, they join as a normal viewer

### Stale Host

No heartbeat polling needed. WebSocket close event fires immediately on TCP disconnect. For browser freeze / background throttle:

- Server sends WebSocket ping every 30s
- If no pong within 10s, server closes the connection and promotes next viewer
- Background tab: browser may throttle JS but WebSocket pings are handled at protocol level (browser maintains the TCP connection even when throttled)

This is strictly better than the current 8s stale detection via polling.

### Socket Disconnects (unclean)

TCP reset, network change, sleep/wake:
- Server detects via ping/pong timeout (40s worst case)
- Client detects via `onclose` / `onerror` → enters reconnect loop
- Grace period: room keeps the viewer's slot for 15s before promoting a new host or removing from presence
- If viewer reconnects within grace, they resume seamlessly (same viewerId, same skip vote state)

### Duplicated Events

- Each `clip_change` includes the clip ID. Client ignores if `clip.id === currentClipId`
- Each `chat_message` includes a server-assigned ID. Client deduplicates by ID
- `skip_vote` is idempotent (setting a boolean, not incrementing)
- `clip_request` has collision prevention (only one active request per room)

### Background Tabs

Current problem: browsers throttle `setInterval` to 1Hz or pause entirely.

WebSocket solution: the connection stays open even in background tabs. The browser maintains the TCP socket at the OS level. Messages arrive immediately when the tab regains focus (buffered by the browser's WebSocket implementation). No polling to throttle.

For the host in a background tab: the host's `controllerAdvance` (picking next clip, resolving MP4 URL) requires JS execution, which browsers may delay. The ping/pong timeout will detect if the host's JS is truly suspended and promote another viewer. The Discord variant's Web Worker approach can still supplement this.

---

## 7. Migration Plan

### Phase 0 — Preparation (current state + 1 session)

- [x] Metrics instrumentation on hot endpoints (done)
- [x] Polling interval tuning (done)
- [x] Schema bootstrap out of request path (done)
- [ ] Measure baseline request volume with metrics_report.php (in progress — deploy just shipped)
- [ ] Document the exact message shapes by logging what the current polling endpoints send/receive over a real session

### Phase 1 — WebSocket Service (MVP)

**Build the realtime service as a standalone Node.js file (`realtime/server.js`).**

Scope: presence + clip sync + skip votes only. No chat, no clip requests yet.

1. **Build `realtime/server.js`** (~300 LOC):
   - `ws` library (no framework)
   - Room management (create on first join, destroy on last leave)
   - Auth: validate PHPSESSID by calling PHP session endpoint, or validate Discord HMAC directly
   - Messages: `clip_change`, `presence`, `skip_vote`, `skip_state`, `skip_triggered`, `host_change`, `room_state`
   - Fire-and-forget POST to `clip_played.php` on clip change

2. **Add WS connection to player JS** (alongside existing polling):
   - If WebSocket connects successfully, disable polling for sync_state, cliptv_viewers, sync_state_heartbeat
   - If WebSocket fails to connect or disconnects, fall back to polling (existing code still works)
   - This is the key risk mitigation: **polling is the fallback, not removed**

3. **Deploy on Railway:**
   - Add a second service in the Railway project (Node.js), or
   - Run Node alongside PHP using a custom start command: `node realtime/server.js & php-fpm & nginx`
   - WebSocket endpoint: `wss://clips.gmgnrepeat.com/ws` (proxied by nginx)

4. **Measure:**
   - Compare metrics_report.php before/after: sync_state, cliptv_viewers, sync_state_heartbeat should drop to near-zero for WS-connected viewers
   - Monitor WS connection stability over 48h

**Phase 1 does NOT touch:** chat, clip requests, dashboard, admin, votes, auth, Discord variant, any PHP endpoint behavior.

### Phase 2 — Chat + Clip Requests over WebSocket

After Phase 1 is stable (1-2 weeks):

1. Add chat relay to realtime service (banned-word filter, rate limit, batch flush to Postgres)
2. Add clip request lifecycle (submit, countdown, play, cancel)
3. Update player JS to send chat/requests over WS instead of HTTP
4. Polling fallback still works for both
5. Update Discord variant (`discord/index.html`) to use same WS connection

### Phase 3 — Cleanup + Scale

After Phase 2 is stable:

1. Remove polling code from player JS (it's been fallback-only for weeks)
2. Drop ephemeral tables: `sync_state`, `cliptv_viewers`, `cliptv_requests`, `command_requests`
3. Retire endpoints: sync_state.php, sync_state_heartbeat.php, cliptv_command.php, skip_check.php, etc.
4. If traffic warrants: add Redis pub/sub for multi-process realtime service scaling
5. If latency matters: move vote submission to WS (instant feedback) with async Postgres write

### What Phase 1 explicitly avoids

- No changes to dashboard, admin, archive, search, auth, bot, extension
- No changes to vote submission (stays HTTP POST to PHP)
- No changes to Discord variant (Phase 2)
- No new infrastructure (no Redis, no message queue)
- No removal of any existing endpoint (polling is fallback)
- No database migrations (ephemeral tables stay until Phase 3)

---

## Appendix: Technology Choice for Realtime Service

**Node.js with `ws` library.** Rationale:

- `ws` is the most battle-tested WebSocket library in the Node ecosystem
- Single-threaded event loop handles thousands of concurrent connections without threads
- JSON parsing is native and fast
- Can call PHP endpoints via HTTP for auth validation and durable writes
- Railway supports Node.js natively (Nixpacks auto-detects)
- The entire realtime service is ~500 LOC — no framework needed, no TypeScript needed for Phase 1

**Not chosen:**
- Go: faster, but team has no Go experience and the concurrency model is overkill for <10K connections
- Bun: interesting but less battle-tested WebSocket implementation
- PHP WebSocket (Ratchet/Swoole): adds PHP concurrency complexity that defeats the purpose of separating concerns
- Cloudflare Durable Objects: vendor lock-in, adds billing complexity, and Railway is already the deploy target
