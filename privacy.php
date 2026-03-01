<?php
header("Content-Type: text/html; charset=utf-8");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ClipTV - Privacy Policy</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #0e0e10; color: #efeff1; font: 16px/1.7 system-ui, -apple-system, sans-serif; }
    .container { max-width: 720px; margin: 0 auto; padding: 40px 24px 80px; }
    h1 { font-size: 32px; margin-bottom: 8px; color: #fff; }
    .subtitle { color: #adadb8; font-size: 14px; margin-bottom: 40px; }
    h2 { font-size: 20px; margin: 32px 0 12px; color: #fff; }
    h3 { font-size: 17px; margin: 20px 0 8px; color: #d3d3d8; }
    p, li { color: #d3d3d8; margin-bottom: 12px; }
    ul { padding-left: 24px; }
    a { color: #a78bfa; text-decoration: none; }
    a:hover { text-decoration: underline; }
    .logo { display: flex; align-items: center; gap: 12px; margin-bottom: 32px; }
    .logo img { width: 48px; height: 48px; border-radius: 10px; }
    .logo span { font-size: 24px; font-weight: 700; color: #fff; }
    table { width: 100%; border-collapse: collapse; margin: 12px 0 20px; }
    th, td { text-align: left; padding: 10px 14px; border: 1px solid #2a2a2d; color: #d3d3d8; }
    th { background: #1a1a1d; color: #efeff1; font-weight: 600; }
  </style>
</head>
<body>
  <div class="container">
    <div class="logo">
      <img src="/cliptvlogo.png" alt="ClipTV">
      <span>ClipTV</span>
    </div>
    <h1>Privacy Policy</h1>
    <p class="subtitle">Last updated: March 1, 2026</p>

    <h2>1. Overview</h2>
    <p>ClipTV ("we", "the Service") respects your privacy. This policy explains what data we collect, how we use it, and your rights regarding that data.</p>

    <h2>2. Data We Collect</h2>

    <h3>When you use ClipTV via the website (Twitch login):</h3>
    <table>
      <tr><th>Data</th><th>Purpose</th></tr>
      <tr><td>Twitch username and display name</td><td>Identify votes and chat messages</td></tr>
      <tr><td>Twitch user ID</td><td>Authentication and rate limiting</td></tr>
      <tr><td>Twitch profile image</td><td>Display in chat</td></tr>
    </table>

    <h3>When you use the ClipTV Discord Activity:</h3>
    <table>
      <tr><th>Data</th><th>Purpose</th></tr>
      <tr><td>Discord username and display name</td><td>Identify you in the Activity</td></tr>
      <tr><td>Discord user ID</td><td>Generate vote authentication token</td></tr>
      <tr><td>Discord avatar</td><td>Display in the Activity</td></tr>
      <tr><td>Linked Twitch account (if visible)</td><td>Enable voting and chat using your Twitch identity</td></tr>
    </table>

    <h3>Automatically collected:</h3>
    <table>
      <tr><th>Data</th><th>Purpose</th></tr>
      <tr><td>Chat messages</td><td>Display in communal chat (archived after 24 hours)</td></tr>
      <tr><td>Votes (like/dislike)</td><td>Clip rankings and social scores</td></tr>
      <tr><td>Viewer presence</td><td>Show viewer count (not stored long-term)</td></tr>
    </table>

    <h2>3. Data We Do NOT Collect</h2>
    <ul>
      <li>We do not collect your email address</li>
      <li>We do not collect your Discord or Twitch password</li>
      <li>We do not collect your IP address in our database</li>
      <li>We do not use cookies for tracking or advertising</li>
      <li>We do not sell, share, or provide your data to third parties</li>
    </ul>

    <h2>4. How We Use Your Data</h2>
    <p>Your data is used solely to operate the Service:</p>
    <ul>
      <li>Display your name alongside your votes and chat messages</li>
      <li>Prevent vote manipulation and spam (rate limiting)</li>
      <li>Calculate community clip rankings</li>
    </ul>

    <h2>5. Data Storage and Retention</h2>
    <ul>
      <li>Chat messages are stored for 24 hours, then archived to daily log files</li>
      <li>Votes are stored indefinitely as part of the clip ranking system</li>
      <li>No long-term session data is stored; authentication is stateless (HMAC tokens for Discord, PHP sessions for web)</li>
    </ul>

    <h2>6. Third-Party Services</h2>
    <p>ClipTV integrates with:</p>
    <ul>
      <li><strong>Twitch</strong> - for clip content, user authentication, and profile data (<a href="https://www.twitch.tv/p/en/legal/privacy-notice/">Twitch Privacy Policy</a>)</li>
      <li><strong>Discord</strong> - for Activity authentication and linked account access (<a href="https://discord.com/privacy">Discord Privacy Policy</a>)</li>
    </ul>

    <h2>7. Your Rights</h2>
    <p>You can:</p>
    <ul>
      <li><strong>Revoke Twitch access</strong> at <a href="https://www.twitch.tv/settings/connections">Twitch Settings &rarr; Connections</a></li>
      <li><strong>Revoke Discord access</strong> at Discord Settings &rarr; Authorized Apps &rarr; ClipTV &rarr; Deauthorize</li>
      <li><strong>Request data deletion</strong> by contacting us through the <a href="https://discord.gg/gmgnrepeat">GMGN Repeat Discord server</a></li>
    </ul>

    <h2>8. Children's Privacy</h2>
    <p>ClipTV is not intended for users under 13 years of age. We do not knowingly collect data from children.</p>

    <h2>9. Changes to This Policy</h2>
    <p>We may update this Privacy Policy at any time. Continued use of the Service after changes constitutes acceptance.</p>

    <h2>10. Contact</h2>
    <p>For privacy questions or data deletion requests, reach out via the <a href="https://discord.gg/gmgnrepeat">GMGN Repeat Discord server</a>.</p>
  </div>
</body>
</html>
