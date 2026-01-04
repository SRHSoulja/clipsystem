# ClipArchive - Twitch Clip Management System

A comprehensive clip archive and playback system for Twitch streamers. Features include clip archival from Twitch API, weighted random playback, community voting, moderator controls, and a full-featured dashboard.

## Features

- **Clip Archive**: Store thousands of clips locally with PostgreSQL, bypassing Twitch's 1000-clip API limit
- **MP4 Playback**: Uses Twitch's internal GQL API to stream any clip as MP4, even from deleted VODs
- **Weighted Rotation**: Customizable clip selection based on recency, views, play count, and community votes
- **Community Voting**: Viewers can like/dislike clips via chat commands
- **Moderator Controls**: Skip, play specific clips, filter by category, block clips
- **Streamer Dashboard**: Full control panel with clip management, weighting presets, playlists, and mod permissions
- **Multi-Channel Support**: Bot joins multiple channels, each with independent clip pools
- **OBS Integration**: Browser source player with HUD overlay for votes and clip info

## Architecture

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   OBS Player    │────▶│   PHP Backend    │────▶│   PostgreSQL    │
│  (Browser Src)  │     │   (Railway)      │     │   (Railway)     │
└─────────────────┘     └──────────────────┘     └─────────────────┘
                               │
                               ▼
┌─────────────────┐     ┌──────────────────┐
│  Twitch Chat    │◀────│    Bot Service   │
│   (!commands)   │     │    (Node.js)     │
└─────────────────┘     └──────────────────┘
```

## Quick Start

### 1. Deploy to Railway

1. Fork this repository
2. Create a new Railway project
3. Add PostgreSQL database
4. Deploy from GitHub
5. Set environment variables (see `.env.sample`)

### 2. Archive Clips

```bash
# Initial backfill (fetches all clips, may take hours for large archives)
clips_backfill.php?login=YOUR_CHANNEL&years=5&fresh=1

# Migrate to database
migrate_clips_to_db.php?login=YOUR_CHANNEL&fresh=1

# Fetch thumbnails (enables MP4 URL generation)
populate_thumbnails.php?login=YOUR_CHANNEL
```

### 3. Set Up OBS

Add a Browser Source with:
```
https://YOUR-RAILWAY-URL/clipplayer_mp4_reel.html?login=YOUR_CHANNEL
```

### 4. Access Dashboard

Navigate to `https://YOUR-RAILWAY-URL/dashboard.php` and log in with Twitch OAuth.

## Bot Commands

### All Users
| Command | Description |
|---------|-------------|
| `!cclip [#]` | Show current clip info (or specific clip by number) |
| `!cfind [query]` | Search clips by title, clipper, or game |
| `!like [#]` | Upvote current clip (or specific clip) |
| `!dislike [#]` | Downvote current clip (or specific clip) |
| `!cvote [#\|clear]` | Clear your vote on a clip |
| `!chelp` | Show available commands |

### Moderator Commands
| Command | Description |
|---------|-------------|
| `!cplay <#>` | Force play a specific clip |
| `!cskip` | Skip the current clip |
| `!cprev` | Go back to previous clip |
| `!ccat <game>` | Filter clips to a specific game/category |
| `!ccat off` | Return to all categories |
| `!ctop [#]` | Show top voted clips overlay |
| `!chud <pos>` | Move HUD (tl, tr, bl, br) |
| `!chud top <pos>` | Move top clips overlay |
| `!cremove <#>` | Hide clip from rotation |
| `!cadd <#>` | Restore hidden clip |
| `!clikeon` | Enable voting |
| `!clikeoff` | Disable voting |

## Player URL Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `login` | - | Twitch username (required) |
| `days` | 0 | Only clips from last N days (0 = all) |
| `pool` | 300 | Number of clips in rotation pool |
| `hud` | 1 | Show vote HUD (0 = off) |
| `debug` | 0 | Show debug overlay |
| `muted` | 0 | Force muted playback |
| `instance` | - | Instance ID for multi-player setups |

