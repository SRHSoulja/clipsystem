# ClipTV WebSocket — Implementation Plan

**Status:** Plan
**Date:** 2026-03-25
**Inputs:** architecture-cliptv-v2-websocket.md, ws-migration-feature-audit.md, ws-protocol.md

---

## Phase 1A — Realtime service skeleton + nginx proxy

### Scope

Stand up a Node.js WebSocket server that accepts connections, manages rooms, and echoes a `room_state` snapshot on join. No player integration yet — the existing polling path is completely untouched. This phase validates the deployment model and proves WS connections work end-to-end on Railway.

### New files

```
realtime/
  server.js        — WS server entry point (~150 LOC for this phase)
  package.json      — { "dependencies": { "ws": "^8" } }
  room.js           — Room class (create, join, leave, destroy, serialize)
```

### Files modified

```
nginx.template.conf   — Add location /ws { proxy_pass to Node; upgrade headers }
railway.json          — Change startCommand to start both php-fpm+nginx and node
```

**nginx addition** (before the `location /` block):

```nginx
location /ws {
    proxy_pass http://127.0.0.1:9090;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 86400;
}
```

**railway.json startCommand:**

```json
"startCommand": "node realtime/server.js & php db_bootstrap.php; php-fpm & nginx -g 'daemon off;'"
```

Note: Nixpacks for PHP already builds nginx + php-fpm. Adding `node realtime/server.js &` before the main process starts Node in the background. If Railway's Nixpacks build doesn't include Node, a `nixpacks.toml` override adds it:

```toml
[phases.setup]
aptPkgs = ["nodejs", "npm"]
```

### server.js behavior (Phase 1A only)

1. Listen on port 9090 (internal, nginx proxies /ws)
2. Parse `?channel=` and `?viewer_id=` from upgrade URL
3. Find or create room for channel
4. Add viewer to room
5. Send `room_state` with `clip: null`, `viewers: N`, `host_id`, `you: { is_host }`, empty skip/request
6. Broadcast `presence` to all viewers in room
7. On close: remove viewer (with 15s grace), broadcast `presence`, promote host if needed
8. Ignore all client messages (not wired yet)

### Polling paths unchanged

All of them. This phase adds a parallel WS path that does nothing except prove the connection works. The player JS is not modified.

### Success criteria

- `wss://clips.gmgnrepeat.com/ws?channel=test&viewer_id=abc` connects successfully
- `room_state` message received in browser devtools
- Opening two tabs shows `viewers: 2`
- Closing one tab drops to `viewers: 1` (after grace period)
- Host promotion works when first viewer disconnects
- No impact on existing ClipTV operation (polling unchanged)

### Rollback

Remove the `node realtime/server.js &` from startCommand. Remove the nginx `/ws` location. Redeploy. Zero impact — nothing depends on the WS path.

### Manual tests

1. Open browser devtools → Network → WS tab. Connect to `wss://clips.gmgnrepeat.com/ws?channel=test&viewer_id=test1`. Verify `room_state` message.
2. Open second browser. Connect with `viewer_id=test2`. Verify both see `presence` update with `viewers: 2`.
3. Close first browser. Verify second browser receives `host_change` after 15s grace.
4. Open `/tv/{channel}` in a normal tab. Verify the existing player still works (polling, no WS yet).

---

## Phase 1B — Clip sync over WebSocket

### Scope

Wire the player JS to use the WebSocket connection for clip changes and presence. The host sends `clip_change` over WS instead of POST to `sync_state.php`. All viewers receive clip changes instantly via WS broadcast instead of polling `sync_state.php` GET. Polling remains as automatic fallback if WS disconnects.

### New files

```
realtime/
  protocol.js     — Message validation and dispatch (~100 LOC)
```

### Files modified

```
realtime/server.js     — Handle clip_change from host, broadcast to room
realtime/room.js       — Add clip state, startedAt, skip vote tracking

clipplayer_sync.html   — Add WS connection manager, dual-mode (WS primary, poll fallback)
```

### clipplayer_sync.html changes

Add a new module (inside the IIFE, after constants, before `init()`):

