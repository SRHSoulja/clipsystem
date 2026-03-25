# ClipTV WebSocket Protocol — Phase 1 + 2

**Status:** Specification
**Date:** 2026-03-25
**Scope:** Message types for room join, clip sync, presence, host coordination, skip voting, clip requests

---

## Connection

```
wss://clips.gmgnrepeat.com/ws?channel={login}&viewer_id={viewerId}
```

**Query parameters:**

| Param | Required | Description |
|-------|----------|-------------|
| `channel` | yes | Channel login (lowercase alphanumeric + underscore) |
| `viewer_id` | yes | Stable viewer identifier (from localStorage, survives reconnect) |

**Authentication** is carried via cookie (`Cookie: PHPSESSID=...`) or header (`X-Auth-Token: {hmac}`). The server validates before completing the upgrade. Unauthenticated viewers are allowed for watching (no auth required to join a room), but chat and requests require auth — those messages return an error if the connection is anonymous.

**Subprotocol:** None. Plain JSON text frames. No binary.

---

## Message Envelope

Every message is a JSON object with a `type` field.

```
Client → Server:  { "type": "...", "seq": 1, ... }
Server → Client:  { "type": "...", "seq": 1, "ts": 1711360000.000, ... }
```

| Field | Direction | Required | Description |
|-------|-----------|----------|-------------|
| `type` | both | yes | Message type string |
| `seq` | C→S | yes | Client-assigned monotonic sequence number. Server echoes it in `ack` responses. Used for at-most-once dedup on reconnect. |
| `ts` | S→C | yes | Server timestamp (epoch seconds, ms precision). Authoritative clock. |

Client `seq` resets to 0 on fresh page load. On reconnect, the client resumes from its last `seq + 1`. The server tracks the last processed `seq` per viewer and ignores replayed messages with `seq <= lastProcessed`.

---

## Server → Client Messages

### `room_state` — Full snapshot on join/reconnect

Sent once immediately after the WebSocket upgrade completes. Contains everything the client needs to render the current room state without any additional HTTP calls.

```json
{
  "type": "room_state",
  "ts": 1711360000.000,
  "channel": "streamername",
  "you": {
    "viewer_id": "abc123",
    "is_host": false,
    "is_authed": true,
    "username": "someuser"
  },
  "clip": {
    "id": "AbcClipId123",
    "url": "https://clips-media...mp4",
    "title": "Insane clutch play",
    "curator": "clipmaker42",
    "duration": 28.5,
    "seq": 147,
    "created_at": "2025-11-03T14:22:00Z"
  },
  "started_at": 1711359990.000,
  "viewers": 3,
  "host_id": "viewer_xyz",
  "skip": {
    "votes": 1,
    "needed": 2,
    "my_vote": false,
    "triggered": false
  },
  "request": null,
  "hud_position": "tr"
}
```

| Field | Auth | Description |
|-------|------|-------------|
| `clip` | — | Current clip. `null` if room is empty (no clips loaded yet). |
| `started_at` | — | Epoch when the current clip started. Client computes `position = now - started_at`. **Authoritative.** |
| `viewers` | — | Current viewer count. **Authoritative.** |
| `host_id` | — | Viewer ID of the current host. **Authoritative.** |
| `skip.votes` | — | Total skip votes in the room. |
| `skip.needed` | — | Votes needed for majority (`floor(viewers/2)+1`, solo=1). |
| `skip.my_vote` | — | Whether this viewer has voted to skip. |
| `request` | — | Active clip request, or `null`. See `clip_requested` shape. |
| `hud_position` | — | HUD position setting for this channel. |

**Client behavior on `room_state`:**
1. If `clip` is not null: play at `position = max(0, now - started_at)`. If position > duration, wait for next clip change.
2. Set `isController = (you.is_host === true)`.
3. Update viewer count, skip UI, request banner from snapshot.
4. If `clip` is null and `you.is_host`: run `controllerAdvance()`.

### `clip_change` — New clip playing

Broadcast to all viewers in the room when the host advances to a new clip.

```json
{
  "type": "clip_change",
  "ts": 1711360030.000,
  "clip": {
    "id": "NewClipId456",
    "url": "https://clips-media...mp4",
    "title": "That was close",
    "curator": "clipfan99",
    "duration": 15.2,
    "seq": 148,
    "created_at": "2025-12-10T08:15:00Z"
  },
  "started_at": 1711360030.000
}
```

**Authoritative fields:** `clip.id`, `clip.url`, `started_at`. Client must play this clip from position 0 (or calculated offset for late delivery).

**Idempotency:** Client ignores if `clip.id === currentClipId`. This handles network retransmit and reconnect-replay.

