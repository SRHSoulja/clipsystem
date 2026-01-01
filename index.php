<?php
// Launch bot if not already running
@include_once __DIR__ . '/bot_launcher.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

// Get current user
$currentUser = getCurrentUser();

// Check if this is an API health check request
if (isset($_GET['health']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
  header("Content-Type: application/json");
  header("Access-Control-Allow-Origin: *");
  echo json_encode(["status" => "ok", "service" => "clipsystem"]);
  exit;
}

// Get list of archived streamers
$archivedStreamers = [];
$pdo = get_db_connection();
if ($pdo) {
  try {
    $stmt = $pdo->query("
      SELECT login, COUNT(*) as clip_count
      FROM clips
      WHERE blocked = FALSE
      GROUP BY login
      ORDER BY clip_count DESC
      LIMIT 20
    ");
    $archivedStreamers = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    // Ignore - just won't show archived streamers
  }
}

// Check if current user should see a dashboard link
$showDashboard = false;
$dashboardChannels = [];
if ($currentUser && $pdo) {
  $userLogin = strtolower($currentUser['login']);

  // Super admins always get dashboard access
  if (isSuperAdmin()) {
    $showDashboard = true;
  }

  // Check if user is an archived streamer
  if (!$showDashboard) {
    try {
      $stmt = $pdo->prepare("SELECT 1 FROM clips WHERE login = ? LIMIT 1");
      $stmt->execute([$userLogin]);
      if ($stmt->fetch()) {
        $showDashboard = true;
        $dashboardChannels[] = $userLogin;
      }
    } catch (PDOException $e) {
      // Ignore
    }
  }

  // Check if user is a mod for any archived streamer
  try {
    $stmt = $pdo->prepare("SELECT channel_login FROM channel_mods WHERE mod_username = ?");
    $stmt->execute([$userLogin]);
    while ($row = $stmt->fetch()) {
      $showDashboard = true;
      $dashboardChannels[] = $row['channel_login'];
    }
  } catch (PDOException $e) {
    // Ignore - table might not exist
  }
}

// Otherwise show landing page
header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ClipArchive - Twitch Clip Archive System</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: linear-gradient(135deg, #0e0e10 0%, #18181b 50%, #1f1f23 100%);
      color: #efeff1;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .container {
      max-width: 600px;
      text-align: center;
    }

    .logo {
      font-size: 72px;
      margin-bottom: 20px;
      animation: pulse 2s ease-in-out infinite;
    }

    @keyframes pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.1); }
    }

    h1 {
      font-size: 48px;
      font-weight: 700;
      margin-bottom: 10px;
      background: linear-gradient(90deg, #9147ff, #bf94ff);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .tagline {
      font-size: 18px;
      color: #adadb8;
      margin-bottom: 40px;
    }

    .features {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 20px;
      margin-bottom: 40px;
    }

    .feature {
      background: rgba(145, 71, 255, 0.1);
      border: 1px solid rgba(145, 71, 255, 0.3);
      border-radius: 12px;
      padding: 20px;
      transition: all 0.3s ease;
    }

    .feature:hover {
      background: rgba(145, 71, 255, 0.2);
      border-color: #9147ff;
      transform: translateY(-3px);
    }

    .feature-icon {
      font-size: 32px;
      margin-bottom: 10px;
    }

    .feature-title {
      font-weight: 600;
      margin-bottom: 5px;
    }

    .feature-desc {
      font-size: 13px;
      color: #adadb8;
    }

    .search-box {
      display: flex;
      gap: 10px;
      margin-bottom: 30px;
    }

    .search-box input {
      flex: 1;
      padding: 14px 20px;
      border: 2px solid #3d3d42;
      border-radius: 8px;
      background: #0e0e10;
      color: #efeff1;
      font-size: 16px;
      transition: border-color 0.2s;
    }

    .search-box input:focus {
      outline: none;
      border-color: #9147ff;
    }

    .search-box button {
      padding: 14px 28px;
      border: none;
      border-radius: 8px;
      background: #9147ff;
      color: white;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.2s;
    }

    .search-box button:hover {
      background: #772ce8;
    }

    .links {
      display: flex;
      gap: 20px;
      justify-content: center;
      flex-wrap: wrap;
    }

    .links a {
      color: #bf94ff;
      text-decoration: none;
      font-size: 14px;
      transition: color 0.2s;
    }

    .links a:hover {
      color: #9147ff;
      text-decoration: underline;
    }

    .archived-section {
      margin-top: 40px;
      text-align: center;
    }

    .archived-section h2 {
      font-size: 18px;
      color: #adadb8;
      margin-bottom: 15px;
      font-weight: 500;
    }

    .streamer-list {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 10px;
    }

    .streamer-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 14px;
      background: rgba(145, 71, 255, 0.15);
      border: 1px solid rgba(145, 71, 255, 0.3);
      border-radius: 20px;
      color: #bf94ff;
      text-decoration: none;
      font-size: 14px;
      transition: all 0.2s;
    }

    .streamer-chip:hover {
      background: rgba(145, 71, 255, 0.3);
      border-color: #9147ff;
      color: #fff;
    }

    .streamer-chip .count {
      font-size: 11px;
      color: #adadb8;
      background: rgba(0,0,0,0.3);
      padding: 2px 6px;
      border-radius: 10px;
    }

    footer {
      margin-top: 60px;
      color: #53535f;
      font-size: 13px;
    }

    footer a {
      color: #9147ff;
      text-decoration: none;
    }

    /* User login/info */
    .user-section {
      position: fixed;
      top: 20px;
      right: 20px;
    }
    .login-btn {
      padding: 10px 20px;
      background: #9147ff;
      color: white;
      text-decoration: none;
      border-radius: 6px;
      font-weight: 600;
      font-size: 14px;
      transition: background 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .login-btn:hover {
      background: #772ce8;
    }
    .login-btn svg {
      width: 18px;
      height: 18px;
    }
    .user-info {
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(31, 31, 35, 0.9);
      padding: 8px 16px;
      border-radius: 6px;
      border: 1px solid #3d3d42;
    }
    .user-name {
      color: #bf94ff;
      font-weight: 500;
      font-size: 14px;
    }
    .user-links {
      display: flex;
      gap: 10px;
    }
    .user-links a {
      color: #adadb8;
      text-decoration: none;
      font-size: 12px;
      transition: color 0.2s;
    }
    .user-links a:hover {
      color: #9147ff;
    }
    .logout-btn:hover {
      color: #ff4757 !important;
    }
  </style>
</head>
<body>
  <div class="user-section">
    <?php if ($currentUser): ?>
    <div class="user-info">
      <span class="user-name"><?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['login']) ?></span>
      <div class="user-links">
        <?php if ($showDashboard): ?>
        <a href="/mod_dashboard.php">Dashboard</a>
        <?php endif; ?>
        <a href="/auth/logout.php" class="logout-btn">Logout</a>
      </div>
    </div>
    <?php else: ?>
    <a href="/auth/login.php" class="login-btn">
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.64 5.93h1.43v4.28h-1.43m3.93-4.28H17v4.28h-1.43M7 2L3.43 5.57v12.86h4.28V22l3.58-3.57h2.85L20.57 12V2m-1.43 9.29l-2.85 2.85h-2.86l-2.5 2.5v-2.5H7.71V3.43h11.43z"/></svg>
      Login with Twitch
    </a>
    <?php endif; ?>
  </div>

  <div class="container">
    <div class="logo">📺</div>
    <h1>ClipArchive</h1>
    <p class="tagline">Twitch Clip Archive & Playback System</p>

    <form class="search-box" onsubmit="goToSearch(event)">
      <input type="text" id="streamer" placeholder="Enter streamer name..." autofocus>
      <button type="submit">Browse Clips</button>
    </form>

    <div class="features">
      <div class="feature">
        <div class="feature-icon">🎬</div>
        <div class="feature-title">Archive</div>
        <div class="feature-desc">Browse thousands of clips</div>
      </div>
      <div class="feature">
        <div class="feature-icon">🔍</div>
        <div class="feature-title">Search</div>
        <div class="feature-desc">Find clips by title or clipper</div>
      </div>
      <div class="feature">
        <div class="feature-icon">🤖</div>
        <div class="feature-title">Bot</div>
        <div class="feature-desc">Twitch chat commands</div>
      </div>
      <div class="feature">
        <div class="feature-icon">📊</div>
        <div class="feature-title">Vote</div>
        <div class="feature-desc">Like & dislike clips</div>
      </div>
    </div>

    <div class="links">
      <a href="/chelp.php">Bot Commands</a>
      <a href="/about.php">About</a>
    </div>

    <?php if (!empty($archivedStreamers)): ?>
    <div class="archived-section">
      <h2>Archived Streamers</h2>
      <div class="streamer-list">
        <?php foreach ($archivedStreamers as $streamer): ?>
        <a href="/search/<?= htmlspecialchars(urlencode($streamer['login'])) ?>" class="streamer-chip">
          <?= htmlspecialchars($streamer['login']) ?>
          <span class="count"><?= number_format($streamer['clip_count']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <footer>
    Powered by <a href="https://gmgnrepeat.com">GMGN Repeat</a>
  </footer>

  <script>
    function goToSearch(e) {
      e.preventDefault();
      const streamer = document.getElementById('streamer').value.trim().toLowerCase();
      if (streamer) {
        window.location.href = '/search/' + encodeURIComponent(streamer);
      }
    }
  </script>
</body>
</html>
