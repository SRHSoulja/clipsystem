# Streamer Dashboard Design

## Overview

A self-service dashboard where streamers can manage their clip reel settings, filter content, create playlists, and view stats - without needing admin access.

## Authentication & Access (3 Tiers)

### 1. Super Admin (you)
- Uses existing `ADMIN_KEY` environment variable
- Full access to all channels via `admin.php`
- Can create/manage streamers, regenerate keys

### 2. Streamer
- Each streamer gets a unique 32-character key stored in database
- Access via: `dashboard.php?key=THEIR_32CHAR_KEY`
- Bookmark-able URL for easy access
- Can only manage their own channel

### 3. Mods
- Streamers can create a "mod password" from their dashboard
- Mods access via: `dashboard.php?login=username` then enter password
- Limited permissions (no key management, no destructive actions)

### Permission Matrix

| Action | Super Admin | Streamer | Mod |
|--------|-------------|----------|-----|
| View all tabs | ✓ | ✓ | ✓ |
| Change HUD positions | ✓ | ✓ | ✓ |
| Toggle voting on/off | ✓ | ✓ | ✓ |
| Block/unblock clips | ✓ | ✓ | ✓ |
| Add blocked words/clippers | ✓ | ✓ | ✗ |
| Refresh clips (fetch new) | ✓ | ✓ | ✗ |
| Manage playlists | ✓ | ✓ | ✗ |
| Create/change mod password | ✓ | ✓ | ✗ |
| Regenerate streamer key | ✓ | ✗ | ✗ |
| Access other channels | ✓ | ✗ | ✗ |

## Filtering System (3 Layers)

### 1. Word-based Filtering
- Block clips whose titles contain certain words
- Stored as JSON array in `blocked_words` column
- Example: `["spoiler", "ending", "leaked"]`
- Case-insensitive matching

### 2. Clipper-based Filtering
- Block all clips from specific clippers
- Stored as JSON array in `blocked_clippers` column
- Example: `["annoying_clipper", "spam_bot"]`
- Blocks all past AND future clips from that user

### 3. Individual Clip Blocking
- Already exists via `blocked` column in `clips` table
- Dashboard provides searchable clip list with block/unblock toggles
- Same as current `!cremove`/`!cadd` but with visual interface

### How Filtering Applies
- `get_next_clip.php` already respects `blocked = true`
- Add to WHERE clause: `AND title NOT ILIKE ANY(blocked_words) AND creator_name NOT IN (blocked_clippers)`
- Filters stack (a clip blocked by ANY method stays blocked)

## Dashboard UI Layout

Single-page dashboard with four tabs:

### Tab 1: Settings
- HUD position selector (visual corner picker)
- Top clips overlay position
- Voting toggle (enable/disable !like/!dislike)
- Mod password management (create/change/remove)
- Player URL display (for OBS copy-paste)
- **Get New Clips button** (triggers refresh_clips.php)
  - Shows last refresh date/time
  - Displays result: "Added 5 new clips!"

### Tab 2: Clip Management
- Searchable clip table (reuses clip_search.php logic)
- Each row shows: #, title, clipper, game, votes, status
- Toggle switches for blocked/unblocked per clip
- Bulk actions: "Block all from this clipper"
- Filter controls at top:
  - Blocked words input (comma-separated, saves on blur)
  - Blocked clippers input (comma-separated)
  - Show: All / Active only / Blocked only

### Tab 3: Playlists
- Create named playlists (e.g., "Best Moments", "Fortnite Highlights")
- Add clips by number or search
- Drag-and-drop reorder
- Set playlist to loop or play once then return to random
- Play/Stop buttons
- Shows current playlist progress when active

### Tab 4: Stats
- Total clips / Active clips / Blocked clips
- Top 10 most liked clips
- Top 10 most disliked clips
- Recent clips added (last 30 days)
- Clips by game category (simple list with counts)

**Styling:** Same dark theme as existing pages (matches admin.php, chelp.php).

## Playlist System

### Features
- Create named playlists of clips to play in sequence
- Alternative to random weighted selection
- Drag-and-drop ordering
- Loop mode option

### Bot Command
- `!cplaylist <name>` - Start a playlist (mod only)
- `!cplaylist off` - Return to random mode

### Player Integration
- New endpoint `playlist_state.php` returns current playlist + position
- Player checks this alongside force_play polling
- When playlist active, plays clips in order instead of random

## State Management & Cleanup

### Playback Modes (Mutually Exclusive)
1. **Random** - Default weighted selection
2. **Playlist** - Playing a specific playlist
3. **Category** - Filtered to one game (!ccat)

### State Cleanup Rules

| Action | Clears |
|--------|--------|
| Browser refresh | Playlist state, category filter, force play |
| `!cskip` during playlist | Advances playlist (doesn't exit) |
| `!cskip` during category | Stays in category, picks next from filter |
| `!ccat <game>` | Exits playlist, enters category mode |
| `!ccat off` | Returns to random |
| `!cplaylist <name>` | Exits category, enters playlist mode |
| `!cplaylist off` | Returns to random |
| `!pclip #` | Plays clip once, then resumes previous mode |

### On Player Init (Browser Refresh)
```javascript
// Clear any stale state on load
fetch(`${API_BASE}/clear_playback_state.php?login=${login}`);
```

## Database Changes

### New Table: streamers
```sql
CREATE TABLE streamers (
  login VARCHAR(50) PRIMARY KEY,
  streamer_key VARCHAR(64) UNIQUE NOT NULL,
  mod_password VARCHAR(64),
  created_at TIMESTAMP DEFAULT NOW()
);
```

### New Table: playlists
```sql
CREATE TABLE playlists (
  id SERIAL PRIMARY KEY,
  login VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  clip_seqs INTEGER[] NOT NULL,  -- ordered array of clip seq numbers
  loop_mode BOOLEAN DEFAULT false,
  created_at TIMESTAMP DEFAULT NOW()
);
```

### Add Columns to channel_settings
```sql
ALTER TABLE channel_settings ADD COLUMN blocked_words JSON DEFAULT '[]';
ALTER TABLE channel_settings ADD COLUMN blocked_clippers JSON DEFAULT '[]';
ALTER TABLE channel_settings ADD COLUMN voting_enabled BOOLEAN DEFAULT true;
ALTER TABLE channel_settings ADD COLUMN last_refresh TIMESTAMP;
ALTER TABLE channel_settings ADD COLUMN active_playlist_id INTEGER;
ALTER TABLE channel_settings ADD COLUMN playlist_position INTEGER;
```

## New Files to Create

1. **dashboard.php** - Main streamer dashboard (single file with tabs)
2. **dashboard_api.php** - JSON API for dashboard actions
3. **playlist_state.php** - Get/set current playlist state
4. **clear_playback_state.php** - Reset all playback state on init

## Files to Modify

1. **get_next_clip.php** - Add word/clipper filtering to WHERE clause
2. **admin.php** - Add "Generate Dashboard Link" button per user
3. **refresh_clips.php** - Accept streamer_key as alternative to ADMIN_KEY
4. **bot.js** - Add `!cplaylist` command
5. **clipplayer_mp4_reel.html** - Add playlist state polling, call clear_playback_state on init

## Access URLs

- **Super admin:** `admin.php` (existing)
- **Streamer:** `dashboard.php?key=THEIR_32CHAR_KEY`
- **Mod:** `dashboard.php?login=username` → enters mod password