```javascript
// === WebSocket connection ===
let ws = null;
let wsConnected = false;
let wsReconnectTimer = null;
let wsReconnectDelay = 1000;

function connectWS() {
  const wsUrl = `wss://${location.host}/ws?channel=${encodeURIComponent(login)}&viewer_id=${encodeURIComponent(viewerId)}`;
  ws = new WebSocket(wsUrl);

  ws.onopen = () => {
    wsConnected = true;
    wsReconnectDelay = 1000;
    debugLog("WebSocket connected", "ok");
    // Disable polling — WS is primary
    if (syncInterval) { clearInterval(syncInterval); syncInterval = null; }
    if (heartbeatInterval) { clearInterval(heartbeatInterval); heartbeatInterval = null; }
    if (controllerHeartbeatInterval) { clearInterval(controllerHeartbeatInterval); controllerHeartbeatInterval = null; }
  };

  ws.onmessage = (e) => handleWSMessage(JSON.parse(e.data));

  ws.onclose = () => {
    wsConnected = false;
    debugLog("WebSocket disconnected — falling back to polling", "warn");
    // Re-enable polling as fallback
    if (!syncInterval) syncInterval = setInterval(pollSyncState, SYNC_POLL_MS);
    if (!heartbeatInterval) heartbeatInterval = setInterval(() => sendHeartbeat(), HEARTBEAT_MS);
    if (isController && !controllerHeartbeatInterval) {
      controllerHeartbeatInterval = setInterval(sendControllerHeartbeat, CTRL_HEARTBEAT_MS);
    }
    // Reconnect with backoff
    wsReconnectTimer = setTimeout(connectWS, wsReconnectDelay);
    wsReconnectDelay = Math.min(wsReconnectDelay * 2, 30000);
  };
}

function handleWSMessage(msg) {
  switch (msg.type) {
    case 'room_state': handleRoomState(msg); break;
    case 'clip_change': handleWSClipChange(msg); break;
    case 'presence': handleWSPresence(msg); break;
    case 'host_change': handleWSHostChange(msg); break;
    // Phase 2 additions here later
  }
}
```

**Key behavior change in `setServerState()`:**

```javascript
async function setServerState(clip, mp4Url) {
  if (wsConnected) {
    // Send via WebSocket — server broadcasts to all viewers
    ws.send(JSON.stringify({
      type: 'clip_change', seq: nextSeq(),
      clip: { id: clip.id, url: mp4Url, title: clip.title,
              curator: clip.creator_name, duration: clip.duration,
              seq: clip.seq, created_at: clip.created_at }
    }));
    return;
  }
  // Fallback: POST to sync_state.php (existing code)
  // ... existing fetch code unchanged ...
}
```

**Key behavior change in `pollSyncState()`:** Polling continues to work exactly as before, but the interval is cleared when WS connects and restored on disconnect. No poll logic is removed.

### Polling paths as fallback

| Path | Status |
|------|--------|
| `sync_state.php` GET (poll) | Active when WS disconnected. Disabled when WS connected. |
| `sync_state.php` POST (host write) | Active when WS disconnected. Bypassed when WS connected (host sends `clip_change` via WS). |
| `sync_state_heartbeat.php` | Active when WS disconnected. Not needed when WS connected (server knows host is alive via TCP). |
| `cliptv_viewers.php` POST (heartbeat) | Active when WS disconnected. Not needed when WS connected (presence is connection-based). |

### server.js additions (Phase 1B)

1. Accept `clip_change` from client. Validate sender is host. Update room state. Broadcast to all.
2. On `clip_change`: POST to `clip_played.php` (fire-and-forget via `http.request`).
3. Include full clip state in `room_state` for late joiners.
4. Include viewer count and host assignment in `presence` broadcasts.

### Success criteria

- Open `/tv/{channel}`. Verify WS connects (devtools shows `room_state`).
- Host advances clip → all viewers see the new clip within <500ms (vs 1-3s before).
- Kill the WS server process (simulate crash). Verify player falls back to polling within seconds and continues working.
- Restart WS server. Verify player reconnects and resumes WS mode.
- metrics_report.php shows sync_state.php traffic drops to near-zero while WS is connected.

### Rollback

Remove the `connectWS()` call from `init()`. Players never attempt WS, polling stays active. Zero behavioral change.

### Manual tests

1. Open `/tv/{channel}` in two browsers. Verify clip changes are instant (no visible delay between host and viewer).
2. In one browser, disconnect wifi briefly (~5s). Verify it reconnects to WS and resumes without visible interruption.
3. In one browser, open devtools Network tab. Verify no sync_state.php or cliptv_viewers.php requests while WS is connected.
4. Kill the Node process (`kill %1` in Railway shell). Verify both browsers fall back to polling and continue working. Restart Node. Verify both browsers reconnect to WS.
5. Verify host failover: close the host's browser, verify the other browser becomes host and advances clips.

---

## Phase 1C — Discord variant

### Scope

Apply the same WS integration to `discord/index.html`. The Discord variant has a Web Worker for background keepalive — WS replaces this for sync/presence (the Worker can optionally remain for chat polling if chat hasn't moved to WS yet).

### Files modified

```
discord/index.html    — Same WS connection manager as clipplayer_sync.html
```

### Differences from clipplayer_sync.html

- Auth: `X-Auth-Token` header with HMAC token instead of cookie
- Web Worker: Can be simplified — no longer needs `syncPoll` and `heartbeat` timers since WS handles both. Keep `controllerHeartbeat` timer in Worker as a safety net (if the Worker detects the main thread hasn't sent a `clip_change` within 2x the clip duration, it sends an application-level ping to wake the server).
- PiP/minimize handling: WS stays connected during Discord Activity PiP mode, which is strictly better than the current Worker-based polling

### Success criteria

- Discord Activity variant connects to WS, receives room state, syncs clips.
- Background/PiP mode: WS stays connected, host doesn't go stale.
- Fallback to polling works on WS disconnect.

### Rollback

Same as Phase 1B — remove `connectWS()` call. Discord variant falls back to full polling.

### Manual tests

1. Open ClipTV as a Discord Activity. Verify WS connects and clips sync.
2. Minimize the Discord Activity (PiP mode). Verify the viewer stays in presence and the host doesn't get promoted away.
3. Disconnect the Activity's network briefly. Verify reconnect and resync.

---

## Phase 2A — Skip voting over WebSocket

### Scope

Move skip vote casting and majority detection from heartbeat-piggybacked HTTP to direct WS messages. The heartbeat POST to `cliptv_viewers.php` carried skip votes as a side channel — with WS, skip votes are their own message type.

### Files modified

```
realtime/server.js    — Handle skip_vote, compute majority, broadcast skip_state/skip_triggered
realtime/room.js      — Add per-viewer skipVote state, majority computation, reset on clip change

