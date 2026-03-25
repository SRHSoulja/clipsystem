# ClipTV WebSocket — Infrastructure Recommendation

**Status:** Recommendation
**Date:** 2026-03-25
**Decision needed:** How to deploy the WebSocket realtime service for Phase 1

---

## Recommendation: Same-container sidecar, then separate service after validation

Start with Node running alongside PHP in the existing Railway container. Promote to a separate Railway service after Phase 1B proves stable in production (~2 weeks). Do not add Redis.

---

## Options evaluated

### Option 1: Stay polling-only

**What it means:** Keep the current architecture. The optimization work already shipped (polling reduction, piggybacked requests, background-tab backoff) cut request volume ~55%. No new infrastructure.

**Where it falls short:** Polling has a hard floor. Even at 3-5s intervals, you're paying for ~38 HTTP round-trips per minute per viewer. Each one is a full PHP-FPM process spawn, Postgres query, JSON encode/decode, and nginx response cycle. The CPU and DB cost per viewer is structurally high. And the product ceiling is visible: clip transitions have 1-3s inherent delay, skip votes take a full heartbeat cycle, and viewer counts ghost for 18s after disconnect.

**Verdict:** The optimization work was necessary regardless — it's the right floor to build from. But polling is the wrong long-term model for a synchronized viewing product. It works, it's shipped, and it buys time. It is not the destination.

### Option 2: Same-container sidecar (recommended for Phase 1)

**What it means:** Add `node realtime/server.js &` to the Railway start command. Node listens on port 9090. nginx proxies `/ws` to Node, everything else to PHP-FPM. One container, one deploy, one bill.

**Why this is the best first move:**

1. **Zero new infrastructure.** No new Railway service, no new domain, no new networking config, no new deploy pipeline. The existing `git push → Railway auto-deploy` workflow ships both PHP and Node in one action.

2. **nginx is already there.** The existing `nginx.template.conf` handles all routing. Adding a `/ws` location block is 6 lines. Railway's Nixpacks PHP build includes nginx — there's no custom Dockerfile to maintain.

3. **Shared filesystem.** Node can read `channel_settings` from the same Postgres via `DATABASE_URL` (if needed later), and the `cache/` directory is shared. No cross-service networking.

4. **Failure isolation is adequate.** If Node crashes, nginx returns 502 for `/ws` only. PHP continues serving all non-WS traffic. The client fallback to polling activates within seconds. If PHP-FPM crashes, Node stays up but viewers can't do HTTP actions (votes, clip pool loading) — same failure mode as today, WS doesn't make it worse.

5. **Cost is the same container.** No additional Railway service charge. Node's memory footprint for handling WebSocket connections is ~50MB base + ~2KB per connection. At ClipTV's current scale (single-digit concurrent viewers), this is negligible alongside PHP-FPM's memory.

**Deployment complexity:** Minimal. One line added to `startCommand`. One nginx location block. No new Railway services, no new environment variables beyond `DATABASE_URL` (which already exists).

**Runtime reliability concern:** The `&` backgrounding means Railway doesn't directly monitor the Node process. If Node exits, it stays dead until the next deploy. Mitigation: wrap in a bash loop (`while true; do node realtime/server.js; sleep 1; done &`) or use a minimal process manager. This is acceptable for Phase 1 because the polling fallback covers any Node downtime automatically.

**Scaling limitation:** One Node process, one event loop. A single Node process handles ~10,000 concurrent WebSocket connections comfortably. ClipTV is nowhere near this. This is not a real constraint.

### Option 3: Separate Railway service

**What it means:** A second Railway service in the same project. Its own `package.json`, its own container, its own deploy lifecycle. Connected via Railway's internal networking or a separate public domain.

**Why not first:** Adds operational surface that isn't justified at current scale. Two deploy pipelines to manage. Cross-service auth (PHP needs to validate WS connections, WS needs to call PHP endpoints — both require network hops that don't exist in the sidecar model). Separate domain means CORS and cookie considerations. Separate billing line.

**When to promote to this:** After Phase 1B is stable and you want independent scaling or independent deploys. The migration is straightforward: extract `realtime/` into its own repo or Railway service, update the nginx proxy to point to the new service's internal address, and update the client's WS URL. No code changes beyond config.

**Verdict:** This is the right Phase 2/3 deployment model. Not the right Phase 1 model.

### Option 4: Add Redis

**Why not:** Redis solves two problems that ClipTV doesn't have.

**Problem 1: Shared state across multiple Node processes.** ClipTV runs one Node process. Room state lives in that process's memory. There is no second process to share with. If ClipTV grows to need horizontal scaling (thousands of concurrent viewers across many channels), Redis pub/sub would coordinate multiple Node instances. That's a Phase 4+ concern at the earliest.

**Problem 2: Persistence across restarts.** Room state is ephemeral. When the Node process restarts, rooms are empty. The first viewer to reconnect creates a fresh room and becomes host. This is correct behavior — there's no value in persisting "viewer X was watching channel Y" across a server restart. The viewers reconnect automatically and the room rebuilds in seconds.

**Cost:** Railway Redis is a separate service with its own billing. Even the smallest instance adds ~$5/month and operational complexity (connection management, serialization, monitoring). For state that is correctly ephemeral and fits in <1MB of memory, this is pure overhead.

