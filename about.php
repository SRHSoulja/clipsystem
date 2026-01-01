<?php
/**
 * about.php - About the Clip System
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

header("Content-Type: text/html; charset=utf-8");

// Get pdo and current user for nav
$pdo = get_db_connection();
$currentUser = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
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
  <?php require_once __DIR__ . '/includes/nav.php'; ?>

  <div class="container" style="padding-top: 20px;">
    <h1>Clip Reel System</h1>

    <p>An automated clip playback system for Twitch streamers. Perfect for BRB screens, pre-stream entertainment, or showcasing your best moments.</p>

    <h2>Features</h2>

    <div class="feature-box">
      <h3>Customizable Clip Weighting</h3>
      <p>Streamers have full control over how clips are selected through the Streamer Dashboard:</p>
      <ul style="color: #adadb8; margin-top: 10px;">
        <li><strong>Recency weight:</strong> Prioritize clips that haven't been played recently</li>
        <li><strong>View count weight:</strong> Boost popular clips with higher view counts</li>
        <li><strong>Play penalty:</strong> Reduce repeat plays by penalizing recently shown clips</li>
        <li><strong>Voting weight:</strong> Let community !like/!dislike votes influence selection</li>
        <li><strong>Duration boosts:</strong> Favor or penalize short, medium, or long clips</li>
        <li><strong>Category boosts:</strong> Boost or penalize specific games/categories</li>
      </ul>
      <p style="margin-top: 10px;">All weights are adjustable from 0 (disabled) to 2 (maximum) in the dashboard.</p>
    </div>

    <div class="feature-box">
      <h3>Full Mod Control</h3>
      <p>Mods have complete control over the clip experience:</p>
      <ul style="color: #adadb8; margin-top: 10px;">
        <li><strong>!cplay #</strong> - Instantly play any specific clip by number</li>
        <li><strong>!cskip</strong> - Skip the current clip immediately</li>
        <li><strong>!cprev</strong> - Go back to the previously played clip</li>
        <li><strong>!ccat game</strong> - Filter to only show clips from a specific game</li>
        <li><strong>!cremove / !cadd</strong> - Remove or restore clips from the rotation</li>
        <li><strong>!ctop</strong> - Display the top-voted clips on screen</li>
        <li><strong>!chud</strong> - Move the HUD overlay to any corner</li>
      </ul>
    </div>

    <div class="feature-box">
      <h3>Chat Voting System</h3>
      <p>Viewers can interact with clips through voting:</p>
      <ul style="color: #adadb8; margin-top: 10px;">
        <li><strong>!like / !dislike</strong> - Vote on the current clip or any clip by number</li>
        <li><strong>!cvote</strong> - Clear your own vote on a clip, or <code>!cvote clear</code> to remove all your votes</li>
        <li><strong>!cclip</strong> - See info about the currently playing clip</li>
        <li><strong>!cfind</strong> - Search clips by title, clipper, or game</li>
      </ul>
      <p style="margin-top: 10px;">Voting can be enabled/disabled per-channel by streamers.</p>
    </div>

    <div class="feature-box">
      <h3>Clip Browser</h3>
      <p>A searchable web interface to browse all clips. Filter by title, clipper, game category, or clip number. See vote counts and click any clip to watch on Twitch.</p>
    </div>

    <div class="feature-box">
      <h3>Streamer Dashboard</h3>
      <p>A full-featured dashboard for streamers to manage their clip reel:</p>
      <ul style="color: #adadb8; margin-top: 10px;">
        <li><strong>Weighting controls:</strong> Customize how clips are selected</li>
        <li><strong>HUD positioning:</strong> Move overlays to any corner</li>
        <li><strong>Voting toggle:</strong> Enable or disable chat voting</li>
        <li><strong>Bot settings:</strong> Configure command responses</li>
        <li><strong>Mod management:</strong> Add mods with customizable permissions</li>
        <li><strong>Stats:</strong> View top voted clips and voting activity</li>
      </ul>
    </div>

    <div class="feature-box">
      <h3>Mod Dashboard & Playlists</h3>
      <p>Mods can access a dedicated dashboard to manage playlists:</p>
      <ul style="color: #adadb8; margin-top: 10px;">
        <li><strong>Create playlists:</strong> Curate themed clip collections</li>
        <li><strong>Browse & search:</strong> Find clips by title, clipper, or game</li>
        <li><strong>Queue playlists:</strong> Play curated playlists on demand</li>
        <li><strong>Block clips:</strong> Hide individual clips from rotation</li>
      </ul>
      <p style="margin-top: 10px;">Streamers can customize what each mod can access through permission settings.</p>
    </div>

    <div class="feature-box">
      <h3>Multi-Channel Support</h3>
      <p>Each channel has its own independent clip pool and settings:</p>
      <ul style="color: #adadb8; margin-top: 10px;">
        <li>Commands typed in a channel affect only that channel's clips</li>
        <li>Each channel has its own HUD position and weighting settings</li>
        <li>Mods can be added per-channel with customizable permissions</li>
        <li>Separate vote tracking per channel</li>
      </ul>
    </div>

    <div class="feature-box">
      <h3>Moveable HUD Overlays</h3>
      <p>Both the clip info HUD and top clips overlay can be positioned in any corner of the screen using <code>!chud</code> and <code>!chud top</code> commands. Settings persist per-channel.</p>
    </div>

    <h2>How It Works</h2>

    <p>Add the clip player as a Browser Source in OBS. The player automatically fetches clips from your Twitch channel, converts them to MP4 for smooth playback, and cycles through them using the weighted selection algorithm.</p>

    <p>The bot joins your Twitch chat to handle commands. Clip data is stored in a database with permanent clip numbers (seq) that never change, even if clips are added or removed. This means clip #42 will always be the same clip.</p>

    <p>New clips can be fetched at any time through the admin panel without affecting existing clip numbers or disrupting playback.</p>

    <h2>Getting Started</h2>

    <p>Want to use this for your channel? The system supports multiple streamers. Reach out to get set up with your own clip reel.</p>

    <div class="credits">
      Built for the Twitch community. Powered by the Twitch API.
    </div>
  </div>
</body>
</html>
