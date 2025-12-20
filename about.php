<?php
/**
 * about.php - About the Clip System
 */
header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>About - Clip Reel System</title>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: #0e0e10;
      color: #efeff1;
      margin: 0;
      padding: 20px;
      line-height: 1.6;
    }
    .container {
      max-width: 800px;
      margin: 0 auto;
    }
    h1 {
      color: #9147ff;
      border-bottom: 2px solid #9147ff;
      padding-bottom: 10px;
    }
    h2 {
      color: #bf94ff;
      margin-top: 30px;
    }
    p {
      color: #adadb8;
    }
    a {
      color: #9147ff;
      text-decoration: none;
    }
    a:hover {
      color: #bf94ff;
      text-decoration: underline;
    }
    .nav-links {
      margin-bottom: 20px;
      padding-bottom: 15px;
      border-bottom: 1px solid #333;
    }
    .nav-links a {
      margin-right: 20px;
      color: #adadb8;
    }
    .nav-links a:hover {
      color: #9147ff;
    }
    .feature-box {
      background: #18181b;
      border-radius: 8px;
      padding: 20px;
      margin: 15px 0;
      border-left: 3px solid #9147ff;
    }
    .feature-box h3 {
      color: #efeff1;
      margin-top: 0;
    }
    .feature-box p {
      margin-bottom: 0;
    }
    .credits {
      margin-top: 40px;
      padding-top: 20px;
      border-top: 1px solid #333;
      color: #666;
      font-size: 14px;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="nav-links">
      <a href="clip_search.php?login=floppyjimmie">Clip Search</a>
      <a href="chelp.php">Bot Commands</a>
    </div>

    <h1>Clip Reel System</h1>

    <p>An automated clip playback system for Twitch streamers. Perfect for BRB screens, pre-stream entertainment, or showcasing your best moments.</p>

    <h2>Features</h2>

    <div class="feature-box">
      <h3>Automatic Clip Playback</h3>
      <p>Plays through your Twitch clips in a shuffled order. Each clip plays once before reshuffling, ensuring variety without repetition.</p>
    </div>

    <div class="feature-box">
      <h3>Chat Integration</h3>
      <p>Viewers can see what's playing with <code>!clip</code>, search clips with <code>!cfind</code>, and vote on clips with <code>!like</code> and <code>!dislike</code>.</p>
    </div>

    <div class="feature-box">
      <h3>Mod Controls</h3>
      <p>Mods can skip clips, play specific clips by number, filter by game category, and remove/restore clips from the rotation.</p>
    </div>

    <div class="feature-box">
      <h3>Clip Browser</h3>
      <p>A searchable web interface to browse all clips. Filter by title, clipper, or game category. Click any clip to watch on Twitch.</p>
    </div>

    <div class="feature-box">
      <h3>Multi-Channel Support</h3>
      <p>Each channel has its own clip pool. Commands typed in a channel affect that channel's clips. Mods can use <code>!cswitch</code> to control another channel's clips.</p>
    </div>

    <h2>How It Works</h2>

    <p>Add the clip player as a Browser Source in OBS. The player automatically fetches clips from your Twitch channel, converts them to MP4 for smooth playback, and cycles through them continuously.</p>

    <p>The bot joins your Twitch chat to handle commands. Clip data is stored in a database with permanent clip numbers that never change, even if clips are added or removed.</p>

    <h2>Getting Started</h2>

    <p>Want to use this for your channel? The system supports multiple streamers. Reach out to get set up with your own clip reel.</p>

    <div class="credits">
      Built for the Twitch community. Powered by the Twitch API.
    </div>
  </div>
</body>
</html>
