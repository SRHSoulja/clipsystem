# Clip System - Standard Operating Procedures

## Overview

This system manages Twitch clips for the reel player. Clips are:
1. Fetched from Twitch API (backfill)
2. Stored in PostgreSQL database (migration)
3. Served to the player via API
4. Tracked with votes and now-playing status

---

## Initial Setup (Fresh Start)

Use this when setting up for the first time or rebuilding from scratch.

### Step 1: Run Fresh Backfill
```
clips_backfill.php?login=floppyjimmie&years=5&fresh=1
```
- Deletes any existing cache
- Fetches ALL clips from Twitch API for the past 5 years
- Gets `creator_name` (clipper credit) for every clip
- Auto-continues through all time windows (~61 windows for 5 years)
- Takes ~2-3 hours to complete

### Step 2: Run Fresh Migration
```
migrate_clips_to_db.php?login=floppyjimmie&key=YOUR_ADMIN_KEY&fresh=1
```
- Deletes existing clips from database (for this login only)
- Imports all clips from JSON cache
- Assigns seq numbers (1 = oldest, N = newest)
- Auto-continues through all clips
- Takes ~10-15 minutes for 14k clips

### Step 3: Export Seq Mapping (IMPORTANT!)
```
seq_export.php?login=floppyjimmie&key=YOUR_ADMIN_KEY
```
- Downloads `seq_map_floppyjimmie_YYYYMMDD.json`
- **Save this file!** It's your backup of clip_id → seq mappings
- If you ever need to rebuild, this ensures seq numbers stay the same

---

## Adding New Clips (After Launch)

Use this to add new clips that were created after the initial backfill.

### Step 1: Run Incremental Backfill
```
clips_backfill.php?login=floppyjimmie&years=1
```
- Loads existing clips from cache (preserves seq numbers)
- Only fetches NEW clips from Twitch
- New clips get seq numbers starting from max+1
- Existing votes/blocks are NOT affected

### Step 2: Run Migration (No Fresh)
```
migrate_clips_to_db.php?login=floppyjimmie&key=YOUR_ADMIN_KEY
```
- Adds new clips to database
- Skips existing clips (ON CONFLICT DO NOTHING)
- Existing votes/blocks are preserved

---

## Updating Clip Metadata

Use this to update fields like `creator_name` without re-importing.

### Update Existing Clips
```
migrate_clips_to_db.php?login=floppyjimmie&key=YOUR_ADMIN_KEY&update=1
```
- Updates `creator_name` from JSON for existing clips
- Uses COALESCE to preserve existing values if JSON is empty

---

## Rebuilding with Preserved Seq Numbers

If you ever need to do a fresh rebuild but want to keep the same seq numbers:

### Step 1: Fresh Backfill
```
clips_backfill.php?login=floppyjimmie&years=5&fresh=1
```

### Step 2: Fresh Migration
```
migrate_clips_to_db.php?login=floppyjimmie&key=YOUR_ADMIN_KEY&fresh=1
```

### Step 3: Restore Seq Numbers
1. Upload your saved `seq_map_*.json` to Railway at `/tmp/clipsystem_cache/seq_map.json`
2. Run:
```
seq_import.php?login=floppyjimmie&key=YOUR_ADMIN_KEY
```
- Restores original seq numbers from your backup
- New clips (not in backup) keep their new seq numbers

---

## Key Concepts

### Seq Numbers
- Each clip has a unique `seq` number within a login
- `seq=1` is the oldest clip, `seq=N` is the newest
- Votes are stored by `clip_id` (permanent), NOT seq number
- Seq is just a human-friendly lookup key
- **Even if seq changes, votes stay attached to the correct clip**

### Fresh Mode
- `fresh=1` on backfill: Ignores cache, re-fetches everything from Twitch
- `fresh=1` on migration: Deletes existing DB clips before importing
- **Only use fresh mode before launch or to rebuild from scratch**

### Cache Locations
- Railway writes to: `/tmp/clipsystem_cache/`
- Static cache (`./cache/`) is NO LONGER deployed with the app
- In fresh mode, ONLY `/tmp` cache is used (never static cache)
- Migration checks `/tmp` first (fresh backfill data), then static cache

---

## Troubleshooting

### "Permission denied" on Railway
- Railway's `/app/` directory is read-only
- Backfill now writes to `/tmp/clipsystem_cache/`
- This is expected behavior

### 504 Gateway Timeout
- Scripts auto-chunk to avoid Railway's ~30s timeout
- They auto-continue with HTML meta refresh
- Just wait for completion

### Clips missing creator_name
- Old cache doesn't have creator_name field
- Run fresh backfill to re-fetch all clips from Twitch API

### New clips not appearing
- Check that backfill found them (should show "Added this window: N")
- Check migration completed successfully
- Verify seq numbers were assigned (seq > 0)

---

## Files Reference

