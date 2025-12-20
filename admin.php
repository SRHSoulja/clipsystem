<?php
/**
 * admin.php - Admin Panel for Clip System
 *
 * Password protected admin page for managing the clip system.
 * Uses ADMIN_KEY from environment for authentication.
 */
session_start();
header("Content-Type: text/html; charset=utf-8");

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/dashboard_auth.php';

$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

// Handle login
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
  if ($_POST['password'] === $ADMIN_KEY && $ADMIN_KEY !== '') {
    $_SESSION['admin_authenticated'] = true;
  } else {
    $error = 'Invalid password';
  }
}

// Handle logout
if (isset($_GET['logout'])) {
  unset($_SESSION['admin_authenticated']);
  header('Location: admin.php');
  exit;
}

// Check if authenticated
$authenticated = isset($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true;

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
      // Redirect to clips_backfill.php with the parameters
      header("Location: clips_backfill.php?login=" . urlencode($newLogin) . "&years=" . $years . "&key=" . urlencode($ADMIN_KEY));
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
      header("Location: refresh_clips.php?login=" . urlencode($login) . "&key=" . urlencode($ADMIN_KEY));
      exit;
    }
  }

  // Generate dashboard link for a user
  if (isset($_POST['action']) && $_POST['action'] === 'generate_dashboard') {
    $login = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['login'] ?? '')));

    if ($login) {
      $auth = new DashboardAuth();
      $key = $auth->createStreamer($login);

      if ($key) {
        $dashboardUrl = "https://gmgnrepeat.com/flop/dashboard.php?key=" . urlencode($key);
        $message = "Dashboard link generated for {$login}!";
        $messageType = 'success';
        $generatedDashboardUrl = $dashboardUrl;
        $generatedLogin = $login;
      } else {
        $message = "Failed to generate dashboard link";
        $messageType = 'error';
      }
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
  <div class="container">
    <?php if (!$authenticated): ?>
    <!-- Login Form -->
    <div class="login-form">
      <h1>Admin Login</h1>
      <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="POST">
        <label for="password">Admin Password</label>
        <input type="password" name="password" id="password" placeholder="Enter admin key" required autofocus>
        <button type="submit" style="width: 100%;">Login</button>
      </form>
    </div>

    <?php else: ?>
    <!-- Admin Panel -->
    <div class="nav-links">
      <a href="clip_search.php?login=floppyjimmie">Clip Search</a>
      <a href="chelp.php">Bot Commands</a>
      <a href="about.php">About</a>
      <a href="?logout=1" class="logout">Logout</a>
    </div>

    <h1>Admin Panel</h1>

    <?php if ($message): ?>
      <div class="<?= $messageType === 'error' ? 'error' : 'success' ?>">
        <?= htmlspecialchars($message) ?>
        <?php if ($playerUrl): ?>
          <div style="margin-top: 10px; padding: 10px; background: rgba(0,0,0,0.3); border-radius: 4px;">
            <strong>Player URL (for OBS Browser Source):</strong><br>
            <input type="text" value="<?= htmlspecialchars($playerUrl) ?>" readonly onclick="this.select()" style="width: 100%; margin-top: 5px; cursor: pointer;">
            <div style="margin-top: 8px; font-size: 13px; color: #adadb8;">
              <strong>Don't forget:</strong> Add <code><?= htmlspecialchars($successLogin) ?></code> to the <code>TWITCH_CHANNEL</code> environment variable in Railway for bot commands to work!
            </div>
          </div>
        <?php endif; ?>
        <?php if (isset($generatedDashboardUrl)): ?>
          <div style="margin-top: 10px; padding: 10px; background: rgba(0,0,0,0.3); border-radius: 4px;">
            <strong>Dashboard URL for <?= htmlspecialchars($generatedLogin) ?>:</strong><br>
            <input type="text" value="<?= htmlspecialchars($generatedDashboardUrl) ?>" readonly onclick="this.select()" style="width: 100%; margin-top: 5px; cursor: pointer;">
            <div style="margin-top: 8px; font-size: 13px; color: #adadb8;">
              Share this link with the streamer. They can bookmark it for easy access.
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Add New User -->
    <div class="card">
      <h3>Add New User</h3>
      <p style="color: #adadb8; margin-top: 0;">Enter a Twitch username to backfill their clips into the system.</p>
      <form method="POST">
        <input type="hidden" name="action" value="add_user">
        <div class="form-row">
          <div class="form-group">
            <label for="login">Twitch Username</label>
            <input type="text" name="login" id="login" placeholder="username" required pattern="[a-zA-Z0-9_]+" title="Letters, numbers, and underscores only">
          </div>
          <div class="form-group" style="flex: 0 0 120px;">
            <label for="years">Years to Fetch</label>
            <input type="number" name="years" id="years" value="3" min="1" max="10">
          </div>
          <div class="form-group" style="flex: 0 0 auto;">
            <button type="submit">Add User</button>
          </div>
        </div>
      </form>
    </div>

    <!-- Existing Users -->
    <h2>Existing Users</h2>
    <?php if (empty($users)): ?>
      <p style="color: #adadb8;">No users found in database.</p>
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
              <a href="clip_search.php?login=<?= htmlspecialchars($user['login']) ?>" style="color: #9147ff; text-decoration: none;">
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
