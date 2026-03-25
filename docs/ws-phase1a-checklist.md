# Phase 1A — Build Checklist

**Scope:** Node WebSocket skeleton + nginx proxy. No player changes. Proves WS connections work on Railway.

---

## Files to create

### `realtime/package.json`

```json
{
  "name": "cliptv-realtime",
  "private": true,
  "version": "0.1.0",
  "dependencies": {
    "ws": "^8.18.0"
  }
}
```

No dev dependencies. No build step. No TypeScript. Plain Node + `ws`.

### `realtime/server.js`

Entry point. Responsibilities for Phase 1A only:

1. Create HTTP server on port `process.env.WS_PORT || 9090`
2. Attach `ws.WebSocketServer` to it
3. On upgrade: parse `?channel=` and `?viewer_id=` from URL. Reject if missing.
4. On connection: join viewer to room (create room if first). Send `room_state`. Broadcast `presence`.
5. On close: start 15s grace timer. On expiry: remove viewer, broadcast `presence`, promote host if needed. Destroy room if empty (after 30s grace).
6. Protocol-level ping every 30s. Close connection if no pong within 10s.
7. Log to stdout: room create/destroy, viewer join/leave, host promotion. One line per event.
8. Health endpoint: `GET /health` returns 200 with `{ rooms: N, viewers: N }`.
9. Ignore all incoming WebSocket text messages (Phase 1A accepts no client messages).

### `realtime/room.js`

Room class.

```javascript
class Room {
  constructor(channel) {
    this.channel = channel;
    this.viewers = new Map();     // viewerId → { socket, joinedAt, graceTimer }
    this.hostId = null;
    this.clip = null;             // Phase 1B populates this
    this.startedAt = null;
    this.destroyTimer = null;
  }

  join(viewerId, socket) { ... }
  leave(viewerId) { ... }         // starts grace timer
  rejoin(viewerId, socket) { ... } // cancels grace, swaps socket
  promoteHost() { ... }           // longest-connected viewer
  broadcast(msg, exclude) { ... }  // JSON.stringify once, send to all
  unicast(viewerId, msg) { ... }
  serialize(viewerId) { ... }     // room_state snapshot for this viewer
  isEmpty() { ... }               // all viewers disconnected (including grace)
}
```

Exported: `Room` class + `rooms` Map (channel → Room).

### `realtime/.gitignore`

```
node_modules/
```

---

## Files to modify

### `nginx.template.conf`

Add WebSocket proxy block **before** the `# Clean URL: /tv/username` line (line 100), inside the `server { }` block:

```nginx
        # WebSocket: proxy to Node realtime service
        location /ws {
            proxy_pass http://127.0.0.1:9090;
            proxy_http_version 1.1;
            proxy_set_header Upgrade $http_upgrade;
            proxy_set_header Connection "upgrade";
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_read_timeout 86400;
            proxy_send_timeout 86400;
        }

        # Health check for realtime service
        location = /ws/health {
            proxy_pass http://127.0.0.1:9090/health;
            proxy_set_header Host $host;
        }
```

The `proxy_read_timeout 86400` (24h) prevents nginx from closing idle WebSocket connections. The default 60s would kill every connection that doesn't send data for a minute.

### `railway.json`

Add `startCommand` to run Node alongside PHP:

```json
{
  "$schema": "https://railway.app/railway.schema.json",
  "build": {
    "builder": "NIXPACKS"
  },
  "deploy": {
    "startCommand": "cd /app/realtime && npm install --production 2>&1 | tail -1 && cd /app && (while true; do node realtime/server.js 2>&1; echo '[realtime] process exited, restarting in 2s'; sleep 2; done) & php db_bootstrap.php && php-fpm -D && nginx -g 'daemon off;'",
    "restartPolicyType": "ON_FAILURE",
    "restartPolicyMaxRetries": 10
  }
}
```

Breakdown of the startCommand:
- `cd /app/realtime && npm install --production` — install ws dependency (node_modules is not in git)
- `(while true; do node realtime/server.js; sleep 2; done) &` — run Node in background with auto-restart on crash
- `php db_bootstrap.php` — existing schema bootstrap
- `php-fpm -D` — start PHP-FPM as daemon
- `nginx -g 'daemon off;'` — start nginx in foreground (Railway needs a foreground process)

### `nixpacks.toml` (new file, root of repo)

Railway's Nixpacks auto-detects PHP from `*.php` files but won't install Node unless told. This file adds Node to the build:

```toml
[phases.setup]
aptPkgs = ["nodejs", "npm"]
```

If Railway's Nixpacks version includes Node by default in PHP builds, this file can be omitted. Test the deploy without it first — if `node` is not found, add it.

### `.gitignore`

Add:

```
realtime/node_modules/
```

---

## What is NOT modified

- `clipplayer_sync.html` — no player changes
- `discord/index.html` — no player changes
- Any PHP endpoint — unchanged
- Any existing polling behavior — unchanged

---

## Local/dev test steps

### Step 1: Verify Node + ws works locally

```bash
cd /mnt/c/GMGNRepeat/clipsystem/realtime
npm install
node server.js
# Should print: [realtime] listening on port 9090
```

### Step 2: Test WebSocket connection from browser

Open browser devtools console on any page:

