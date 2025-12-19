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
  // !pb - Show currently playing clip
  async pb(channel, tags, args) {
    try {
      const url = `${config.apiBaseUrl}/now_playing_get.php?login=${config.channel}`;
      const res = await fetchWithTimeout(url);
      const data = await res.json();

      if (!data || !data.seq) {
        return 'No clip currently playing.';
      }

      const title = data.title || 'Unknown';
      return `Now playing Clip #${data.seq}: ${title}`;
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
      const url = `${config.apiBaseUrl}/pclip.php?login=${config.channel}&key=${config.adminKey}&seq=${seq}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!pclip error:', err.message);
      return 'Could not play clip.';
    }
  },

  // !like <seq> - Upvote a clip
  async like(channel, tags, args) {
    const seq = parseInt(args[0]);
    if (!seq || seq <= 0) {
      return 'Usage: !like <clip#>';
    }

    try {
      const user = tags.username || 'anonymous';
      const url = `${config.apiBaseUrl}/vote_submit.php?login=${config.channel}&user=${user}&seq=${seq}&vote=like`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!like error:', err.message);
      return 'Could not record vote.';
    }
  },

  // !dislike <seq> - Downvote a clip
  async dislike(channel, tags, args) {
    const seq = parseInt(args[0]);
    if (!seq || seq <= 0) {
      return 'Usage: !dislike <clip#>';
    }

    try {
      const user = tags.username || 'anonymous';
      const url = `${config.apiBaseUrl}/vote_submit.php?login=${config.channel}&user=${user}&seq=${seq}&vote=dislike`;
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
      const url = `${config.apiBaseUrl}/cremove.php?login=${config.channel}&key=${config.adminKey}&seq=${seq}`;
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

    try {
      const url = `${config.apiBaseUrl}/cadd.php?login=${config.channel}&key=${config.adminKey}&seq=${seq}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!cadd error:', err.message);
      return 'Could not restore clip.';
    }
  },

  // !cfind <query> - Search clips by title (mod only)
  async cfind(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const query = args.join(' ').trim();
    if (query.length < 2) {
      return 'Usage: !cfind <search term>';
    }

    try {
      const url = `${config.apiBaseUrl}/cfind.php?login=${config.channel}&key=${config.adminKey}&q=${encodeURIComponent(query)}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!cfind error:', err.message);
      return 'Could not search clips.';
    }
  },

  // !playlist <name> - Play a saved playlist (mod only)
  async playlist(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const name = args.join(' ').trim();
    if (!name) {
      return 'Usage: !playlist <name>';
    }

    try {
      // First find playlist by name
      const findUrl = `${config.apiBaseUrl}/playlist_api.php?action=get_by_name&login=${config.channel}&key=${config.adminKey}&name=${encodeURIComponent(name)}`;
      const findRes = await fetchWithTimeout(findUrl);
      const findData = await findRes.json();

      if (findData.error) {
        return `Playlist "${name}" not found`;
      }

      // Now play it
      const playUrl = `${config.apiBaseUrl}/playlist_api.php?action=play&login=${config.channel}&key=${config.adminKey}&id=${findData.playlist.id}`;
      const playRes = await fetchWithTimeout(playUrl);
      const playData = await playRes.json();

      return playData.message || 'Playing playlist';
    } catch (err) {
      console.error('!playlist error:', err.message);
      return 'Could not play playlist.';
    }
  },

  // !clip or !clips - Show info about the clip system
  async clip(channel, tags, args) {
    return `Use !pb to see the current clip. Mods can use !pclip <#> to play a specific clip. Vote with !like <#> or !dislike <#>`;
  },

  // Alias
  async clips(channel, tags, args) {
    return commands.clip(channel, tags, args);
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
  console.log(`Joined channel: ${config.channel}`);
  console.log(`Bot username: ${config.botUsername}`);
  console.log('Commands active: !pb, !pclip, !cfind, !playlist, !like, !dislike, !cremove, !cadd, !clip');
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
