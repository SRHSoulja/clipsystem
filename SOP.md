# ClipArchive - Standard Operating Procedures

## Overview

ClipArchive manages Twitch clips for reel playback. The system:
1. Fetches clips from Twitch API (backfill)
2. Stores them in PostgreSQL database
3. Serves clips to the OBS player via API
4. Tracks votes, play history, and now-playing status
5. Provides chat bot commands for viewers and moderators

---

## Initial Setup (New Channel)

### Step 1: Run Clip Backfill

Navigate to (requires super admin OAuth login):
```
clips_backfill.php?login=CHANNEL_NAME&years=5&fresh=1
```

- Fetches ALL clips from Twitch API for the past 5 years
- Uses 30-day windows to bypass Twitch's 1000-clip limit
- Gets `creator_name` (clipper credit) for every clip
- Auto-continues through all time windows
- Takes 1-3 hours depending on clip count

### Step 2: Migrate to Database

Navigate to (requires super admin OAuth login):
```
migrate_clips_to_db.php?login=CHANNEL_NAME&fresh=1
```

- Imports all clips from JSON cache to PostgreSQL
- Assigns seq numbers (1 = oldest, N = newest)
- Auto-continues through all clips
- Takes ~10-15 minutes for 14k clips

### Step 3: Populate Thumbnails

Navigate to:
```
populate_thumbnails.php?login=CHANNEL_NAME
```

- Fetches thumbnail URLs from Twitch API
- Enables MP4 URL generation from thumbnails
- Marks deleted/unavailable clips as `NOT_FOUND`

### Step 4: Export Seq Mapping (Backup)

Navigate to:
```
seq_export.php?login=CHANNEL_NAME
```

- Downloads `seq_map_CHANNEL_YYYYMMDD.json`
- **Save this file!** It's your backup of clip_id to seq mappings
- If you ever rebuild, this ensures seq numbers stay the same

---

## Adding New Clips (Maintenance)

### Automatic: Dashboard Refresh

From the Streamer Dashboard, click "Refresh Clips" button.

### Manual: Incremental Backfill

```
clips_backfill.php?login=CHANNEL_NAME&years=1
```

- Loads existing clips from cache (preserves seq numbers)
- Only fetches NEW clips from Twitch
- New clips get seq numbers starting from max+1

Then run migration (without `fresh=1`):
```
migrate_clips_to_db.php?login=CHANNEL_NAME
```

- Adds new clips to database
- Skips existing clips (ON CONFLICT DO NOTHING)
- Existing votes/blocks are preserved

---

## Dashboard Access

### Streamer Dashboard
```
/dashboard.php
```

Login with Twitch OAuth. Access granted to:
- Super admins (thearsondragon, cliparchive)
- The channel owner (streamer)

Features:
- **Overview**: Quick stats and player URL
- **Clips Tab**: Browse, search, block/unblock clips
- **Weighting Tab**: Configure clip selection algorithm
- **Playlists Tab**: Create and manage playlists
- **Blocked Tab**: View blocked clips and clippers
- **Mods Tab**: Grant permissions to moderators
- **Settings Tab**: Channel-specific settings

### Mod Dashboard
```
/mod_dashboard.php
```

Login with Twitch OAuth. Access granted to:
- Super admins
- Moderators with permissions for the channel

Features depend on granted permissions.

### Public Clip Browser
```
/search/CHANNEL_NAME
```

No login required. Anyone can browse clips and vote (if voting enabled).

---

## Bot Commands Reference

### All Users
| Command | Description |
|---------|-------------|
| `!cclip [#]` | Show currently playing clip (or specific clip by number) |
| `!cfind [query]` | Search clips and get browse link |
| `!like [#]` | Upvote current clip (or specific clip) |
| `!dislike [#]` | Downvote current clip (or specific clip) |
| `!cvote [#\|clear]` | Clear your vote (current clip, specific, or all) |
| `!chelp` | Show available commands |

### Moderator Commands
| Command | Description |
|---------|-------------|
| `!cplay <#>` | Force play a specific clip by seq number |
| `!cskip` | Skip the current clip |
| `!cprev` | Go back to the previous clip |
| `!ccat <game>` | Filter clips to specific game/category |
| `!ccat off` | Return to all games (exit category filter) |
| `!ctop [#]` | Show top voted clips overlay (default 5) |
| `!chud <pos>` | Move vote HUD (tl, tr, bl, br) |
| `!chud top <pos>` | Move top clips overlay position |
| `!cremove <#>` | Hide clip from rotation |
| `!cadd <#>` | Restore a hidden clip |
| `!clikeon` | Enable voting for channel |
| `!clikeoff` | Disable voting for channel |
| `!cswitch <channel>` | Control another channel's clips (super admin only) |

---

## Player Setup (OBS)

### Browser Source URL
```
https://clips.gmgnrepeat.com/clipplayer_mp4_reel.html?login=CHANNEL_NAME
```

### URL Parameters
| Parameter | Default | Description |
|-----------|---------|-------------|
| `login` | - | Channel name (required) |
| `days` | 0 | Only clips from last N days (0 = all time) |
| `pool` | 300 | Number of clips in rotation pool |
| `hud` | 1 | Show vote HUD (0 = hide) |
| `debug` | 0 | Show debug overlay (1 = show) |
| `muted` | 0 | Force muted playback (1 = mute) |
| `instance` | - | Instance ID for multi-player setups |

### Recommended OBS Settings
- Width: 1920
- Height: 1080
- Custom CSS: (leave empty)
- Shutdown source when not visible: OFF
- Refresh browser when scene becomes active: OFF

---

## Weighting Configuration