```javascript
const ws = new WebSocket('ws://localhost:9090/ws?channel=test&viewer_id=dev1');
ws.onopen = () => console.log('connected');
ws.onmessage = (e) => console.log('msg:', JSON.parse(e.data));
ws.onclose = (e) => console.log('closed:', e.code, e.reason);
```

Expected: `connected`, then `msg:` with a `room_state` object.

### Step 3: Test presence with two connections

Open a second console tab:

```javascript
const ws2 = new WebSocket('ws://localhost:9090/ws?channel=test&viewer_id=dev2');
ws2.onmessage = (e) => console.log('msg:', JSON.parse(e.data));
```

Expected: Both connections receive `presence` with `viewers: 2`. First connection's `room_state` says `is_host: true`. Second says `is_host: false`.

### Step 4: Test host promotion

Close the first WebSocket (`ws.close()`). Wait 15s (grace period). Expected: second connection receives `host_change` with `host_id: "dev2"` and `presence` with `viewers: 1`.

### Step 5: Test reconnect within grace

Open connection as `dev1`. Close it. Within 15s, reconnect with same `viewer_id=dev1`. Expected: new `room_state` with `is_host: true` (host slot preserved). No `presence` flicker on the other connection.

### Step 6: Test health endpoint

```bash
curl http://localhost:9090/health
# Expected: { "ok": true, "rooms": 1, "viewers": 2 }
```

### Step 7: Test missing params rejected

```javascript
const bad = new WebSocket('ws://localhost:9090/ws');
bad.onclose = (e) => console.log('rejected:', e.code, e.reason);
// Expected: rejected, code 4400 or similar
```

### Step 8: Verify PHP app unaffected

Open `/tv/{channel}` locally (if local PHP dev server is running). Verify the player loads and works normally via polling. No WS connection attempted (player JS not modified yet).

---

## Railway deploy test steps

### Step 1: Push and monitor build

```bash
git push
```

Watch Railway build logs. Verify:
- `npm install` runs for `realtime/` directory
- `node` binary is available (if not, add `nixpacks.toml`)
- Build succeeds

### Step 2: Verify PHP app still works

Open `https://clips.gmgnrepeat.com/tv/{channel}`. Verify the player loads, clips play, polling works. This must be identical to pre-deploy behavior.

### Step 3: Test WS connection on Railway

Open browser devtools console on any ClipTV page:

```javascript
const ws = new WebSocket('wss://clips.gmgnrepeat.com/ws?channel=test&viewer_id=railtest1');
ws.onopen = () => console.log('connected');
ws.onmessage = (e) => console.log(JSON.parse(e.data));
ws.onclose = (e) => console.log('closed:', e.code, e.reason);
```

Expected: `connected`, then `room_state` message.

If connection fails:
- Check Railway deploy logs for Node startup errors
- Check if nginx proxy is routing `/ws` correctly (`curl -v wss://clips.gmgnrepeat.com/ws` — should get 101 Switching Protocols or a WS-related error, not 404)
- If 404: nginx config not picking up the `/ws` location. Check template variable substitution.
- If 502: Node isn't running or isn't on port 9090. Check logs.

### Step 4: Test health endpoint on Railway

```bash
curl https://clips.gmgnrepeat.com/ws/health
# Expected: { "ok": true, "rooms": 0, "viewers": 0 }
```

### Step 5: Multi-viewer on Railway

Open two browser tabs with devtools. Connect both to the same channel. Verify `viewers: 2` in presence messages. Close one. Verify `viewers: 1` after grace.

### Step 6: Verify Node auto-restarts

(Optional, if you have Railway shell access) Kill the Node process. Verify it restarts within 2s (the bash restart loop). Verify new WS connections succeed after restart.

---

## Rollback steps

### If WS doesn't work but PHP is fine

1. Remove `startCommand` from `railway.json` (revert to no startCommand — Nixpacks default)
2. Remove `/ws` and `/ws/health` location blocks from `nginx.template.conf`
3. Push. Railway redeploys with PHP only.
4. The `realtime/` directory can stay in the repo — it's inert without the startCommand.

### If the deploy itself is broken (PHP won't start)

1. `git revert HEAD` (revert the last commit)
2. Push. Railway redeploys to the previous known-good state.
3. Investigate offline.

### If Node is consuming too many resources

1. Remove just the Node startup from `startCommand` (keep `php db_bootstrap.php && php-fpm -D && nginx -g 'daemon off;'`)
2. Push. Node stops, WS returns 502, PHP is unaffected.

---

## Definition of done

All of the following must be true:

- [ ] `wss://clips.gmgnrepeat.com/ws?channel=test&viewer_id=x` connects and returns `room_state`
- [ ] Two viewers in the same channel see `viewers: 2` in presence
- [ ] Closing one viewer drops presence to 1 after 15s grace
- [ ] Host promotion works: close host → other viewer becomes host after grace
- [ ] Reconnect within grace: same viewer_id rejoins without presence flicker
- [ ] `/ws/health` returns room/viewer counts
- [ ] Missing channel/viewer_id params are rejected
- [ ] Existing ClipTV player (`/tv/{channel}`) works identically to before (no WS attempted, polling unchanged)
- [ ] `metrics_report.php` shows no change in polling traffic (proving no side effects)
- [ ] Railway deploy logs show Node process starting and staying alive
- [ ] Node crash → auto-restart within 2s (bash loop)
