<?php
/**
 * admin.php - Admin Panel for Clip System
 *
 * Super admin page for managing the clip system.
 * Access via Twitch OAuth - only super admins (thearsondragon, cliparchive) allowed.
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/dashboard_auth.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

header("Content-Type: text/html; charset=utf-8");

// Get pdo for nav
$pdo = get_db_connection();

// Check OAuth authentication
$currentUser = getCurrentUser();
$authenticated = $currentUser && isSuperAdmin();
$error = '';

// Handle logout
if (isset($_GET['logout'])) {
  logout();
  header('Location: admin.php');
  exit;
}

// Handle success message from backfill/migration completion
$successLogin = isset($_GET['success']) ? preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['success'])) : '';
$successClips = isset($_GET['clips']) ? intval($_GET['clips']) : 0;

// Handle actions
$message = '';
$messageType = '';
$playerUrl = '';

if ($successLogin && $authenticated) {
  $playerUrl = "https://gmgnrepeat.com/flop/clipplayer_mp4_reel.html?login=" . urlencode($successLogin);
  $message = "Successfully added {$successLogin} with {$successClips} clips!";
  $messageType = 'success';
}

if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST') {
  // Add new user action
  if (isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $newLogin = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['login'] ?? '')));
    $years = max(1, min(10, intval($_POST['years'] ?? 3)));

    if ($newLogin) {
      // Redirect to clips_backfill.php with the parameters (OAuth session provides auth)
      header("Location: clips_backfill.php?login=" . urlencode($newLogin) . "&years=" . $years);
      exit;
    } else {
      $message = 'Invalid username';
      $messageType = 'error';
    }
  }

  // Refresh clips for existing user (fetch NEW clips only)
  if (isset($_POST['action']) && $_POST['action'] === 'refresh_user') {
    $login = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['login'] ?? '')));

    if ($login) {
      header("Location: refresh_clips.php?login=" . urlencode($login));
      exit;
    }
  }

  // Generate dashboard link for a user
  if (isset($_POST['action']) && $_POST['action'] === 'generate_dashboard') {
    $login = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['login'] ?? '')));

    if ($login) {
      $auth = new DashboardAuth();
      $created = $auth->createStreamer($login);

      if ($created || $auth->streamerExists($login)) {
        // OAuth-based URL - streamer logs in with Twitch to access their dashboard
        $dashboardUrl = "https://clips.gmgnrepeat.com/dashboard/" . urlencode($login);
        $message = "Dashboard enabled for {$login}! They can access it by logging in with Twitch.";
        $messageType = 'success';
        $generatedDashboardUrl = $dashboardUrl;
        $generatedLogin = $login;
      } else {
        $message = "Failed to enable dashboard";
        $messageType = 'error';
      }
    }
  }

  // Resolve missing game names
  if (isset($_POST['action']) && $_POST['action'] === 'resolve_games') {
    $login = isset($_POST['login']) ? strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['login']))) : '';

    // Call games_api.php resolve action via HTTP (OAuth session provides auth)
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $resolveUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . "/games_api.php?action=resolve";
    if ($login) {
      $resolveUrl .= "&login=" . urlencode($login);
    }

    $ctx = stream_context_create(['http' => ['timeout' => 60]]);
    $response = @file_get_contents($resolveUrl, false, $ctx);
    $result = $response ? json_decode($response, true) : null;

    if ($result && isset($result['resolved'])) {
      $message = $result['message'];
      $messageType = $result['resolved'] > 0 ? 'success' : 'info';
    } else {
      $errorDetail = $result['error'] ?? 'Unknown error';
      $message = "Failed to resolve game names: " . $errorDetail;
      $messageType = 'error';
    }
  }
}

// Get list of existing users from database
$users = [];
if ($authenticated) {
  $pdo = get_db_connection();
  if ($pdo) {
    try {
      $stmt = $pdo->query("SELECT login, COUNT(*) as clip_count, MAX(created_at) as latest_clip FROM clips GROUP BY login ORDER BY login");
      $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      // Ignore errors
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <title>Admin - Clip System</title>
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
      max-width: 900px;
      margin: 0 auto;
    }
    h1 {
      color: #ff4444;
      border-bottom: 2px solid #ff4444;
      padding-bottom: 10px;
    }
    h2 {
      color: #bf94ff;
      margin-top: 30px;
      border-bottom: 1px solid #333;
      padding-bottom: 5px;
    }
    .login-form {
      max-width: 400px;
      margin: 100px auto;
      background: #18181b;
      padding: 30px;
      border-radius: 8px;
    }
    .login-form h1 {
      margin-top: 0;
      text-align: center;
    }
    input[type="text"], input[type="password"], input[type="number"], select {
      width: 100%;
      padding: 12px;
      margin: 8px 0 16px 0;
      background: #0e0e10;
      border: 1px solid #333;
      border-radius: 4px;
      color: #efeff1;
      font-size: 16px;
    }
    input:focus, select:focus {
      outline: none;
      border-color: #9147ff;
    }
    button, .btn {
      background: #9147ff;
      color: white;
      border: none;
      padding: 12px 24px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 16px;
      text-decoration: none;
      display: inline-block;
    }
    button:hover, .btn:hover {
      background: #772ce8;
    }
    .btn-danger {
      background: #ff4444;
    }
    .btn-danger:hover {
      background: #cc3333;
    }
    .btn-secondary {
      background: #333;
    }
    .btn-secondary:hover {
      background: #444;
    }
    .error {
      background: rgba(255, 68, 68, 0.2);
      border: 1px solid #ff4444;
      color: #ff6666;
      padding: 10px;
      border-radius: 4px;
      margin-bottom: 15px;
    }
    .success {
      background: rgba(68, 255, 68, 0.2);
      border: 1px solid #44ff44;
      color: #66ff66;
      padding: 10px;
      border-radius: 4px;
      margin-bottom: 15px;
    }
    .card {
      background: #18181b;
      border-radius: 8px;
      padding: 20px;
      margin: 15px 0;
    }
    .card h3 {
      margin-top: 0;
      color: #efeff1;
    }
    .user-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 15px;
    }
    .user-table th, .user-table td {
      padding: 12px;
      text-align: left;
      border-bottom: 1px solid #333;
    }
    .user-table th {
      color: #adadb8;
      font-weight: 600;
    }
    .user-table tr:hover {
      background: #1f1f23;
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
    .logout {
      float: right;
      color: #adadb8;
      text-decoration: none;
    }
    .logout:hover {
      color: #ff4444;
    }
    .command {
      background: #18181b;
      border-radius: 8px;
      padding: 15px;
      margin: 10px 0;
      border-left: 3px solid #ff4444;
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
    .admin-only {
      display: inline-block;
      background: #ff4444;
      color: white;
      font-size: 0.75em;
      padding: 2px 8px;
      border-radius: 4px;
      margin-left: 10px;
    }
    .form-row {
      display: flex;
      gap: 15px;
      align-items: flex-end;
    }
    .form-row .form-group {
      flex: 1;
    }
    .form-group label {
      display: block;
      color: #adadb8;
      margin-bottom: 4px;
      font-size: 14px;
    }
    .inline-form {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    .inline-form input {
      margin: 0;
      width: auto;
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/includes/nav.php'; ?>

  <div class="container" style="padding-top: 20px;">
    <?php if (!$authenticated): ?>
    <!-- Login Form -->
    <div class="login-form">
      <h1>Admin Login</h1>
      <?php if ($currentUser): ?>
        <div class="error">Access denied. Only super admins (thearsondragon, cliparchive) can access this page.</div>
        <p style="color: #adadb8; text-align: center; margin-top: 15px;">
          Logged in as: <?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['login']) ?>
        </p>
      <?php else: ?>
        <p style="color: #adadb8; text-align: center; margin-bottom: 20px;">
          Sign in with Twitch to access the admin panel.
        </p>
        <?php
        $oauth = new TwitchOAuth();
        $authUrl = $oauth->getAuthUrl($_SERVER['REQUEST_URI']);
        ?>
        <a href="<?= htmlspecialchars($authUrl) ?>" class="btn" style="width: 100%; text-align: center; background: #9147ff;">
          Login with Twitch
        </a>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- Admin Panel -->

    <h1>Admin Panel</h1>

    <?php if ($message): ?>
      <div class="<?= $messageType === 'error' ? 'error' : 'success' ?>">
        <?= htmlspecialchars($message) ?>
        <?php if ($playerUrl): ?>
          <div style="margin-top: 10px; padding: 10px; background: rgba(0,0,0,0.3); border-radius: 4px;">
            <strong>Player URL (for OBS Browser Source):</strong><br>
            <input type="text" value="<?= htmlspecialchars($playerUrl) ?>" readonly onclick="this.select()" style="width: 100%; margin-top: 5px; cursor: pointer;">
            <div style="margin-top: 8px; font-size: 13px; color: #adadb8;">
              <strong>Next step:</strong> Add <code><?= htmlspecialchars($successLogin) ?></code> to the bot using the "Bot Channel Management" section below for chat commands to work.
            </div>
          </div>
        <?php endif; ?>
        <?php if (isset($generatedDashboardUrl)): ?>
          <div style="margin-top: 10px; padding: 10px; background: rgba(0,0,0,0.3); border-radius: 4px;">
            <strong>Dashboard enabled for <?= htmlspecialchars($generatedLogin) ?>!</strong><br>
            <div style="margin-top: 8px; font-size: 13px; color: #adadb8;">
              The streamer can access their dashboard by visiting <a href="https://clips.gmgnrepeat.com/channels" style="color: #9147ff;">clips.gmgnrepeat.com/channels</a> and logging in with their Twitch account.
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Archive New Streamer -->
    <h2 style="color: #00b894; border-color: #00b894;">Archive New Streamer</h2>
    <div class="card" style="border: 1px solid #00b894;">
      <h3 style="color: #00b894;">Add Streamer to Archive</h3>
      <p style="color: #adadb8; margin-top: 0; margin-bottom: 16px;">
        Enter a Twitch username to archive their clips. This will:
      </p>
      <ul style="color: #adadb8; margin-left: 20px; margin-bottom: 16px; font-size: 14px;">
        <li>Fetch all clips from the selected time period</li>
        <li>Create dashboard access for the streamer</li>
        <li>Register channel for bot commands (bot won't auto-join until invited)</li>
        <li>Show them on the homepage with other archived streamers</li>
      </ul>
      <form method="POST">
        <input type="hidden" name="action" value="add_user">
        <div class="form-row">
          <div class="form-group">
            <label for="login">Twitch Username</label>
            <input type="text" name="login" id="login" placeholder="username" required pattern="[a-zA-Z0-9_]+" title="Letters, numbers, and underscores only">
          </div>
          <div class="form-group" style="flex: 0 0 180px;">
            <label for="years">Clips to Fetch</label>
            <select name="years" id="years">
              <option value="1">Last 1 year</option>
              <option value="2">Last 2 years</option>
              <option value="3" selected>Last 3 years</option>
              <option value="4">Last 4 years</option>
              <option value="5">Last 5 years</option>
              <option value="7">Last 7 years</option>
              <option value="10">All time (10 years)</option>
            </select>
          </div>
          <div class="form-group" style="flex: 0 0 auto;">
            <button type="submit" style="background: #00b894;">Archive Streamer</button>
          </div>
        </div>
      </form>
      <p style="color: #666; font-size: 12px; margin-top: 12px;">
        Note: This process takes 1-5 minutes depending on clip count. The page will auto-refresh during processing.
      </p>
    </div>

    <!-- Archived Streamers -->
    <h2>Archived Streamers</h2>
    <?php if (empty($users)): ?>
      <p style="color: #adadb8;">No streamers archived yet. Use the form above to add one.</p>
    <?php else: ?>
      <table class="user-table">
        <thead>
          <tr>
            <th>Username</th>
            <th>Clips</th>
            <th>Latest Clip</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
          <tr>
            <td>
              <a href="/search/<?= htmlspecialchars($user['login']) ?>" style="color: #9147ff; text-decoration: none;">
                <?= htmlspecialchars($user['login']) ?>
              </a>
            </td>
            <td><?= number_format($user['clip_count']) ?></td>
            <td><?= $user['latest_clip'] ? date('M j, Y', strtotime($user['latest_clip'])) : '-' ?></td>
            <td>
              <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="refresh_user">
                <input type="hidden" name="login" value="<?= htmlspecialchars($user['login']) ?>">
                <button type="submit" class="btn-secondary" style="padding: 6px 12px; font-size: 14px;" title="Fetch new clips since last update">Get New Clips</button>
              </form>
              <form method="POST" style="display: inline; margin-left: 8px;">
                <input type="hidden" name="action" value="generate_dashboard">
                <input type="hidden" name="login" value="<?= htmlspecialchars($user['login']) ?>">
                <button type="submit" class="btn-secondary" style="padding: 6px 12px; font-size: 14px; background: #9147ff;" title="Generate dashboard access link">Dashboard</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <!-- Resolve Game Names -->
    <div class="card" style="margin-top: 25px;">
      <h3>Resolve Game Names</h3>
      <p style="color: #adadb8; margin-top: 0;">Fetch game names from Twitch API for any clips showing "Game 12345" instead of actual game names.</p>
      <form method="POST">
        <input type="hidden" name="action" value="resolve_games">
        <div class="form-row">
          <div class="form-group">
            <label for="resolve_login">Username (optional - leave blank for all)</label>
            <select name="login" id="resolve_login">
              <option value="">All Users</option>
              <?php foreach ($users as $user): ?>
              <option value="<?= htmlspecialchars($user['login']) ?>"><?= htmlspecialchars($user['login']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex: 0 0 auto;">
            <button type="submit" style="background: #00b894;">Resolve Game Names</button>
          </div>
        </div>
      </form>
    </div>

    <!-- Bot Channel Management -->
    <h2>Bot Channel Management</h2>
    <p style="color: #adadb8;">Manage which Twitch channels the bot joins. Changes take effect within 30 seconds.</p>

    <div class="card">
      <h3>Add Bot to Channel</h3>
      <div class="form-row">
        <div class="form-group">
          <label for="bot_channel">Twitch Channel</label>
          <input type="text" id="bot_channel" placeholder="channel_name" pattern="[a-zA-Z0-9_]+" title="Letters, numbers, and underscores only">
        </div>
        <div class="form-group" style="flex: 0 0 auto;">
          <button onclick="addBotChannel()" style="background: #00b894;">Add Channel</button>
        </div>
      </div>
      <div id="botChannelMessage" style="margin-top: 10px;"></div>
    </div>

    <div class="card">
      <h3>Active Bot Channels</h3>
      <div id="botChannelList">
        <p style="color: #adadb8;">Loading...</p>
      </div>
    </div>

    <script>
      async function loadBotChannels() {
        try {
          const res = await fetch(`/bot_api.php?action=list_all`);
          const data = await res.json();

          const container = document.getElementById('botChannelList');

          if (!data.success || !data.channels || data.channels.length === 0) {
            container.innerHTML = '<p style="color: #adadb8;">No channels configured. Add a channel above.</p>';
            return;
          }

          let html = '<table class="user-table"><thead><tr><th>Channel</th><th>Added</th><th>Status</th><th>Actions</th></tr></thead><tbody>';

          for (const ch of data.channels) {
            const statusBadge = ch.active
              ? '<span style="color: #00b894;">● Active</span>'
              : '<span style="color: #666;">○ Inactive</span>';

            const actionBtn = ch.active
              ? `<button onclick="removeBotChannel('${ch.channel_login}')" class="btn-danger" style="padding: 6px 12px; font-size: 14px;">Remove</button>`
              : `<button onclick="addBotChannel('${ch.channel_login}')" class="btn-secondary" style="padding: 6px 12px; font-size: 14px; background: #00b894;">Re-add</button>`;

            html += `<tr>
              <td><a href="https://twitch.tv/${ch.channel_login}" target="_blank" style="color: #9147ff; text-decoration: none;">${ch.channel_login}</a></td>
              <td>${ch.added_at ? new Date(ch.added_at).toLocaleDateString() : '-'}</td>
              <td>${statusBadge}</td>
              <td>${actionBtn}</td>
            </tr>`;
          }

          html += '</tbody></table>';
          container.innerHTML = html;
        } catch (e) {
          console.error('Error loading bot channels:', e);
          document.getElementById('botChannelList').innerHTML = '<p style="color: #ff6666;">Error loading channels</p>';
        }
      }

      async function addBotChannel(channel) {
        const channelName = channel || document.getElementById('bot_channel').value.trim().toLowerCase();
        if (!channelName) {
          showBotMessage('Please enter a channel name', 'error');
          return;
        }

        try {
          const res = await fetch(`/bot_api.php?action=add&channel=${encodeURIComponent(channelName)}`);
          const data = await res.json();

          if (data.success) {
            showBotMessage(data.message, 'success');
            document.getElementById('bot_channel').value = '';
            loadBotChannels();
          } else {
            showBotMessage(data.error || 'Failed to add channel', 'error');
          }
        } catch (e) {
          showBotMessage('Error adding channel', 'error');
        }
      }

      async function removeBotChannel(channel) {
        if (!confirm(`Remove bot from #${channel}?`)) return;

        try {
          const res = await fetch(`/bot_api.php?action=remove&channel=${encodeURIComponent(channel)}`);
          const data = await res.json();

          if (data.success) {
            showBotMessage(data.message, 'success');
            loadBotChannels();
          } else {
            showBotMessage(data.error || 'Failed to remove channel', 'error');
          }
        } catch (e) {
          showBotMessage('Error removing channel', 'error');
        }
      }

      function showBotMessage(msg, type) {
        const el = document.getElementById('botChannelMessage');
        el.innerHTML = `<div class="${type === 'error' ? 'error' : 'success'}">${msg}</div>`;
        setTimeout(() => el.innerHTML = '', 5000);
      }

      // Load channels on page load
      loadBotChannels();
    </script>

    <!-- Suspicious Voters Management -->
    <h2 style="color: #ff6b6b; border-color: #ff6b6b;">🛡️ Anti-Bot: Suspicious Voters</h2>
    <p style="color: #adadb8;">Monitor and manage accounts flagged for suspicious voting patterns.</p>

    <div class="card" style="border: 1px solid #ff6b6b;">
      <h3 style="color: #ff6b6b;">Flagged Accounts</h3>
      <div id="suspiciousVotersList">
        <p style="color: #adadb8;">Loading...</p>
      </div>
    </div>

    <div class="card">
      <h3>All Voter Activity</h3>
      <p style="color: #adadb8; margin-bottom: 15px;">View all accounts with vote tracking data.</p>
      <button onclick="loadAllVoters()" class="btn-secondary">Load All Voters</button>
      <div id="allVotersList" style="margin-top: 15px;"></div>
    </div>

    <script>
      // Helper to escape HTML and prevent XSS
      function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }

      // Helper to safely parse JSON response
      async function safeJsonParse(res) {
        const text = await res.text();
        try {
          return JSON.parse(text);
        } catch (e) {
          console.error('Invalid JSON response:', text);
          return { success: false, error: 'Invalid server response' };
        }
      }

      async function loadSuspiciousVoters() {
        const container = document.getElementById('suspiciousVotersList');
        container.innerHTML = '<p style="color: #adadb8;">Loading...</p>';

        try {
          const res = await fetch(`/api/suspicious_voters.php?action=list_flagged`);
          const data = await safeJsonParse(res);

          if (!data.success || !data.voters || data.voters.length === 0) {
            container.innerHTML = '<p style="color: #00b894;">✓ No suspicious voters flagged</p>';
            return;
          }

          let html = `<p style="color: #ff6b6b; margin-bottom: 15px;">⚠️ ${data.voters.length} account(s) flagged for review</p>`;
          html += '<table class="user-table"><thead><tr><th>Username</th><th>Total Votes</th><th>Downvote %</th><th>Reason</th><th>Flagged</th><th>Actions</th></tr></thead><tbody>';

          for (const voter of data.voters) {
            const safeUsername = escapeHtml(voter.username);
            const downvotePct = (parseFloat(voter.downvote_ratio) * 100).toFixed(1);
            const flaggedAt = voter.flagged_at ? new Date(voter.flagged_at).toLocaleString() : '-';
            const safeReason = escapeHtml(voter.flag_reason) || '-';

            html += `<tr style="background: rgba(255, 107, 107, 0.1);">
              <td><a href="https://twitch.tv/${encodeURIComponent(voter.username)}" target="_blank" style="color: #9147ff;">${safeUsername}</a></td>
              <td>${parseInt(voter.total_votes) || 0}</td>
              <td>${downvotePct}%</td>
              <td style="max-width: 200px; font-size: 12px; color: #ff6b6b;">${safeReason}</td>
              <td style="font-size: 12px;">${escapeHtml(flaggedAt)}</td>
              <td>
                <button onclick="undoVotes('${escapeHtml(voter.username).replace(/'/g, "\\'")}')" class="btn-danger" style="padding: 4px 8px; font-size: 12px; margin-right: 5px;">Undo Votes</button>
                <button onclick="clearFlag('${escapeHtml(voter.username).replace(/'/g, "\\'")}')" class="btn-secondary" style="padding: 4px 8px; font-size: 12px; background: #00b894;">Clear Flag</button>
              </td>
            </tr>`;
          }

          html += '</tbody></table>';
          container.innerHTML = html;
        } catch (e) {
          console.error('Error loading suspicious voters:', e);
          container.innerHTML = '<p style="color: #ff6666;">Error loading data</p>';
        }
      }

      async function loadAllVoters() {
        const container = document.getElementById('allVotersList');
        container.innerHTML = '<p style="color: #adadb8;">Loading...</p>';

        try {
          const res = await fetch(`/api/suspicious_voters.php?action=list_all`);
          const data = await safeJsonParse(res);

          if (!data.success || !data.voters || data.voters.length === 0) {
            container.innerHTML = '<p style="color: #adadb8;">No voter data found</p>';
            return;
          }

          let html = '<table class="user-table"><thead><tr><th>Username</th><th>Total</th><th>Last Hour</th><th>Downvote %</th><th>First Vote</th><th>Last Vote</th><th>Status</th></tr></thead><tbody>';

          for (const voter of data.voters) {
            const safeUsername = escapeHtml(voter.username);
            const downvotePct = (parseFloat(voter.downvote_ratio) * 100).toFixed(1);
            const firstVote = voter.first_vote_at ? new Date(voter.first_vote_at).toLocaleDateString() : '-';
            const lastVote = voter.last_vote_at ? new Date(voter.last_vote_at).toLocaleString() : '-';
            const status = voter.flagged
              ? (voter.reviewed ? '<span style="color: #00b894;">Reviewed ✓</span>' : '<span style="color: #ff6b6b;">Flagged ⚠️</span>')
              : '<span style="color: #adadb8;">OK</span>';
            const rowStyle = voter.flagged && !voter.reviewed ? 'background: rgba(255, 107, 107, 0.1);' : '';

            html += `<tr style="${rowStyle}">
              <td><a href="https://twitch.tv/${encodeURIComponent(voter.username)}" target="_blank" style="color: #9147ff;">${safeUsername}</a></td>
              <td>${parseInt(voter.total_votes) || 0}</td>
              <td>${parseInt(voter.votes_last_hour) || 0}</td>
              <td>${downvotePct}%</td>
              <td style="font-size: 12px;">${escapeHtml(firstVote)}</td>
              <td style="font-size: 12px;">${escapeHtml(lastVote)}</td>
              <td>${status}</td>
            </tr>`;
          }

          html += '</tbody></table>';
          container.innerHTML = html;
        } catch (e) {
          console.error('Error loading all voters:', e);
          container.innerHTML = '<p style="color: #ff6666;">Error loading data</p>';
        }
      }

      async function undoVotes(username) {
        if (!confirm(`This will remove ALL votes from ${username}. This action cannot be undone. Continue?`)) return;

        try {
          const res = await fetch(`/api/suspicious_voters.php?action=undo_votes&username=${encodeURIComponent(username)}`);
          const data = await safeJsonParse(res);

          if (data.success) {
            alert(data.message);
            await loadSuspiciousVoters();
            // Only reload all voters if it was previously loaded
            if (document.getElementById('allVotersList').innerHTML.includes('<table')) {
              await loadAllVoters();
            }
          } else {
            alert('Error: ' + (data.error || 'Failed to undo votes'));
          }
        } catch (e) {
          console.error('Error undoing votes:', e);
          alert('Error undoing votes');
        }
      }

      async function clearFlag(username) {
        if (!confirm(`Mark ${username} as reviewed and clear the flag?`)) return;

        try {
          const res = await fetch(`/api/suspicious_voters.php?action=clear_flag&username=${encodeURIComponent(username)}`);
          const data = await safeJsonParse(res);

          if (data.success) {
            alert(data.message);
            await loadSuspiciousVoters();
          } else {
            alert('Error: ' + (data.error || 'Failed to clear flag'));
          }
        } catch (e) {
          console.error('Error clearing flag:', e);
          alert('Error clearing flag');
        }
      }

      // Load suspicious voters on page load
      loadSuspiciousVoters();
    </script>

    <!-- Admin Commands -->
    <h2>Admin-Only Commands</h2>
    <p style="color: #adadb8;">These commands only work in TheArsonDragon's channel.</p>

    <div class="command">
      <span class="command-name">!cswitch &lt;channel&gt;</span>
      <span class="admin-only">ADMIN</span>
      <div class="command-desc">Temporarily control another channel's clips from your chat. All clip commands will affect the target channel until you switch back.</div>
      <div class="command-example">
        !cswitch joshbelmar &nbsp;(control josh's clips)<br>
        !cswitch floppyjimmie &nbsp;(control floppy's clips)<br>
        !cswitch off &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(return to normal)
      </div>
    </div>

    <div class="command">
      <span class="command-name">!clikeon [channel]</span>
      <span class="admin-only">ADMIN</span>
      <div class="command-desc">Enable voting (!like/!dislike) for a channel. Voting is OFF by default on bot restart.</div>
      <div class="command-example">
        !clikeon &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(enable for current channel)<br>
        !clikeon joshbelmar &nbsp;(enable for josh's channel)
      </div>
    </div>

    <div class="command">
      <span class="command-name">!clikeoff [channel]</span>
      <span class="admin-only">ADMIN</span>
      <div class="command-desc">Disable voting (!like/!dislike) for a channel.</div>
      <div class="command-example">
        !clikeoff &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(disable for current channel)<br>
        !clikeoff joshbelmar &nbsp;(disable for josh's channel)
      </div>
    </div>

    <?php endif; ?>
  </div>
</body>
</html>