**Verdict:** Do not add Redis. If the need arises (multi-process Node, or a feature that requires pubsub between PHP and Node), revisit then. The architecture is designed so Redis can be inserted later without protocol changes — room state serialization is already JSON.

---

## State placement

| State | Where | Why |
|-------|-------|-----|
| Room membership (who is in which channel) | Node memory | Ephemeral. Created on first join, destroyed on last leave. Lost on restart = correct behavior. |
| Current clip + started_at | Node memory | Ephemeral session state. Rebuilt when host reconnects and sends first `clip_change`. |
| Viewer presence (count, host assignment) | Node memory | Derived from active WebSocket connections. The most natural in-memory state possible. |
| Skip votes | Node memory | Per-viewer boolean, reset on every clip change. No persistence value. |
| Active clip request + countdown timer | Node memory | 6-second lifecycle. Persisting this would be absurd. |
| Viewer reconnect grace (15s slots) | Node memory | Short-lived timer. Lost on restart = viewers just rejoin fresh. |
| Clips, votes, playlists, settings | Postgres (via PHP) | Durable business data. Unchanged. |
| Chat messages | Postgres (via PHP) | Durable. Node relays in realtime, PHP batch-writes for archival. (Phase 3.) |
| Clip play history, skip events | Postgres (via PHP) | Analytics. Node fires POST to `clip_played.php` on clip change. |
| Channel settings (HUD, banned words) | Postgres, cached in Node room on creation | Read once when room is created. Invalidated if a mod changes settings (Phase 3: push update via PHP → Node HTTP call or shared Postgres poll). |

**Nothing goes in Redis.** Everything is either ephemeral (Node memory) or durable (Postgres). There is no middle ground that Redis would fill at this scale.

---

## Cost impact

### Current polling model

Every active viewer generates ~38 HTTP requests/minute. Each request:
- nginx accepts connection, routes to PHP-FPM
- PHP-FPM spawns/reuses a worker process (~20MB memory per worker)
- Worker connects to Postgres (connection pooled, but still a query per request)
- Worker serializes JSON response, PHP-FPM returns it, nginx sends it

Railway charges by CPU-second and memory-second. PHP-FPM is the most expensive component per-request because each request occupies a full process for its duration (typically 5-50ms).

For 3 concurrent viewers: ~114 req/min = ~1.9 req/sec sustained. This is light, but the per-request overhead is high relative to the information transferred (a few hundred bytes of JSON).

### WebSocket model

Each active viewer holds one persistent TCP connection to Node. Memory cost: ~2KB per connection. CPU cost: near zero when idle (no data flowing between clip changes). When a clip changes, Node broadcasts a JSON message to N viewers — one `JSON.stringify` + N `socket.send` calls. Total CPU: microseconds.

PHP-FPM request volume drops from ~38/min/viewer to ~6/min/viewer (chat polling + HUD polling in Phase 1, dropping further in Phase 2/3). The expensive per-request PHP overhead applies to far fewer requests.

**Estimated savings:** Phase 1 eliminates ~85% of PHP-FPM request volume for active sync viewers. The Node sidecar's resource consumption is negligible (a few MB of memory, near-zero CPU). Net result: lower Railway bill for the same viewer count, or more headroom for growth at the same bill.

The exact savings depend on ClipTV's traffic pattern — if most Railway cost comes from dashboard/search/archive (not sync polling), the savings are proportionally smaller. The metrics instrumentation deployed today will show the actual breakdown within a week.

---

## Scaling limitations accepted for Phase 1

| Limitation | Threshold | What happens | When to address |
|-----------|-----------|-------------|-----------------|
| Single Node process | ~10K concurrent WS connections | Event loop saturation, message delays | When ClipTV has thousands of simultaneous viewers (not soon) |
| In-memory room state | Node restart = all rooms lost | Viewers reconnect, rooms rebuild in seconds | Acceptable. Only reconsider if restarts cause visible disruption at scale. |
| No horizontal scaling | One Railway container | Can't distribute load across machines | When single container can't handle the load (same constraint as current PHP) |
| Node crash = WS offline | Process exit | Polling fallback activates automatically | Add bash restart wrapper for Phase 1. Separate service for Phase 2+. |
| Channel settings cached once | Mod changes settings while room is active | Room doesn't see the change until recreated | Add a PHP → Node HTTP notify endpoint in Phase 2. Acceptable for now. |

All of these are real limitations. None of them matter at ClipTV's current scale. The architecture is designed so that graduating past each limitation (separate service, Redis pub/sub, persistent rooms) doesn't require protocol changes — only deployment changes.

---

## Decision summary

| Question | Answer |
|----------|--------|
| Where does the WS service run? | Same Railway container as PHP, behind nginx |
| What process manager? | Bash restart loop for Phase 1, separate service for Phase 2+ |
| What port? | 9090 internal (nginx proxies `/ws`) |
| Do we need Redis? | No |
| Do we need a separate domain? | No |
| Do we need a separate Railway service? | Not yet. Promote after Phase 1B is stable. |
| What state is in memory? | All ephemeral session state (rooms, presence, clips, votes, requests) |
| What state is in Postgres? | All durable business state (unchanged) |
| What is the cost impact? | Lower. ~85% fewer PHP-FPM requests for sync viewers. Node overhead negligible. |
| What is the scaling ceiling? | ~10K concurrent WS connections. Far beyond current needs. |
| What is the restart behavior? | Rooms lost, viewers reconnect, rooms rebuild. Polling fallback covers the gap. |