| File | Purpose |
|------|---------|
| `clips_backfill.php` | Fetch clips from Twitch API |
| `migrate_clips_to_db.php` | Import clips from JSON to PostgreSQL |
| `seq_export.php` | Export clip_id → seq mapping (backup) |
| `seq_import.php` | Restore seq numbers from backup |
| `twitch_reel_api.php` | API for player to get clip list |
| `now_playing_set.php` | Update currently playing clip |
| `now_playing_get.php` | Get current clip (for !pb command) |
| `vote_submit.php` | Record votes |
| `vote_status.php` | Get vote counts |
| `pclip.php` | Play specific clip by seq number |

---

## Workflow Summary

```
[Fresh Start]
  backfill?fresh=1 → migrate?fresh=1 → Ready!

[Add New Clips]
  backfill (no fresh) → migrate (no fresh) → New clips added!

[Update Metadata]
  backfill?fresh=1 → migrate?update=1 → Metadata updated!
```

---

## Bot Commands Reference

### All Users
| Command | Description |
|---------|-------------|
| `!clip` | Show currently playing clip with link |
| `!clips` | Alias for !clip |
| `!cfind` | Get link to clip search page |
| `!like [#]` | Upvote current clip or specific clip# |
| `!dislike [#]` | Downvote current clip or specific clip# |
| `!chelp` | Show available commands |

### Mod-Only Commands
| Command | Description |
|---------|-------------|
| `!pclip <#>` | Force play a specific clip by seq number |
| `!cskip` | Skip the current clip |
| `!ccat <game>` | Filter clips to specific game/category |
| `!ccat off` | Return to all games (exit category filter) |
| `!cremove <#>` | Remove clip from rotation |
| `!cadd <#>` | Restore a removed clip |

### Category Filter (!ccat)
- Works with fuzzy matching: `!ccat elden` matches "Elden Ring"
- Exit keywords: `off`, `clear`, `all`, `exit`, `reset`, `none`
- Shows available games if no match found

---

## Player Features

### Reel Player (floppyjimmie_mp4_reel.html)

**URL Parameters:**
| Parameter | Default | Description |
|-----------|---------|-------------|
| `login` | floppyjimmie | Twitch username for clips |
| `days` | 180 | Only clips from last N days |
| `pool` | 400 | Number of clips in rotation |
| `rotate` | 0 | Enable smart rotation (1=on) |
| `hud` | 1 | Show vote HUD (0=off) |
| `debug` | 0 | Show debug overlay (1=on) |
| `muted` | 0 | Force muted playback (1=on) |

**Buffering Behavior:**
- First clip (scene init): Buffer 8s or full clip
- Command-triggered (!pclip, !cskip): 5s buffer
- Normal progression: 2s buffer

### Smart Rotation (rotate=1)
When enabled, the pool of 400 clips rotates through all 14,000+ clips:
- 70% fresh/never-played clips
- 20% least-recently-played clips
- 10% top-voted favorites

Requires `clip_played.php` tracking to be active.

---

## API Endpoints Reference

### Player APIs
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `twitch_reel_api.php` | GET | Get clip pool for player |
| `now_playing.php` | POST | Report currently playing clip |
| `now_playing_get.php` | GET | Get current clip (for !clip command) |
| `clip_played.php` | GET | Report clip was played (for rotation) |
| `force_play_get.php` | GET | Check for force play command |
| `force_play_clear.php` | GET | Clear force play after playing |
| `skip_check.php` | GET | Check for skip command |
| `category_get.php` | GET | Get active category filter |

### Command APIs (require admin key)
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `pclip.php` | GET | Set force play by seq |
| `cskip.php` | GET | Trigger skip |
| `ccat.php` | GET | Set/clear category filter |
| `cremove.php` | GET | Remove clip from pool |
| `cadd.php` | GET | Restore removed clip |

### Voting APIs
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `vote_submit.php` | GET | Submit vote |
| `vote_status.php` | GET | Get current vote counts |
| `votes_export.php` | GET | Export all vote data |

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `clips` | All clips with metadata, seq numbers, blocked status |
| `votes` | Aggregate vote counts per clip |
| `vote_ledger` | Individual votes (prevents duplicates) |
| `blocklist` | Removed clips |
| `clip_plays` | Play history for smart rotation |
| `playlist_active` | Currently playing playlist |
| `playlists` | Saved playlists |
| `playlist_clips` | Clips in playlists |
| `games_cache` | Cached game names from Twitch |

---

## Environment Variables (Railway)

| Variable | Required | Purpose |
|----------|----------|---------|
| `DATABASE_URL` | Yes | PostgreSQL connection string |
| `ADMIN_KEY` | Yes | Secret key for mod commands |
| `TWITCH_BOT_USERNAME` | For bot | Bot's Twitch username |
| `TWITCH_OAUTH_TOKEN` | For bot | Bot OAuth token |
| `TWITCH_CHANNEL` | For bot | Channel to join |
| `CLIP_CHANNEL` | For bot | Channel whose clips to play |
| `TWITCH_CLIENT_ID` | For games | Twitch API client ID |
| `TWITCH_CLIENT_SECRET` | For games | Twitch API secret |
