/**
 * FloppyJimmie Clip System - Twitch Chat Bot
 *
 * Multi-channel bot - commands in each chat control that channel's clips.
 * Use !cswitch to temporarily control another channel's clips.
 *
 * Environment variables (set in Railway):
 *   TWITCH_BOT_USERNAME - Bot's Twitch username
 *   TWITCH_OAUTH_TOKEN  - OAuth token (from twitchtokengenerator.com)
 *   TWITCH_CHANNEL      - Fallback channels (comma-separated) - used if database is empty
 *   API_BASE_URL        - Base URL for PHP endpoints
 *   ADMIN_KEY           - Admin key for mod commands
 *
 * Channel management is now dynamic via bot_api.php - no need to update env vars!
 */

const tmi = require('tmi.js');

// Configuration from environment
const config = {
  botUsername: process.env.TWITCH_BOT_USERNAME || '',
  oauthToken: process.env.TWITCH_OAUTH_TOKEN || '',
  channel: process.env.TWITCH_CHANNEL || 'floppyjimmie',
  apiBaseUrl: (process.env.API_BASE_URL || 'https://clips.gmgnrepeat.com').trim().replace(/\/+$/, ''),
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

// Parse fallback channels from env (used if database is empty)
const fallbackChannels = config.channel.split(',').map(c => c.trim().toLowerCase()).filter(Boolean);

// Track current channels we're joined to
let currentChannels = new Set();

// Channel overrides - allows mods to control a different channel's clips
// Key: chat channel (without #), Value: target clip channel
const channelOverrides = new Map();

// Cache for voting settings - checked from database, cached briefly
// Key: channel login, Value: { votingEnabled: boolean, voteFeedback: boolean, cachedAt: timestamp }
const votingSettingsCache = new Map();
const VOTING_CACHE_TTL = 30000; // 30 second cache

// Cache for command settings - which commands are enabled/disabled per channel
// Key: channel login, Value: { commands: {cmdName: boolean}, cachedAt: timestamp }
const commandSettingsCache = new Map();
const COMMAND_CACHE_TTL = 30000; // 30 second cache

// Cache for bot settings - silent prefix, etc.
// Key: channel login, Value: { silentPrefix: boolean, cachedAt: timestamp }
const botSettingsCache = new Map();
const BOT_SETTINGS_CACHE_TTL = 30000; // 30 second cache

// Fetch voting settings for a channel (returns both voting_enabled and vote_feedback)
async function getVotingSettings(login) {
  const cached = votingSettingsCache.get(login);
  const now = Date.now();

  // Return cached value if fresh
  if (cached && (now - cached.cachedAt) < VOTING_CACHE_TTL) {
    return cached;
  }

  // Fetch from database via API
  try {
    const url = `${config.apiBaseUrl}/voting_status.php?login=${encodeURIComponent(login)}`;
    const res = await fetchWithTimeout(url, 3000);
    const data = await res.json();
    const settings = {
      votingEnabled: data.voting_enabled === true,
      voteFeedback: data.vote_feedback !== false, // Default to true
      cachedAt: now
    };
    votingSettingsCache.set(login, settings);
    return settings;
  } catch (err) {
    console.error('Error checking voting settings:', err.message);
    // On error, use cached value if available, otherwise default
    return cached || { votingEnabled: false, voteFeedback: true, cachedAt: now };
  }
}

// Check if voting is enabled for a channel
async function isVotingEnabled(login) {
  const settings = await getVotingSettings(login);
  return settings.votingEnabled;
}

// Check if vote feedback/confirmation is enabled for a channel
async function isVoteFeedbackEnabled(login) {
  const settings = await getVotingSettings(login);
  return settings.voteFeedback;
}

// Fetch command settings for a channel
async function getCommandSettings(login) {
  const cached = commandSettingsCache.get(login);
  const now = Date.now();

  // Return cached value if fresh
  if (cached && (now - cached.cachedAt) < COMMAND_CACHE_TTL) {
    return cached.commands;
  }

  // Fetch from database via API
  try {
    const url = `${config.apiBaseUrl}/command_settings.php?login=${encodeURIComponent(login)}`;
    const res = await fetchWithTimeout(url, 3000);
    const data = await res.json();

    if (data.success && data.commands) {
      commandSettingsCache.set(login, { commands: data.commands, cachedAt: now });
      return data.commands;
    }
    return null;
  } catch (err) {
    console.error('Error fetching command settings:', err.message);
    // On error, use cached value if available
    return cached ? cached.commands : null;
  }
}

// Check if a specific command is enabled for a channel
async function isCommandEnabled(login, commandName) {
  const commands = await getCommandSettings(login);
  // If we couldn't fetch settings, allow the command (fail open)
  if (!commands) return true;
  // If command not in settings, it's enabled by default
  return commands[commandName] !== false;
}

// Fetch bot settings for a channel (silent_prefix, etc.)
async function getBotSettings(login) {
  const cached = botSettingsCache.get(login);
  const now = Date.now();

  // Return cached value if fresh
  if (cached && (now - cached.cachedAt) < BOT_SETTINGS_CACHE_TTL) {
    return cached;
  }

  // Fetch from database via API
  try {
    const url = `${config.apiBaseUrl}/bot_settings.php?login=${encodeURIComponent(login)}`;
    const res = await fetchWithTimeout(url, 3000);
    const data = await res.json();
    const settings = {
      silentPrefix: data.silent_prefix === true,
      cachedAt: now
    };
    botSettingsCache.set(login, settings);
    return settings;
  } catch (err) {
    console.error('Error fetching bot settings:', err.message);
    // On error, use cached value if available, otherwise default
    return cached || { silentPrefix: false, cachedAt: now };
  }
}

// Check if silent prefix is enabled for a channel
async function isSilentPrefixEnabled(login) {
  const settings = await getBotSettings(login);
  return settings.silentPrefix;
}

// Helper to get the clip channel for a given chat channel
// Checks for override first, otherwise uses the chat channel name
function getClipChannel(chatChannel) {
  // Remove # prefix if present
  const cleanChannel = chatChannel.replace(/^#/, '').toLowerCase();
  // Check for override, otherwise use the chat channel
  return channelOverrides.get(cleanChannel) || cleanChannel;
}

// Create TMI client - starts with no channels, we'll join dynamically
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
  channels: [] // Start empty, sync will join channels
});

