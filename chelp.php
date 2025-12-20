<?php
/**
 * chelp.php - Bot Commands Help Page
 */
header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Clip Bot Commands</title>
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
    .nav-links {
      margin-bottom: 20px;
      padding-bottom: 15px;
      border-bottom: 1px solid #333;
    }
    .nav-links a {
      margin-right: 20px;
      color: #adadb8;
      text-decoration: none;
    }
    .nav-links a:hover {
      color: #9147ff;
    }
    h2 {
      color: #bf94ff;
      margin-top: 30px;
      border-bottom: 1px solid #333;
      padding-bottom: 5px;
    }
    .command {
      background: #18181b;
      border-radius: 8px;
      padding: 15px;
      margin: 10px 0;
      border-left: 3px solid #9147ff;
    }
    .command-name {
      color: #00ff7f;
      font-family: monospace;
      font-size: 1.1em;
      font-weight: bold;
    }
    .command-desc {
      margin-top: 8px;
      color: #adadb8;
    }
    .command-example {
      background: #0e0e10;
      padding: 8px 12px;
      border-radius: 4px;
      margin-top: 8px;
      font-family: monospace;
      color: #dedee3;
    }
    .mod-only {
      display: inline-block;
      background: #772ce8;
      color: white;
      font-size: 0.75em;
      padding: 2px 8px;
      border-radius: 4px;
      margin-left: 10px;
    }
    .note {
      background: #1f1f23;
      border-radius: 8px;
      padding: 15px;
      margin: 20px 0;
      border-left: 3px solid #ffb31a;
    }
    .note-title {
      color: #ffb31a;
      font-weight: bold;
      margin-bottom: 5px;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="nav-links">
      <a href="clip_search.php?login=floppyjimmie">Clip Search</a>
      <a href="about.php">About</a>
    </div>

    <h1>Clip Bot Commands</h1>

    <div class="note">
      <div class="note-title">Multi-Channel Mode</div>
      Commands in each channel control that channel's clips. Type !clip 5 in #joshbelmar to see Josh's clip #5.
    </div>

    <h2>Everyone</h2>

    <div class="command">
      <span class="command-name">!clip [#]</span>
      <div class="command-desc">Shows the currently playing clip, or look up a specific clip by number.</div>
      <div class="command-example">!clip &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(show current clip)<br>!clip 42 &nbsp;(look up clip #42)</div>
    </div>

    <div class="command">
      <span class="command-name">!cfind &lt;query&gt;</span>
      <div class="command-desc">Search clips by title, clipper, or game category.</div>
      <div class="command-example">!cfind nerf &nbsp;&nbsp;&nbsp;&nbsp;(search for "nerf")<br>!cfind &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(get link to browse page)</div>
    </div>

    <div class="command">
      <span class="command-name">!like [#]</span>
      <div class="command-desc">Upvote the current clip, or a specific clip by number.</div>
      <div class="command-example">!like &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(vote for current clip)<br>!like 42 &nbsp;(vote for clip #42)</div>
    </div>

    <div class="command">
      <span class="command-name">!dislike [#]</span>
      <div class="command-desc">Downvote the current clip, or a specific clip by number.</div>
    </div>

    <div class="command">
      <span class="command-name">!chelp</span>
      <div class="command-desc">Show available commands in chat.</div>
    </div>

    <h2>Mod Commands</h2>

    <div class="command">
      <span class="command-name">!pclip &lt;#&gt;</span>
      <span class="mod-only">MOD</span>
      <div class="command-desc">Force play a specific clip by its number.</div>
      <div class="command-example">!pclip 123</div>
    </div>

    <div class="command">
      <span class="command-name">!cskip</span>
      <span class="mod-only">MOD</span>
      <div class="command-desc">Skip the current clip and move to the next one.</div>
    </div>

    <div class="command">
      <span class="command-name">!ccat &lt;game&gt;</span>
      <span class="mod-only">MOD</span>
      <div class="command-desc">Filter clips to only show a specific game/category.</div>
      <div class="command-example">!ccat Fortnite &nbsp;&nbsp;(filter to Fortnite clips)<br>!ccat off &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(return to all games)</div>
    </div>

    <div class="command">
      <span class="command-name">!cremove &lt;#&gt;</span>
      <span class="mod-only">MOD</span>
      <div class="command-desc">Remove a clip from the rotation pool.</div>
      <div class="command-example">!cremove 42</div>
    </div>

    <div class="command">
      <span class="command-name">!cadd &lt;#&gt;</span>
      <span class="mod-only">MOD</span>
      <div class="command-desc">Restore a previously removed clip to the pool.</div>
      <div class="command-example">!cadd 42</div>
    </div>

  </div>
</body>
</html>
