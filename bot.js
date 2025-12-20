/**
 * FloppyJimmie Clip System - Twitch Chat Bot
 *
 * Handles chat commands directly via tmi.js (no Nightbot needed).
 *
 * Environment variables (set in Railway):
 *   TWITCH_BOT_USERNAME - Bot's Twitch username
 *   TWITCH_OAUTH_TOKEN  - OAuth token (from twitchtokengenerator.com)
 *   TWITCH_CHANNEL      - Channel to join (e.g., "floppyjimmie")
 *   API_BASE_URL        - Base URL for PHP endpoints (e.g., "https://clipsystem-production.up.railway.app")
 *   ADMIN_KEY           - Admin key for mod commands
 */

const tmi = require('tmi.js');

// Configuration from environment
const config = {
  botUsername: process.env.TWITCH_BOT_USERNAME || '',
  oauthToken: process.env.TWITCH_OAUTH_TOKEN || '',
  channel: process.env.TWITCH_CHANNEL || 'floppyjimmie',
  clipChannel: process.env.CLIP_CHANNEL || '',
  apiBaseUrl: process.env.API_BASE_URL || 'https://clipsystem-production.up.railway.app',
  adminKey: process.env.ADMIN_KEY || ''
};

// Validate required config
if (!config.botUsername || !config.oauthToken) {
  console.error('ERROR: Missing required environment variables:');
  console.error('  TWITCH_BOT_USERNAME - Your bot\'s Twitch username');
  console.error('  TWITCH_OAUTH_TOKEN  - OAuth token from twitchtokengenerator.com');
  console.error('');
  console.error('Set these in Railway environment variables.');
  process.exit(1);
}

// Ensure oauth token has the oauth: prefix
const oauthToken = config.oauthToken.startsWith('oauth:')
  ? config.oauthToken
  : `oauth:${config.oauthToken}`;

// Parse channels (supports comma-separated list for joining multiple chats)
const channels = config.channel.split(',').map(c => c.trim().toLowerCase()).filter(Boolean);

// Helper to get the clip channel for a given chat channel
// Checks for override first, otherwise uses the chat channel name
function getClipChannel(chatChannel) {
  // Remove # prefix if present
  const cleanChannel = chatChannel.replace(/^#/, '').toLowerCase();
  // Check for override, otherwise use the chat channel
  return channelOverrides.get(cleanChannel) || cleanChannel;
}

// Create TMI client
const client = new tmi.Client({
  options: { debug: false },
  connection: {
    reconnect: true,
    secure: true
  },
  identity: {
    username: config.botUsername,
    password: oauthToken
  },
  channels: channels
});

// Rate limiting - prevent spam
const cooldowns = new Map();
const COOLDOWN_MS = 3000; // 3 second cooldown per user per command

// Channel overrides - allows mods to control a different channel's clips
// Key: chat channel (without #), Value: target clip channel
const channelOverrides = new Map();

function isOnCooldown(user, command) {
  const key = `${user}:${command}`;
  const lastUsed = cooldowns.get(key) || 0;
  const now = Date.now();

  if (now - lastUsed < COOLDOWN_MS) {
    return true;
  }

  cooldowns.set(key, now);
  return false;
}

// Check if user is mod or broadcaster
function isMod(tags) {
  return tags.mod || tags.badges?.broadcaster === '1';
}

// Fetch helper with timeout
async function fetchWithTimeout(url, timeoutMs = 5000) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(url, { signal: controller.signal });
    clearTimeout(timeout);
    return response;
  } catch (err) {
    clearTimeout(timeout);
    throw err;
  }
}

