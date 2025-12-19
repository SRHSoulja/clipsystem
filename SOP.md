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

## Key Concepts

### Seq Numbers
- Each clip has a unique `seq` number within a login
- `seq=1` is the oldest clip, `seq=N` is the newest
- Votes reference clips by seq number
- **NEVER change seq numbers for voted clips** - this breaks vote references

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