## Dashboard Features

### Streamer Dashboard (`/dashboard.php`)
- **Clip Browser**: Search, filter, and manage all archived clips
- **Weighting Config**: Customize clip selection algorithm with presets
- **Playlists**: Create and manage clip playlists
- **Blocked Clips**: View and restore hidden clips
- **Blocked Clippers**: Block all clips from specific users
- **Mod Permissions**: Grant moderators specific permissions
- **Channel Settings**: Configure voting, commands, HUD position

### Mod Dashboard (`/mod_dashboard.php`)
- Simplified interface for moderators
- Clip management based on granted permissions
- Real-time control of player

## Authentication

The system uses Twitch OAuth for authentication:

- **Super Admins**: Full access to all channels (hardcoded usernames)
- **Streamers**: Full access to their own channel
- **Moderators**: Access based on permissions granted by streamer

## API Endpoints

### Player APIs
| Endpoint | Purpose |
|----------|---------|
| `twitch_reel_api.php` | Get weighted clip pool |
| `clip_mp4_url.php` | Get signed MP4 URL for clip |
| `poll.php` | Consolidated polling (votes, skip, category, etc.) |
| `now_playing_get.php` | Get currently playing clip |
| `vote_status.php` | Get current vote counts |

### Management APIs
| Endpoint | Purpose |
|----------|---------|
| `dashboard_api.php` | Dashboard operations |
| `playlist_api.php` | Playlist management |
| `clips_api.php` | Clip CRUD operations |
| `bot_api.php` | Bot channel management |

## Database Tables

| Table | Purpose |
|-------|---------|
| `clips` | All clips with metadata, seq numbers, blocked status |
| `votes` | Aggregate vote counts per clip |
| `vote_ledger` | Individual user votes |
| `clip_plays` | Play history for smart rotation |
| `playlists` | Saved playlists |
| `playlist_clips` | Clips in playlists |
| `channel_settings` | Per-channel configuration |
| `channel_mods` | Moderator assignments |
| `mod_permissions` | Granular mod permissions |
| `bot_channels` | Channels the bot should join |
| `games_cache` | Cached game names from Twitch |
| `blocked_clippers` | Blocked clipper usernames |
| `streamers` | Registered streamers |

## How MP4 Streaming Works

The system uses Twitch's internal GraphQL API (`gql.twitch.tv/gql`) to get signed MP4 URLs:

```javascript
// Uses Twitch's web player client ID
const CLIENT_ID = "kimne78kx3ncx6brgo4mv6wki5h1ko";

// VideoAccessToken_Clip operation returns signed playback tokens
// Works for ANY clip, even from deleted VODs
```

This bypasses the limitation where Twitch's public API doesn't provide direct MP4 URLs.

## Environment Variables

See `.env.sample` for all required variables:

```env
# Required
DATABASE_URL=postgresql://...
TWITCH_CLIENT_ID=your_client_id
TWITCH_CLIENT_SECRET=your_client_secret
API_BASE_URL=https://your-app.railway.app
ADMIN_KEY=your_secure_key

# Bot (optional)
TWITCH_BOT_USERNAME=your_bot
TWITCH_OAUTH_TOKEN=oauth:...
```

## Files Reference

| File | Purpose |
|------|---------|
| `clipplayer_mp4_reel.html` | Main OBS player |
| `clipplayer_sync.html` | Multi-instance sync player |
| `dashboard.php` | Streamer dashboard |
| `mod_dashboard.php` | Moderator dashboard |
| `clip_search.php` | Public clip browser |
| `clips_backfill.php` | Fetch clips from Twitch |
| `migrate_clips_to_db.php` | Import clips to database |
| `refresh_clips.php` | Incremental clip updates |
| `bot-service/bot.js` | Twitch chat bot |

## License

Private project - not licensed for public use.
