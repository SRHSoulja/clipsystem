# ClipTV Twitch Panel Extension — v1 Design

**Date:** 2026-03-11
**Status:** Approved, ready for implementation

---

## Overview

Build ClipTV as a publicly installable Twitch Panel Extension. When a streamer installs it, the panel in their About section shows that streamer's own clip library — browsable by viewers without leaving Twitch. The extension is a lightweight native front end that calls the existing ClipTV backend for data. No iframes of external sites; clips open on Twitch in a new tab.

---

## Architecture

Twitch extensions have two distinct pieces:

1. **Front-end assets** — static HTML/JS/CSS served from a URL you control during development, then hosted on Twitch's CDN for hosted test and production. Lives in `twitch-extension/`.
2. **EBS (Extension Back-end Service)** — our existing Railway-hosted clipsystem app. A new `extension_api.php` endpoint is added here.

These are kept separate. The front end has no DB access; all data comes from the EBS via authenticated API calls.

---

## File Structure

```
clipsystem/
  twitch-extension/           ← uploaded to Twitch (static assets only)
    panel.html
    panel.js
    panel.css
    config.html
    config.js
    common.js                 ← shared helpers (JWT passthrough, theme, clip formatting)
    README.md                 ← dev setup, extension IDs, test instructions

  extension_api.php           ← new EBS endpoint in clipsystem root
```

---

## Panel Dimensions & UX Model

- **Width:** 318px (fixed by Twitch)
- **Height:** broadcaster-configurable in Extension Manager (100–500px); design and test at a chosen default (recommend 500px)
- **No iframe scrolling:** the panel must feel like a compact app within those bounds
- **Pop-out:** expanded mode for browsing more clips; link opens `clips.gmgnrepeat.com/tv/{login}`
- **Always active:** panels are visible when channel is offline — ideal for a clip browser

---

## Auth & Identity Flow

```
panel.html loads
  → Twitch.ext.onAuthorized(auth)
      auth.token    = Twitch-signed JWT (sent as Bearer to our backend)
      auth.channelId = broadcaster's numeric Twitch user ID (extracted from JWT server-side)

  → fetch /extension_api.php?action=channel
      Authorization: Bearer <auth.token>

  → backend verifies JWT with HMAC-SHA256 using base64-decoded TWITCH_EXT_SECRET
  → extracts channel_id from verified JWT payload
  → looks up channel_id in channel_settings.twitch_id (or known_users.twitch_id)
  → returns { registered: true, login: "xqc", settings: {...} }
        or { registered: false }
```

**Key rule:** the front end never passes the channel ID directly as a trusted param. Identity is always derived from the verified JWT on the server.

---

## Settings Split

### Twitch broadcaster configuration segment
Lightweight panel preferences stored via `window.Twitch.ext.configuration.set("broadcaster", ...)`:
- `ext_sort` — default sort: `recent` | `top` | `random`
- `ext_clip_count` — clips shown: 5–25 (default 10)
- `ext_autoplay` — boolean (default false)
- `ext_featured` — boolean (default false)

### ClipTV DB (`channel_settings` table)
Heavier / identity data that Twitch config can't own:
- `twitch_id VARCHAR(64)` — new column, broadcaster's numeric Twitch ID
- Existing: `login`, `display_name`, `profile_image_url`
- Clip ownership, registration status, account linkage

---

## Backend: `extension_api.php`

**New env var:** `TWITCH_EXT_SECRET` (base64-encoded, from Twitch Extensions developer console)

### JWT Verification (all endpoints)

```
1. Read Authorization: Bearer <token> header
2. base64_decode($TWITCH_EXT_SECRET)
3. Verify HMAC-SHA256 signature
4. Extract payload: channel_id, role, exp
5. Reject if expired or signature invalid
```

### Endpoints

| Action | Method | Role required | Description |
|---|---|---|---|
| `channel` | GET | any | Resolve channel_id → login + settings. Returns `registered` flag. |
| `clips` | GET | any | Fetch clips. Params: `sort=recent\|top\|random`, `limit=5–25` |
| `search` | GET | any | Search clips. Params: `q=query` |
| `settings` | POST | broadcaster | Save broadcaster config to DB. JWT role must be `broadcaster`. |

### `channel` response