The player selects clips using weighted random selection. Configure via Dashboard > Weighting tab.

### Weight Factors
| Factor | Description |
|--------|-------------|
| `recency` | Bonus for clips not played recently |
| `views` | Bonus based on view count (log scale) |
| `play_penalty` | Penalty for frequently played clips |
| `voting` | Bonus/penalty based on net vote score |

### Quick Presets
- **Balanced**: Equal weights (default)
- **Popular Clips**: Favor high view counts
- **Fresh Content**: Favor recently created, rarely played
- **Community Picks**: Favor highly voted clips
- **Pure Random**: No weighting at all

### Golden Clips
Specific clips that should appear more frequently. Add by seq number with boost value.

### Category/Clipper Boosts
Boost or reduce clips from specific games or clippers.

---

## API Endpoints

### Player APIs
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `twitch_reel_api.php` | GET | Get weighted clip pool |
| `clip_mp4_url.php` | GET | Get signed MP4 URL for clip |
| `poll.php` | GET | Consolidated polling (votes, skip, commands) |
| `now_playing_get.php` | GET | Get currently playing clip |
| `now_playing_set.php` | POST | Report currently playing clip |
| `vote_status.php` | GET | Get current vote counts |
| `hud_position.php` | GET | Get/set HUD position |

### Bot Command APIs
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `pclip.php` | GET | Set force play by seq |
| `cskip.php` | GET | Trigger skip |
| `cprev.php` | GET | Go to previous clip |
| `ccat.php` | GET | Set/clear category filter |
| `ctop.php` | GET | Trigger top clips overlay |
| `cremove.php` | GET | Block clip |
| `cadd.php` | GET | Unblock clip |

### Management APIs
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `dashboard_api.php` | GET/POST | Dashboard operations |
| `playlist_api.php` | GET/POST | Playlist CRUD |
| `clips_api.php` | GET/POST | Clip management |
| `bot_api.php` | GET/POST | Bot channel management |

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `clips` | All clips with metadata, seq, blocked status |
| `votes` | Aggregate vote counts per clip |
| `vote_ledger` | Individual user votes (prevents duplicates) |
| `clip_plays` | Play history for smart rotation |
| `playlists` | Saved playlists |
| `playlist_clips` | Clips in playlists (with position) |
| `playlist_active` | Currently playing playlist per channel |
| `channel_settings` | Per-channel configuration |
| `channel_mods` | Moderator assignments |
| `mod_permissions` | Granular mod permissions |
| `bot_channels` | Channels the bot should join |
| `games_cache` | Cached game names from Twitch |
| `blocked_clippers` | Blocked clipper usernames |
| `streamers` | Registered streamers |
| `hud_state` | HUD position per channel |
| `now_playing` | Current clip per channel |
| `force_play` | Pending force play commands |
| `skip_flag` | Pending skip commands |
| `category_filter` | Active category filter per channel |

---

## How MP4 Streaming Works

The system uses Twitch's internal GraphQL API to get signed MP4 URLs:

```javascript
// Twitch's web player client ID (public)
const CLIENT_ID = "kimne78kx3ncx6brgo4mv6wki5h1ko";

// VideoAccessToken_Clip operation hash
const QUERY_HASH = "36b89d2507fce29e5ca551df756d27c1cfe079e2609642b4390aa4c35796eb11";

// POST to https://gql.twitch.tv/gql
{
  operationName: "VideoAccessToken_Clip",
  variables: { slug: "ClipSlugHere" },
  extensions: { persistedQuery: { version: 1, sha256Hash: QUERY_HASH } }
}
```

This returns:
- `playbackAccessToken.value` - Signed token
- `playbackAccessToken.signature` - Signature
- `videoQualities` - Array of quality options with source URLs

Final MP4 URL: `{sourceURL}?sig={signature}&token={token}`

**Key benefit**: Works for ANY clip, even from deleted VODs, because Twitch generates fresh tokens on-demand.

---

## Troubleshooting

### "Permission denied" on Railway
- Railway's `/app/` directory is read-only
- Backfill writes to `/tmp/clipsystem_cache/`
- This is expected behavior

### 504 Gateway Timeout
- Scripts auto-chunk to avoid Railway's ~30s timeout
- They auto-continue with HTML meta refresh
- Just wait for completion

### Clips not playing
- Check debug overlay (`?debug=1`)
- Verify clip exists in database
- Check if clip is blocked
- GQL may fail for very old/deleted clips

### Votes not showing
- Verify voting is enabled in channel settings
- Check vote_status.php response
- Browser may be caching - try hard refresh

### Bot not responding
- Check bot is in channel (`bot_api.php?action=list`)
- Verify command is enabled in channel settings
- Check Railway logs for bot errors

### New clips not appearing
- Run refresh_clips.php or dashboard refresh
- Verify migration completed
- Check if clips are being filtered (date range, category)

---

## Workflow Summary

```
[New Channel Setup]
  backfill?fresh=1 → migrate?fresh=1 → populate_thumbnails → Ready!

[Add New Clips]
  backfill (no fresh) → migrate (no fresh) → New clips added!

[Rebuild with Same Seq Numbers]
  backfill?fresh=1 → migrate?fresh=1 → seq_import (from backup) → Done!

[Daily Maintenance]
  Dashboard "Refresh Clips" button → Auto-fetches new clips
```

---

## Security Notes

- All management APIs require OAuth authentication
- Bot commands require ADMIN_KEY for protected operations
- Super admin list is hardcoded (thearsondragon, cliparchive)
- Streamers can only access their own channel data
- Moderators have granular permissions set by streamer
- Vote deduplication prevents spam voting
