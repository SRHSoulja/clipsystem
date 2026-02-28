<?php
/**
 * remote.php - Remote Control for ClipSystem Player
 *
 * OAuth protected page for mods/streamers to control playback:
 * - See now playing info
 * - Skip/prev/stop playback
 * - Quick play clips by seq or search
 * - Control playlists
 */

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

header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Content-Security-Policy: upgrade-insecure-requests");

$pdo = get_db_connection();
$currentUser = getCurrentUser();

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");

$oauthAuthorized = false;
$oauthChannel = '';
$isSuperAdmin = false;
$isChannelMod = false;
$modPermissions = [];
$isStreamerOfChannel = false;
$isInModListButNoViewPerm = false;

$auth = new DashboardAuth();

if ($currentUser) {
  $oauthChannel = strtolower($currentUser['login']);
  $isSuperAdmin = isSuperAdmin();

  if (!$login || $login === 'default') {
    $login = $oauthChannel;
  }

  if ($isSuperAdmin) {
    $oauthAuthorized = true;
  }

  if ($login === $oauthChannel && $pdo) {
    try {
      $stmt = $pdo->prepare("SELECT 1 FROM clips WHERE login = ? LIMIT 1");
      $stmt->execute([$login]);
      if ($stmt->fetch()) {
        $isStreamerOfChannel = true;
        $oauthAuthorized = true;
      }
    } catch (PDOException $e) {}
  }

  if (!$oauthAuthorized && $pdo) {
    try {
      $stmt = $pdo->prepare("SELECT 1 FROM channel_mods WHERE channel_login = ? AND mod_username = ?");
      $stmt->execute([$login, $oauthChannel]);
      if ($stmt->fetch()) {
        $modPermissions = $auth->getModPermissions($login, $oauthChannel);
        if (in_array('view_dashboard', $modPermissions)) {
          $oauthAuthorized = true;
          $isChannelMod = true;
        } else {
          $isInModListButNoViewPerm = true;
        }
      }
    } catch (PDOException $e) {}
  }
}

if ($isSuperAdmin || $isStreamerOfChannel) {
  $modPermissions = array_keys(DashboardAuth::ALL_PERMISSIONS);
}

