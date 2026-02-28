<?php
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

$currentUser = getCurrentUser();
$pdo = get_db_connection();
$prefillLogin = strtolower(trim($_GET['login'] ?? ''));
$prefillLogin = preg_replace("/[^a-z0-9_]/", "", $prefillLogin);

header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <title>Archive a Streamer - ClipArchive</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: linear-gradient(135deg, #0e0e10 0%, #18181b 50%, #1f1f23 100%);
      color: #efeff1;
      min-height: 100vh;
    }

    .page-content {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-height: calc(100vh - 56px);
      padding: 40px 20px;
    }

    .container {
      max-width: 520px;
      width: 100%;
      text-align: center;
    }

    .archive-icon {
      font-size: 64px;
      margin-bottom: 16px;
    }

    h1 {
      font-size: 36px;
      font-weight: 700;
      margin-bottom: 8px;
      background: linear-gradient(90deg, #9147ff, #bf94ff);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .subtitle {
      color: #adadb8;
      font-size: 16px;
      margin-bottom: 32px;
    }

    /* ── Input form ── */
    .archive-form {
      display: flex;
      gap: 10px;
      margin-bottom: 16px;
    }

    .archive-form input {
      flex: 1;
      padding: 14px 20px;
      border: 2px solid #3d3d42;
      border-radius: 8px;
      background: #0e0e10;
      color: #efeff1;
      font-size: 16px;
      transition: border-color 0.2s;
    }

    .archive-form input:focus {
      outline: none;
      border-color: #9147ff;
    }

    .archive-form button {
      padding: 14px 28px;
      border: none;
      border-radius: 8px;
      background: #9147ff;
      color: white;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.2s;
      white-space: nowrap;
    }

    .archive-form button:hover:not(:disabled) {
      background: #772ce8;
    }

    .archive-form button:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .form-hint {
      font-size: 13px;
      color: #53535f;
      margin-bottom: 32px;
    }

    /* ── Progress section ── */
    .progress-section {
      display: none;
      background: rgba(145, 71, 255, 0.08);
      border: 1px solid rgba(145, 71, 255, 0.2);
      border-radius: 12px;
      padding: 28px;
      text-align: left;
    }

    .progress-section.visible {
      display: block;
    }

    .progress-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
    }

    .progress-title {
      font-size: 18px;
      font-weight: 600;
    }

    .progress-pct {
      font-size: 24px;
      font-weight: 700;
      color: #bf94ff;
    }

    .progress-bar-track {
      width: 100%;
      height: 8px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 4px;
      overflow: hidden;
      margin-bottom: 16px;
    }

    .progress-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, #9147ff, #bf94ff);
      border-radius: 4px;
      transition: width 0.5s ease;
      width: 0%;
    }

    .progress-stats {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-bottom: 16px;
    }

    .stat {
      background: rgba(0, 0, 0, 0.2);
      border-radius: 8px;
      padding: 12px;
    }

    .stat-label {
      font-size: 11px;
      color: #adadb8;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 4px;
    }

    .stat-value {
      font-size: 20px;
      font-weight: 600;
    }

    .progress-status {
      font-size: 14px;
      color: #adadb8;
      text-align: center;
    }

    .progress-status .safe-msg {
      margin-top: 8px;
      font-size: 12px;
      color: #53535f;
    }

    /* ── Messages ── */
    .message {
      padding: 16px 20px;
      border-radius: 8px;
      margin-bottom: 16px;
      font-size: 14px;
      display: none;
    }

    .message.visible {
      display: block;
    }

    .message.error {
      background: rgba(255, 71, 87, 0.15);
      border: 1px solid rgba(255, 71, 87, 0.3);
      color: #ff6b81;
    }

    .message.success {
      background: rgba(46, 213, 115, 0.15);
      border: 1px solid rgba(46, 213, 115, 0.3);
      color: #7bed9f;
    }

    .message a {
      color: #bf94ff;
      text-decoration: underline;
    }

    /* ── Complete section ── */
    .complete-section {
      display: none;
      text-align: center;
      padding: 32px;
      background: rgba(46, 213, 115, 0.08);
      border: 1px solid rgba(46, 213, 115, 0.2);
      border-radius: 12px;
    }

    .complete-section.visible {
      display: block;
    }

    .complete-icon {
      font-size: 48px;
      margin-bottom: 12px;
    }

    .complete-title {
      font-size: 24px;
      font-weight: 700;
      color: #7bed9f;
      margin-bottom: 8px;
    }

    .complete-subtitle {
      color: #adadb8;
      margin-bottom: 16px;
    }

    .complete-btn {
      display: inline-block;
      padding: 12px 28px;
      background: #9147ff;
      color: white;
      text-decoration: none;
      border-radius: 8px;
      font-weight: 600;
      transition: background 0.2s;
    }

    .complete-btn:hover {
      background: #772ce8;
    }

    /* ── Spinner ── */
    .spinner {
      display: inline-block;
      width: 16px;
      height: 16px;
      border: 2px solid rgba(255,255,255,0.3);
      border-top-color: #fff;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      vertical-align: middle;
      margin-right: 6px;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/includes/nav.php'; ?>

  <div class="page-content">
  <div class="container">
    <div class="archive-icon">📦</div>
    <h1>Archive a Streamer</h1>
    <p class="subtitle">Permanently save every clip from any Twitch channel</p>

    <div id="msg" class="message"></div>

    <form class="archive-form" id="archiveForm" onsubmit="startArchive(event)">
      <input type="text" id="loginInput" placeholder="Enter streamer name..." value="<?= htmlspecialchars($prefillLogin) ?>" autofocus>
      <button type="submit" id="archiveBtn">Archive</button>
    </form>
    <p class="form-hint">Fetches the last 5 years of clips. Takes 5-20 minutes depending on channel size.</p>

    <div class="progress-section" id="progressSection">
      <div class="progress-header">
        <span class="progress-title" id="progressTitle">Archiving...</span>
        <span class="progress-pct" id="progressPct">0%</span>
      </div>
      <div class="progress-bar-track">
        <div class="progress-bar-fill" id="progressBar"></div>
      </div>
      <div class="progress-stats">
        <div class="stat">
          <div class="stat-label">Clips Found</div>
          <div class="stat-value" id="statFound">0</div>
        </div>
        <div class="stat">
          <div class="stat-label">Window</div>
          <div class="stat-value" id="statWindow">0 / 0</div>
        </div>
      </div>
      <div class="progress-status">
        <span id="statusText"><span class="spinner"></span>Processing...</span>
        <div class="safe-msg">You can close this page — archiving continues in the background.</div>
      </div>
    </div>

    <div class="complete-section" id="completeSection">
      <div class="complete-icon">&#10003;</div>
      <div class="complete-title" id="completeTitle">Archive Complete!</div>
      <div class="complete-subtitle" id="completeSubtitle"></div>
      <a href="#" class="complete-btn" id="completeBtn">Browse Clips</a>
    </div>
  </div>
  </div>

  <script>
    const loginInput = document.getElementById('loginInput');
    const archiveBtn = document.getElementById('archiveBtn');
    const archiveForm = document.getElementById('archiveForm');
    const msg = document.getElementById('msg');
    const progressSection = document.getElementById('progressSection');
    const completeSection = document.getElementById('completeSection');

    let currentLogin = '';
    let pollTimer = null;
    let isObserver = false;

    function showMsg(text, type) {
      msg.className = 'message visible ' + type;
      msg.innerHTML = text;
    }

    function hideMsg() {
      msg.className = 'message';
    }

    function setFormDisabled(disabled) {
      loginInput.disabled = disabled;
      archiveBtn.disabled = disabled;
      archiveBtn.textContent = disabled ? 'Archiving...' : 'Archive';
    }

    async function startArchive(e) {
      e.preventDefault();
      hideMsg();

      const login = loginInput.value.trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
      if (!login) {
        showMsg('Please enter a streamer name.', 'error');
        return;
      }

      currentLogin = login;
      setFormDisabled(true);

      try {
        const res = await fetch('/archive_api.php?action=start', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'login=' + encodeURIComponent(login)
        });

        const data = await res.json();

        if (data.error === 'login_required') {
          window.location.href = data.login_url || '/auth/login.php?return=' + encodeURIComponent('/archive?login=' + login);
          return;
        }

        if (data.error === 'rate_limited') {
          showMsg(data.message || 'Archive queue is full. Try again in a few minutes.', 'error');
          setFormDisabled(false);
          return;
        }

        if (data.error === 'streamer_not_found') {
          showMsg(data.message || 'Streamer not found on Twitch.', 'error');
          setFormDisabled(false);
          return;
        }

        if (data.error) {
          showMsg(data.error, 'error');
          setFormDisabled(false);
          return;
        }

        if (data.status === 'already_archived') {
          showMsg('This streamer is already archived! <a href="' + data.redirect + '">Browse their clips</a>', 'success');
          setFormDisabled(false);
          return;
        }

        if (data.status === 'in_progress') {
          // Observer mode — someone else started this, just poll
          isObserver = true;
          showProgress(data.job);
          startPolling();
          return;
        }

        if (data.status === 'started') {
          // We started it — fire off process (fire-and-forget) then poll
          isObserver = false;
          showProgress(data.job);
          fireProcess(login);
          startPolling();
          return;
        }

        showMsg('Unexpected response.', 'error');
        setFormDisabled(false);
      } catch (err) {
        showMsg('Network error. Please try again.', 'error');
        setFormDisabled(false);
      }
    }

    function fireProcess(login) {
      // Fire-and-forget: we don't need the response
      fetch('/archive_api.php?action=process', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'login=' + encodeURIComponent(login)
      }).catch(() => {
        // Ignore — background processing continues server-side
      });
    }

    function showProgress(job) {
      archiveForm.style.display = 'none';
      document.querySelector('.form-hint').style.display = 'none';
      progressSection.classList.add('visible');
      updateProgress(job);
    }

    function updateProgress(job) {
      if (!job) return;

      const pct = job.progress_pct || 0;
      const found = parseInt(job.clips_found) || 0;
      const current = parseInt(job.current_window) || 0;
      const total = parseInt(job.total_windows) || 61;

      document.getElementById('progressPct').textContent = pct + '%';
      document.getElementById('progressBar').style.width = pct + '%';
      document.getElementById('statFound').textContent = found.toLocaleString();
      document.getElementById('statWindow').textContent = current + ' / ' + total;

      const status = job.status || 'running';
      if (status === 'resolving_games') {
        document.getElementById('progressTitle').textContent = 'Setting up...';
        document.getElementById('statusText').innerHTML = '<span class="spinner"></span>Resolving game names & configuring streamer...';
        document.getElementById('progressPct').textContent = '99%';
        document.getElementById('progressBar').style.width = '99%';
      } else {
        document.getElementById('progressTitle').textContent = 'Archiving ' + currentLogin + '...';
        document.getElementById('statusText').innerHTML = '<span class="spinner"></span>Processing window ' + current + ' of ' + total + '...';
      }
    }

    function startPolling() {
      if (pollTimer) clearInterval(pollTimer);
      pollTimer = setInterval(pollStatus, 3000);
    }

    async function pollStatus() {
      try {
        const res = await fetch('/archive_api.php?action=status&login=' + encodeURIComponent(currentLogin));
        const data = await res.json();

        if (data.status === 'complete') {
          clearInterval(pollTimer);
          showComplete(data.job);
          return;
        }

        if (data.status === 'failed') {
          clearInterval(pollTimer);
          progressSection.classList.remove('visible');
          archiveForm.style.display = 'flex';
          document.querySelector('.form-hint').style.display = '';
          showMsg('Archive failed: ' + (data.job?.error_message || 'Unknown error') + '. You can try again.', 'error');
          setFormDisabled(false);
          return;
        }

        if (data.job) {
          updateProgress(data.job);
        }
      } catch (err) {
        // Network hiccup — keep polling, server is still processing
      }
    }

    function showComplete(job) {
      progressSection.classList.remove('visible');
      completeSection.classList.add('visible');

      const total = parseInt(job?.total_clips || job?.clips_inserted || 0);
      document.getElementById('completeTitle').textContent = 'Archive Complete!';
      document.getElementById('completeSubtitle').textContent = total.toLocaleString() + ' clips archived for ' + currentLogin;

      const redirect = job?.redirect || '/search/' + encodeURIComponent(currentLogin);
      document.getElementById('completeBtn').href = redirect;

      // Auto-redirect after 3 seconds
      setTimeout(() => {
        window.location.href = redirect;
      }, 3000);
    }

    // Auto-start if login is prefilled and user is logged in
    <?php if ($prefillLogin && $currentUser): ?>
    document.addEventListener('DOMContentLoaded', () => {
      startArchive(new Event('submit'));
    });
    <?php endif; ?>

    // Also check if there's an existing in-progress job on page load
    <?php if ($prefillLogin): ?>
    document.addEventListener('DOMContentLoaded', async () => {
      try {
        const res = await fetch('/archive_api.php?action=status&login=<?= urlencode($prefillLogin) ?>');
        const data = await res.json();
        if (data.status === 'running' || data.status === 'resolving_games') {
          currentLogin = '<?= $prefillLogin ?>';
          isObserver = true;
          showProgress(data.job);
          startPolling();
        } else if (data.status === 'complete') {
          currentLogin = '<?= $prefillLogin ?>';
          showComplete(data.job);
        }
      } catch (e) {}
    });
    <?php endif; ?>
  </script>
</body>
</html>
