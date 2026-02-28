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
    <p class="form-hint">Fetches all clips since the channel was created. Takes 5-20 minutes depending on channel age.</p>

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
        <div class="safe-msg">Keep this tab open. If you close it, just come back — it'll resume where it left off.</div>
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
    let busy = false;
    let processing = false;

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

    async function apiCall(action, login, method = 'POST') {
      const url = method === 'GET'
        ? '/archive_api.php?action=' + action + '&login=' + encodeURIComponent(login)
        : '/archive_api.php?action=' + action;
      const opts = method === 'GET' ? {} : {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'login=' + encodeURIComponent(login)
      };
      const res = await fetch(url, opts);
      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (e) {
        console.error('Non-JSON response from ' + action + ':', text);
        throw new Error('Server returned invalid response');
      }
    }

    async function startArchive(e) {
      if (e && e.preventDefault) e.preventDefault();
      if (busy) return;
      busy = true;
      hideMsg();

      const login = loginInput.value.trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
      if (!login) {
        showMsg('Please enter a streamer name.', 'error');
        busy = false;
        return;
      }

      currentLogin = login;
      setFormDisabled(true);

      try {
        const data = await apiCall('start', login);

        if (data.error === 'login_required') {
          window.location.href = data.login_url || '/auth/login.php?return=' + encodeURIComponent('/archive?login=' + login);
          return;
        }

        if (data.error === 'rate_limited') {
          showMsg(data.message || 'Archive queue is full. Try again in a few minutes.', 'error');
          setFormDisabled(false);
          busy = false;
          return;
        }

        if (data.error === 'streamer_not_found') {
          showMsg(data.message || 'Streamer not found on Twitch.', 'error');
          setFormDisabled(false);
          busy = false;
          return;
        }

        if (data.error) {
          showMsg('Error: ' + (data.message || data.error), 'error');
          setFormDisabled(false);
          busy = false;
          return;
        }

        if (data.status === 'already_archived') {
          showMsg('This streamer is already archived! <a href="' + data.redirect + '">Browse their clips</a>', 'success');
          setFormDisabled(false);
          busy = false;
          return;
        }

        if (data.status === 'in_progress') {
          // Another tab is processing — just show progress and poll
          showProgress(data.job);
          pollUntilDone();
          return;
        }

        if (data.status === 'started') {
          showProgress(data.job);
          if (data.driver === 'github') {
            // GitHub Actions handles processing — just watch
            updateSafeMsg('You can close this tab — archiving continues in the background.');
            pollUntilDone();
          } else {
            // No GitHub worker — browser drives processing
            processLoop(login);
          }
          return;
        }

        console.error('Unexpected response:', data);
        showMsg('Unexpected response from server.', 'error');
        setFormDisabled(false);
        busy = false;
      } catch (err) {
        console.error('Archive error:', err);
        showMsg('Error: ' + err.message, 'error');
        setFormDisabled(false);
        busy = false;
      }
    }

    /**
     * Main processing loop — calls process once per window.
     * Each call processes one 30-day window and returns immediately.
     * Progress updates in real-time after each window completes.
     */
    async function processLoop(login) {
      if (processing) return;
      processing = true;
      let retries = 0;

      while (processing) {
        try {
          const data = await apiCall('process', login);

          if (data.error === 'login_required') {
            window.location.href = '/auth/login.php?return=' + encodeURIComponent('/archive?login=' + login);
            return;
          }

          if (data.error || data.status === 'failed') {
            showMsg('Error: ' + (data.error || 'Processing failed') + '. Retrying...', 'error');
            retries++;
            if (retries > 3) {
              showMsg('Archive failed after multiple retries. You can refresh to try again.', 'error');
              resetForm();
              return;
            }
            await sleep(3000);
            continue;
          }

          retries = 0; // Reset on success

          if (data.job) {
            updateProgress(data.job);
          }

          if (data.done || data.status === 'windows_complete') {
            // All windows done — finalize
            updateStatus('Setting up streamer...', true);
            const fin = await apiCall('finalize', login);

            if (fin.status === 'complete') {
              showComplete({ total_clips: fin.clips_total, redirect: fin.redirect });
            } else {
              showMsg('Finalize error: ' + (fin.error || 'unknown'), 'error');
              resetForm();
            }
            return;
          }

          // Small pause between windows to be nice
          await sleep(200);

        } catch (err) {
          console.error('Process loop error:', err);
          retries++;
          if (retries > 5) {
            showMsg('Lost connection. Refresh the page to resume — progress is saved.', 'error');
            resetForm();
            return;
          }
          await sleep(3000);
        }
      }
    }

    /**
     * Observer mode — another tab is driving the process.
     * Just poll status until done.
     */
    async function pollUntilDone() {
      let pendingTicks = 0;

      while (true) {
        await sleep(3000);
        try {
          const data = await apiCall('status', currentLogin, 'GET');

          if (data.status === 'complete') {
            showComplete(data.job);
            return;
          }

          if (data.status === 'failed') {
            showMsg('Archive failed: ' + (data.job?.error_message || 'Unknown error') + '. You can try again.', 'error');
            resetForm();
            return;
          }

          // If stuck in pending too long, GitHub Actions may have failed to start
          if (data.status === 'pending') {
            pendingTicks++;
            if (pendingTicks > 20) { // ~60 seconds
              updateSafeMsg('Keep this tab open — processing from your browser.');
              processLoop(currentLogin);
              return;
            }
          } else {
            pendingTicks = 0;
          }

          if (data.job) {
            updateProgress(data.job);
          }
        } catch (e) {
          // Network hiccup — keep polling
        }
      }
    }

    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

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
      const total = parseInt(job.total_windows) || 1;

      document.getElementById('progressPct').textContent = pct + '%';
      document.getElementById('progressBar').style.width = pct + '%';
      document.getElementById('statFound').textContent = found.toLocaleString();
      document.getElementById('statWindow').textContent = current + ' / ' + total;

      document.getElementById('progressTitle').textContent = 'Archiving ' + currentLogin + '...';
      document.getElementById('statusText').innerHTML = '<span class="spinner"></span>Processing window ' + current + ' of ' + total + '...';
    }

    function updateSafeMsg(text) {
      const el = document.querySelector('.safe-msg');
      if (el) el.textContent = text;
    }

    function updateStatus(text, isFinalize) {
      document.getElementById('statusText').innerHTML = '<span class="spinner"></span>' + text;
      if (isFinalize) {
        document.getElementById('progressPct').textContent = '99%';
        document.getElementById('progressBar').style.width = '99%';
        document.getElementById('progressTitle').textContent = 'Almost done...';
      }
    }

    function showComplete(job) {
      processing = false;
      progressSection.classList.remove('visible');
      completeSection.classList.add('visible');

      const total = parseInt(job?.total_clips || job?.clips_inserted || 0);
      document.getElementById('completeTitle').textContent = 'Archive Complete!';
      document.getElementById('completeSubtitle').textContent = total.toLocaleString() + ' clips archived for ' + currentLogin;

      const redirect = job?.redirect || '/search/' + encodeURIComponent(currentLogin);
      document.getElementById('completeBtn').href = redirect;

      setTimeout(() => { window.location.href = redirect; }, 3000);
    }

    function resetForm() {
      processing = false;
      busy = false;
      progressSection.classList.remove('visible');
      archiveForm.style.display = 'flex';
      document.querySelector('.form-hint').style.display = '';
      setFormDisabled(false);
    }

    // On page load — check for existing job, resume if needed
    document.addEventListener('DOMContentLoaded', async () => {
      const prefill = '<?= addslashes($prefillLogin) ?>';
      if (!prefill) return;

      try {
        const data = await apiCall('status', prefill, 'GET');

        if (data.status === 'running') {
          currentLogin = prefill;
          showProgress(data.job);
          updateSafeMsg('Archiving in progress — you can close this tab safely.');
          pollUntilDone();
          return;
        }

        if (data.status === 'resolving_games') {
          currentLogin = prefill;
          showProgress(data.job);
          updateStatus('Setting up streamer...', true);
          pollUntilDone();
          return;
        }

        if (data.status === 'complete' && data.job) {
          currentLogin = prefill;
          showComplete(data.job);
          return;
        }

        // Pending or failed job — restart (triggers GitHub Actions if available)
        if (data.status === 'pending' || data.status === 'failed') {
          <?php if ($currentUser): ?>
          startArchive();
          return;
          <?php endif; ?>
        }
      } catch (e) {
        console.error('Status check error:', e);
      }

      // No existing job — auto-start if user is logged in
      <?php if ($currentUser): ?>
      startArchive();
      <?php endif; ?>
    });
  </script>
</body>
</html>
