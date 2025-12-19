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
  apiBaseUrl: (process.env.API_BASE_URL || 'https://clipsystem-production.up.railway.app').trim().replace(/\/+$/, ''),
  adminKey: (process.env.ADMIN_KEY || '').trim()
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

// The channel whose clips we're playing (CLIP_CHANNEL env var, or first channel)
const clipChannel = config.clipChannel || channels[0] || 'floppyjimmie';

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

// Message deduplication - prevent processing duplicate Twitch messages
const recentMessages = new Map();
const DEDUP_WINDOW_MS = 5000; // 5 second window for deduplication

function isDuplicateMessage(messageId) {
  if (!messageId) return false;

  const now = Date.now();

  // Clean old entries
  for (const [id, time] of recentMessages) {
    if (now - time > DEDUP_WINDOW_MS) {
      recentMessages.delete(id);
    }
  }

  if (recentMessages.has(messageId)) {
    console.log(`Duplicate message detected: ${messageId}`);
    return true;
  }

  recentMessages.set(messageId, now);
  return false;
}

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

// Fetch helper with timeout, no caching, and no keep-alive
async function fetchWithTimeout(url, timeoutMs = 5000) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(url, {
      signal: controller.signal,
      cache: 'no-store',
      keepalive: false,
      headers: {
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache',
        'Connection': 'close'
      }
    });
    clearTimeout(timeout);
    return response;
  } catch (err) {
    clearTimeout(timeout);
    throw err;
  }
}

// Command handlers
const commands = {
  // !clip - Show currently playing clip with link
  async clip(channel, tags, args) {
    try {
      const apiUrl = `${config.apiBaseUrl}/now_playing_get.php?login=${clipChannel}`;
      const res = await fetchWithTimeout(apiUrl);
      const data = await res.json();

      if (!data || !data.seq) {
        return 'No clip currently playing.';
      }

      const clipUrl = data.url || `https://clips.twitch.tv/${data.clip_id}`;
      return `Clip #${data.seq}: ${clipUrl}`;
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

    try {
      const url = `${config.apiBaseUrl}/pclip.php?login=${clipChannel}&key=${config.adminKey}&seq=${seq}`;
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
        const npRes = await fetchWithTimeout(`${config.apiBaseUrl}/now_playing_get.php?login=${clipChannel}`);
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
      const url = `${config.apiBaseUrl}/vote_submit.php?login=${clipChannel}&user=${user}&seq=${seq}&vote=like`;
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
        const npRes = await fetchWithTimeout(`${config.apiBaseUrl}/now_playing_get.php?login=${clipChannel}`);
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
      const url = `${config.apiBaseUrl}/vote_submit.php?login=${clipChannel}&user=${user}&seq=${seq}&vote=dislike`;
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
      const url = `${config.apiBaseUrl}/cremove.php?login=${clipChannel}&key=${config.adminKey}&seq=${seq}`;
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
      const url = `${config.apiBaseUrl}/cadd.php?login=${clipChannel}&key=${config.adminKey}&seq=${seq}`;
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
      // Use fresh https request to avoid any connection reuse issues
      const https = require('https');
      const cacheBuster = Date.now() + '_' + Math.random().toString(36).substr(2, 9);

      // Build URL parts separately to avoid encoding issues
      const encodedQuery = encodeURIComponent(query);
      const encodedLogin = encodeURIComponent(clipChannel);
      const encodedKey = encodeURIComponent(config.adminKey);
      const path = `/cfind.php?login=${encodedLogin}&key=${encodedKey}&q=${encodedQuery}&_cb=${cacheBuster}`;

      console.log(`!cfind request: ${query} -> path: ${path.substring(0, 80)}...`);

      return new Promise((resolve, reject) => {
        const options = {
          hostname: 'clipsystem-production.up.railway.app',
          port: 443,
          path: path,
          method: 'GET',
          headers: {
            'Cache-Control': 'no-cache',
            'Connection': 'close'
          },
          agent: false // Disable connection pooling
        };

        const req = https.request(options, (res) => {
          let data = '';
          res.on('data', chunk => data += chunk);
          res.on('end', () => {
            console.log(`!cfind response: ${data.substring(0, 100)}`);
            resolve(data);
          });
        });

        req.on('error', (err) => {
          console.error('!cfind error:', err.message);
          resolve('Could not search clips.');
        });

        req.setTimeout(5000, () => {
          req.destroy();
          resolve('Search timed out.');
        });

        req.end();
      });
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
      const findUrl = `${config.apiBaseUrl}/playlist_api.php?action=get_by_name&login=${clipChannel}&key=${config.adminKey}&name=${encodeURIComponent(name)}`;
      const findRes = await fetchWithTimeout(findUrl);
      const findData = await findRes.json();

      if (findData.error) {
        return `Playlist "${name}" not found`;
      }

      // Now play it
      const playUrl = `${config.apiBaseUrl}/playlist_api.php?action=play&login=${clipChannel}&key=${config.adminKey}&id=${findData.playlist.id}`;
      const playRes = await fetchWithTimeout(playUrl);
      const playData = await playRes.json();

      return playData.message || 'Playing playlist';
    } catch (err) {
      console.error('!playlist error:', err.message);
      return 'Could not play playlist.';
    }
  },

  // !clips - Alias for !clip
  async clips(channel, tags, args) {
    return commands.clip(channel, tags, args);
  },

  // !chelp - Show available clip commands
  async chelp(channel, tags, args) {
    if (isMod(tags)) {
      return 'Clip commands: !clip (current), !like/!dislike [#], !pclip <#>, !cremove <#>, !cadd <#>, !cfind <query>, !playlist <name>';
    }
    return 'Clip commands: !clip (see current), !like [#] (upvote), !dislike [#] (downvote)';
  }
};

// Message handler
client.on('message', async (channel, tags, message, self) => {
  // Ignore own messages
  if (self) return;

  // Check for duplicate messages from Twitch
  const messageId = tags['id'] || tags['message-id'];
  if (isDuplicateMessage(messageId)) {
    return;
  }

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

  // Log command execution for debugging
  if (cmdName === 'cfind') {
    console.log(`Processing !cfind from ${tags.username}: "${args.join(' ')}" (msg-id: ${messageId})`);
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
  console.log(`Joined channels: ${channels.join(', ')}`);
  console.log(`Clip channel: ${clipChannel}`);
  console.log(`Bot username: ${config.botUsername}`);
  console.log('Commands active: !clip, !pclip, !cfind, !playlist, !like, !dislike, !cremove, !cadd, !chelp');
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