// Channel sync interval (check for changes every 30 seconds)
const CHANNEL_SYNC_INTERVAL = 30000;

// Fetch channel list from API
async function fetchChannelList() {
  try {
    const url = `${config.apiBaseUrl}/bot_api.php?action=list`;
    const res = await fetchWithTimeout(url, 5000);
    const data = await res.json();

    if (data.success && Array.isArray(data.channels)) {
      return data.channels.map(c => c.toLowerCase());
    }
    return null;
  } catch (err) {
    console.error('Error fetching channel list:', err.message);
    return null;
  }
}

// Sync channels - join new ones, leave removed ones
async function syncChannels() {
  const apiChannels = await fetchChannelList();

  // If API failed or returned empty, use fallback channels on first run
  let targetChannels;
  if (apiChannels === null) {
    // API error - keep current channels
    console.log('Channel sync: API unavailable, keeping current channels');
    return;
  } else if (apiChannels.length === 0 && currentChannels.size === 0) {
    // No channels in DB and we haven't joined any yet - use fallback
    console.log('Channel sync: No channels in database, using fallback:', fallbackChannels.join(', '));
    targetChannels = new Set(fallbackChannels);
  } else {
    targetChannels = new Set(apiChannels);
  }

  // Find channels to join (in target but not current)
  const toJoin = [...targetChannels].filter(c => !currentChannels.has(c));

  // Find channels to leave (in current but not target)
  const toLeave = [...currentChannels].filter(c => !targetChannels.has(c));

  // Join new channels
  for (const channel of toJoin) {
    try {
      console.log(`Joining channel: #${channel}`);
      await client.join(channel);
      currentChannels.add(channel);
    } catch (err) {
      console.error(`Failed to join #${channel}:`, err.message);
    }
  }

  // Leave removed channels
  for (const channel of toLeave) {
    try {
      console.log(`Leaving channel: #${channel}`);
      await client.part(channel);
      currentChannels.delete(channel);
    } catch (err) {
      console.error(`Failed to leave #${channel}:`, err.message);
    }
  }

  if (toJoin.length > 0 || toLeave.length > 0) {
    console.log(`Channel sync complete. Current channels: ${[...currentChannels].join(', ') || '(none)'}`);
  }
}

