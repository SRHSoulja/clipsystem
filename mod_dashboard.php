<?php
/**
 * mod_dashboard.php - Mod Dashboard for Playlist Management
 *
 * Password protected page for mods to:
 * - Browse all clips with search/filter
 * - Create and manage playlists
 * - Queue playlists for playback
 */
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

require_once __DIR__ . '/db_config.php';

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "floppyjimmie");
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mod Dashboard - <?php echo htmlspecialchars($login); ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #0e0e10;
      color: #efeff1;
      min-height: 100vh;
    }
    .login-screen {
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
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
    .clip-card .seq { color: #9147ff; font-weight: bold; }
    .clip-card .title {
      margin: 8px 0;
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
  </style>
</head>
<body>
  <div class="login-screen" id="loginScreen">
    <div class="login-box">
      <h1>Mod Dashboard</h1>
      <div class="error" id="loginError" style="display:none;"></div>
      <input type="password" id="keyInput" placeholder="Admin Key" autofocus>
      <button onclick="login()">Enter</button>
    </div>
  </div>

  <div class="dashboard" id="dashboard">
    <div class="header">
      <h1>Mod Dashboard</h1>
      <div class="user"><?php echo htmlspecialchars($login); ?></div>
    </div>

    <div class="main">
      <div class="sidebar">
        <h2>Playlists</h2>
        <div class="playlist-list" id="playlistList">
          <div class="loading">Loading...</div>
        </div>
        <button class="new-playlist-btn" onclick="showNewPlaylistModal()">+ New Playlist</button>

        <div class="current-playlist" id="currentPlaylist" style="display:none;">
          <h3>
            <span id="currentPlaylistName">Playlist</span>
            <button class="btn-primary" style="padding:6px 12px;font-size:12px;" onclick="playPlaylist()">Play All</button>
          </h3>
          <div class="playlist-clips" id="playlistClips"></div>
        </div>
      </div>

      <div class="content">
        <div class="search-bar">
          <input type="text" id="searchInput" placeholder="Search clips by title..." oninput="filterClips()">
          <select id="gameFilter" onchange="filterClips()">
            <option value="">All Games</option>
          </select>
        </div>

        <div class="clips-grid" id="clipsGrid">
          <div class="loading">Loading clips...</div>
        </div>

        <div class="actions-bar">
          <button class="btn-primary" onclick="addSelectedToPlaylist()">Add to Playlist</button>
          <button class="btn-secondary" onclick="playSelected()">Play Selected</button>
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

  <script>
    const LOGIN = <?php echo json_encode($login); ?>;
    const API_BASE = '';
    let adminKey = '';
    let allClips = [];
    let playlists = [];
    let currentPlaylist = null;
    let selectedClips = new Set();
    let games = {};
    let gameNames = {};

    // Login
    document.getElementById('keyInput').addEventListener('keypress', (e) => {
      if (e.key === 'Enter') login();
    });

    async function login() {
      adminKey = document.getElementById('keyInput').value.trim();
      if (!adminKey) return;

      try {
        // Test the key by fetching clips
        const res = await fetch(`${API_BASE}/playlist_api.php?action=list&login=${LOGIN}&key=${encodeURIComponent(adminKey)}`);
        const data = await res.json();

        if (data.error) {
          document.getElementById('loginError').textContent = data.error;
          document.getElementById('loginError').style.display = 'block';
          return;
        }

        document.getElementById('loginScreen').style.display = 'none';
        document.getElementById('dashboard').classList.add('active');
        loadDashboard();
      } catch (err) {
        document.getElementById('loginError').textContent = 'Connection error';
        document.getElementById('loginError').style.display = 'block';
      }
    }

    async function loadDashboard() {
      await Promise.all([loadClips(), loadPlaylists()]);
    }

    async function loadClips() {
      try {
        const res = await fetch(`${API_BASE}/twitch_reel_api.php?login=${LOGIN}&pool=5000&days=0`);
        const data = await res.json();
        allClips = data.clips || [];

        // Build game list
        games = {};
        allClips.forEach(c => {
          if (c.game_id) {
            games[c.game_id] = (games[c.game_id] || 0) + 1;
          }
        });

        // Fetch game names from API
        const gameIds = Object.keys(games).slice(0, 100).join(',');
        if (gameIds) {
          try {
            const gamesRes = await fetch(`${API_BASE}/games_api.php?action=get&ids=${gameIds}`);
            const gamesData = await gamesRes.json();
            if (gamesData.games) {
              gameNames = {};
              Object.values(gamesData.games).forEach(g => {
                gameNames[g.game_id || g.id] = g.name;
              });
            }
          } catch (e) {
            console.log('Could not fetch game names:', e);
          }
        }

        // Populate game filter
        const gameFilter = document.getElementById('gameFilter');
        gameFilter.innerHTML = '<option value="">All Games</option>';
        Object.entries(games)
          .sort((a, b) => b[1] - a[1])
          .slice(0, 50)
          .forEach(([id, count]) => {
            const opt = document.createElement('option');
            opt.value = id;
            const name = gameNames[id] || `Game ${id}`;
            opt.textContent = `${name} (${count})`;
            gameFilter.appendChild(opt);
          });

        renderClips();
      } catch (err) {
        document.getElementById('clipsGrid').innerHTML = '<div class="loading">Error loading clips</div>';
      }
    }

    async function loadPlaylists() {
      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=list&login=${LOGIN}&key=${encodeURIComponent(adminKey)}`);
        const data = await res.json();
        playlists = data.playlists || [];
        renderPlaylists();
      } catch (err) {
        document.getElementById('playlistList').innerHTML = '<div class="loading">Error loading playlists</div>';
      }
    }

    function renderClips() {
      const search = document.getElementById('searchInput').value.toLowerCase();
      const gameId = document.getElementById('gameFilter').value;

      const filtered = allClips.filter(c => {
        if (search && !(c.title || '').toLowerCase().includes(search)) return false;
        if (gameId && c.game_id !== gameId) return false;
        return true;
      });

      const grid = document.getElementById('clipsGrid');
      grid.innerHTML = filtered.slice(0, 200).map(c => `
        <div class="clip-card ${selectedClips.has(c.seq) ? 'selected' : ''}"
             onclick="toggleClip(${c.seq})"
             ondblclick="playClip(${c.seq})">
          <div class="seq">#${c.seq}</div>
          <div class="title">${escapeHtml(c.title || '(no title)')}</div>
          <div class="meta">
            <span>${c.view_count ? c.view_count.toLocaleString() + ' views' : ''}</span>
            <span>${c.game_id ? (gameNames[c.game_id] || c.game_id) : ''}</span>
          </div>
        </div>
      `).join('');

      if (filtered.length > 200) {
        grid.innerHTML += `<div class="loading">Showing 200 of ${filtered.length} clips</div>`;
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

    function filterClips() {
      renderClips();
    }

    async function selectPlaylist(id) {
      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=get&login=${LOGIN}&key=${encodeURIComponent(adminKey)}&id=${id}`);
        const data = await res.json();
        currentPlaylist = data.playlist;

        document.getElementById('currentPlaylistName').textContent = currentPlaylist.name;
        document.getElementById('currentPlaylist').style.display = 'block';

        renderPlaylistClips();
        renderPlaylists();
      } catch (err) {
        console.error('Error loading playlist:', err);
      }
    }

    function renderPlaylistClips() {
      const container = document.getElementById('playlistClips');
      const clips = currentPlaylist?.clips || [];

      container.innerHTML = clips.map((c, i) => `
        <div class="playlist-clip">
          <div>
            <span class="seq">#${c.seq}</span>
            ${escapeHtml((c.title || '').substring(0, 40))}
          </div>
          <button class="remove-btn" onclick="removeFromPlaylist(${c.seq})">×</button>
        </div>
      `).join('') || '<div class="loading">Empty playlist</div>';
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
        const res = await fetch(`${API_BASE}/playlist_api.php?action=add_clips&login=${LOGIN}&key=${encodeURIComponent(adminKey)}&id=${currentPlaylist.id}&seqs=${seqs.join(',')}`);
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
        const res = await fetch(`${API_BASE}/playlist_api.php?action=remove_clip&login=${LOGIN}&key=${encodeURIComponent(adminKey)}&id=${currentPlaylist.id}&seq=${seq}`);
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
        await fetch(`${API_BASE}/pclip.php?login=${LOGIN}&key=${encodeURIComponent(adminKey)}&seq=${seq}`);
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
        const res = await fetch(`${API_BASE}/playlist_api.php?action=play&login=${LOGIN}&key=${encodeURIComponent(adminKey)}&id=${currentPlaylist.id}`);
        const data = await res.json();
        if (data.message) {
          alert(data.message);
        }
      } catch (err) {
        console.error('Error playing playlist:', err);
      }
    }

    function showNewPlaylistModal() {
      document.getElementById('newPlaylistModal').classList.add('active');
      document.getElementById('newPlaylistName').value = '';
      document.getElementById('newPlaylistName').focus();
    }

    function closeModal() {
      document.getElementById('newPlaylistModal').classList.remove('active');
    }

    async function createPlaylist() {
      const name = document.getElementById('newPlaylistName').value.trim();
      if (!name) return;

      try {
        const res = await fetch(`${API_BASE}/playlist_api.php?action=create&login=${LOGIN}&key=${encodeURIComponent(adminKey)}&name=${encodeURIComponent(name)}`);
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

    function escapeHtml(str) {
      const div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    }
  </script>
</body>
</html>