```json
{
  "registered": true,
  "login": "xqc",
  "display_name": "xQc",
  "profile_image_url": "...",
  "settings": {
    "ext_sort": "recent",
    "ext_clip_count": 10,
    "ext_autoplay": false,
    "ext_featured": false
  }
}
```

### `clips` response

```json
{
  "clips": [
    {
      "id": "AbcDef123",
      "title": "xQc goes insane",
      "thumbnail_url": "...",
      "view_count": 142000,
      "duration": 83,
      "creator_name": "someuser",
      "game_name": "Overwatch 2",
      "clip_url": "https://clips.twitch.tv/AbcDef123",
      "created_at": "2024-11-15T20:30:00Z"
    }
  ]
}
```

### DB schema change

```sql
ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS twitch_id VARCHAR(64);
CREATE INDEX IF NOT EXISTS idx_channel_settings_twitch_id ON channel_settings(twitch_id);
```

---

## Panel UI Layout

Fixed 318px wide. Compact app feel — no scrolling chrome.

```
┌──────────────────────────────────┐
│ 📼 ClipTV  · xqc's clips         │  ← header (logo + channel name)
├──────────────────────────────────┤
│ [Recent] [Top] [Random] [🔍]     │  ← tab bar
├──────────────────────────────────┤
│ ┌──────────────────────────────┐ │
│ │ 🖼  Title of clip            │ │
│ │     👤 creator  ·  ⏱ 1:23   │ │
│ └──────────────────────────────┘ │
│  ... (up to ext_clip_count cards) │
├──────────────────────────────────┤
│      [ Open full ClipTV → ]      │  ← CTA opens clips.gmgnrepeat.com/tv/login
└──────────────────────────────────┘
```

### Panel States

| State | Behaviour |
|---|---|
| Loading | Spinner centred in panel |
| Not registered | "This streamer hasn't set up ClipTV yet" + link to site |
| No clips | "No clips found" message |
| Search active | Debounced text input replaces tab bar; results replace clip list |
| Error | "Couldn't load clips" with retry link |

### Light/Dark Mode

Respond to `window.Twitch.ext.onContext(ctx => ctx.theme)`. Toggle a `data-theme="light|dark"` attribute on `<body>`. CSS custom properties handle colours. Default: dark (most Twitch viewers are on dark).

---

## Config UI Layout

Broadcaster-only. Shown in Twitch Extensions manager.

```
┌──────────────────────────────────┐
│ ClipTV Panel Settings            │
├──────────────────────────────────┤
│ Default sort                     │
│ ● Recent  ○ Top  ○ Random        │
│                                  │
│ Clips shown:  [10 ▾]  (5–25)    │
│                                  │
│ Autoplay           [ toggle ]    │
│ Featured clips     [ toggle ]    │
│                                  │
│ Account                          │
│ ✅ Linked as xqc                 │
│    (or)                          │
│ ⚠ Not linked → [Connect ClipTV] │
│                                  │
│         [ Save Settings ]        │
└──────────────────────────────────┘
```

Settings are saved to Twitch config segment via `Twitch.ext.configuration.set("broadcaster", ...)`. Account link status is fetched from our backend (JWT-verified `channel` endpoint).

---

## What's Explicitly Out of v1

- In-panel clip playback (CSP/sandbox complexity; open in new tab is the v1 play action)
- Viewer voting or chat inside the extension
- Mobile or video overlay views
- Real-time sync with the main ClipTV player

---

## Twitch Review Readiness

- No external iframes
- All external requests go to our own EBS (clips.gmgnrepeat.com)
- Clips open on twitch.tv in a new tab (first-party, allowed)
- JWT verified server-side before any data is returned
- Light/dark theme supported
- Panel sized for no-scroll UX
- Config view uses Twitch's broadcaster segment

---

## Development Workflow

1. Register extension in [Twitch Developer Console](https://dev.twitch.tv/console/extensions)
2. Set "Testing Base URI" to your ngrok or Railway URL serving `twitch-extension/`
3. Use "Extension Activation" → Local Test to load panel in a real channel
4. Set `TWITCH_EXT_SECRET` in Railway env vars
5. For hosted test: zip `twitch-extension/` assets and upload in developer console

---

## Open Questions (post-v1)

- Should new unlinked streamers who install the extension auto-trigger a ClipTV account creation flow?
- Do we want a "Request a clip" feature from the extension panel in v2?
- Should we support the Twitch mobile panel view in v2?
