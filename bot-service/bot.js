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

// Toggle for like/dislike commands (can be disabled by mods)
let likesEnabled = true;

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

// Check if user is mod or broadcaster (or special exception users)
function isMod(tags) {
  const username = (tags.username || '').toLowerCase();
  // Special exception: TheArsonDragon gets mod privileges
  if (username === 'thearsonddragon') return true;
  return tags.mod || tags.badges?.broadcaster === '1';
}

// Check if user is subscriber or higher (sub, mod, broadcaster)
function isSubOrHigher(tags) {
  return tags.subscriber || tags.badges?.subscriber ||
         tags.badges?.founder || tags.mod || tags.badges?.broadcaster === '1';
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
  // !clip [seq] - Show clip info (current if no seq provided)
  async clip(channel, tags, args) {
    try {
      // Check if a seq number was provided
      const seq = parseInt(args[0]);

      if (seq && seq > 0) {
        // Fetch specific clip by seq
        const apiUrl = `${config.apiBaseUrl}/clip_info.php?login=${clipChannel}&seq=${seq}`;
        const res = await fetchWithTimeout(apiUrl);
        const data = await res.json();

        if (data.error) {
          return `Clip #${seq} not found.`;
        }

        const title = data.title ? ` - ${data.title}` : '';
        return `Clip #${data.seq}${title}: ${data.url}`;
      } else {
        // No seq - show currently playing clip
        const apiUrl = `${config.apiBaseUrl}/now_playing_get.php?login=${clipChannel}`;
        const res = await fetchWithTimeout(apiUrl);
        const data = await res.json();

        if (!data || !data.seq) {
          return 'No clip currently playing.';
        }

        const clipUrl = data.url || `https://clips.twitch.tv/${data.clip_id}`;
        return `Clip #${data.seq}: ${clipUrl}`;
      }
    } catch (err) {
      console.error('!clip error:', err.message);
      return 'Could not fetch clip info.';
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
    if (!likesEnabled) return null; // Silently ignore when disabled

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
    if (!likesEnabled) return null; // Silently ignore when disabled

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

  // !cskip - Skip the current clip (mod only)
  async cskip(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    try {
      const url = `${config.apiBaseUrl}/cskip.php?login=${clipChannel}&key=${config.adminKey}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!cskip error:', err.message);
      return 'Could not skip clip.';
    }
  },

  // !cfind <query> - Search clips by title (subs and up)
  async cfind(channel, tags, args) {
    if (!isSubOrHigher(tags)) {
      return null; // Silently ignore non-subs
    }

    // Filter out empty args and invisible Unicode chars (Twitch adds these to duplicate messages)
    // \u034f = Combining Grapheme Joiner, \u200B-\u200D = zero-width chars, \uFEFF = BOM
    const cleanArgs = args.filter(a => a && a.replace(/[\u034f\u200B-\u200D\uFEFF\s]/g, ''));
    const query = cleanArgs.join(' ').trim();

    console.log(`!cfind args: ${JSON.stringify(args)} -> query: "${query}"`);

    try {
      const url = `${config.apiBaseUrl}/cfind.php?login=${encodeURIComponent(clipChannel)}&key=${encodeURIComponent(config.adminKey)}&q=${encodeURIComponent(query)}`;
      console.log(`!cfind request: "${query}"`);
      const res = await fetchWithTimeout(url);
      const data = await res.text();
      console.log(`!cfind response: ${data.substring(0, 100)}`);
      return data;
    } catch (err) {
      console.error('!cfind error:', err.message);
      return 'Could not search clips.';
    }
  },

  // !clips - Alias for !clip
  async clips(channel, tags, args) {
    return commands.clip(channel, tags, args);
  },

  // !chelp - Show available clip commands
  async chelp(channel, tags, args) {
    if (isMod(tags)) {
      return 'Clip commands: !clip, !like/!dislike [#], !cfind <query>, !cskip, !pclip <#>, !cremove <#>, !cadd <#>, !clikeoff/!clikeon';
    }
    if (isSubOrHigher(tags)) {
      return 'Clip commands: !clip (see current), !like/!dislike [#], !cfind <query> (search clips)';
    }
    return 'Clip commands: !clip (see current), !like [#] (upvote), !dislike [#] (downvote)';
  },

  // !clikeoff - Disable like/dislike commands (mod only)
  async clikeoff(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }
    likesEnabled = false;
    return 'Clip voting (!like/!dislike) disabled.';
  },

  // !clikeon - Enable like/dislike commands (mod only)
  async clikeon(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }
    likesEnabled = true;
    return 'Clip voting (!like/!dislike) enabled.';
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
  console.log('Commands active: !clip, !cskip, !pclip, !cfind, !like, !dislike, !cremove, !cadd, !chelp');
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