clipplayer_sync.html  — Skip button sends WS message when connected; falls back to heartbeat when not
discord/index.html    — Same changes
```

### clipplayer_sync.html changes

**`handleSkipButton`:**

```javascript
if (viewerCount <= 1) {
  // Solo: still instant
  triggerSkip();
} else if (wsConnected) {
  // Multi + WS: send vote via WebSocket
  const newVote = !mySkipVote;
  mySkipVote = newVote;
  skipBtn.classList.toggle('voted', newVote);
  ws.send(JSON.stringify({ type: 'skip_vote', seq: nextSeq(), vote: newVote }));
} else {
  // Multi + no WS: fallback to heartbeat (existing code)
  const newVote = !mySkipVote;
  mySkipVote = newVote;
  skipBtn.classList.toggle('voted', newVote);
  sendHeartbeat(newVote);
}
```

**`handleWSMessage` additions:**

```javascript
case 'skip_state': handleWSSkipState(msg); break;
case 'skip_ack': handleWSSkipAck(msg); break;
case 'skip_triggered': handleWSSkipTriggered(msg); break;
```

**`triggerSkip` changes:**

When WS is connected and `skip_triggered` comes from the server, the client no longer needs to call `triggerSkip()` to POST to `cliptv_command.php`. The host receives `skip_triggered` and calls `controllerAdvance()` directly. Non-host viewers just wait for `clip_change`. The entire `cliptv_command.php` relay is bypassed.

### server.js additions (Phase 2A)

1. On `skip_vote`: update `viewer.skipVote`, compute majority, unicast `skip_ack` to voter, broadcast `skip_state` to others.
2. If majority reached: broadcast `skip_triggered`, reset all votes.
3. On `clip_change`: auto-reset all skip votes in the room (already done in Phase 1B, but verify votes are included).

### Polling paths as fallback

| Path | Status |
|------|--------|
| `cliptv_viewers.php` POST (with skip param) | Still works when WS is disconnected. |
| `cliptv_command.php` POST (skip relay) | Still works when WS is disconnected (non-host skip in poll mode). |
| `cliptv_command.php` GET (controller polls) | Still works when WS is disconnected. |
| `cliptv_skip_reset.php` | Still works when WS is disconnected. |

### Success criteria

- Solo viewer: skip button → instant `skip_triggered` → clip advances. No HTTP calls.
- 2 viewers: both vote skip → `skip_triggered` broadcast → host advances. No cliptv_command relay.
- Kill WS: skip button falls back to heartbeat-based voting. Same behavior as today.
- Skip vote state correct in `room_state` on reconnect.

### Rollback

Remove the `wsConnected` branch in `handleSkipButton`. Skip always goes through heartbeat. Zero behavioral change.

### Manual tests

1. Solo viewer: press skip. Verify instant advance with no HTTP calls in Network tab.
2. Two viewers: one votes skip. Verify the other sees vote count update instantly (vs 5s before). Second votes. Verify instant `skip_triggered`.
3. Two viewers: one votes skip, then cancels. Verify vote count decrements instantly.
4. Disconnect WS (kill Node). Vote skip. Verify it falls back to heartbeat path and still works.

---

## Phase 2B — Clip requests over WebSocket

### Scope

Move the clip request lifecycle (submit → countdown → play/cancel) from HTTP + client-side timers to server-authoritative WS messages.

### Files modified

```
realtime/server.js    — Handle clip_request, clip_request_play_now, clip_request_cancel
realtime/room.js      — Add activeRequest state, server-side countdown timer, cooldown tracking

