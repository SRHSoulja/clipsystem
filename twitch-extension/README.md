# ClipTV Twitch Panel Extension

## Setup

### 1. Register the extension

1. Go to https://dev.twitch.tv/console/extensions and create a new extension.
2. Set **Extension Type** to **Panel**.
3. Set **Panel Height** to `500`.
4. Under **Capabilities**, enable **Configuration Service** (for broadcaster config segment).
5. Copy your **Extension Client ID** and **Base64 Extension Secret**.

### 2. Configure environment

Add to Railway (or your hosting env):
```
TWITCH_EXT_SECRET=<your base64 extension secret from Twitch dev console>
```

### 3. Local Test

1. Run `php -S localhost:8080 -t twitch-extension/` from the clipsystem root.
   - This serves the `twitch-extension/` folder as root at port 8080, matching the Base URI Twitch has configured (`https://localhost:8080/`).
2. Enable **Local Test** mode in the Twitch developer console.
3. Install the extension on your test channel via **Extension Manager** → **View on Twitch and Install**.

### 4. Hosted Test

1. Zip the contents of `twitch-extension/` (not the folder itself — zip the files directly).
2. Upload the zip in the Twitch dev console under **Files**.
3. Switch to **Hosted Test** mode.

### 5. Backend (EBS)

All API calls go to `https://clips.gmgnrepeat.com/extension_api.php`.

CORS is open (`Access-Control-Allow-Origin: *`). JWT verification uses `TWITCH_EXT_SECRET`.

Make sure `TWITCH_EXT_SECRET` is set in your Railway environment variables.

## Linking a channel

A broadcaster who installs the extension must link their ClipTV account via the config view:

1. Go to **Extension Manager** → ClipTV → **Configure**.
2. Enter their Twitch username (must match a ClipTV account that has clips).
3. Click **Link**.

This writes `twitch_id → login` into `channel_settings` in the DB.

Broadcasters who have already logged into ClipTV via OAuth may be auto-detected via the `known_users` table without needing to link manually.

## Files

| File | Purpose |
|---|---|
| `panel.html` | Panel view shell |
| `panel.js` | Panel logic: tabs, clip cards, search, auth |
| `panel.css` | Styles (light + dark Twitch theme) |
| `config.html` | Broadcaster config view |
| `config.js` | Config logic: settings form, account link |
| `common.js` | Shared helpers: theme, EBS fetch, formatting |
| `README.md` | This file |

## Extension API endpoints

All require `Authorization: Bearer <twitch-jwt>` header.

| Endpoint | Method | Description |
|---|---|---|
| `?action=channel` | GET | Resolve broadcaster identity + registration status |
| `?action=clips` | GET | Fetch clips (`sort=recent\|top\|random`, `limit=5-25`) |
| `?action=search` | GET | Search clips (`q=query`) |
| `?action=settings` | POST | Link ClipTV account (broadcaster role required) |

## Dev notes

- The panel renders correctly offline (when the Twitch channel is offline). This is a feature — viewers can always browse the clip library.
- Light/dark theme is applied automatically via `Twitch.ext.onContext`. Test both in the Twitch developer console context simulator.
- The extension does NOT use any external iframes. Clips open on `clips.twitch.tv` in a new tab.
- `TWITCH_EXT_SECRET` must be the base64-encoded value exactly as shown in the Twitch Extensions developer console. Do not decode it before storing.
