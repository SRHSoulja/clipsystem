<?php
/**
 * chat_admin.php - Chat Admin Panel
 *
 * Super admin page for viewing and managing ClipTV chat messages.
 * Requires Twitch OAuth - only super admins allowed.
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

// --- API mode: handle AJAX requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['api'])) {
  header('Content-Type: application/json');

  $currentUser = getCurrentUser();
  if (!$currentUser || !isSuperAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
  }

  $pdo = get_db_connection();
  if (!$pdo) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
  }

  // Ensure table exists
  $pdo->exec("CREATE TABLE IF NOT EXISTS cliptv_chat (
    id SERIAL PRIMARY KEY,
    login VARCHAR(50) NOT NULL,
    user_id VARCHAR(50),
    username VARCHAR(64) NOT NULL,
    display_name VARCHAR(64) NOT NULL,
    message TEXT NOT NULL,
    scope VARCHAR(10) NOT NULL DEFAULT 'stream',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  )");

  $action = $_GET['action'] ?? $_POST['action'] ?? '';

  try {
    switch ($action) {
      case 'list':
        $where = [];
        $params = [];

        $channel = trim($_GET['channel'] ?? '');
        if ($channel !== '') {
          $where[] = "login = ?";
          $params[] = $channel;
        }

        $scope = trim($_GET['scope'] ?? '');
        if ($scope === 'stream' || $scope === 'global') {
          $where[] = "scope = ?";
          $params[] = $scope;
        }

        $username = trim($_GET['username'] ?? '');
        if ($username !== '') {
          $where[] = "LOWER(username) LIKE ?";
          $params[] = '%' . strtolower($username) . '%';
        }

        $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("SELECT id, login, username, display_name, message, scope, created_at FROM cliptv_chat {$whereSQL} ORDER BY id DESC LIMIT 200");
        $stmt->execute($params);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get distinct channels for filter dropdown
        $channels = $pdo->query("SELECT DISTINCT login FROM cliptv_chat ORDER BY login")->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode(['messages' => $messages, 'channels' => $channels]);
        break;

      case 'delete':
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || count($ids) === 0) {
          echo json_encode(['error' => 'No message IDs provided']);
          break;
        }
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM cliptv_chat WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount()]);
        break;

      default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
  } catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
  }
  exit;
}

// --- HTML page ---
header("Content-Type: text/html; charset=utf-8");

$currentUser = getCurrentUser();
$authenticated = $currentUser && isSuperAdmin();

if (isset($_GET['logout'])) {
  logout();
  header('Location: /chat-admin');
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/tapefacecliptv.png" type="image/png">
  <title>Chat Admin - ClipTV</title>
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
    .container { max-width: 1100px; margin: 0 auto; }
    h1 { color: #9147ff; border-bottom: 2px solid #9147ff; padding-bottom: 10px; display: flex; align-items: center; gap: 12px; }
    .login-box {
      max-width: 400px; margin: 100px auto;
      background: #18181b; padding: 30px; border-radius: 8px; text-align: center;
    }
    .login-box h1 { justify-content: center; border: none; }
    a.btn {
      background: #9147ff; color: white; border: none; padding: 12px 24px;
      border-radius: 4px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block;
    }
    a.btn:hover { background: #772ce8; }
    .filters {
      display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
      margin-bottom: 16px; padding: 12px; background: #18181b; border-radius: 8px;
    }
    .filters select, .filters input[type="text"] {
      background: #0e0e10; border: 1px solid #333; border-radius: 4px;
      color: #efeff1; padding: 8px 12px; font-size: 14px;
    }
    .filters select:focus, .filters input:focus { outline: none; border-color: #9147ff; }
    .filters label { color: #adadb8; font-size: 13px; font-weight: 600; }
    .toolbar {
      display: flex; gap: 10px; align-items: center; margin-bottom: 10px;
    }
    .toolbar button {
      padding: 6px 14px; border-radius: 4px; border: none; cursor: pointer;
      font-size: 13px; font-weight: 600;
    }
    .btn-danger { background: #ff4444; color: #fff; }
    .btn-danger:hover { background: #cc3333; }
    .btn-danger:disabled { opacity: 0.4; cursor: default; }
    .btn-sm { background: #ff4444; color: #fff; padding: 4px 10px; border-radius: 4px; border: none; cursor: pointer; font-size: 12px; }
    .btn-sm:hover { background: #cc3333; }
    .btn-refresh { background: #333; color: #efeff1; }
    .btn-refresh:hover { background: #444; }
    .status { color: #adadb8; font-size: 13px; }
    table {
      width: 100%; border-collapse: collapse; font-size: 14px;
    }
    thead th {
      background: #18181b; color: #adadb8; font-weight: 600; text-align: left;
      padding: 10px 8px; position: sticky; top: 0; z-index: 1;
    }
    tbody tr { border-bottom: 1px solid #1f1f23; }
    tbody tr:hover { background: #1a1a2e; }
    td { padding: 8px; vertical-align: top; }
    td.msg { max-width: 400px; word-break: break-word; }
    td.channel { color: #bf94ff; font-weight: 600; }
    td.user { color: #4ade80; }
    td.scope { font-size: 12px; }
    .scope-stream { color: #60a5fa; }
    .scope-global { color: #fbbf24; }
    td.time { color: #adadb8; font-size: 12px; white-space: nowrap; }
    td.actions { white-space: nowrap; }
    input[type="checkbox"] { accent-color: #9147ff; width: 16px; height: 16px; cursor: pointer; }
    .empty { text-align: center; padding: 40px; color: #666; font-size: 16px; }
    .nav-links { display: flex; gap: 12px; margin-bottom: 20px; font-size: 14px; }
    .nav-links a { color: #9147ff; text-decoration: none; }
    .nav-links a:hover { text-decoration: underline; }
    .count { background: #333; color: #adadb8; font-size: 12px; padding: 2px 8px; border-radius: 10px; font-weight: 400; }
    @media (max-width: 700px) {
      .filters { flex-direction: column; }
      td.msg { max-width: 200px; }
    }
  </style>
</head>
<body>
<div class="container">

<?php if (!$authenticated): ?>
  <div class="login-box">
    <h1>Chat Admin</h1>
    <p style="color:#adadb8">Super admin access required</p>
    <a class="btn" href="/auth/login.php?return=<?= urlencode('/chat-admin') ?>">Login with Twitch</a>
  </div>
<?php else: ?>
  <div class="nav-links">
    <a href="/admin.php">Admin Panel</a>
    <a href="/chat-admin">Chat Admin</a>
    <a href="?logout" style="margin-left:auto;color:#ff4444;">Logout</a>
  </div>

  <h1>Chat Admin <span class="count" id="msgCount">0</span></h1>

  <div class="filters">
    <div>
      <label>Channel</label><br>
      <select id="filterChannel"><option value="">All Channels</option></select>
    </div>
    <div>
      <label>Scope</label><br>
      <select id="filterScope">
        <option value="">All</option>
        <option value="stream">Stream</option>
        <option value="global">Global</option>
      </select>
    </div>
    <div>
      <label>Username</label><br>
      <input type="text" id="filterUsername" placeholder="Search..." style="width:160px">
    </div>
  </div>

  <div class="toolbar">
    <button class="btn-danger" id="deleteSelected" disabled>Delete Selected</button>
    <button class="btn-refresh" id="refreshBtn">Refresh</button>
    <label style="display:flex;align-items:center;gap:6px;margin-left:auto;cursor:pointer;font-size:13px;color:#adadb8;">
      <input type="checkbox" id="autoRefresh" checked> Auto-refresh (10s)
    </label>
    <span class="status" id="lastRefresh"></span>
  </div>

  <table>
    <thead>
      <tr>
        <th><input type="checkbox" id="selectAll"></th>
        <th>Channel</th>
        <th>User</th>
        <th>Message</th>
        <th>Scope</th>
        <th>Time</th>
        <th></th>
      </tr>
    </thead>
    <tbody id="chatBody">
      <tr><td colspan="7" class="empty">Loading...</td></tr>
    </tbody>
  </table>

  <script>
    const chatBody = document.getElementById('chatBody');
    const filterChannel = document.getElementById('filterChannel');
    const filterScope = document.getElementById('filterScope');
    const filterUsername = document.getElementById('filterUsername');
    const deleteSelectedBtn = document.getElementById('deleteSelected');
    const selectAllBox = document.getElementById('selectAll');
    const msgCount = document.getElementById('msgCount');
    const lastRefresh = document.getElementById('lastRefresh');
    const autoRefreshBox = document.getElementById('autoRefresh');
    let autoRefreshInterval = null;

    function escapeHtml(str) {
      const d = document.createElement('div');
      d.textContent = str;
      return d.innerHTML;
    }

    function formatTime(ts) {
      try {
        const d = new Date(ts + (ts.includes('Z') || ts.includes('+') ? '' : 'Z'));
        return d.toLocaleString();
      } catch { return ts; }
    }

    async function loadMessages() {
      const params = new URLSearchParams({ api: '1', action: 'list' });
      if (filterChannel.value) params.set('channel', filterChannel.value);
      if (filterScope.value) params.set('scope', filterScope.value);
      if (filterUsername.value.trim()) params.set('username', filterUsername.value.trim());

      try {
        const res = await fetch('/chat-admin?' + params, { cache: 'no-store' });
        const data = await res.json();

        if (data.error) { chatBody.innerHTML = `<tr><td colspan="7" class="empty">${escapeHtml(data.error)}</td></tr>`; return; }

        // Update channel dropdown (preserve selection)
        const prevChannel = filterChannel.value;
        const opts = '<option value="">All Channels</option>' +
          (data.channels || []).map(c => `<option value="${escapeHtml(c)}" ${c === prevChannel ? 'selected' : ''}>${escapeHtml(c)}</option>`).join('');
        filterChannel.innerHTML = opts;

        const msgs = data.messages || [];
        msgCount.textContent = msgs.length + (msgs.length >= 200 ? '+' : '');

        if (msgs.length === 0) {
          chatBody.innerHTML = '<tr><td colspan="7" class="empty">No messages found</td></tr>';
        } else {
          chatBody.innerHTML = msgs.map(m => `
            <tr data-id="${m.id}">
              <td><input type="checkbox" class="msg-check" value="${m.id}"></td>
              <td class="channel">${escapeHtml(m.login)}</td>
              <td class="user">${escapeHtml(m.display_name)}<br><span style="color:#adadb8;font-size:11px">@${escapeHtml(m.username)}</span></td>
              <td class="msg">${escapeHtml(m.message)}</td>
              <td class="scope"><span class="scope-${m.scope}">${m.scope}</span></td>
              <td class="time">${formatTime(m.created_at)}</td>
              <td class="actions"><button class="btn-sm" onclick="deleteMsg(${m.id})">Delete</button></td>
            </tr>
          `).join('');
        }

        lastRefresh.textContent = 'Updated ' + new Date().toLocaleTimeString();
        updateDeleteBtn();
      } catch (e) {
        chatBody.innerHTML = '<tr><td colspan="7" class="empty">Failed to load messages</td></tr>';
      }
    }

    async function deleteMessages(ids) {
      if (!ids.length) return;
      if (!confirm(`Delete ${ids.length} message(s)?`)) return;

      try {
        const formData = new FormData();
        formData.append('action', 'delete');
        ids.forEach(id => formData.append('ids[]', id));

        const res = await fetch('/chat-admin', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.ok) {
          // Remove rows from DOM instantly
          ids.forEach(id => {
            const row = chatBody.querySelector(`tr[data-id="${id}"]`);
            if (row) row.remove();
          });
          const remaining = chatBody.querySelectorAll('tr[data-id]').length;
          msgCount.textContent = remaining;
          if (remaining === 0) chatBody.innerHTML = '<tr><td colspan="7" class="empty">No messages found</td></tr>';
          updateDeleteBtn();
        }
      } catch (e) {
        alert('Delete failed');
      }
    }

    function deleteMsg(id) { deleteMessages([id]); }

    function updateDeleteBtn() {
      const checked = chatBody.querySelectorAll('.msg-check:checked');
      deleteSelectedBtn.disabled = checked.length === 0;
      deleteSelectedBtn.textContent = checked.length > 0 ? `Delete Selected (${checked.length})` : 'Delete Selected';
    }

    // Select all
    selectAllBox.addEventListener('change', () => {
      chatBody.querySelectorAll('.msg-check').forEach(cb => cb.checked = selectAllBox.checked);
      updateDeleteBtn();
    });

    // Checkbox changes
    chatBody.addEventListener('change', (e) => {
      if (e.target.classList.contains('msg-check')) updateDeleteBtn();
    });

    // Delete selected
    deleteSelectedBtn.addEventListener('click', () => {
      const ids = [...chatBody.querySelectorAll('.msg-check:checked')].map(cb => parseInt(cb.value));
      deleteMessages(ids);
    });

    // Filters
    filterChannel.addEventListener('change', loadMessages);
    filterScope.addEventListener('change', loadMessages);
    let usernameTimer = null;
    filterUsername.addEventListener('input', () => {
      clearTimeout(usernameTimer);
      usernameTimer = setTimeout(loadMessages, 400);
    });

    // Refresh
    document.getElementById('refreshBtn').addEventListener('click', loadMessages);

    // Auto-refresh
    function startAutoRefresh() {
      stopAutoRefresh();
      if (autoRefreshBox.checked) {
        autoRefreshInterval = setInterval(loadMessages, 10000);
      }
    }
    function stopAutoRefresh() {
      if (autoRefreshInterval) { clearInterval(autoRefreshInterval); autoRefreshInterval = null; }
    }
    autoRefreshBox.addEventListener('change', startAutoRefresh);

    // Init
    loadMessages();
    startAutoRefresh();
  </script>
<?php endif; ?>
</div>
</body>
</html>