// Rate limiting - prevent spam
const cooldowns = new Map();
const COOLDOWN_MS = 3000; // 3 second cooldown per user per command

// Clean up cooldowns periodically to prevent memory leak
const COOLDOWN_CLEANUP_INTERVAL = 60000; // 1 minute
setInterval(() => {
  const now = Date.now();
  for (const [key, time] of cooldowns) {
    if (now - time > COOLDOWN_MS) {
      cooldowns.delete(key);
    }
  }
}, COOLDOWN_CLEANUP_INTERVAL);

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
  if (username === 'thearsondragon') return true;
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
  // !cclip [seq] - Show clip info (current if no seq, or specific clip by number)
  async cclip(channel, tags, args) {
    const login = getClipChannel(channel);
    const seq = parseInt(args[0]);

    try {
      // If seq provided, look up that specific clip
      if (seq && seq > 0) {
        const apiUrl = `${config.apiBaseUrl}/clip_info.php?login=${login}&seq=${seq}`;
        const res = await fetchWithTimeout(apiUrl);
        const data = await res.json();

        if (data.error) {
          return `Clip #${seq} not found.`;
        }

        const title = data.title ? ` - ${data.title}` : '';
        return `Clip #${data.seq}${title}: ${data.url}`;
      }

      // No seq - show currently playing clip
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
      console.error('!cclip error:', err.message);
      return 'Could not fetch clip info.';
    }
  },

  // !cplay <seq> - Force play a specific clip (mod only)
  async cplay(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const seq = parseInt(args[0]);
    if (!seq || seq <= 0) {
      return 'Usage: !cplay <clip#>';
    }

    const login = getClipChannel(channel);
    try {
      const url = `${config.apiBaseUrl}/pclip.php?login=${login}&key=${config.adminKey}&seq=${seq}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!cplay error:', err.message);
      return 'Could not play clip.';
    }
  },

  // !like [seq] - Upvote a clip (current clip if no seq provided)
  async like(channel, tags, args) {
    const login = getClipChannel(channel);
    const votingEnabled = await isVotingEnabled(login);
    if (!votingEnabled) return null; // Silently ignore when disabled

    // Check if vote feedback is enabled - if not, stay completely silent
    const feedbackEnabled = await isVoteFeedbackEnabled(login);

    let seq = parseInt(args[0]);

    // If no seq provided, get current playing clip
    if (!seq || seq <= 0) {
      try {
        const npRes = await fetchWithTimeout(`${config.apiBaseUrl}/now_playing_get.php?login=${login}`);
        const npData = await npRes.json();
        if (npData && npData.seq) {
          seq = npData.seq;
        } else {
          return feedbackEnabled ? 'No clip currently playing. Use !like <clip#>' : null;
        }
      } catch (err) {
        return feedbackEnabled ? 'Could not get current clip.' : null;
      }
    }

    try {
      const user = tags.username || 'anonymous';
      const url = `${config.apiBaseUrl}/vote_submit.php?login=${login}&user=${user}&seq=${seq}&vote=like`;
      console.log(`!like: Calling ${url}`);
      const res = await fetchWithTimeout(url);
      const text = await res.text();
      console.log(`!like: Response status=${res.status}, text="${text}"`);
      // API already respects vote_feedback setting, but text might be empty
      return text || null;
    } catch (err) {
      console.error('!like error:', err.message);
      return feedbackEnabled ? 'Could not record vote.' : null;
    }
  },

  // !dislike [seq] - Downvote a clip (current clip if no seq provided)
  async dislike(channel, tags, args) {
    const login = getClipChannel(channel);
    const votingEnabled = await isVotingEnabled(login);
    if (!votingEnabled) return null; // Silently ignore when disabled

    // Check if vote feedback is enabled - if not, stay completely silent
    const feedbackEnabled = await isVoteFeedbackEnabled(login);

    let seq = parseInt(args[0]);

    // If no seq provided, get current playing clip
    if (!seq || seq <= 0) {
      try {
        const npRes = await fetchWithTimeout(`${config.apiBaseUrl}/now_playing_get.php?login=${login}`);
        const npData = await npRes.json();
        if (npData && npData.seq) {
          seq = npData.seq;
        } else {
          return feedbackEnabled ? 'No clip currently playing. Use !dislike <clip#>' : null;
        }
      } catch (err) {
        return feedbackEnabled ? 'Could not get current clip.' : null;
      }
    }

    try {
      const user = tags.username || 'anonymous';
      const url = `${config.apiBaseUrl}/vote_submit.php?login=${login}&user=${user}&seq=${seq}&vote=dislike`;
      console.log(`!dislike: Calling ${url}`);
      const res = await fetchWithTimeout(url);
      const text = await res.text();
      console.log(`!dislike: Response status=${res.status}, text="${text}"`);
      // API already respects vote_feedback setting, but text might be empty
      return text || null;
    } catch (err) {
      console.error('!dislike error:', err.message);
      return feedbackEnabled ? 'Could not record vote.' : null;
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

  // !cfind <query> - Search clips by title/clipper/game
  async cfind(channel, tags, args) {
    const login = getClipChannel(channel);
    const query = args.join(' ').trim();

    try {
      const url = `${config.apiBaseUrl}/cfind.php?login=${encodeURIComponent(login)}&key=${encodeURIComponent(config.adminKey)}&q=${encodeURIComponent(query)}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!cfind error:', err.message);
      return `Browse clips: ${config.apiBaseUrl}/clip_search.php?login=${login}`;
    }
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

  // !cprev - Go back to the previous clip (mod only)
  async cprev(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const login = getClipChannel(channel);
    try {
      const url = `${config.apiBaseUrl}/cprev.php?login=${login}&key=${config.adminKey}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!cprev error:', err.message);
      return 'Could not go to previous clip.';
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

  // !ctop [count] - Show top voted clips overlay (mod only)
  async ctop(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const login = getClipChannel(channel);
    const count = parseInt(args[0]) || 5;

    try {
      const url = `${config.apiBaseUrl}/ctop.php?login=${encodeURIComponent(login)}&key=${encodeURIComponent(config.adminKey)}&count=${count}`;
      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!ctop error:', err.message);
      return 'Could not show top clips.';
    }
  },

  // !cvote [seq|clear] - Clear user's own votes
  // !cvote - clear vote on currently playing clip
  // !cvote 3 - clear vote on clip #3
  // !cvote clear - clear ALL votes the user has ever made
  async cvote(channel, tags, args) {
    const login = getClipChannel(channel);
    const user = tags.username || 'anonymous';
    const arg = (args[0] || '').toLowerCase();

    try {
      let url = `${config.apiBaseUrl}/cvote.php?login=${encodeURIComponent(login)}&user=${encodeURIComponent(user)}`;

      if (arg === 'clear') {
        // Clear all votes
        url += '&clear=all';
      } else {
        const seq = parseInt(arg) || 0;
        if (seq > 0) {
          url += `&seq=${seq}`;
        }
      }

      const res = await fetchWithTimeout(url);
      return await res.text();
    } catch (err) {
      console.error('!cvote error:', err.message);
      return 'Could not clear vote.';
    }
  },

  // !chelp - Show available clip commands
  async chelp(channel, tags, args) {
    if (isMod(tags)) {
      return 'Mod: !cplay <#>, !cskip, !cprev, !ccat <game>, !ctop [#], !chud <pos>, !cremove/!cadd <#> | All: !cclip [#], !cfind [keywords/clipper/category], !like/!dislike [#], !cvote [#|clear]';
    }
    return 'Clip commands: !cclip [#], !cfind [keywords/clipper/category], !like [#], !dislike [#], !cvote [#|clear]';
  },

  // !chud <position> - Move the HUD overlay (mod only)
  // !chud top <position> - Move the top clips overlay (mod only)
  // Positions: tr (top-right), tl (top-left), br (bottom-right), bl (bottom-left)
  async chud(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const login = getClipChannel(channel);

    // Map friendly names to position codes
    const positionMap = {
      'tr': 'tr', 'topright': 'tr', 'top-right': 'tr',
      'tl': 'tl', 'topleft': 'tl', 'top-left': 'tl',
      'br': 'br', 'bottomright': 'br', 'bottom-right': 'br',
      'bl': 'bl', 'bottomleft': 'bl', 'bottom-left': 'bl'
    };
    const posNames = { tr: 'top-right', tl: 'top-left', br: 'bottom-right', bl: 'bottom-left' };

    // Check if moving the top clips overlay
    if (args[0]?.toLowerCase() === 'top') {
      const position = (args[1] || '').toLowerCase();
      const pos = positionMap[position];
      if (!pos) {
        return 'Usage: !chud top <position> - Positions: tl, tr, bl, br';
      }

      try {
        const url = `${config.apiBaseUrl}/hud_position.php?login=${encodeURIComponent(login)}&key=${encodeURIComponent(config.adminKey)}&set=1&type=top&position=${pos}`;
        const res = await fetchWithTimeout(url);
        const data = await res.json();

        if (data.ok) {
          return `Top clips overlay moved to ${posNames[pos]}.`;
        }
        return 'Could not move top clips overlay.';
      } catch (err) {
        console.error('!chud top error:', err.message);
        return 'Could not move top clips overlay.';
      }
    }

    // Move the main HUD
    const position = (args[0] || '').toLowerCase();
    const pos = positionMap[position];
    if (!pos) {
      return 'Usage: !chud <position> or !chud top <position> - Positions: tl, tr, bl, br';
    }

    try {
      const url = `${config.apiBaseUrl}/hud_position.php?login=${encodeURIComponent(login)}&key=${encodeURIComponent(config.adminKey)}&set=1&position=${pos}`;
      const res = await fetchWithTimeout(url);
      const data = await res.json();

      if (data.ok) {
        return `HUD moved to ${posNames[pos]}.`;
      }
      return 'Could not move HUD.';
    } catch (err) {
      console.error('!chud error:', err.message);
      return 'Could not move HUD.';
    }
  },

  // !clikeoff [channel] - Disable voting for a channel (mod only)
  async clikeoff(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    // If channel specified, use that; otherwise use current clip channel
    const target = (args[0] || '').toLowerCase().replace(/^@/, '') || getClipChannel(channel);

    try {
      const url = `${config.apiBaseUrl}/voting_status.php?login=${encodeURIComponent(target)}&key=${encodeURIComponent(config.adminKey)}&set=0`;
      const res = await fetchWithTimeout(url);
      const data = await res.json();

      if (data.ok) {
        // Clear cache so next check gets fresh state
        votingSettingsCache.delete(target);
        return `Clip voting disabled for ${target}.`;
      }
      return 'Could not disable voting.';
    } catch (err) {
      console.error('!clikeoff error:', err.message);
      return 'Could not disable voting.';
    }
  },

  // !clikeon [channel] - Enable voting for a channel (mod only)
  async clikeon(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    // If channel specified, use that; otherwise use current clip channel
    const target = (args[0] || '').toLowerCase().replace(/^@/, '') || getClipChannel(channel);

    try {
      const url = `${config.apiBaseUrl}/voting_status.php?login=${encodeURIComponent(target)}&key=${encodeURIComponent(config.adminKey)}&set=1`;
      const res = await fetchWithTimeout(url);
      const data = await res.json();

      if (data.ok) {
        // Clear cache so next check gets fresh state
        votingSettingsCache.delete(target);
        return `Clip voting enabled for ${target}.`;
      }
      return 'Could not enable voting.';
    } catch (err) {
      console.error('!clikeon error:', err.message);
      return 'Could not enable voting.';
    }
  },

  // !cswitch <channel> - Switch which channel's clips commands affect (mod only)
  // Use !cswitch off or !cswitch reset to return to normal
  // RESTRICTED: Only works in thearsondragon's channel
  async cswitch(channel, tags, args) {
    if (!isMod(tags)) {
      return null; // Silently ignore non-mods
    }

    const chatChannel = channel.replace(/^#/, '').toLowerCase();

    // Only allow !cswitch in thearsondragon's channel
    if (chatChannel !== 'thearsondragon') {
      return null; // Silently ignore in other channels
    }
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

  // Check if command is enabled for this channel
  const clipChannel = getClipChannel(channel);
  const commandEnabled = await isCommandEnabled(clipChannel, cmdName);
  if (!commandEnabled) {
    // Command is disabled for this channel - silently ignore
    return;
  }

  try {
    const response = await handler(channel, tags, args);
    if (response) {
      // Check if silent prefix is enabled - prepends ! to hide from on-screen chat overlays
      const useSilentPrefix = await isSilentPrefixEnabled(clipChannel);
      const finalResponse = useSilentPrefix ? `! ${response}` : response;
      client.say(channel, finalResponse);
    }
  } catch (err) {
    console.error(`Error handling !${cmdName}:`, err);
  }
});

// Connection events
client.on('connected', async (addr, port) => {
  console.log(`Connected to Twitch IRC at ${addr}:${port}`);
  console.log(`Bot username: ${config.botUsername}`);
  console.log(`Multi-channel mode: commands use clips from the channel they're typed in`);
  console.log('Commands: !cclip, !cfind, !like, !dislike, !cplay, !cskip, !cprev, !ccat, !ctop, !cvote, !chud, !cremove, !cadd, !cswitch, !clikeon, !clikeoff, !chelp');

  // Initial channel sync
  console.log('Fetching channel list from database...');
  await syncChannels();

  // Start periodic channel sync
  setInterval(syncChannels, CHANNEL_SYNC_INTERVAL);
  console.log(`Channel sync interval: ${CHANNEL_SYNC_INTERVAL / 1000}s`);
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

client.on('reconnect', () => {
  console.log('Attempting to reconnect to Twitch...');
});

client.on('error', (err) => {
  console.error('TMI client error:', err);
});

client.on('notice', (channel, msgid, message) => {
  console.log(`Notice from ${channel}: [${msgid}] ${message}`);
});

// Graceful shutdown handler
let isShuttingDown = false;

async function shutdown(signal) {
  if (isShuttingDown) return;
  isShuttingDown = true;

  console.log(`${signal} received, shutting down gracefully...`);

  try {
    await client.disconnect();
    console.log('Disconnected from Twitch.');
  } catch (err) {
    console.error('Error during disconnect:', err);
  }

  console.log('Shutdown complete.');
  process.exit(0);
}

// Handle process signals for graceful shutdown
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

// Handle uncaught errors to prevent crashes
process.on('unhandledRejection', (reason, promise) => {
  console.error('Unhandled Rejection at:', promise, 'reason:', reason);
});

process.on('uncaughtException', (err) => {
  console.error('Uncaught Exception:', err);
  // Don't exit on uncaught exception - let the bot continue
});

// Connect
console.log('Starting FloppyJimmie Clip Bot...');
client.connect().catch(err => {
  console.error('Failed to connect:', err);
  process.exit(1);
});
