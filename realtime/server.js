'use strict';

const http = require('http');
const { URL } = require('url');
const { WebSocketServer } = require('ws');
const { rooms, getOrCreateRoom, destroyRoom } = require('./room');

const PORT = parseInt(process.env.PORT || process.env.WS_PORT || '9090', 10);
const PING_INTERVAL_MS = 30000;  // 30s protocol-level ping
const PONG_TIMEOUT_MS = 10000;   // 10s to respond before disconnect

// ── HTTP server (health endpoint + WS upgrade) ─────────────────────

const CORS_ORIGIN = process.env.CORS_ORIGIN || '*';

const httpServer = http.createServer((req, res) => {
  res.setHeader('Access-Control-Allow-Origin', CORS_ORIGIN);

  if (req.url === '/health') {
    let totalViewers = 0;
    for (const room of rooms.values()) {
      totalViewers += room.connectedViewerCount();
    }
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, rooms: rooms.size, viewers: totalViewers }));
    return;
  }
  // Reject non-WS HTTP requests
  res.writeHead(426, { 'Content-Type': 'text/plain' });
  res.end('WebSocket upgrade required');
});

// ── WebSocket server ────────────────────────────────────────────────

const wss = new WebSocketServer({ noServer: true });

httpServer.on('upgrade', (req, socket, head) => {
  // Parse query params from request URL
  let url;
  try {
    url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  } catch (_) {
    socket.write('HTTP/1.1 400 Bad Request\r\n\r\n');
    socket.destroy();
    return;
  }

  const channel = (url.searchParams.get('channel') || '').toLowerCase().trim();
  const viewerId = (url.searchParams.get('viewer_id') || '').trim();

  if (!channel || !viewerId) {
    socket.write('HTTP/1.1 400 Bad Request\r\n\r\nMissing channel or viewer_id\r\n');
    socket.destroy();
    return;
  }

  // Validate channel format (alphanumeric + underscore only)
  if (!/^[a-z0-9_]+$/.test(channel)) {
    socket.write('HTTP/1.1 400 Bad Request\r\n\r\nInvalid channel format\r\n');
    socket.destroy();
    return;
  }

  wss.handleUpgrade(req, socket, head, (ws) => {
    ws._channel = channel;
    ws._viewerId = viewerId;
    wss.emit('connection', ws, req);
  });
});

wss.on('connection', (ws) => {
  const channel = ws._channel;
  const viewerId = ws._viewerId;

  // Join room
  const room = getOrCreateRoom(channel);
  const { reconnected } = room.join(viewerId, ws);

  console.log(`[viewer] ${reconnected ? 'reconnected' : 'joined'}: ${viewerId} -> ${channel} (${room.connectedViewerCount()} connected)`);

  // Send room state snapshot
  room.unicast(viewerId, room.serialize(viewerId));

  // Broadcast presence to others (skip if reconnect within grace — no flicker)
  if (!reconnected) {
    room.broadcast({
      type: 'presence',
      ts: Date.now() / 1000,
      viewers: room.activeViewerCount(),
      host_id: room.hostId,
    }, viewerId);
  }

  // ── Ping/pong keepalive ─────────────────────────────────────────

  ws._isAlive = true;

  ws.on('pong', () => {
    ws._isAlive = true;
  });

  // ── Incoming messages (Phase 1A: ignore all) ────────────────────

  ws.on('message', () => {
    // Phase 1A accepts no client messages.
    // Phase 1B+ will dispatch here.
  });

  // ── Disconnect ──────────────────────────────────────────────────

  ws.on('close', () => {
    console.log(`[viewer] disconnected: ${viewerId} <- ${channel}`);

    room.leave(viewerId, (result) => {
      // Grace period expired — viewer did not reconnect
      if (!result.removed) return;

      console.log(`[viewer] grace expired: ${viewerId} <- ${channel} (${room.connectedViewerCount()} connected)`);

      // Broadcast host change if promoted
      if (result.hostChanged && result.newHostId) {
        room.broadcast({
          type: 'host_change',
          ts: Date.now() / 1000,
          host_id: result.newHostId,
          reason: 'previous_disconnected',
        });
      }

      // Broadcast updated presence
      room.broadcast({
        type: 'presence',
        ts: Date.now() / 1000,
        viewers: room.activeViewerCount(),
        host_id: room.hostId,
      });

      // Destroy room if empty
      if (room.activeViewerCount() === 0) {
        destroyRoom(channel);
      }
    });
  });
});

// ── Ping interval (all connections) ─────────────────────────────────

const pingInterval = setInterval(() => {
  wss.clients.forEach((ws) => {
    if (!ws._isAlive) {
      console.log(`[viewer] ping timeout: ${ws._viewerId} <- ${ws._channel}`);
      ws.terminate();
      return;
    }
    ws._isAlive = false;
    ws.ping();
  });
}, PING_INTERVAL_MS);

wss.on('close', () => {
  clearInterval(pingInterval);
});

// ── Room cleanup: periodic sweep for expired rooms ──────────────────

setInterval(() => {
  for (const [channel, room] of rooms) {
    if (room.activeViewerCount() === 0 && !room.destroyTimer) {
      // Stale room with no viewers and no destroy timer — clean up
      destroyRoom(channel);
    }
  }
}, 60000); // Every 60s

// ── Start ───────────────────────────────────────────────────────────

httpServer.listen(PORT, () => {
  console.log(`[realtime] listening on port ${PORT}`);
});

// Graceful shutdown
process.on('SIGTERM', () => {
  console.log('[realtime] SIGTERM received, shutting down');
  wss.close();
  httpServer.close();
  process.exit(0);
});

process.on('SIGINT', () => {
  console.log('[realtime] SIGINT received, shutting down');
  wss.close();
  httpServer.close();
  process.exit(0);
});