if ($currentUser && $login === $oauthChannel && !$isStreamerOfChannel && !$isSuperAdmin) {
  header('Location: /channels?not_archived=1');
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <title>Remote Control - <?php echo htmlspecialchars($login); ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { background: #0e0e10; min-height: 100%; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #0e0e10;
      color: #efeff1;
      min-height: 100vh;
    }

    /* Login screen */
    .login-screen {
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: calc(100vh - 56px);
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
    .error { color: #eb0400; margin-bottom: 16px; }
    .oauth-user {
      background: #26262c;
      padding: 12px;
      border-radius: 4px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .oauth-user-info { display: flex; align-items: center; gap: 10px; }
    .oauth-user-name { color: #bf94ff; font-weight: 500; }
    .oauth-logout { color: #adadb8; text-decoration: none; font-size: 12px; }
    .oauth-logout:hover { color: #ff4757; }
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

    /* Remote control container */
    .remote {
      display: none;
      max-width: 500px;
      margin: 0 auto;
      padding: 16px;
      padding-bottom: 80px;
    }
    .remote.active { display: block; }

    .remote-header {
      text-align: center;
      margin-bottom: 16px;
      padding: 8px 0;
    }
    .remote-header h1 {
      font-size: 18px;
      color: #efeff1;
      font-weight: 600;
    }
    .remote-header h1 span { color: #9147ff; }
    .remote-header .channel-name {
      font-size: 14px;
      color: #adadb8;
      margin-top: 4px;
    }

    /* Section cards */
    .section {
      background: #18181b;
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 12px;
    }
    .section h3 {
      font-size: 12px;
      font-weight: 600;
      color: #adadb8;
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-bottom: 12px;
    }

    /* Now Playing */
    .now-playing {
      border: 2px solid transparent;
      transition: border-color 0.3s, box-shadow 0.3s;
    }
    .now-playing.playing {
      border-color: #9147ff;
      box-shadow: 0 0 20px rgba(145, 71, 255, 0.15);
    }
    .now-playing-content {
      display: flex;
      gap: 14px;
      align-items: center;
    }
    .now-playing-thumb {
      width: 120px;
      height: 68px;
      border-radius: 6px;
      object-fit: cover;
      background: #26262c;
      flex-shrink: 0;
    }
    .now-playing-info { flex: 1; min-width: 0; }
    .now-playing-seq {
      font-size: 13px;
      color: #9147ff;
      font-weight: 600;
      margin-bottom: 4px;
    }
    .now-playing-title {
      font-size: 15px;
      font-weight: 500;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .now-playing-empty {
      text-align: center;
      color: #666;
      padding: 16px;
      font-size: 14px;
    }

    /* Playback Controls */
    .controls {
      display: flex;
      gap: 10px;
      margin-bottom: 12px;
    }
    .control-btn {
      flex: 1;
      padding: 18px 12px;
      border: none;
      border-radius: 12px;
      background: #26262c;
      color: #efeff1;
      cursor: pointer;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: background 0.15s, transform 0.1s;
      -webkit-tap-highlight-color: transparent;
      user-select: none;
    }
    .control-btn:hover { background: #3a3a3d; }
    .control-btn:active { transform: scale(0.95); background: #4a4a4d; }
    .control-btn .icon { font-size: 28px; line-height: 1; }
    .control-btn .label { font-size: 12px; font-weight: 500; color: #adadb8; }
    .control-btn.stop-btn { background: #3a1515; }
    .control-btn.stop-btn:hover { background: #4a2020; }

    /* Quick Play */
    .quick-play-input {
      width: 100%;
      padding: 14px 16px;
      border: 1px solid #3a3a3d;
      border-radius: 8px;
      background: #0e0e10;
      color: #efeff1;
      font-size: 16px;
      outline: none;
      transition: border-color 0.2s;
    }
    .quick-play-input:focus { border-color: #9147ff; }
    .quick-play-input::placeholder { color: #666; }
    .search-results { margin-top: 6px; }
    .search-result {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 12px;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.1s;
      -webkit-tap-highlight-color: transparent;
    }
    .search-result:hover { background: #26262c; }
    .search-result:active { background: #3a3a3d; }
    .search-result-seq {
      color: #9147ff;
      font-weight: 700;
      font-size: 14px;
      min-width: 45px;
    }
    .search-result-title {
      flex: 1;
      font-size: 14px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .search-result-duration {
      color: #adadb8;
      font-size: 12px;
      flex-shrink: 0;
    }
    .search-result-play {
      color: #9147ff;
      font-size: 18px;
      flex-shrink: 0;
    }

    /* Playlist Controls */
    .playlist-select {
      width: 100%;
      padding: 14px 16px;
      border: 1px solid #3a3a3d;
      border-radius: 8px;
      background: #0e0e10;
      color: #efeff1;
      font-size: 16px;
      margin-bottom: 10px;
      cursor: pointer;
      outline: none;
    }
    .playlist-select:focus { border-color: #9147ff; }
    .playlist-btns {
      display: flex;
      gap: 8px;
    }
    .playlist-btn {
      flex: 1;
      padding: 14px 10px;
      border: none;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: background 0.15s, transform 0.1s;
      -webkit-tap-highlight-color: transparent;
    }
    .playlist-btn:active { transform: scale(0.95); }
    .playlist-btn.play { background: #9147ff; color: white; }
    .playlist-btn.play:hover { background: #772ce8; }
    .playlist-btn.stop { background: #3a3a3d; color: #efeff1; }
    .playlist-btn.stop:hover { background: #4a4a4d; }
    .playlist-btn.shuffle { background: #26262c; color: #efeff1; }
    .playlist-btn.shuffle:hover { background: #3a3a3d; }
    .playlist-status {
      margin-top: 10px;
      padding: 10px 14px;
      background: #9147ff22;
      border-radius: 8px;
      border-left: 3px solid #9147ff;
      font-size: 13px;
      color: #bf94ff;
      display: none;
    }
    .playlist-status.active { display: block; }

    /* Queue */
    .queue-section {
      display: none;
    }
    .queue-section.active { display: block; }
    .queue-list {
      max-height: 300px;
      overflow-y: auto;
    }
    .queue-clip {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      border-radius: 6px;
      font-size: 13px;
      cursor: pointer;
      transition: background 0.1s;
      -webkit-tap-highlight-color: transparent;
    }
    .queue-clip:hover { background: #26262c; }
    .queue-clip:active { background: #3a3a3d; }
    .queue-clip.current {
      background: rgba(145, 71, 255, 0.15);
      border-left: 3px solid #9147ff;
    }
    .queue-clip.played { opacity: 0.4; }
    .queue-clip-seq {
      color: #9147ff;
      font-weight: 600;
      min-width: 40px;
      flex-shrink: 0;
    }
    .queue-clip-title {
      flex: 1;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .queue-clip-duration {
      color: #adadb8;
      font-size: 12px;
      flex-shrink: 0;
    }
    .queue-clip-indicator {
      font-size: 11px;
      color: #9147ff;
      font-weight: 600;
      flex-shrink: 0;
    }

    /* Toast */
    #toast {
      position: fixed;
      bottom: 30px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 9999;
      max-width: 90vw;
      text-align: center;
    }

    /* Mobile adjustments */
    @media (max-width: 520px) {
      .remote { padding: 12px; padding-bottom: 60px; }
      .section { padding: 14px; border-radius: 10px; }
      .now-playing-thumb { width: 90px; height: 51px; }
      .now-playing-title { font-size: 14px; }
      .control-btn { padding: 16px 10px; }
      .control-btn .icon { font-size: 24px; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/includes/nav.php'; ?>

  <div class="login-screen" id="loginScreen">
    <div class="login-box">
      <h1>Remote Control</h1>
      <div class="error" id="loginError" style="display:none;"></div>

      <?php if ($currentUser): ?>
      <div class="oauth-user">
        <div class="oauth-user-info">
          <span>Logged in as</span>
          <span class="oauth-user-name"><?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['login']) ?></span>
        </div>
        <a href="/auth/logout.php?return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="oauth-logout">Logout</a>
      </div>

      <?php if ($oauthAuthorized): ?>
      <?php if ($isChannelMod): ?>
      <p style="color:#adadb8;margin-bottom:16px;">Connecting to <strong><?= htmlspecialchars($login) ?></strong>'s player...</p>
      <?php else: ?>
      <p style="color:#adadb8;margin-bottom:16px;">Connecting to your player...</p>
      <?php endif; ?>
      <?php else: ?>
      <?php if ($isInModListButNoViewPerm): ?>
      <p style="color:#adadb8;margin-bottom:16px;">You're a mod for <strong><?= htmlspecialchars($login) ?></strong>, but don't have dashboard access permission.</p>
      <p style="color:#666;font-size:13px;margin-bottom:16px;">Ask the streamer to grant you the "Dashboard" permission.</p>
      <?php else: ?>
      <p style="color:#adadb8;margin-bottom:16px;">You don't have access to <strong><?= htmlspecialchars($login) ?></strong>'s remote control.</p>
      <p style="color:#666;font-size:13px;margin-bottom:16px;">The streamer needs to add you to their mod list.</p>
      <?php endif; ?>
      <a href="/channels" style="display:block;text-align:center;padding:12px;background:#3a3a3d;color:white;border-radius:4px;text-decoration:none;margin-bottom:12px;">View My Channels</a>
      <?php endif; ?>

      <?php else: ?>
      <a href="/auth/login.php?return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="oauth-btn">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.64 5.93h1.43v4.28h-1.43m3.93-4.28H17v4.28h-1.43M7 2L3.43 5.57v12.86h4.28V22l3.58-3.57h2.85L20.57 12V2m-1.43 9.29l-2.85 2.85h-2.86l-2.5 2.5v-2.5H7.71V3.43h11.43z"/></svg>
        Login with Twitch
      </a>
      <p style="color:#666;font-size:12px;margin-top:16px;">Login with your Twitch account to access the remote control. You can also find your channels at <a href="/channels" style="color:#9147ff;">/channels</a>.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="remote" id="remote">
    <div class="remote-header">
      <h1><span>Remote Control</span></h1>
      <div class="channel-name"><?= htmlspecialchars($login) ?></div>
    </div>

    <!-- Now Playing -->
    <div class="section now-playing" id="nowPlaying">
      <h3>Now Playing</h3>
      <div class="now-playing-empty" id="nowPlayingEmpty">Nothing playing</div>
      <div class="now-playing-content" id="nowPlayingContent" style="display:none;">
        <img class="now-playing-thumb" id="npThumb" src="" alt="" onerror="this.style.display='none'">
        <div class="now-playing-info">
          <div class="now-playing-seq" id="npSeq"></div>
          <div class="now-playing-title" id="npTitle"></div>
        </div>
      </div>
    </div>

    <!-- Playback Controls -->
    <div class="controls">
      <button class="control-btn" onclick="doPrev()">
        <span class="icon">&#x23EE;&#xFE0F;</span>
        <span class="label">Prev</span>
      </button>
      <button class="control-btn" onclick="doSkip()">
        <span class="icon">&#x23ED;&#xFE0F;</span>
        <span class="label">Skip</span>
      </button>
      <button class="control-btn stop-btn" onclick="doStop()">
        <span class="icon">&#x23F9;&#xFE0F;</span>
        <span class="label">Stop</span>
      </button>
    </div>

    <!-- Quick Play -->
    <div class="section">
      <h3>Quick Play</h3>
      <input type="text" class="quick-play-input" id="quickPlayInput"
             placeholder="Clip # or search..."
             autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
      <div class="search-results" id="searchResults"></div>
    </div>

    <!-- Playlist Controls -->
    <div class="section">
      <h3>Playlists</h3>
      <select class="playlist-select" id="playlistSelect">
        <option value="">Select a playlist...</option>
      </select>
      <div class="playlist-btns">
        <button class="playlist-btn play" onclick="doPlayPlaylist()">&#x25B6; Play</button>
        <button class="playlist-btn stop" onclick="doStop()">&#x23F9; Stop</button>
        <button class="playlist-btn shuffle" onclick="doShuffle()">Shuffle</button>
      </div>
      <div class="playlist-status" id="playlistStatus"></div>
    </div>

    <!-- Queue Preview -->
    <div class="section queue-section" id="queueSection">
      <h3>Queue</h3>
      <div class="queue-list" id="queueList"></div>
    </div>
  </div>

  <script>
    const LOGIN = <?= json_encode($login) ?>;
    const API_BASE = '';
    const oauthAuthorized = <?= $oauthAuthorized ? 'true' : 'false' ?>;

    function getAuthParams() {
      return 'oauth=1';
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    // Toast notification
    function showToast(message, type = 'success') {
      const existing = document.getElementById('toast');
      if (existing) existing.remove();
      const toast = document.createElement('div');
      toast.id = 'toast';
      toast.textContent = message;
      Object.assign(toast.style, {
        position: 'fixed', bottom: '30px', left: '50%', transform: 'translateX(-50%)',
        padding: '12px 24px', borderRadius: '8px', fontSize: '14px', fontWeight: '500',
        zIndex: '9999', color: 'white', boxShadow: '0 4px 12px rgba(0,0,0,0.5)',
        background: type === 'success' ? '#00c853' : type === 'error' ? '#eb0400' : '#9147ff',
        transition: 'opacity 0.3s', opacity: '1', maxWidth: '90vw', textAlign: 'center'
      });
      document.body.appendChild(toast);
      setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 2500);
    }

    // Auto-login
    document.addEventListener('DOMContentLoaded', () => {
      if (oauthAuthorized && LOGIN) {
        document.getElementById('loginScreen').style.display = 'none';
        document.getElementById('remote').classList.add('active');
        init();
      }
    });

    // Init
    async function init() {
      await loadPlaylists();
      pollStatus();
      setInterval(pollStatus, 3000);
    }

    // Polling: now playing + playlist status
    let lastNpSeq = null;
    let activePlaylistId = null;

    async function pollStatus() {
      try {
        const [npRes, plRes] = await Promise.all([
          fetch(`${API_BASE}/now_playing_get.php?login=${LOGIN}`, { credentials: 'same-origin' }),
          fetch(`${API_BASE}/playlist_api.php?action=status&login=${LOGIN}&${getAuthParams()}`, { credentials: 'same-origin' })
        ]);
        const npData = await npRes.json();
        const plData = await plRes.json();
        updateNowPlaying(npData);
        updatePlaylistStatus(plData);
      } catch (err) {
        console.error('Poll error:', err);
      }
    }

    // Now Playing
    function updateNowPlaying(data) {
      const container = document.getElementById('nowPlaying');
      const empty = document.getElementById('nowPlayingEmpty');
      const content = document.getElementById('nowPlayingContent');

      if (data.error || !data.seq) {
        empty.style.display = 'block';
        content.style.display = 'none';
        container.classList.remove('playing');
        lastNpSeq = null;
        return;
      }

      empty.style.display = 'none';
      content.style.display = 'flex';
      container.classList.add('playing');

      document.getElementById('npSeq').textContent = `#${data.seq}`;
      document.getElementById('npTitle').textContent = data.title || 'Untitled';

      // Update thumbnail when clip changes
      if (data.clip_id && lastNpSeq !== data.seq) {
        const thumb = document.getElementById('npThumb');
        thumb.style.display = '';
        thumb.src = `https://clips-media-assets2.twitch.tv/${data.clip_id}-preview-480x272.jpg`;
      }
      lastNpSeq = data.seq;
    }

    // Playlist Status
    function updatePlaylistStatus(data) {
      const statusEl = document.getElementById('playlistStatus');
      const queueSection = document.getElementById('queueSection');

      if (data.active) {
        const idx = (data.current_index || 0);
        statusEl.textContent = `Playing: ${data.playlist_name || 'Playlist'} (clip ${idx + 1} of ${data.total_clips || '?'})`;
        statusEl.classList.add('active');

        // Auto-select active playlist in dropdown
        const select = document.getElementById('playlistSelect');
        if (select.value !== String(data.playlist_id)) {
          select.value = data.playlist_id;
        }

        // Load queue if playlist changed
        if (activePlaylistId !== data.playlist_id) {
          loadQueue(data.playlist_id, idx);
          activePlaylistId = data.playlist_id;
        } else {
          highlightQueueItem(idx);
        }
        queueSection.classList.add('active');
      } else {
        statusEl.textContent = '';
        statusEl.classList.remove('active');
        queueSection.classList.remove('active');
        activePlaylistId = null;
      }
    }

    // Playback Controls
    async function doSkip() {
      try {
        const res = await fetch(`${API_BASE}/cskip.php?login=${LOGIN}&${getAuthParams()}`, { credentials: 'same-origin' });
        const text = await res.text();
        showToast(text, 'info');
      } catch (err) { showToast('Error skipping', 'error'); }
    }

    async function doPrev() {
      try {
        const res = await fetch(`${API_BASE}/cprev.php?login=${LOGIN}&${getAuthParams()}`, { credentials: 'same-origin' });
        const text = await res.text();
        showToast(text, 'info');
      } catch (err) { showToast('Error going back', 'error'); }
    }

    async function doStop() {
      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=stop&login=${LOGIN}&${getAuthParams()}`, { credentials: 'same-origin' });
        const data = await res.json();
        showToast(data.error ? data.error : 'Stopped', data.error ? 'error' : 'info');
      } catch (err) { showToast('Error stopping', 'error'); }
    }

    async function doShuffle() {
      try {
        const res = await fetch(`${API_BASE}/cshuffle.php?login=${LOGIN}&${getAuthParams()}`, { credentials: 'same-origin' });
        const text = await res.text();
        showToast(text, 'info');
      } catch (err) { showToast('Error shuffling', 'error'); }
    }

    // Quick Play
    let searchTimeout = null;

    document.getElementById('quickPlayInput').addEventListener('input', function() {
      const val = this.value.trim();
      clearTimeout(searchTimeout);
      if (!val) {
        document.getElementById('searchResults').innerHTML = '';
        return;
      }
      if (/^\d+$/.test(val)) {
        document.getElementById('searchResults').innerHTML =
          `<div class="search-result" onclick="playClip(${parseInt(val)})">
            <span class="search-result-seq">#${parseInt(val)}</span>
            <span class="search-result-title">Play clip #${parseInt(val)}</span>
            <span class="search-result-play">&#x25B6;</span>
          </div>`;
        return;
      }
      searchTimeout = setTimeout(() => searchClips(val), 300);
    });

    document.getElementById('quickPlayInput').addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        const val = this.value.trim();
        if (/^\d+$/.test(val)) {
          playClip(parseInt(val));
          this.value = '';
          document.getElementById('searchResults').innerHTML = '';
        }
      }
    });

    async function searchClips(query) {
      try {
        const res = await fetch(
          `${API_BASE}/clips_api.php?action=list&login=${LOGIN}&${getAuthParams()}&q=${encodeURIComponent(query)}&per_page=5`,
          { credentials: 'same-origin' }
        );
        const data = await res.json();
        const container = document.getElementById('searchResults');
        if (!data.clips || data.clips.length === 0) {
          container.innerHTML = '<div style="padding:12px;color:#666;font-size:13px;">No clips found</div>';
          return;
        }
        container.innerHTML = data.clips.map(c => `
          <div class="search-result" onclick="playClip(${c.seq})">
            <span class="search-result-seq">#${c.seq}</span>
            <span class="search-result-title">${escapeHtml(c.title || 'Untitled')}</span>
            <span class="search-result-duration">${c.duration ? Math.round(c.duration) + 's' : ''}</span>
            <span class="search-result-play">&#x25B6;</span>
          </div>
        `).join('');
      } catch (err) {
        console.error('Search error:', err);
      }
    }

    async function playClip(seq) {
      try {
        const res = await fetch(`${API_BASE}/pclip.php?login=${LOGIN}&${getAuthParams()}&seq=${seq}`, { credentials: 'same-origin' });
        const text = await res.text();
        showToast(text, res.ok ? 'info' : 'error');
        document.getElementById('quickPlayInput').value = '';
        document.getElementById('searchResults').innerHTML = '';
      } catch (err) {
        showToast('Error playing clip', 'error');
      }
    }

    // Playlists
    async function loadPlaylists() {
      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=list&login=${LOGIN}&${getAuthParams()}`, { credentials: 'same-origin' });
        const data = await res.json();
        const select = document.getElementById('playlistSelect');
        while (select.options.length > 1) select.remove(1);
        if (data.playlists) {
          data.playlists.forEach(pl => {
            const opt = document.createElement('option');
            opt.value = pl.id;
            opt.textContent = `${pl.name} (${pl.clip_count || 0} clips)`;
            select.appendChild(opt);
          });
        }
      } catch (err) {
        console.error('Error loading playlists:', err);
      }
    }

    async function doPlayPlaylist() {
      const playlistId = document.getElementById('playlistSelect').value;
      if (!playlistId) {
        showToast('Select a playlist first', 'error');
        return;
      }
      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=play&login=${LOGIN}&${getAuthParams()}&id=${playlistId}`, { credentials: 'same-origin' });
        const data = await res.json();
        if (data.error) {
          showToast(data.error, 'error');
        } else if (data.success && data.first_clip) {
          await fetch(`${API_BASE}/pclip.php?login=${LOGIN}&${getAuthParams()}&seq=${data.first_clip.seq}`, { credentials: 'same-origin' });
          showToast(data.message || 'Playlist started', 'info');
        } else if (data.message) {
          showToast(data.message, 'info');
        }
      } catch (err) {
        showToast('Error playing playlist', 'error');
      }
    }

    // Queue
    let queueClips = [];

    async function loadQueue(playlistId, currentIndex) {
      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=get&login=${LOGIN}&${getAuthParams()}&id=${playlistId}`, { credentials: 'same-origin' });
        const data = await res.json();
        if (data.playlist && data.playlist.clips) {
          queueClips = data.playlist.clips;
          renderQueue(currentIndex);
        }
      } catch (err) {
        console.error('Error loading queue:', err);
      }
    }

    function renderQueue(currentIndex) {
      const container = document.getElementById('queueList');
      container.innerHTML = queueClips.map((clip, i) => {
        let cls = 'queue-clip';
        let indicator = '';
        if (i === currentIndex) {
          cls += ' current';
          indicator = '<span class="queue-clip-indicator">NOW</span>';
        } else if (i < currentIndex) {
          cls += ' played';
        }
        return `<div class="${cls}" onclick="playClip(${clip.seq})">
          <span class="queue-clip-seq">#${clip.seq}</span>
          <span class="queue-clip-title">${escapeHtml(clip.title || 'Untitled')}</span>
          <span class="queue-clip-duration">${clip.duration ? Math.round(clip.duration) + 's' : ''}</span>
          ${indicator}
        </div>`;
      }).join('');
    }

    function highlightQueueItem(currentIndex) {
      document.querySelectorAll('.queue-clip').forEach((item, i) => {
        item.classList.remove('current', 'played');
        const indicator = item.querySelector('.queue-clip-indicator');
        if (i === currentIndex) {
          item.classList.add('current');
          if (!indicator) {
            item.insertAdjacentHTML('beforeend', '<span class="queue-clip-indicator">NOW</span>');
          }
        } else if (i < currentIndex) {
          item.classList.add('played');
          if (indicator) indicator.remove();
        } else {
          if (indicator) indicator.remove();
        }
      });
    }
  </script>
</body>
</html>
