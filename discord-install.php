<?php
// Redirect page with OG/Twitter meta tags for rich previews on X/Twitter/etc.
// Immediately redirects browsers to the Discord Activity install link.
$installUrl = 'https://discord.com/oauth2/authorize?client_id=1477451341776421046';
$image = 'https://clips.gmgnrepeat.com/tapefacecliptv.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ClipTV - Discord Activity | Powered by GMGNRepeat</title>
<meta name="description" content="Watch Twitch clips together with friends in Discord. Browse streamers, vote to skip, chat, and sync up in a shared viewing experience.">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:title" content="ClipTV - Watch Clips Together on Discord">
<meta property="og:description" content="A synced Twitch clip viewer you can launch right inside Discord. Browse streamers, vote, chat, and watch together with friends.">
<meta property="og:image" content="<?= $image ?>">
<meta property="og:url" content="https://clips.gmgnrepeat.com/discord-install">
<meta property="og:site_name" content="ClipTV by GMGNRepeat">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="ClipTV - Watch Clips Together on Discord">
<meta name="twitter:description" content="A synced Twitch clip viewer you can launch right inside Discord. Browse streamers, vote, chat, and watch together with friends.">
<meta name="twitter:image" content="<?= $image ?>">

<meta http-equiv="refresh" content="0;url=<?= $installUrl ?>">
</head>
<body style="background:#0e0e10;color:#efeff1;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;">
<div style="text-align:center;padding:24px;">
<img src="/tapefacecliptv.png" alt="ClipTV" style="width:80px;height:80px;border-radius:16px;margin-bottom:12px;">
<h1 style="font-size:24px;margin:0 0 8px;">ClipTV</h1>
<p style="color:#adadb8;margin:0 0 16px;">Redirecting to Discord...</p>
<a href="<?= $installUrl ?>" style="color:#9147ff;">Click here if not redirected</a>
</div>
</body>
</html>