clipplayer_sync.html  — requestClip() sends via WS when connected; countdown uses server expires_at
discord/index.html    — Same changes
```

### clipplayer_sync.html changes

**`requestClip`:**

```javascript
async function requestClip(clip) {
  if (viewerCount <= 1) {
    await playSpecificClip(clip); // Solo: immediate, unchanged
    return;
  }
  if (wsConnected) {
    ws.send(JSON.stringify({
      type: 'clip_request', seq: nextSeq(),
      clip: { id: clip.id || clip.clip_id, seq: clip.seq, title: clip.title,
              game: clip.game_name, creator: clip.creator_name, duration: clip.duration }
    }));
    return; // Server broadcasts clip_requested, client shows banner from that
  }
  // Fallback: existing HTTP POST + local countdown (unchanged)
  // ... existing code ...
}
```

**Banner countdown change:**

Currently the banner runs a local `setTimeout` and `setInterval` for the countdown. With WS, the `clip_requested` message includes `expires_at` (server clock). The banner computes remaining time as `expires_at - serverNow` where `serverNow` is estimated from the last `ts` received. This prevents countdown drift between viewers.

The `clip_request_play` message from the server triggers `playSpecificClip()` on the host. Non-host viewers hide the banner and wait for `clip_change`.

**`handleWSMessage` additions:**

```javascript
case 'clip_requested': handleWSClipRequested(msg); break;
case 'clip_request_play': handleWSClipRequestPlay(msg); break;
case 'clip_request_cancelled': handleWSClipRequestCancelled(msg); break;
```

### server.js additions (Phase 2B)

1. On `clip_request`: check solo (immediate play), check collision, check cooldown, set `room.activeRequest`, start server-side `setTimeout(6000)`, broadcast `clip_requested`.
2. On timer expiry: broadcast `clip_request_play`, clear `room.activeRequest`, start requester cooldown (30s).
3. On `clip_request_play_now`: cancel timer, broadcast `clip_request_play` immediately.
4. On `clip_request_cancel`: validate sender is requester, cancel timer, broadcast `clip_request_cancelled`.
5. On `clip_change`: if active request, cancel timer and clear it (the clip changed before countdown finished).

### Polling paths as fallback

| Path | Status |
|------|--------|
| `cliptv_request.php` POST (submit) | Still works when WS is disconnected. |
| `cliptv_viewers.php` piggybacked clip_request | Still works when WS is disconnected. |

### Success criteria

- Solo viewer: browse → play clip → instant. No countdown. No HTTP.
- Two viewers: viewer A requests clip → viewer B sees banner instantly (vs 5s before) → countdown synced → clip plays.
- "Play Now" button → clip plays immediately for all viewers, countdown ends.
- "Cancel" button → banner hides for all viewers.
- Kill WS: request falls back to HTTP POST + piggybacked poll. Same behavior as today.
- Request collision: second viewer's request rejected with clear error.
- Cooldown: requesting again within 30s of a played request → rejected with remaining time.

### Rollback

Remove the `wsConnected` branch in `requestClip`. Requests always go through HTTP. Zero behavioral change.

### Manual tests

1. Two viewers: viewer A browses clips and selects one. Verify viewer B sees the request banner appear instantly.
2. Verify countdown is synchronized (both viewers show the same remaining time, within ~1s).
3. Viewer B clicks "Play Now". Verify clip plays immediately for both.
4. Viewer A requests, then clicks "Cancel". Verify banner hides for both.
5. Viewer A requests a clip, it plays. Viewer A immediately requests another. Verify cooldown error.
6. Disconnect WS. Request a clip. Verify HTTP fallback works.

---

## Phase Sequencing and Timeline

| Phase | Depends on | Estimated size | Can ship independently |
|-------|-----------|---------------|----------------------|
| **1A** Skeleton + proxy | Nothing | ~200 LOC + config | Yes |
| **1B** Clip sync | 1A | ~300 LOC server + ~150 LOC client | Yes (with 1A) |
| **1C** Discord variant | 1B | ~100 LOC client (port from 1B) | Yes |
| **2A** Skip voting | 1B | ~100 LOC server + ~50 LOC client | Yes (with 1A+1B) |
| **2B** Clip requests | 1B | ~150 LOC server + ~80 LOC client | Yes (with 1A+1B) |

**1A and 1B can be combined into a single deploy** if preferred — they're separated here for clarity, but in practice building the skeleton without clip sync has limited standalone value. Shipping 1A alone is mainly useful to validate the Railway deployment model before writing the clip sync code.

**2A and 2B can be done in either order** — they're independent features that both depend on the room/presence infrastructure from Phase 1. Skip voting (2A) is simpler and has a more noticeable UX improvement, so it's recommended first.

**Total new code:** ~900 LOC server-side (Node), ~380 LOC client-side (JS additions to both player files). No existing code is removed — only branching (`if wsConnected`) is added.

---

## Deployment Model

### Option A: Single Railway service (recommended for Phase 1)

Run Node alongside PHP in the same container:

```
startCommand: "node realtime/server.js & php db_bootstrap.php; php-fpm -D && nginx -g 'daemon off;'"
```

- Node listens on 9090 (internal)
- nginx proxies `/ws` to 9090, everything else to php-fpm on 9000
- Single container, single deploy, no cross-service networking

**Tradeoff:** If Node crashes, it takes the WS service down but PHP keeps serving (nginx routes non-WS traffic normally). The `&` backgrounding means Railway's restart policy doesn't directly monitor Node. A process supervisor (or a simple bash wrapper that restarts Node on exit) would harden this.

### Option B: Separate Railway service (recommended if Phase 1 is stable)

Add a second Railway service in the same project:

- Service 1: PHP app (unchanged)
- Service 2: Node realtime service (its own `package.json`, port 9090)
- Railway internal networking: `realtime.railway.internal:9090`
- External: `wss://realtime-cliptv.up.railway.app/ws` (separate domain) or proxy via custom domain

**Tradeoff:** More operational complexity, but Node and PHP scale/restart independently. Better for production. Migrate to this after Phase 1 proves stable.

### nginx proxy requirement

The key config addition is the WebSocket upgrade proxy. The `proxy_read_timeout 86400` prevents nginx from closing idle WS connections (default is 60s).

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Railway doesn't support WS on Nixpacks PHP build | Medium | Blocks Phase 1A | Test with minimal deploy first. Fallback: Option B (separate service). |
| Node process crashes in production | Medium | WS offline, polling takes over | Polling fallback is automatic. Add bash restart wrapper. |
| WS and polling both running creates split-brain | Low | Two viewers on different paths see different state | Both paths write/read the same truth source. WS viewers ignore polls. Poll viewers don't know about WS. No conflict. |
| `clip_change` via WS arrives before host's local playback starts | Low | Brief desync | Client already handles this — `isSwitching` lock prevents re-entrant clip loads. |
| Reconnect storm after server restart (all viewers reconnect at once) | Low | Brief CPU spike on Node | Jittered reconnect delay (add random 0-1s to backoff). Room creation is cheap. |