**Side effects (server):** On processing the host's `clip_change`, the server also:
- Resets all skip votes in the room
- Clears any active clip request
- POSTs to `clip_played.php` (fire-and-forget, for analytics/rotation)

### `presence` — Viewer count changed

Broadcast when a viewer joins, leaves, or reconnects.

```json
{
  "type": "presence",
  "ts": 1711360031.000,
  "viewers": 4,
  "host_id": "viewer_xyz"
}
```

**Authoritative fields:** `viewers`, `host_id`. Client updates its `viewerCount` and `isController` from this.

Deliberately minimal — no viewer list in the default broadcast. The viewer list is available in `room_state` on join. Broadcasting the full list on every join/leave is wasteful for rooms with many viewers. If a viewer list UI is needed later, add a separate `viewer_list` message type.

### `host_change` — Host promoted

Broadcast when the current host disconnects and a new host is promoted.

```json
{
  "type": "host_change",
  "ts": 1711360035.000,
  "host_id": "viewer_abc",
  "reason": "previous_disconnected"
}
```

| `reason` | Meaning |
|----------|---------|
| `previous_disconnected` | Host's connection closed (clean or timeout) |
| `previous_idle` | Host failed ping/pong within timeout |
| `room_created` | First viewer joined, became host by default |

**Client behavior:** If `host_id === myViewerId`, set `isController = true` and start driving clip advancement. If the current clip has ended locally (player.ended), immediately call `controllerAdvance()`.

### `skip_state` — Skip vote tally updated

Broadcast to all viewers when any viewer votes or a vote is reset.

```json
{
  "type": "skip_state",
  "ts": 1711360040.000,
  "votes": 2,
  "needed": 2,
  "triggered": false
}
```

Sent to the voter only (unicast) with their personal vote status:

```json
{
  "type": "skip_ack",
  "ts": 1711360040.000,
  "seq": 5,
  "my_vote": true,
  "votes": 2,
  "needed": 2,
  "triggered": false
}
```

When `triggered` is `true`, the server has already determined majority was reached. The host's client calls `controllerAdvance()`. Non-host clients show the skip animation and wait for the `clip_change` broadcast.

### `skip_triggered` — Majority reached, skip authorized

Broadcast to all viewers when skip votes reach majority.

```json
{
  "type": "skip_triggered",
  "ts": 1711360041.000
}
```

**Client behavior:**
- Host: call `controllerAdvance()` → send `clip_change`
- Non-host: show skip animation, wait for `clip_change`

This is a separate message from `skip_state` so the client doesn't have to compute majority locally. The server is authoritative on when the threshold is crossed.

### `clip_requested` — Clip request submitted

Broadcast when a viewer requests a clip and `viewerCount > 1`.

```json
{
  "type": "clip_requested",
  "ts": 1711360050.000,
  "clip": {
    "id": "RequestedClip789",
    "seq": 55,
    "title": "Watch this one",
    "game": "Fortnite",
    "creator": "clipmaker",
    "duration": 22.0
  },
  "requester_id": "viewer_def",
  "countdown_seconds": 6,
  "expires_at": 1711360056.000
}
```

**Authoritative fields:** `expires_at` (server clock), `countdown_seconds` (initial value). Client uses `expires_at - now` for remaining time, not a local setTimeout. This prevents countdown drift between viewers.

### `clip_request_play` — Countdown ended, play the requested clip

Broadcast when the server-side countdown expires or a viewer clicks "Play Now".

```json
{
  "type": "clip_request_play",
  "ts": 1711360056.000,
  "clip": {
    "id": "RequestedClip789",
    "seq": 55,
    "title": "Watch this one",
    "game": "Fortnite",
    "creator": "clipmaker",
    "duration": 22.0
  }
}
```

**Client behavior (host):** Call `playSpecificClip(clip)` → which sends `clip_change` with resolved MP4 URL.
**Client behavior (non-host):** Hide request banner, wait for `clip_change`.

### `clip_request_cancelled` — Request cancelled

Broadcast when the requester cancels or the request is superseded.

```json
{
  "type": "clip_request_cancelled",
  "ts": 1711360053.000
}
```

Client hides the request banner.

### `error` — Server error or rejection

Unicast to the sender.

```json
{
  "type": "error",
  "ts": 1711360060.000,
  "seq": 7,
  "code": "request_cooldown",
  "message": "Wait 18s before requesting another clip"
}
```

| Code | Meaning |
|------|---------|
| `auth_required` | Action requires authentication |
| `not_host` | Only the host can send `clip_change` |
| `request_cooldown` | Per-user cooldown not expired |
| `request_collision` | Another request is already active |
| `invalid_message` | Malformed or unknown message type |
| `rate_limited` | Too many messages |

---

