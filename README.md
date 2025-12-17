# Clip System for Twitch

PHP backend for the FloppyJimmie clip reel system.

## Railway Deployment

1. Push this repo to GitHub
2. Go to [Railway](https://railway.app)
3. Click "New Project" > "Deploy from GitHub repo"
4. Select this repository
5. Railway will auto-detect the Dockerfile and deploy
6. Once deployed, get your URL (e.g., `https://clipsystem-production.up.railway.app`)

## After Deployment

Update your player HTML to use the Railway API URL:

```javascript
// In floppyjimmie_reel.html, change:
const res = await fetch("./now_playing_set.php?" + params.toString(), ...

// To:
const res = await fetch("https://YOUR-RAILWAY-URL.up.railway.app/now_playing_set.php?" + params.toString(), ...
```

## Files

- `now_playing_set.php` - Updates currently playing clip
- `now_playing_get.php` - Gets current clip for !pb command
- `vote_submit.php` - Records votes
- `vote_status.php` - Gets vote counts for HUD
- `force_play_get.php` / `force_play_clear.php` - Force play commands
- `twitch_reel_api.php` - Main clip list API
- `pclip.php` - Play specific clip command

## Environment

- PHP 8.2 with Apache
- No external database needed (uses local JSON files in `/cache`)
