<?php
/**
 * mod_dashboard.php - Mod Dashboard for Playlist Management
 *
 * OAuth protected page for mods to:
 * - Browse all clips with search/filter
 * - Create and manage playlists
 * - Queue playlists for playback
 */
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Content-Security-Policy: upgrade-insecure-requests");

// Load env file if exists
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
  foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    if (strpos($line, '=') === false) continue;
    list($k, $v) = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v));
  }
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';
require_once __DIR__ . '/includes/dashboard_auth.php';

// Get current OAuth user
$currentUser = getCurrentUser();

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");

// Check if user is authorized via OAuth for a specific channel
$oauthAuthorized = false;
$oauthChannel = '';
$isSuperAdmin = false;
$isChannelMod = false;
$modPermissions = [];
$isStreamerOfChannel = false;

$auth = new DashboardAuth();

if ($currentUser) {
  $oauthChannel = strtolower($currentUser['login']);
  $isSuperAdmin = isSuperAdmin();

  // If no login specified, default to user's own channel
  if (!$login || $login === 'default') {
    $login = $oauthChannel;
  }

  // User is authorized if they're a super admin or accessing their own channel
  $oauthAuthorized = isAuthorizedForChannel($login);

  // Check if user is the streamer of this channel (for permission inheritance)
  if ($login === $oauthChannel) {
    $isStreamerOfChannel = true;
  }

  // Also check if user is in the channel's mod list
  if (!$oauthAuthorized) {
    $pdo = get_db_connection();
    if ($pdo) {
      try {
        $stmt = $pdo->prepare("SELECT 1 FROM channel_mods WHERE channel_login = ? AND mod_username = ?");
        $stmt->execute([$login, $oauthChannel]);
        if ($stmt->fetch()) {
          // Check if mod has view_dashboard permission
          $modPermissions = $auth->getModPermissions($login, $oauthChannel);
          if (in_array('view_dashboard', $modPermissions)) {
            $oauthAuthorized = true;
            $isChannelMod = true;
          }
        }
      } catch (PDOException $e) {
        // Ignore - table might not exist yet
      }
    }
  }
}