// Command handlers
const commands = {
  // !clip - Show currently playing clip
  async clip(channel, tags, args) {
    const login = getClipChannel(channel);
    try {
      const apiUrl = `${config.apiBaseUrl}/now_playing_get.php?login=${login}`;
      const res = await fetchWithTimeout(apiUrl);
      const data = await res.json();

      if (!data || !data.seq) {
        return 'No clip currently playing.';
      }

      const title = data.title || 'Unknown';
      const clipUrl = data.url || `https://clips.twitch.tv/${data.clip_id}`;
      return `Clip #${data.seq}: ${title} - ${clipUrl}`;
    } catch (err) {
      console.error('!clip error:', err.message);
      return 'Could not fetch current clip.';
    }
  },

  // !pclip <seq> - Force play a specific clip (mod only)
  async pclip(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const seq = parseInt(args[0]);
    if (!seq || seq <= 0) {
      return 'Usage: !pclip <clip#>';
    }

    const login = getClipChannel(channel);
    try {
      const url = `${config.apiBaseUrl}/pclip.php?login=${login}&key=${config.adminKey}&seq=${seq}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!pclip error:', err.message);
      return 'Could not play clip.';
    }
  },

  // !like [seq] - Upvote a clip (current clip if no seq provided)
  async like(channel, tags, args) {
    const login = getClipChannel(channel);
    let seq = parseInt(args[0]);

    // If no seq provided, get current playing clip
    if (!seq || seq <= 0) {
      try {
        const npRes = await fetchWithTimeout(`${config.apiBaseUrl}/now_playing_get.php?login=${login}`);
        const npData = await npRes.json();
        if (npData && npData.seq) {
          seq = npData.seq;
        } else {
          return 'No clip currently playing. Use !like <clip#>';
        }
      } catch (err) {
        return 'Could not get current clip.';
      }
    }

    try {
      const user = tags.username || 'anonymous';
      const url = `${config.apiBaseUrl}/vote_submit.php?login=${login}&user=${user}&seq=${seq}&vote=like`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!like error:', err.message);
      return 'Could not record vote.';
    }
  },

  // !dislike [seq] - Downvote a clip (current clip if no seq provided)
  async dislike(channel, tags, args) {
    const login = getClipChannel(channel);
    let seq = parseInt(args[0]);

    // If no seq provided, get current playing clip
    if (!seq || seq <= 0) {
      try {
        const npRes = await fetchWithTimeout(`${config.apiBaseUrl}/now_playing_get.php?login=${login}`);
        const npData = await npRes.json();
        if (npData && npData.seq) {
          seq = npData.seq;
        } else {
          return 'No clip currently playing. Use !dislike <clip#>';
        }
      } catch (err) {
        return 'Could not get current clip.';
      }
    }

    try {
      const user = tags.username || 'anonymous';
      const url = `${config.apiBaseUrl}/vote_submit.php?login=${login}&user=${user}&seq=${seq}&vote=dislike`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!dislike error:', err.message);
      return 'Could not record vote.';
    }
  },

  // !cremove <seq> - Remove a clip from pool (mod only)
  async cremove(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const seq = parseInt(args[0]);
    if (!seq || seq <= 0) {
      return 'Usage: !cremove <clip#>';
    }

    const login = getClipChannel(channel);
    try {
      const url = `${config.apiBaseUrl}/cremove.php?login=${login}&key=${config.adminKey}&seq=${seq}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!cremove error:', err.message);
      return 'Could not remove clip.';
    }
  },

  // !cadd <seq> - Restore a removed clip (mod only)
  async cadd(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const seq = parseInt(args[0]);
    if (!seq || seq <= 0) {
      return 'Usage: !cadd <clip#>';
    }

    const login = getClipChannel(channel);
    try {
      const url = `${config.apiBaseUrl}/cadd.php?login=${login}&key=${config.adminKey}&seq=${seq}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!cadd error:', err.message);
      return 'Could not restore clip.';
    }
  },

  // !cfind - Link to clip search/browse site
  async cfind(channel, tags, args) {
    const login = getClipChannel(channel);
    return `Browse & search clips: ${config.apiBaseUrl}/clip_search.php?login=${login}`;
  },

  // !cskip - Skip the current clip (mod only)
  async cskip(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const login = getClipChannel(channel);
    try {
      const url = `${config.apiBaseUrl}/cskip.php?login=${login}&key=${config.adminKey}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!cskip error:', err.message);
      return 'Could not skip clip.';
    }
  },

  // !ccat <game> - Filter clips by category/game (mod only)
  // Use !ccat off to return to all games
  async ccat(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const category = args.join(' ').trim();
    if (!category) {
      return 'Usage: !ccat <game> to filter, !ccat off to exit';
    }

    const login = getClipChannel(channel);
    try {
      const url = `${config.apiBaseUrl}/ccat.php?login=${login}&key=${config.adminKey}&category=${encodeURIComponent(category)}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!ccat error:', err.message);
      return 'Could not set category.';
    }
  },

  // !chelp - Show available clip commands
  async chelp(channel, tags, args) {
    if (isMod(tags)) {
      return 'Clip commands: !clip, !cfind, !like/!dislike [#], !pclip <#>, !cskip, !ccat <game>, !cremove <#>, !cadd <#>, !cswitch <channel>';
    }
    return 'Clip commands: !clip (current), !cfind (browse), !like [#] (upvote), !dislike [#] (downvote)';
  },

  // !cswitch <channel> - Switch which channel's clips commands affect (mod only)
  // Use !cswitch off or !cswitch reset to return to normal
  async cswitch(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const chatChannel = channel.replace(/^#/, '').toLowerCase();
    const target = (args[0] || '').toLowerCase().replace(/^@/, '');

    if (!target) {
      const current = channelOverrides.get(chatChannel);
      if (current) {
        return `Currently controlling: ${current}'s clips. Use !cswitch off to reset.`;
      }
      return `Currently controlling: ${chatChannel}'s clips (default). Use !cswitch <channel> to switch.`;
    }

    // Reset to default
    if (target === 'off' || target === 'reset' || target === chatChannel) {
      channelOverrides.delete(chatChannel);
      return `Switched back to ${chatChannel}'s clips.`;
    }

    // Set override
    channelOverrides.set(chatChannel, target);
    return `Now controlling ${target}'s clips. Use !cswitch off to reset.`;
  }
};

