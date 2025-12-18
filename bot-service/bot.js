/**
 * FloppyJimmie Clip System - Twitch Chat Bot
 *
 * Handles chat commands directly via tmi.js (no Nightbot needed).
 *
 * Environment variables (set in Railway):
 *   TWITCH_BOT_USERNAME - Bot's Twitch username
 *   TWITCH_OAUTH_TOKEN  - OAuth token (from twitchtokengenerator.com)
 *   TWITCH_CHANNEL      - Channel to join for commands (e.g., "thearsondragon")
 *   CLIP_CHANNEL        - Channel whose clips to use (e.g., "floppyjimmie") - defaults to TWITCH_CHANNEL
 *   API_BASE_URL        - Base URL for PHP endpoints (e.g., "https://clipsystem-production.up.railway.app")
 *   ADMIN_KEY           - Admin key for mod commands
 */

const tmi = require('tmi.js');

// Configuration from environment
const config = {
  botUsername: process.env.TWITCH_BOT_USERNAME || '',
  oauthToken: process.env.TWITCH_OAUTH_TOKEN || '',
  channel: process.env.TWITCH_CHANNEL || 'floppyjimmie',
  clipChannel: process.env.CLIP_CHANNEL || process.env.TWITCH_CHANNEL || 'floppyjimmie',
  apiBaseUrl: process.env.API_BASE_URL || 'https://clipsystem-production.up.railway.app',
  adminKey: process.env.ADMIN_KEY || 'flopjim2024'
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
  channels: [config.channel]
});

// Rate limiting - prevent spam
const cooldowns = new Map();
const COOLDOWN_MS = 3000; // 3 second cooldown per user per command

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
    try {
      const url = `${config.apiBaseUrl}/now_playing_get.php?login=${config.clipChannel}`;
      const res = await fetchWithTimeout(url);
      const text = await res.text();

      if (!text || text === 'No clip yet') {
        return 'No clip currently playing.';
      }

      return `Now playing ${text}`;
    } catch (err) {
      console.error('!pb error:', err.message);
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

    try {
      const url = `${config.apiBaseUrl}/pclip.php?login=${config.clipChannel}&key=${config.adminKey}&seq=${seq}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!pclip error:', err.message);
      return 'Could not play clip.';
    }
  },

  // !like [seq] - Upvote a clip (current clip if no seq provided)
  async like(channel, tags, args) {
    let seq = parseInt(args[0]);

    // If no seq provided, get current playing clip
    if (!seq || seq <= 0) {
      try {
        const nowUrl = `${config.apiBaseUrl}/now_playing_get.php?login=${config.clipChannel}`;
        const nowRes = await fetchWithTimeout(nowUrl);
        const nowText = await nowRes.text();
        // Parse "Clip #123: url" format
        const match = nowText.match(/Clip #(\d+)/);
        if (match) {
          seq = parseInt(match[1]);
        } else {
          return 'No clip currently playing to like.';
        }
      } catch (err) {
        console.error('!like get current error:', err.message);
        return 'Could not get current clip.';
      }
    }

    try {
      const user = tags.username || 'anonymous';
      const url = `${config.apiBaseUrl}/vote_submit.php?login=${config.clipChannel}&user=${user}&seq=${seq}&vote=like`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!like error:', err.message);
      return 'Could not record vote.';
    }
  },

  // !dislike [seq] - Downvote a clip (current clip if no seq provided)
  async dislike(channel, tags, args) {
    let seq = parseInt(args[0]);

    // If no seq provided, get current playing clip
    if (!seq || seq <= 0) {
      try {
        const nowUrl = `${config.apiBaseUrl}/now_playing_get.php?login=${config.clipChannel}`;
        const nowRes = await fetchWithTimeout(nowUrl);
        const nowText = await nowRes.text();
        // Parse "Clip #123: url" format
        const match = nowText.match(/Clip #(\d+)/);
        if (match) {
          seq = parseInt(match[1]);
        } else {
          return 'No clip currently playing to dislike.';
        }
      } catch (err) {
        console.error('!dislike get current error:', err.message);
        return 'Could not get current clip.';
      }
    }

    try {
      const user = tags.username || 'anonymous';
      const url = `${config.apiBaseUrl}/vote_submit.php?login=${config.clipChannel}&user=${user}&seq=${seq}&vote=dislike`;
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

    try {
      const url = `${config.apiBaseUrl}/cremove.php?login=${config.clipChannel}&key=${config.adminKey}&seq=${seq}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!cremove error:', err.message);
      return 'Could not remove clip.';
    }
  },

  // !clips - Alias for !clip
  async clips(channel, tags, args) {
    return commands.clip(channel, tags, args);
  },

  // !chelp - Show command help
  async chelp(channel, tags, args) {
    return '!clip (shows current clip) | !like / !dislike to vote on current clip or !like # !dislike # to vote for specific clip | Mod Commands - !pclip # (plays specific clip) | !cremove # (permanently removes specific clip)';
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
  console.log(`Listening in channel: ${config.channel}`);
  console.log(`Using clips from: ${config.clipChannel}`);
  console.log(`Bot username: ${config.botUsername}`);
  console.log('Commands active: !clip, !pclip, !like, !dislike, !cremove');
});

client.on('disconnected', (reason) => {
  console.log('Disconnected:', reason);
});

// Connect
console.log('Starting FloppyJimmie Clip Bot...');
client.connect().catch(err => {
  console.error('Failed to connect:', err);
  process.exit(1);
});
