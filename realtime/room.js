'use strict';

const GRACE_PERIOD_MS = 15000;   // 15s before removing disconnected viewer
const ROOM_DESTROY_MS = 30000;   // 30s after last viewer before destroying room

class Room {
  constructor(channel) {
    this.channel = channel;
    this.viewers = new Map();     // viewerId -> { socket, joinedAt, graceTimer }
    this.hostId = null;
    this.clip = null;             // Phase 1B populates this
    this.startedAt = null;
    this.destroyTimer = null;
    this.createdAt = Date.now();
  }

  join(viewerId, socket) {
    // Cancel room destruction if pending
    if (this.destroyTimer) {
      clearTimeout(this.destroyTimer);
      this.destroyTimer = null;
    }

    const existing = this.viewers.get(viewerId);
    if (existing) {
      // Reconnect within grace — swap socket, cancel grace timer
      if (existing.graceTimer) {
        clearTimeout(existing.graceTimer);
        existing.graceTimer = null;
      }
      // Close old socket if still open
      if (existing.socket && existing.socket.readyState <= 1) {
        try { existing.socket.close(4001, 'replaced'); } catch (_) {}
      }
      existing.socket = socket;
      return { reconnected: true };
    }

    this.viewers.set(viewerId, {
      socket,
      joinedAt: Date.now(),
      graceTimer: null,
    });

    // First viewer becomes host
    if (!this.hostId) {
      this.hostId = viewerId;
    }

    return { reconnected: false };
  }

  leave(viewerId, onGraceExpired) {
    const viewer = this.viewers.get(viewerId);
    if (!viewer) return { removed: false };

    // Start grace period — don't remove yet
    viewer.socket = null;
    viewer.graceTimer = setTimeout(() => {
      const result = this._removeViewer(viewerId);
      if (onGraceExpired) onGraceExpired(result);
    }, GRACE_PERIOD_MS);

    return { removed: false, graceStarted: true };
  }

  _removeViewer(viewerId) {
    const viewer = this.viewers.get(viewerId);
    if (!viewer) return { removed: false, hostChanged: false, newHostId: null };

    if (viewer.graceTimer) {
      clearTimeout(viewer.graceTimer);
    }
    this.viewers.delete(viewerId);

    // Promote host if the removed viewer was host
    let hostChanged = false;
    if (this.hostId === viewerId) {
      hostChanged = this._promoteHost();
    }

    // Schedule room destruction if empty
    if (this.activeViewerCount() === 0 && !this.destroyTimer) {
      this.destroyTimer = setTimeout(() => {
        // Room manager checks and removes — handled by periodic sweep
      }, ROOM_DESTROY_MS);
    }

    return { removed: true, hostChanged, newHostId: this.hostId };
  }

  _promoteHost() {
    // Find the longest-connected viewer with an active socket
    let oldest = null;
    let oldestTime = Infinity;
    for (const [id, viewer] of this.viewers) {
      if (viewer.socket && viewer.joinedAt < oldestTime) {
        oldest = id;
        oldestTime = viewer.joinedAt;
      }
    }
    this.hostId = oldest; // null if no active viewers
    return this.hostId !== null;
  }

  broadcast(msg, excludeViewerId) {
    const data = JSON.stringify(msg);
    for (const [id, viewer] of this.viewers) {
      if (id === excludeViewerId) continue;
      if (viewer.socket && viewer.socket.readyState === 1) {
        viewer.socket.send(data);
      }
    }
  }

  unicast(viewerId, msg) {
    const viewer = this.viewers.get(viewerId);
    if (viewer && viewer.socket && viewer.socket.readyState === 1) {
      viewer.socket.send(JSON.stringify(msg));
    }
  }

  serialize(viewerId) {
    return {
      type: 'room_state',
      ts: Date.now() / 1000,
      channel: this.channel,
      you: {
        viewer_id: viewerId,
        is_host: this.hostId === viewerId,
        is_authed: false,  // Phase 1A: no auth yet
        username: null,
      },
      clip: this.clip,
      started_at: this.startedAt,
      viewers: this.activeViewerCount(),
      host_id: this.hostId,
      skip: { votes: 0, needed: 1, my_vote: false, triggered: false },
      request: null,
      hud_position: null,
    };
  }

  activeViewerCount() {
    let count = 0;
    for (const viewer of this.viewers.values()) {
      // Count viewers with active sockets OR in grace period
      if (viewer.socket || viewer.graceTimer) count++;
    }
    return count;
  }

  connectedViewerCount() {
    let count = 0;
    for (const viewer of this.viewers.values()) {
      if (viewer.socket && viewer.socket.readyState === 1) count++;
    }
    return count;
  }

  isExpired() {
    return this.destroyTimer !== null && this.activeViewerCount() === 0;
  }
}

// Global room registry
const rooms = new Map(); // channel -> Room

function getOrCreateRoom(channel) {
  let room = rooms.get(channel);
  if (room) {
    // Cancel destruction if pending
    if (room.destroyTimer) {
      clearTimeout(room.destroyTimer);
      room.destroyTimer = null;
    }
    return room;
  }
  room = new Room(channel);
  rooms.set(channel, room);
  console.log(`[room] created: ${channel}`);
  return room;
}

function destroyRoom(channel) {
  const room = rooms.get(channel);
  if (room) {
    if (room.destroyTimer) clearTimeout(room.destroyTimer);
    // Clean up any remaining grace timers
    for (const viewer of room.viewers.values()) {
      if (viewer.graceTimer) clearTimeout(viewer.graceTimer);
    }
    rooms.delete(channel);
    console.log(`[room] destroyed: ${channel}`);
  }
}

module.exports = { Room, rooms, getOrCreateRoom, destroyRoom };