// Message handler
client.on('message', async (channel, tags, message, self) => {
  // Ignore own messages
  if (self) return;

  // Parse message
  const trimmed = message.trim();
  if (!trimmed.startsWith('!')) return;

  const parts = trimmed.split(/\s+/);
  const cmdName = parts[0].substring(1).toLowerCase();
  const args = parts.slice(1);

  // Find command handler
  const handler = commands[cmdName];
  if (!handler) return;

  // Check cooldown (except for mods)
  if (!isMod(tags) && isOnCooldown(tags.username, cmdName)) {
    return;
  }

  try {
    const response = await handler(channel, tags, args);
    if (response) {
      client.say(channel, response);
    }
  } catch (err) {
    console.error(`Error handling !${cmdName}:`, err);
  }
});

// Connection events
client.on('connected', (addr, port) => {
  console.log(`Connected to Twitch IRC at ${addr}:${port}`);
  console.log(`Joining channels: ${channels.join(', ')}`);
  console.log(`Multi-channel mode: commands use clips from the channel they're typed in`);
  console.log(`Bot username: ${config.botUsername}`);
  console.log('Commands active: !clip, !cfind, !like, !dislike, !pclip, !cskip, !ccat, !cremove, !cadd, !cswitch, !chelp');
});

client.on('join', (channel, username, self) => {
  if (self) {
    console.log(`Successfully joined: ${channel}`);
  }
});

client.on('part', (channel, username, self) => {
  if (self) {
    console.log(`Left channel: ${channel}`);
  }
});

client.on('disconnected', (reason) => {
  console.log('Disconnected:', reason);
});

client.on('notice', (channel, msgid, message) => {
  console.log(`Notice from ${channel}: [${msgid}] ${message}`);
});

// Connect
console.log('Starting FloppyJimmie Clip Bot...');
client.connect().catch(err => {
  console.error('Failed to connect:', err);
  process.exit(1);
});