// For admins and streamers, grant all permissions
if ($isSuperAdmin || $isStreamerOfChannel) {
  $modPermissions = array_keys(DashboardAuth::ALL_PERMISSIONS);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <title>Mod Dashboard - <?php echo htmlspecialchars($login); ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html {
      background: #0e0e10;
      min-height: 100%;
    }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #0e0e10;
      color: #efeff1;
      min-height: 100vh;
      min-height: -webkit-fill-available;
    }
    .login-screen {
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      min-height: -webkit-fill-available;
      padding: 20px;
    }
    .login-box {
      background: #18181b;
      border-radius: 8px;
      padding: 32px;
      max-width: 400px;
      width: 100%;
    }
    .login-box h1 {
      margin-bottom: 24px;
      color: #9147ff;
    }
    .login-box input {
      width: 100%;
      padding: 12px;
      margin-bottom: 16px;
      border: 1px solid #3a3a3d;
      border-radius: 4px;
      background: #0e0e10;
      color: #efeff1;
      font-size: 16px;
    }
    .login-box button {
      width: 100%;
      padding: 12px;
      background: #9147ff;
      color: white;
      border: none;
      border-radius: 4px;
      font-size: 16px;
      cursor: pointer;
    }
    .login-box button:hover { background: #772ce8; }
    .error { color: #eb0400; margin-bottom: 16px; }

    .login-divider {
      display: flex;
      align-items: center;
      margin: 20px 0;
      color: #adadb8;
      font-size: 14px;
    }
    .login-divider::before,
    .login-divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: #3a3a3d;
    }
    .login-divider span {
      padding: 0 15px;
    }
    .oauth-btn {
      width: 100%;
      padding: 12px;
      background: #9147ff;
      color: white;
      border: none;
      border-radius: 4px;
      font-size: 16px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      text-decoration: none;
      margin-bottom: 16px;
    }
    .oauth-btn:hover { background: #772ce8; }
    .oauth-btn svg { width: 20px; height: 20px; }
    .oauth-user {
      background: #26262c;
      padding: 12px;
      border-radius: 4px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .oauth-user-info {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .oauth-user-name {
      color: #bf94ff;
      font-weight: 500;
    }
    .oauth-logout {
      color: #adadb8;
      text-decoration: none;
      font-size: 12px;
    }
    .oauth-logout:hover { color: #ff4757; }

    .dashboard { display: none; }
    .dashboard.active { display: flex; flex-direction: column; height: 100vh; }

    .header {
      background: #18181b;
      padding: 16px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid #3a3a3d;
    }
    .header h1 { font-size: 20px; color: #9147ff; }
    .header .user { color: #adadb8; }
    .header .nav-links { display: flex; gap: 12px; align-items: center; }
    .header .nav-link {
      color: #bf94ff;
      text-decoration: none;
      padding: 6px 12px;
      border-radius: 4px;
      background: rgba(145, 71, 255, 0.15);
      font-size: 14px;
      transition: background 0.2s;
    }
    .header .nav-link:hover { background: rgba(145, 71, 255, 0.3); }

    .main {
      display: flex;
      flex: 1;
      overflow: hidden;
    }

    .sidebar {
      width: 280px;
      background: #18181b;
      border-right: 1px solid #3a3a3d;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .sidebar h2 {
      padding: 16px;
      font-size: 14px;
      text-transform: uppercase;
      color: #adadb8;
      border-bottom: 1px solid #3a3a3d;
    }
    .playlist-list {
      flex: 1;
      overflow-y: auto;
      padding: 8px;
    }
    .playlist-item {
      padding: 12px;
      border-radius: 4px;
      cursor: pointer;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .playlist-item:hover { background: #26262c; }
    .playlist-item.active { background: #9147ff33; border-left: 3px solid #9147ff; }
    .playlist-item .name { font-weight: 500; }
    .playlist-item .count { color: #adadb8; font-size: 12px; }

    .new-playlist-btn {
      margin: 8px;
      padding: 12px;
      background: #3a3a3d;
      border: none;
      border-radius: 4px;
      color: #efeff1;
      cursor: pointer;
      font-size: 14px;
    }
    .new-playlist-btn:hover { background: #464649; }

    .current-playlist {
      border-top: 1px solid #3a3a3d;
      padding: 16px;
      max-height: 40%;
      overflow-y: auto;
    }
    .current-playlist h3 {
      font-size: 14px;
      margin-bottom: 12px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .playlist-clips {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .playlist-clip {
      padding: 8px;
      background: #26262c;
      border-radius: 4px;
      font-size: 13px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      cursor: grab;
      transition: background 0.15s, transform 0.15s, opacity 0.15s;
    }
    .playlist-clip:active { cursor: grabbing; }
    .playlist-clip.dragging { opacity: 0.5; background: #3a3a42; }
    .playlist-clip.drag-over { background: #9147ff; transform: scale(1.02); }
    .playlist-clip .drag-handle {
      color: #666;
      margin-right: 8px;
      cursor: grab;
      user-select: none;
      font-size: 14px;
      letter-spacing: -2px;
    }
    .playlist-clip .seq { color: #9147ff; font-weight: bold; margin-right: 8px; }
    .playlist-clip .remove-btn {
      background: none;
      border: none;
      color: #eb0400;
      cursor: pointer;
      padding: 4px 8px;
    }

    .content {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .search-bar {
      padding: 16px;
      background: #18181b;
      border-bottom: 1px solid #3a3a3d;
      display: flex;
      gap: 12px;
    }
    .search-bar input {
      flex: 1;
      padding: 10px 14px;
      border: 1px solid #3a3a3d;
      border-radius: 4px;
      background: #0e0e10;
      color: #efeff1;
      font-size: 14px;
    }
    .search-bar select {
      padding: 10px 14px;
      border: 1px solid #3a3a3d;
      border-radius: 4px;
      background: #0e0e10;
      color: #efeff1;
      font-size: 14px;
      min-width: 150px;
    }

    .clips-grid {
      flex: 1;
      overflow-y: auto;
      padding: 16px;
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 12px;
      align-content: start;
    }

    .clip-card {
      background: #18181b;
      border-radius: 6px;
      padding: 12px;
      cursor: pointer;
      border: 2px solid transparent;
      transition: border-color 0.2s;
    }
    .clip-card:hover { border-color: #9147ff55; }
    .clip-card.selected { border-color: #9147ff; background: #9147ff22; }
    .clip-card .clip-header {
      display: flex;
      gap: 10px;
      align-items: flex-start;
    }
    .clip-card .thumbnail {
      width: 80px;
      height: 45px;
      border-radius: 4px;
      background: #26262c;
      object-fit: cover;
      flex-shrink: 0;
    }
    .clip-card .clip-info { flex: 1; min-width: 0; }
    .clip-card .seq { color: #9147ff; font-weight: bold; }
    .clip-card .title {
      margin: 4px 0;
      font-size: 14px;
      line-height: 1.4;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .clip-card .meta {
      font-size: 12px;
      color: #adadb8;
      display: flex;
      justify-content: space-between;
    }

    .actions-bar {
      padding: 16px;
      background: #18181b;
      border-top: 1px solid #3a3a3d;
      display: flex;
      gap: 12px;
      align-items: center;
    }
    .actions-bar button {
      padding: 10px 20px;
      border: none;
      border-radius: 4px;
      font-size: 14px;
      cursor: pointer;
    }
    .btn-primary { background: #9147ff; color: white; }
    .btn-primary:hover { background: #772ce8; }
    .btn-secondary { background: #3a3a3d; color: #efeff1; }
    .btn-secondary:hover { background: #464649; }
    .btn-danger { background: #eb0400; color: white; }
    .btn-danger:hover { background: #bf0000; }
    .selected-count { color: #adadb8; margin-left: auto; }

    .modal {
      display: none;
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.8);
      justify-content: center;
      align-items: center;
      z-index: 1000;
    }
    .modal.active { display: flex; }
    .modal-content {
      background: #18181b;
      border-radius: 8px;
      padding: 24px;
      max-width: 400px;
      width: 90%;
    }
    .modal-content h2 { margin-bottom: 16px; }
    .modal-content input {
      width: 100%;
      padding: 12px;
      margin-bottom: 16px;
      border: 1px solid #3a3a3d;
      border-radius: 4px;
      background: #0e0e10;
      color: #efeff1;
      font-size: 16px;
    }
    .modal-actions { display: flex; gap: 12px; justify-content: flex-end; }

    .loading {
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 40px;
      color: #adadb8;
    }
    .clips-pagination {
      padding: 16px;
      background: #18181b;
      border-top: 1px solid #3a3a3d;
      display: flex;
      flex-direction: column;
      gap: 12px;
      align-items: center;
    }
    .pagination-info {
      color: #adadb8;
      font-size: 14px;
    }
    .pagination-controls {
      display: flex;
      gap: 8px;
      align-items: center;
    }
    .pagination-controls .page-num {
      padding: 0 12px;
      color: #efeff1;
    }
    .pagination-controls .page-input {
      width: 60px;
      padding: 6px 8px;
      border: 1px solid #3a3a3d;
      border-radius: 4px;
      background: #0e0e10;
      color: #efeff1;
      font-size: 14px;
      text-align: center;
    }
    .pagination-controls .page-goto {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-left: 16px;
      padding-left: 16px;
      border-left: 1px solid #3a3a3d;
    }

    /* Mobile toggle button for sidebar */
    .mobile-toggle {
      display: none;
      background: #9147ff;
      border: none;
      color: white;
      padding: 8px 16px;
      border-radius: 4px;
      font-size: 14px;
      cursor: pointer;
    }

    /* Mobile responsive styles */
    @media (max-width: 768px) {
      .mobile-toggle { display: block; }

      .header {
        flex-wrap: wrap;
        gap: 12px;
        padding: 12px 16px;
      }
      .header h1 { font-size: 18px; }

      .main {
        flex-direction: column;
        position: relative;
      }

      .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        width: 100%;
        z-index: 100;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
      }
      .sidebar.open {
        transform: translateX(0);
      }
      .sidebar-close {
        display: block;
        position: absolute;
        top: 12px;
        right: 12px;
        background: #eb0400;
        border: none;
        color: white;
        padding: 8px 16px;
        border-radius: 4px;
        font-size: 14px;
        cursor: pointer;
        z-index: 101;
      }

      .search-bar {
        flex-wrap: wrap;
        padding: 12px;
        gap: 8px;
      }
      .search-bar input {
        width: 100%;
        flex: unset;
      }
      .search-bar select {
        flex: 1;
        min-width: unset;
      }

      .clips-grid {
        padding: 12px;
        grid-template-columns: 1fr;
        gap: 10px;
      }

      .clip-card {
        padding: 10px;
      }
      .clip-card .thumbnail {
        width: 70px;
        height: 40px;
      }
      .clip-card .title { font-size: 13px; }

      .actions-bar {
        flex-wrap: wrap;
        gap: 8px;
        padding: 12px;
      }
      .actions-bar button {
        padding: 10px 14px;
        font-size: 13px;
      }
      .selected-count {
        width: 100%;
        text-align: center;
        order: -1;
        margin-left: 0;
      }

      .clips-pagination {
        padding: 12px;
      }
      .pagination-controls {
        flex-wrap: wrap;
        justify-content: center;
      }
      .pagination-controls .page-goto {
        width: 100%;
        justify-content: center;
        margin-left: 0;
        margin-top: 8px;
        padding-left: 0;
        border-left: none;
        padding-top: 8px;
        border-top: 1px solid #3a3a3d;
      }

      .current-playlist {
        max-height: 35vh;
      }
    }
  </style>
</head>
<body>
  <div class="login-screen" id="loginScreen">
    <div class="login-box">
      <h1>Mod Dashboard</h1>
      <div class="error" id="loginError" style="display:none;"></div>

      <?php if ($currentUser): ?>
      <!-- User is logged in via OAuth -->
      <div class="oauth-user">
        <div class="oauth-user-info">
          <span>Logged in as</span>
          <span class="oauth-user-name"><?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['login']) ?></span>
        </div>
        <a href="/auth/logout.php?return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="oauth-logout">Logout</a>
      </div>

      <?php if ($oauthAuthorized): ?>
      <!-- Auto-enter for authorized user (own channel or mod list) -->
      <?php if ($isChannelMod): ?>
      <p style="color:#adadb8;margin-bottom:16px;">Accessing <strong><?= htmlspecialchars($login) ?></strong>'s dashboard as mod...</p>
      <?php else: ?>
      <p style="color:#adadb8;margin-bottom:16px;">Accessing your channel dashboard...</p>
      <?php endif; ?>
      <?php else: ?>
      <!-- Not authorized - show channels they can access -->
      <p style="color:#adadb8;margin-bottom:16px;">You don't have mod access to <strong><?= htmlspecialchars($login) ?></strong>.</p>
      <p style="color:#666;font-size:13px;margin-bottom:16px;">The streamer needs to add your username to their mod list in their dashboard settings.</p>
      <a href="/channels" style="display: block; text-align: center; padding: 12px; background: #3a3a3d; color: white; border-radius: 4px; text-decoration: none; margin-bottom: 12px;">View My Channels</a>
      <input type="hidden" id="channelInput" value="<?= htmlspecialchars($login) ?>">
      <input type="hidden" id="keyInput" value="">
      <?php endif; ?>

      <?php else: ?>
      <!-- Not logged in - show OAuth button -->
      <a href="/auth/login.php?return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="oauth-btn">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.64 5.93h1.43v4.28h-1.43m3.93-4.28H17v4.28h-1.43M7 2L3.43 5.57v12.86h4.28V22l3.58-3.57h2.85L20.57 12V2m-1.43 9.29l-2.85 2.85h-2.86l-2.5 2.5v-2.5H7.71V3.43h11.43z"/></svg>
        Login with Twitch
      </a>
      <p style="color: #666; font-size: 12px; margin-top: 16px;">Login with your Twitch account to access channels where you're a mod.</p>
      <input type="hidden" id="channelInput" value="<?= htmlspecialchars($login && $login !== 'default' ? $login : '') ?>">
      <input type="hidden" id="keyInput" value="">
      <?php endif; ?>
    </div>
  </div>

  <div class="dashboard" id="dashboard">
    <div class="header">
      <h1>Mod Dashboard</h1>
      <div class="nav-links">
        <a href="#" id="manageClipsLink" class="nav-link" style="display:none;">Manage Clips</a>
        <a href="#" id="searchClipsLink" class="nav-link" style="display:none;">Search Clips</a>
        <button class="mobile-toggle" onclick="toggleSidebar()">Playlists</button>
      </div>
      <div class="user" style="display: flex; align-items: center; gap: 12px;">
        <select id="channelSwitcher" onchange="switchChannel(this.value)" style="background: #26262c; color: #efeff1; border: 1px solid #3a3a3d; border-radius: 4px; padding: 6px 10px; font-size: 14px; cursor: pointer;">
          <option value="<?php echo htmlspecialchars($login); ?>" selected><?php echo htmlspecialchars($login); ?></option>
        </select>
        <?php if ($isSuperAdmin): ?>
        <span style="background: #eb0400; padding: 2px 6px; border-radius: 4px; font-size: 10px;">SUPER ADMIN</span>
        <?php endif; ?>
        <a href="/auth/logout.php" style="color: #adadb8; text-decoration: none; font-size: 12px;">Logout</a>
      </div>
    </div>

    <?php if ($isSuperAdmin): ?>
    <div class="admin-bar" style="background: linear-gradient(90deg, #eb0400, #9147ff); padding: 10px 16px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 14px;">
      <span style="font-weight: bold;">Quick Access:</span>
      <input type="text" id="adminChannelInput" placeholder="Channel name..." style="padding: 6px 10px; border-radius: 4px; border: none; background: rgba(0,0,0,0.3); color: white; width: 150px; font-size: 14px;">
      <button onclick="window.location.href='/mod/'+encodeURIComponent(document.getElementById('adminChannelInput').value.trim().toLowerCase())" style="padding: 6px 12px; background: rgba(0,0,0,0.3); border: none; color: white; border-radius: 4px; cursor: pointer; font-size: 14px;">Go</button>
      <a href="/dashboard/<?php echo urlencode($login); ?>" style="color: white; text-decoration: none; padding: 6px 12px; background: rgba(0,0,0,0.2); border-radius: 4px;">Streamer Dashboard</a>
      <a href="/auth/logout.php" style="margin-left: auto; color: white; text-decoration: none; opacity: 0.8;">Logout</a>
    </div>
    <?php endif; ?>

    <div class="main">
      <div class="sidebar" id="sidebar">
        <button class="sidebar-close" onclick="toggleSidebar()" style="display:none;">Close</button>
        <h2>Playlists</h2>
        <div class="playlist-list" id="playlistList">
          <div class="loading">Loading...</div>
        </div>
        <button class="new-playlist-btn" onclick="showNewPlaylistModal()">+ New Playlist</button>

        <div class="current-playlist" id="currentPlaylist" style="display:none;">
          <h3>
            <span id="currentPlaylistName">Playlist</span>
            <div style="display:flex;gap:4px;">
              <button class="btn-primary" style="padding:6px 10px;font-size:12px;" onclick="playPlaylist()" title="Play All">▶ Play</button>
              <button class="btn-secondary" style="padding:6px 10px;font-size:12px;background:#c9302c;" onclick="stopPlaylist()" title="Stop Playlist">⏹ Stop</button>
              <button class="btn-secondary" style="padding:6px 8px;font-size:12px;" onclick="shufflePlaylist()" title="Shuffle">🔀</button>
              <button class="btn-secondary" style="padding:6px 8px;font-size:12px;" onclick="showRenameModal()" title="Rename">✏️</button>
              <button class="btn-danger" style="padding:6px 8px;font-size:12px;" onclick="confirmDeletePlaylist()" title="Delete">🗑️</button>
            </div>
          </h3>
          <div class="playlist-clips" id="playlistClips"></div>
        </div>
      </div>

      <div class="content">
        <div class="search-bar">
          <input type="text" id="searchInput" placeholder="Search titles..." oninput="filterClips()">
          <input type="text" id="creatorFilter" placeholder="Filter by clipper..." oninput="filterClips()" style="max-width:180px;">
          <select id="gameFilter" onchange="filterClips()">
            <option value="">All Games</option>
          </select>
        </div>

        <div class="clips-grid" id="clipsGrid">
          <div class="loading">Loading clips...</div>
        </div>

        <div class="actions-bar">
          <button class="btn-primary" onclick="addSelectedToPlaylist()">Add to Playlist</button>
          <button class="btn-secondary" onclick="playSelected()" title="Plays the first selected clip (Tip: double-click any clip to play it instantly!)">Play One</button>
          <span class="selected-count"><span id="selectedCount">0</span> selected</span>
        </div>
      </div>
    </div>
  </div>

  <div class="modal" id="newPlaylistModal">
    <div class="modal-content">
      <h2>New Playlist</h2>
      <input type="text" id="newPlaylistName" placeholder="Playlist name" onkeypress="if(event.key==='Enter')createPlaylist()">
      <div class="modal-actions">
        <button class="btn-secondary" onclick="closeModal()">Cancel</button>
        <button class="btn-primary" onclick="createPlaylist()">Create</button>
      </div>
    </div>
  </div>

  <div class="modal" id="renamePlaylistModal">
    <div class="modal-content">
      <h2>Rename Playlist</h2>
      <input type="text" id="renamePlaylistName" placeholder="New name" onkeypress="if(event.key==='Enter')renamePlaylist()">
      <div class="modal-actions">
        <button class="btn-secondary" onclick="closeModal()">Cancel</button>
        <button class="btn-primary" onclick="renamePlaylist()">Rename</button>
      </div>
    </div>
  </div>

  <div class="modal" id="confirmDeleteModal">
    <div class="modal-content">
      <h2>Delete Playlist?</h2>
      <p style="margin-bottom:16px;color:#adadb8;">Are you sure you want to delete "<span id="deletePlaylistName"></span>"? This cannot be undone.</p>
      <div class="modal-actions">
        <button class="btn-secondary" onclick="closeModal()">Cancel</button>
        <button class="btn-danger" onclick="deletePlaylist()">Delete</button>
      </div>
    </div>
  </div>

  <script>
    let LOGIN = '';  // Set on login
    const API_BASE = '';
    let adminKey = '';
    let allClips = [];
    let playlists = [];
    let currentPlaylist = null;
    let selectedClips = new Set();
    let games = {};
    let gameNames = {};
    let currentPage = 1;
    let totalPages = 1;
    let totalClips = 0;
    let perPage = 200;
    let isLoading = false;

    // OAuth state from PHP
    const oauthAuthorized = <?= $oauthAuthorized ? 'true' : 'false' ?>;
    const oauthChannel = <?= json_encode($oauthChannel) ?>;
    const oauthLogin = <?= json_encode($login) ?>;
    const userPermissions = <?= json_encode($modPermissions) ?>;
    const isChannelMod = <?= $isChannelMod ? 'true' : 'false' ?>;

    // Permission checking helper
    function hasPermission(perm) {
      return userPermissions.includes(perm);
    }

    // Check for auto-login from URL parameters or OAuth
    const urlParams = new URLSearchParams(window.location.search);
    const urlLogin = urlParams.get('login');
    const urlKey = urlParams.get('key');

    document.addEventListener('DOMContentLoaded', async () => {
      // OAuth auto-login for own channel
      if (oauthAuthorized && oauthChannel) {
        try {
          // For OAuth users accessing their own channel, use 'oauth' as the key
          // The backend will need to recognize this and validate the session
          const res = await fetch(`${API_BASE}/playlist_api.php?action=list&login=${encodeURIComponent(oauthChannel)}&oauth=1`, { credentials: 'same-origin' });
          const data = await res.json();

          if (!data.error) {
            LOGIN = oauthChannel;
            adminKey = 'oauth';  // Special marker for OAuth auth
            document.querySelector('.header .user').textContent = LOGIN;
            document.getElementById('loginScreen').style.display = 'none';
            document.getElementById('dashboard').classList.add('active');
            loadDashboard();
            return;
          }
        } catch (err) {
          console.error('OAuth auto-login failed:', err);
        }
      }

      // URL parameter auto-login (legacy)
      if (urlLogin && urlKey) {
        try {
          const res = await fetch(`${API_BASE}/playlist_api.php?action=list&login=${encodeURIComponent(urlLogin)}&key=${encodeURIComponent(urlKey)}`, { credentials: 'same-origin' });
          const data = await res.json();

          if (!data.error) {
            LOGIN = urlLogin;
            adminKey = urlKey;
            document.querySelector('.header .user').textContent = LOGIN;
            document.getElementById('loginScreen').style.display = 'none';
            document.getElementById('dashboard').classList.add('active');
            loadDashboard();
          }
        } catch (err) {
          console.error('Auto-login failed:', err);
        }
      }
    });

    // Mobile sidebar toggle
    function toggleSidebar() {
      const sidebar = document.getElementById('sidebar');
      const closeBtn = sidebar.querySelector('.sidebar-close');
      sidebar.classList.toggle('open');
      closeBtn.style.display = sidebar.classList.contains('open') ? 'block' : 'none';
    }

    // Login - handle Enter on both inputs (only if login form exists)
    document.getElementById('channelInput')?.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') document.getElementById('keyInput')?.focus();
    });
    document.getElementById('keyInput')?.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') login();
    });

    async function login() {
      const channelInput = document.getElementById('channelInput').value.trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
      adminKey = document.getElementById('keyInput').value.trim();

      if (!channelInput) {
        document.getElementById('loginError').textContent = 'Please enter a channel name';
        document.getElementById('loginError').style.display = 'block';
        return;
      }
      if (!adminKey) {
        document.getElementById('loginError').textContent = 'Please enter a streamer key';
        document.getElementById('loginError').style.display = 'block';
        return;
      }

      try {
        // Test the key by fetching playlists for the entered channel
        const res = await fetch(`${API_BASE}/playlist_api.php?action=list&login=${encodeURIComponent(channelInput)}&key=${encodeURIComponent(adminKey)}`, { credentials: 'same-origin' });
        const data = await res.json();

        if (data.error) {
          document.getElementById('loginError').textContent = data.error === 'forbidden'
            ? 'Invalid key for this channel'
            : data.error;
          document.getElementById('loginError').style.display = 'block';
          return;
        }

        // Success - set LOGIN and update UI
        LOGIN = channelInput;
        document.querySelector('.header .user').textContent = LOGIN;
        document.getElementById('loginScreen').style.display = 'none';
        document.getElementById('dashboard').classList.add('active');

        // Show and configure navigation links
        const manageLink = document.getElementById('manageClipsLink');
        const searchLink = document.getElementById('searchClipsLink');
        manageLink.href = `/manage/${encodeURIComponent(LOGIN)}`;
        manageLink.style.display = 'inline-block';
        searchLink.href = `/search/${encodeURIComponent(LOGIN)}`;
        searchLink.style.display = 'inline-block';

        loadDashboard();
      } catch (err) {
        document.getElementById('loginError').textContent = 'Connection error';
        document.getElementById('loginError').style.display = 'block';
      }
    }

    async function loadDashboard() {
      await Promise.all([loadClips(), loadPlaylists(), loadGames()]);
      applyPermissions();
    }

    // Apply permission-based visibility to UI elements
    function applyPermissions() {
      // Manage playlists permission - controls create/edit/delete
      const canManagePlaylists = hasPermission('manage_playlists');
      const newPlaylistBtn = document.querySelector('.new-playlist-btn');
      if (newPlaylistBtn) {
        newPlaylistBtn.style.display = canManagePlaylists ? 'block' : 'none';
      }

      // Block clips permission - show/hide block buttons in clip grid
      // This will be handled in renderClips function

      // If mod has no manage_playlists permission, hide add to playlist button
      const addToPlaylistBtn = document.querySelector('.actions-bar .btn-primary');
      if (addToPlaylistBtn && !canManagePlaylists) {
        addToPlaylistBtn.style.display = 'none';
      }

      // Load accessible channels for the channel switcher dropdown
      loadAccessibleChannels();
    }

    // Load channels the user can access for the channel switcher
    async function loadAccessibleChannels() {
      try {
        const res = await fetch(`${API_BASE}/dashboard_api.php?action=get_accessible_channels`, { credentials: 'same-origin' });
        const data = await res.json();

        if (data.success && data.channels && data.channels.length > 0) {
          const select = document.getElementById('channelSwitcher');
          // Clear existing options and build safely to prevent XSS
          select.innerHTML = '';
          data.channels.forEach(ch => {
            const option = document.createElement('option');
            option.value = ch.login;
            option.selected = (ch.login === LOGIN);
            const suffix = ch.role === 'streamer' ? ' (Your Channel)' :
                          ch.role === 'mod' ? ' (Mod)' : '';
            option.textContent = ch.login + suffix;
            select.appendChild(option);
          });
        }
      } catch (e) {
        console.error('Error loading accessible channels:', e);
      }
    }

    // Switch to a different channel
    function switchChannel(login) {
      if (login && login !== LOGIN) {
        window.location.href = `/mod/${encodeURIComponent(login)}`;
      }
    }

    // Helper to build auth params for API calls
    function getAuthParams() {
      if (adminKey === 'oauth') {
        return `oauth=1`;
      }
      return `key=${encodeURIComponent(adminKey)}`;
    }

    async function loadClips(page = 1) {
      if (isLoading) return;
      isLoading = true;

      const search = document.getElementById('searchInput').value.trim();
      const creator = document.getElementById('creatorFilter').value.trim();
      const gameId = document.getElementById('gameFilter').value;

      try {
        // Build query params
        let url = `${API_BASE}/clips_api.php?action=list&login=${LOGIN}&${getAuthParams()}&page=${page}&per_page=${perPage}`;
        if (search) url += `&q=${encodeURIComponent(search)}`;
        if (creator) url += `&creator=${encodeURIComponent(creator)}`;
        if (gameId) url += `&game_id=${encodeURIComponent(gameId)}`;

        const res = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();

        if (data.error) {
          document.getElementById('clipsGrid').innerHTML = `<div class="loading">Error: ${data.error}</div>`;
          return;
        }

        allClips = data.clips || [];
        currentPage = data.page || 1;
        totalPages = data.total_pages || 1;
        totalClips = data.total || 0;

        renderClips();
        renderPagination();
      } catch (err) {
        document.getElementById('clipsGrid').innerHTML = '<div class="loading">Error loading clips</div>';
      } finally {
        isLoading = false;
      }
    }

    async function loadGames() {
      try {
        const res = await fetch(`${API_BASE}/clips_api.php?action=games&login=${LOGIN}&${getAuthParams()}`, { credentials: 'same-origin' });
        const data = await res.json();

        const gameFilter = document.getElementById('gameFilter');
        gameFilter.innerHTML = '<option value="">All Games</option>';

        if (data.games) {
          data.games.forEach(g => {
            const opt = document.createElement('option');
            opt.value = g.game_id;
            opt.textContent = `${g.name} (${g.count})`;
            gameNames[g.game_id] = g.name;
            gameFilter.appendChild(opt);
          });
        }
      } catch (e) {
        console.log('Could not fetch games:', e);
      }
    }

    async function loadPlaylists() {
      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=list&login=${LOGIN}&${getAuthParams()}`, { credentials: 'same-origin' });
        const data = await res.json();
        playlists = data.playlists || [];
        renderPlaylists();
      } catch (err) {
        document.getElementById('playlistList').innerHTML = '<div class="loading">Error loading playlists</div>';
      }
    }

    function renderClips() {
      const grid = document.getElementById('clipsGrid');

      if (allClips.length === 0) {
        grid.innerHTML = '<div class="loading">No clips found</div>';
        return;
      }

      grid.innerHTML = allClips.map(c => `
        <div class="clip-card ${selectedClips.has(c.seq) ? 'selected' : ''}"
             onclick="toggleClip(${c.seq})"
             ondblclick="playClip(${c.seq})">
          <div class="clip-header">
            <img class="thumbnail" src="${c.thumbnail_url || ''}" alt="" loading="lazy" onerror="this.style.display='none'">
            <div class="clip-info">
              <div class="seq">#${c.seq}</div>
              <div class="title">${escapeHtml(c.title || '(no title)')}</div>
            </div>
          </div>
          <div class="meta">
            <span>${c.duration ? Math.round(c.duration) + 's' : ''}</span>
            <span>${c.view_count ? Number(c.view_count).toLocaleString() + ' views' : ''}</span>
            <span>${c.creator_name ? '✂️ ' + escapeHtml(c.creator_name) : ''}</span>
          </div>
          <div class="meta" style="margin-top:4px;">
            <span>${c.game_id ? (gameNames[c.game_id] || c.game_id) : ''}</span>
          </div>
        </div>
      `).join('');
    }

    function renderPagination() {
      let paginationHtml = `<div class="pagination-info">Showing ${allClips.length} of ${totalClips.toLocaleString()} clips (Page ${currentPage} of ${totalPages})</div>`;

      if (totalPages > 1) {
        paginationHtml += '<div class="pagination-controls">';
        if (currentPage > 1) {
          paginationHtml += `<button onclick="goToPage(1)" class="btn-secondary">First</button>`;
          paginationHtml += `<button onclick="goToPage(${currentPage - 1})" class="btn-secondary">Prev</button>`;
        }
        paginationHtml += `<span class="page-num">Page ${currentPage} of ${totalPages}</span>`;
        if (currentPage < totalPages) {
          paginationHtml += `<button onclick="goToPage(${currentPage + 1})" class="btn-secondary">Next</button>`;
          paginationHtml += `<button onclick="goToPage(${totalPages})" class="btn-secondary">Last</button>`;
        }
        paginationHtml += `<div class="page-goto">
          <span>Go to:</span>
          <input type="number" class="page-input" id="pageInput" min="1" max="${totalPages}" value="${currentPage}" onkeypress="if(event.key==='Enter')jumpToPage()">
          <button class="btn-secondary" onclick="jumpToPage()">Go</button>
        </div>`;
        paginationHtml += '</div>';
      }

      // Insert pagination after clips grid
      let paginationEl = document.getElementById('clipsPagination');
      if (!paginationEl) {
        paginationEl = document.createElement('div');
        paginationEl.id = 'clipsPagination';
        paginationEl.className = 'clips-pagination';
        document.getElementById('clipsGrid').after(paginationEl);
      }
      paginationEl.innerHTML = paginationHtml;
    }

    function goToPage(page) {
      if (page < 1 || page > totalPages || page === currentPage) return;
      loadClips(page);
    }

    function jumpToPage() {
      const input = document.getElementById('pageInput');
      if (!input) return;
      const page = parseInt(input.value);
      if (!isNaN(page) && page >= 1 && page <= totalPages) {
        goToPage(page);
      } else {
        input.value = currentPage;
      }
    }

    function renderPlaylists() {
      const list = document.getElementById('playlistList');
      if (playlists.length === 0) {
        list.innerHTML = '<div class="loading">No playlists yet</div>';
        return;
      }

      list.innerHTML = playlists.map(p => `
        <div class="playlist-item ${currentPlaylist?.id === p.id ? 'active' : ''}"
             onclick="selectPlaylist(${p.id})">
          <span class="name">${escapeHtml(p.name)}</span>
          <span class="count">${p.clip_count || 0}</span>
        </div>
      `).join('');
    }

    function toggleClip(seq) {
      if (selectedClips.has(seq)) {
        selectedClips.delete(seq);
      } else {
        selectedClips.add(seq);
      }
      document.getElementById('selectedCount').textContent = selectedClips.size;
      renderClips();
    }

    let filterTimeout = null;
    function filterClips() {
      // Debounce search to avoid too many API calls
      clearTimeout(filterTimeout);
      filterTimeout = setTimeout(() => {
        currentPage = 1;
        loadClips(1);
      }, 300);
    }

    async function selectPlaylist(id) {
      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=get&login=${LOGIN}&${getAuthParams()}&id=${id}`, { credentials: 'same-origin' });
        const data = await res.json();
        currentPlaylist = data.playlist;

        document.getElementById('currentPlaylistName').textContent = currentPlaylist.name;
        document.getElementById('currentPlaylist').style.display = 'block';

        // Hide edit controls for mods without manage_playlists permission
        const canManagePlaylists = hasPermission('manage_playlists');
        const playlistControls = document.querySelector('#currentPlaylist h3 > div');
        if (playlistControls) {
          // Keep play button visible, hide shuffle/rename/delete for non-managers
          const buttons = playlistControls.querySelectorAll('button');
          buttons.forEach(btn => {
            const title = btn.getAttribute('title') || '';
            // Only show edit buttons for those with manage_playlists
            if (title === 'Shuffle' || title === 'Rename' || title === 'Delete') {
              btn.style.display = canManagePlaylists ? 'inline-block' : 'none';
            }
          });
        }

        renderPlaylistClips();
        renderPlaylists();
      } catch (err) {
        console.error('Error loading playlist:', err);
      }
    }

    function formatDuration(seconds) {
      if (!seconds || seconds <= 0) return '0:00';
      const mins = Math.floor(seconds / 60);
      const secs = Math.round(seconds % 60);
      return mins > 0 ? `${mins}:${secs.toString().padStart(2, '0')}` : `0:${secs.toString().padStart(2, '0')}`;
    }

    function renderPlaylistClips() {
      const container = document.getElementById('playlistClips');
      const clips = currentPlaylist?.clips || [];
      const totalDuration = currentPlaylist?.total_duration || 0;
      const canManagePlaylists = hasPermission('manage_playlists');

      let html = '';
      if (clips.length > 0) {
        // Show total duration header
        const reorderText = canManagePlaylists ? ' - drag to reorder' : '';
        html += `<div style="margin-bottom:8px;padding:6px;background:#1a1a1d;border-radius:4px;font-size:12px;color:#adadb8;">
          Total: ${formatDuration(totalDuration)} (${clips.length} clips)${reorderText}
        </div>`;

        html += clips.map((c, i) => `
          <div class="playlist-clip" draggable="${canManagePlaylists}" data-index="${i}" data-seq="${c.seq}">
            ${canManagePlaylists ? '<span class="drag-handle" title="Drag to reorder">⋮⋮</span>' : ''}
            <div style="flex:1;min-width:0;">
              <span class="seq">#${c.seq}</span>
              <span style="color:#adadb8;font-size:11px;margin-left:4px;">${c.duration ? formatDuration(c.duration) : ''}</span>
              <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml((c.title || '').substring(0, 40))}</div>
            </div>
            ${canManagePlaylists ? `<button class="remove-btn" onclick="removeFromPlaylist(${c.seq})">×</button>` : ''}
          </div>
        `).join('');
      } else {
        html = '<div class="loading">Empty playlist</div>';
      }

      container.innerHTML = html;
      if (canManagePlaylists) {
        initPlaylistDragDrop();
      }
    }

    async function addSelectedToPlaylist() {
      if (!currentPlaylist) {
        alert('Select a playlist first');
        return;
      }
      if (selectedClips.size === 0) {
        alert('Select some clips first');
        return;
      }

      const seqs = Array.from(selectedClips);
      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=add_clips&login=${LOGIN}&${getAuthParams()}&id=${currentPlaylist.id}&seqs=${seqs.join(',')}`, { credentials: 'same-origin' });
        const data = await res.json();

        if (data.success) {
          selectedClips.clear();
          document.getElementById('selectedCount').textContent = 0;
          await selectPlaylist(currentPlaylist.id);
          await loadPlaylists();
          renderClips();
        }
      } catch (err) {
        console.error('Error adding clips:', err);
      }
    }

    async function removeFromPlaylist(seq) {
      if (!currentPlaylist) return;

      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=remove_clip&login=${LOGIN}&${getAuthParams()}&id=${currentPlaylist.id}&seq=${seq}`, { credentials: 'same-origin' });
        const data = await res.json();

        if (data.success) {
          await selectPlaylist(currentPlaylist.id);
          await loadPlaylists();
        }
      } catch (err) {
        console.error('Error removing clip:', err);
      }
    }

    async function playClip(seq) {
      try {
        const url = `${API_BASE}/pclip.php?login=${LOGIN}&${getAuthParams()}&seq=${seq}`;
        console.log('Playing clip:', url);
        const res = await fetch(url, { credentials: 'same-origin' });
        const text = await res.text();
        console.log('pclip response:', res.status, text);
      } catch (err) {
        console.error('Error playing clip:', err);
      }
    }

    async function playSelected() {
      if (selectedClips.size === 0) return;
      const first = Array.from(selectedClips)[0];
      await playClip(first);
    }

    async function playPlaylist() {
      if (!currentPlaylist) return;

      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=play&login=${LOGIN}&${getAuthParams()}&id=${currentPlaylist.id}`, { credentials: 'same-origin' });
        const data = await res.json();
        console.log('Playlist play response:', data);
        if (data.error) {
          alert('Error: ' + data.error);
        } else if (data.success && data.first_clip) {
          // Force play the first clip immediately
          await playClip(data.first_clip.seq);
          alert(data.message);
        } else if (data.message) {
          alert(data.message);
        }
      } catch (err) {
        console.error('Error playing playlist:', err);
        alert('Error playing playlist: ' + err.message);
      }
    }

    async function stopPlaylist() {
      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=stop&login=${LOGIN}&${getAuthParams()}`, { credentials: 'same-origin' });
        const data = await res.json();
        console.log('Playlist stop response:', data);
        if (data.error) {
          alert('Error: ' + data.error);
        } else {
          alert('Playlist stopped. Player will return to normal mode after current clip.');
        }
      } catch (err) {
        console.error('Error stopping playlist:', err);
        alert('Error stopping playlist: ' + err.message);
      }
    }

    function showNewPlaylistModal() {
      document.getElementById('newPlaylistModal').classList.add('active');
      document.getElementById('newPlaylistName').value = '';
      document.getElementById('newPlaylistName').focus();
    }

    function closeModal() {
      document.querySelectorAll('.modal').forEach(m => m.classList.remove('active'));
    }

    async function createPlaylist() {
      const name = document.getElementById('newPlaylistName').value.trim();
      if (!name) return;

      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=create&login=${LOGIN}&${getAuthParams()}&name=${encodeURIComponent(name)}`, { credentials: 'same-origin' });
        const data = await res.json();

        if (data.success) {
          closeModal();
          await loadPlaylists();
          if (data.id) {
            await selectPlaylist(data.id);
          }
        }
      } catch (err) {
        console.error('Error creating playlist:', err);
      }
    }

    function showRenameModal() {
      if (!currentPlaylist) return;
      document.getElementById('renamePlaylistName').value = currentPlaylist.name;
      document.getElementById('renamePlaylistModal').classList.add('active');
      document.getElementById('renamePlaylistName').focus();
      document.getElementById('renamePlaylistName').select();
    }

    async function renamePlaylist() {
      if (!currentPlaylist) return;
      const newName = document.getElementById('renamePlaylistName').value.trim();
      if (!newName) return;

      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=rename&login=${LOGIN}&${getAuthParams()}&id=${currentPlaylist.id}&name=${encodeURIComponent(newName)}`, { credentials: 'same-origin' });
        const data = await res.json();

        if (data.success) {
          closeModal();
          currentPlaylist.name = newName;
          document.getElementById('currentPlaylistName').textContent = newName;
          await loadPlaylists();
        } else if (data.error) {
          alert(data.error);
        }
      } catch (err) {
        console.error('Error renaming playlist:', err);
      }
    }

    function confirmDeletePlaylist() {
      if (!currentPlaylist) return;
      document.getElementById('deletePlaylistName').textContent = currentPlaylist.name;
      document.getElementById('confirmDeleteModal').classList.add('active');
    }

    async function deletePlaylist() {
      if (!currentPlaylist) return;

      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=delete&login=${LOGIN}&${getAuthParams()}&id=${currentPlaylist.id}`, { credentials: 'same-origin' });
        const data = await res.json();

        if (data.success) {
          closeModal();
          currentPlaylist = null;
          document.getElementById('currentPlaylist').style.display = 'none';
          await loadPlaylists();
        } else if (data.error) {
          alert(data.error);
        }
      } catch (err) {
        console.error('Error deleting playlist:', err);
      }
    }

    // Drag and drop reordering
    let draggedItem = null;
    let draggedIndex = null;

    function initPlaylistDragDrop() {
      const container = document.getElementById('playlistClips');
      const items = container.querySelectorAll('.playlist-clip[draggable="true"]');

      items.forEach(item => {
        item.addEventListener('dragstart', handleDragStart);
        item.addEventListener('dragend', handleDragEnd);
        item.addEventListener('dragover', handleDragOver);
        item.addEventListener('dragenter', handleDragEnter);
        item.addEventListener('dragleave', handleDragLeave);
        item.addEventListener('drop', handleDrop);
      });
    }

    function handleDragStart(e) {
      draggedItem = this;
      draggedIndex = parseInt(this.dataset.index);
      this.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', draggedIndex);
    }

    function handleDragEnd(e) {
      this.classList.remove('dragging');
      document.querySelectorAll('.playlist-clip').forEach(item => {
        item.classList.remove('drag-over');
      });
      draggedItem = null;
      draggedIndex = null;
    }

    function handleDragOver(e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
    }

    function handleDragEnter(e) {
      e.preventDefault();
      if (this !== draggedItem) {
        this.classList.add('drag-over');
      }
    }

    function handleDragLeave(e) {
      this.classList.remove('drag-over');
    }

    async function handleDrop(e) {
      e.preventDefault();
      this.classList.remove('drag-over');

      if (this === draggedItem) return;

      const fromIndex = draggedIndex;
      const toIndex = parseInt(this.dataset.index);

      if (fromIndex === toIndex) return;

      // Reorder locally first for instant feedback
      const clips = currentPlaylist.clips;
      const [moved] = clips.splice(fromIndex, 1);
      clips.splice(toIndex, 0, moved);
      renderPlaylistClips();

      // Then update server
      await reorderPlaylist(fromIndex, toIndex);
    }

    async function reorderPlaylist(fromIndex, toIndex) {
      if (!currentPlaylist) return;

      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=reorder&login=${LOGIN}&${getAuthParams()}&id=${currentPlaylist.id}&from=${fromIndex}&to=${toIndex}`, { credentials: 'same-origin' });
        const data = await res.json();

        if (!data.success) {
          console.error('Reorder failed:', data.error);
          // Reload to get correct order from server
          await selectPlaylist(currentPlaylist.id);
        }
      } catch (err) {
        console.error('Error reordering playlist:', err);
        await selectPlaylist(currentPlaylist.id);
      }
    }

    async function shufflePlaylist() {
      if (!currentPlaylist) {
        alert('No playlist selected');
        return;
      }
      if (!currentPlaylist.clips || currentPlaylist.clips.length < 2) {
        alert('Need at least 2 clips to shuffle');
        return;
      }

      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=shuffle&login=${LOGIN}&${getAuthParams()}&id=${currentPlaylist.id}`, { credentials: 'same-origin' });
        const data = await res.json();
        console.log('Shuffle response:', data);

        if (data.success) {
          // Reload playlist to get new order
          await selectPlaylist(currentPlaylist.id);
        } else if (data.error) {
          alert('Error: ' + data.error);
        }
      } catch (err) {
        console.error('Error shuffling playlist:', err);
        alert('Error shuffling playlist: ' + err.message);
      }
    }

    function escapeHtml(str) {
      const div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    }
  </script>
</body>
</html>