## Client → Server Messages

### `clip_change` — Host broadcasts new clip

Sent by the host only. Server validates that the sender is the current host and rejects otherwise.

```json
{
  "type": "clip_change",
  "seq": 3,
  "clip": {
    "id": "NewClipId456",
    "url": "https://clips-media...mp4",
    "title": "That was close",
    "curator": "clipfan99",
    "duration": 15.2,
    "seq": 148,
    "created_at": "2025-12-10T08:15:00Z"
  }
}
```

**Server behavior:**
1. Validate sender is host. If not → `error` with `not_host`.
2. Update `room.clip`, set `room.startedAt = now`.
3. Reset all skip votes in the room.
4. Clear any active clip request.
5. Broadcast `clip_change` to all viewers (including sender, for `started_at` authority).
6. Fire-and-forget POST to `clip_played.php`.

**Idempotency:** Server ignores if `clip.id === room.clip.id` (same clip re-sent).

### `skip_vote` — Vote to skip

```json
{
  "type": "skip_vote",
  "seq": 5,
  "vote": true
}
```

`vote: true` = skip, `vote: false` = cancel vote.

**Server behavior:**
1. Set `viewer.skipVote = vote`.
2. Compute `totalVotes` and `needed` from room state.
3. Send `skip_ack` (unicast to sender) with personal + room state.
4. Broadcast `skip_state` to all other viewers.
5. If `totalVotes >= needed`: broadcast `skip_triggered`, reset all votes.

**Idempotency:** Setting a boolean is inherently idempotent.

**Solo fast path:** When `viewers === 1`, the vote immediately triggers `skip_triggered`. The server doesn't need the client to tell it about solo status — it knows `room.viewers.size`.

### `clip_request` — Request a clip

```json
{
  "type": "clip_request",
  "seq": 7,
  "clip": {
    "id": "RequestedClip789",
    "seq": 55,
    "title": "Watch this one",
    "game": "Fortnite",
    "creator": "clipmaker",
    "duration": 22.0
  }
}
```

**Server behavior:**
1. If `viewers <= 1`: broadcast `clip_request_play` immediately (no countdown).
2. If active request exists and not from this viewer → `error` with `request_collision`.
3. If viewer is in cooldown (30s after last played request) → `error` with `request_cooldown`.
4. Set `room.activeRequest`, start server-side 6s timer.
5. Broadcast `clip_requested` to all viewers.
6. On timer expiry: broadcast `clip_request_play`, clear `room.activeRequest`.

**Idempotency:** Same requester re-sending for same clip ID is a no-op (request already active).

### `clip_request_play_now` — End countdown early

```json
{
  "type": "clip_request_play_now",
  "seq": 8
}
```

Any viewer can send this. Server cancels the countdown timer and broadcasts `clip_request_play` immediately.

### `clip_request_cancel` — Cancel my request

```json
{
  "type": "clip_request_cancel",
  "seq": 9
}
```

Only the original requester can cancel. Server clears `room.activeRequest`, cancels timer, broadcasts `clip_request_cancelled`.

### `ping` — Application-level keepalive (optional)

```json
{
  "type": "ping",
  "seq": 10
}
```

Response:

```json
{
  "type": "pong",
  "ts": 1711360070.000,
  "seq": 10
}
```

This is separate from WebSocket protocol-level ping/pong. It exists so the client can measure server round-trip time and detect application-layer stalls that protocol ping might not catch (e.g., server event loop blocked). Optional — the client can rely on protocol ping alone.

---

## Reconnect / Resync Behavior

### Client reconnect flow

```
1. Connection lost (onclose/onerror)
2. Enter reconnect loop: 1s, 2s, 4s, 8s, 16s, 30s cap
3. On successful reconnect:
   a. Send same channel + viewer_id in query params
   b. Receive room_state (full snapshot)
   c. Resume seq from last sent + 1
   d. Apply room_state:
      - If clip changed: play new clip at calculated position
      - If clip same: seek to calculated position if drift > 3s
      - Update presence, skip, request state from snapshot
4. If was host before disconnect:
   a. If room_state.you.is_host === true: resume as host (grace period held the slot)
   b. If room_state.you.is_host === false: another viewer was promoted, join as viewer
```

### Server reconnect handling

When a viewer disconnects, the server starts a **15-second grace period** before removing them from the room:

```
1. Connection closes
2. Mark viewer as "disconnecting", start 15s timer
3. If viewer reconnects with same viewer_id within 15s:
   a. Cancel timer
   b. Swap old socket for new socket
   c. Restore skip vote, host status
   d. Send room_state
   e. Do NOT broadcast presence change (viewer never "left")
4. If 15s expires without reconnect:
   a. Remove viewer from room
   b. If was host: promote next viewer, broadcast host_change
   c. Broadcast presence with decremented count
   d. If room empty: start 30s room destruction timer
```

This prevents flicker in viewer count during brief network interruptions and avoids unnecessary host promotions.

### Idempotency summary

| Message | Dedup strategy |
|---------|---------------|
| `clip_change` (C→S) | Server ignores if `clip.id === room.clip.id` |
| `clip_change` (S→C) | Client ignores if `clip.id === currentClipId` |
| `skip_vote` | Boolean set, inherently idempotent |
| `clip_request` | Server ignores if active request exists from same viewer for same clip |
| `clip_request_play_now` | Server ignores if no active request |
| `clip_request_cancel` | Server ignores if no active request from this viewer |
| All C→S with `seq` | Server tracks `lastProcessedSeq` per viewer. Ignores `seq <= last`. Handles reconnect replay. |

---

## Example Flows

### Solo viewer session

```
C: connect wss://...?channel=arson&viewer_id=v1
S: room_state { clip: null, viewers: 1, you: { is_host: true }, ... }
   — client sees is_host, clip is null → controllerAdvance()
C: clip_change { seq:1, clip: { id:"abc", url:"...", ... } }
S: clip_change { clip: { id:"abc", ... }, started_at: 1711360000 }
   — clip plays for all (just one viewer)
   — clip ends naturally
C: clip_change { seq:2, clip: { id:"def", ... } }
S: clip_change { clip: { id:"def", ... }, started_at: 1711360030 }
   — viewer wants to skip
C: skip_vote { seq:3, vote: true }
S: skip_ack { seq:3, my_vote:true, votes:1, needed:1, triggered:false }
S: skip_triggered {}
   — solo: triggered immediately, client calls controllerAdvance()
C: clip_change { seq:4, clip: { id:"ghi", ... } }
S: clip_change { ... }
```

### Multi-viewer skip flow

```
   — room has 3 viewers (v1=host, v2, v3)
v2→S: skip_vote { seq:5, vote: true }
S→v2: skip_ack { seq:5, my_vote:true, votes:1, needed:2, triggered:false }
S→all: skip_state { votes:1, needed:2, triggered:false }
v3→S: skip_vote { seq:3, vote: true }
S→v3: skip_ack { seq:3, my_vote:true, votes:2, needed:2, triggered:false }
S→all: skip_triggered {}
   — v1 (host) sees skip_triggered → controllerAdvance()
v1→S: clip_change { seq:8, clip: { id:"next", ... } }
S→all: clip_change { clip: { id:"next", ... }, started_at: ... }
   — server auto-resets all skip votes
```

### Clip request with countdown

```
   — room has 2 viewers (v1=host, v2)
v2→S: clip_request { seq:4, clip: { id:"req1", seq:55, title:"Watch this", ... } }
S→all: clip_requested { clip:{...}, requester_id:"v2", countdown_seconds:6, expires_at:T+6 }
   — both viewers show banner, countdown from server clock
   — 6 seconds pass (server timer)
S→all: clip_request_play { clip: { id:"req1", ... } }
   — v1 (host) calls playSpecificClip → resolves MP4 → sends clip_change
v1→S: clip_change { seq:9, clip: { id:"req1", url:"...", ... } }
S→all: clip_change { clip: { id:"req1", ... }, started_at: ... }
```

### Viewer joins mid-session

```
C: connect wss://...?channel=arson&viewer_id=v4
S: room_state {
     clip: { id:"abc", ... },
     started_at: 1711360000,    ← 15 seconds ago
     viewers: 4,
     host_id: "v1",
     you: { viewer_id:"v4", is_host:false },
     skip: { votes:0, needed:3, my_vote:false },
     request: null
   }
S→all: presence { viewers:4, host_id:"v1" }
   — v4 plays clip "abc" at position = now - 1711360000 = 15s
```

### Host disconnects, promotion

```
   — v1 (host) closes browser
   — server detects connection close
   — 15s grace period starts
   — 15s passes, v1 doesn't reconnect
S: remove v1 from room
S→all: host_change { host_id:"v2", reason:"previous_disconnected" }
S→all: presence { viewers:2, host_id:"v2" }
   — v2 sets isController=true
   — if current clip has ended: v2 calls controllerAdvance()
```

### Reconnect within grace period

```
   — v1 (host) has brief network blip
   — server detects close, starts 15s grace
   — 3s later, v1 reconnects with same viewer_id
C: connect wss://...?channel=arson&viewer_id=v1
S: cancel grace timer, swap socket
S→v1: room_state { ..., you: { is_host: true }, ... }
   — no presence broadcast (v1 never "left")
   — v1 resumes as host, seeks to current position
```
