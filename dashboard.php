<?php
/**
 * dashboard.php - Streamer Dashboard
 *
 * Self-service dashboard for streamers to manage their clip reel.
 * Access: dashboard.php?key=STREAMER_KEY or dashboard.php?login=username (+ mod password)
 * Super admins (thearsondragon, cliparchive) can access any channel via OAuth
 */
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

function clean_login($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace("/[^a-z0-9_]/", "", $s);
    return $s ?: "";
}

$key = $_GET['key'] ?? '';
$login = clean_login($_GET['login'] ?? '');

// Check for OAuth super admin access
$currentUser = getCurrentUser();
$oauthAuthorized = false;
$isSuperAdmin = false;

if ($currentUser) {
    $isSuperAdmin = isSuperAdmin();
    if ($isSuperAdmin) {
        $oauthAuthorized = true;
        // Super admins can specify any login, or default to their own
        if (!$login) {
            $login = strtolower($currentUser['login']);
        }
    } elseif (!$login || $login === strtolower($currentUser['login'])) {
        // Regular users can access their own channel
        $oauthAuthorized = true;
        $login = strtolower($currentUser['login']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Streamer Dashboard - Clip Reel System</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0e0e10;
            color: #efeff1;
            min-height: 100vh;
        }

        /* Login Screen */
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
        .error { color: #eb0400; margin-bottom: 16px; display: none; }

        /* Dashboard */
        .dashboard { display: none; }
        .dashboard.active { display: block; }

        .header {
            background: #18181b;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #3a3a3d;
        }
        .header h1 { font-size: 20px; color: #9147ff; }
        .header .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .header .role-badge {
            background: #9147ff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            text-transform: uppercase;
        }
        .header .role-badge.mod { background: #00ad03; }
        .header .role-badge.admin { background: #eb0400; }

        /* Tabs */
        .tabs {
            display: flex;
            background: #18181b;
            border-bottom: 1px solid #3a3a3d;
            padding: 0 24px;
        }
        .tab {
            padding: 16px 24px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            color: #adadb8;
        }
        .tab:hover { color: #efeff1; }
        .tab.active {
            color: #9147ff;
            border-bottom-color: #9147ff;
        }

        /* Tab Content */
        .tab-content {
            display: none;
            padding: 24px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .tab-content.active { display: block; }

        /* Cards */
        .card {
            background: #18181b;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card h3 {
            margin-bottom: 16px;
            color: #efeff1;
            font-size: 16px;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #adadb8;
            font-size: 14px;
        }
        input[type="text"], input[type="password"], select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #3a3a3d;
            border-radius: 4px;
            background: #0e0e10;
            color: #efeff1;
            font-size: 14px;
        }
        textarea { min-height: 80px; resize: vertical; }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            display: inline-block;
        }
        .btn-primary { background: #9147ff; color: white; }
        .btn-primary:hover { background: #772ce8; }
        .btn-secondary { background: #3a3a3d; color: #efeff1; }
        .btn-secondary:hover { background: #464649; }
        .btn-danger { background: #eb0400; color: white; }

        /* Position Picker */
        .position-picker {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            width: 200px;
        }
        .position-btn {
            padding: 20px;
            background: #26262c;
            border: 2px solid transparent;
            border-radius: 4px;
            color: #adadb8;
            cursor: pointer;
            text-align: center;
            font-size: 12px;
        }
        .position-btn:hover { background: #3a3a3d; }
        .position-btn.active {
            border-color: #9147ff;
            background: #9147ff33;
            color: #efeff1;
        }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
        }
        .stat-box {
            background: #26262c;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #9147ff;
        }
        .stat-label {
            color: #adadb8;
            font-size: 14px;
            margin-top: 4px;
        }

        /* Tags */
        .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        .tag {
            background: #3a3a3d;
            padding: 4px 12px;
            border-radius: 16px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tag .remove {
            cursor: pointer;
            color: #eb0400;
            font-weight: bold;
        }

        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #3a3a3d;
            border-radius: 26px;
            transition: 0.3s;
        }
        .toggle-slider:before {
            content: "";
            position: absolute;
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.3s;
        }
        .toggle-switch input:checked + .toggle-slider { background: #9147ff; }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(24px); }

        /* URL Box */
        .url-box {
            background: #0e0e10;
            padding: 12px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
            word-break: break-all;
            cursor: pointer;
        }
        .url-box:hover { background: #1a1a1d; }

        /* Success/Error Messages */
        .message {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        .message.success { background: rgba(0, 173, 3, 0.2); border: 1px solid #00ad03; }
        .message.error { background: rgba(235, 4, 0, 0.2); border: 1px solid #eb0400; }
    </style>
</head>
<body>
    <div class="login-screen" id="loginScreen">
        <div class="login-box">
            <h1>Streamer Dashboard</h1>
            <div class="error" id="loginError"></div>
            <?php if ($oauthAuthorized): ?>
                <!-- OAuth user is logged in - auto-redirect handled by JS -->
                <p style="color: #adadb8; margin-bottom: 16px;">Loading dashboard for <strong><?= htmlspecialchars($login) ?></strong>...</p>
            <?php elseif ($login): ?>
                <p style="color: #adadb8; margin-bottom: 16px;">Channel: <strong><?= htmlspecialchars($login) ?></strong></p>
                <input type="password" id="modPassword" placeholder="Mod Password" autofocus>
                <button onclick="loginWithPassword()">Enter</button>
                <div style="text-align: center; margin: 16px 0; color: #666;">— or —</div>
                <a href="/auth/login.php?return=<?= urlencode('/dashboard.php?login=' . urlencode($login)) ?>" style="display: block; text-align: center; padding: 12px; background: #9147ff; color: white; border-radius: 4px; text-decoration: none;">Login with Twitch</a>
            <?php else: ?>
                <p style="color: #adadb8; margin-bottom: 16px;">Enter your dashboard key or login with Twitch.</p>
                <input type="text" id="dashboardKey" placeholder="Dashboard Key" autofocus>
                <button onclick="loginWithKey()">Enter</button>
                <div style="text-align: center; margin: 16px 0; color: #666;">— or —</div>
                <a href="/auth/login.php?return=<?= urlencode('/dashboard.php') ?>" style="display: block; text-align: center; padding: 12px; background: #9147ff; color: white; border-radius: 4px; text-decoration: none;">Login with Twitch</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="dashboard" id="dashboard">
        <div class="header">
            <h1>Streamer Dashboard</h1>
            <div class="user-info">
                <span id="channelName"></span>
                <span class="role-badge" id="roleBadge">MOD</span>
                <?php if ($isSuperAdmin): ?>
                <span class="role-badge" style="background: #eb0400;">SUPER ADMIN</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isSuperAdmin): ?>
        <div class="admin-bar" style="background: linear-gradient(90deg, #eb0400, #9147ff); padding: 12px 24px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <span style="font-weight: bold;">Quick Access:</span>
            <input type="text" id="adminChannelInput" placeholder="Enter channel name..." style="padding: 8px 12px; border-radius: 4px; border: none; background: rgba(0,0,0,0.3); color: white; width: 200px;">
            <button onclick="goToChannel()" style="padding: 8px 16px; background: rgba(0,0,0,0.3); border: none; color: white; border-radius: 4px; cursor: pointer;">Go to Dashboard</button>
            <button onclick="goToModDashboard()" style="padding: 8px 16px; background: rgba(0,0,0,0.3); border: none; color: white; border-radius: 4px; cursor: pointer;">Go to Mod Dashboard</button>
            <a href="/auth/logout.php" style="margin-left: auto; color: white; text-decoration: none; opacity: 0.8;">Logout</a>
        </div>
        <?php endif; ?>

        <div class="tabs">
            <div class="tab active" data-tab="settings">Settings</div>
            <div class="tab" data-tab="clips">Clip Management</div>
            <div class="tab" data-tab="playlists">Playlists</div>
            <div class="tab" data-tab="stats">Stats</div>
        </div>

        <div class="tab-content active" id="tab-settings">
            <div id="settingsMessage"></div>

            <div class="card">
                <h3>HUD Position</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Position of the clip info overlay on screen.</p>
                <div class="position-picker" id="hudPositionPicker">
                    <button class="position-btn" data-pos="tl">Top Left</button>
                    <button class="position-btn" data-pos="tr">Top Right</button>
                    <button class="position-btn" data-pos="bl">Bottom Left</button>
                    <button class="position-btn" data-pos="br">Bottom Right</button>
                </div>
            </div>

            <div class="card">
                <h3>Top Clips Overlay Position</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Position of the !ctop overlay.</p>
                <div class="position-picker" id="topPositionPicker">
                    <button class="position-btn" data-pos="tl">Top Left</button>
                    <button class="position-btn" data-pos="tr">Top Right</button>
                    <button class="position-btn" data-pos="bl">Bottom Left</button>
                    <button class="position-btn" data-pos="br">Bottom Right</button>
                </div>
            </div>

            <div class="card">
                <h3>Chat Voting</h3>
                <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 12px;">
                    <label class="toggle-switch">
                        <input type="checkbox" id="votingEnabled" onchange="saveVoting()">
                        <span class="toggle-slider"></span>
                    </label>
                    <span>Enable !like and !dislike commands</span>
                </div>
                <div style="display: flex; align-items: center; gap: 16px;">
                    <label class="toggle-switch">
                        <input type="checkbox" id="voteFeedback" onchange="saveVoteFeedback()">
                        <span class="toggle-slider"></span>
                    </label>
                    <span>Show vote confirmation in chat</span>
                </div>
            </div>

            <div class="card" id="refreshCard">
                <h3>Refresh Clips</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Fetch new clips from Twitch.</p>
                <p style="color: #666; font-size: 13px; margin-bottom: 12px;">Last refresh: <span id="lastRefresh">Never</span></p>
                <button class="btn btn-primary" onclick="refreshClips()">Get New Clips</button>
            </div>

            <div class="card" id="modManagementCard">
                <h3>Mod Access</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Add Twitch users who can access your mod dashboard. They just login with their Twitch account - no password needed.</p>
                <div class="form-group">
                    <input type="text" id="newModUsername" placeholder="Enter Twitch username" onkeypress="if(event.key==='Enter')addMod()">
                    <button class="btn btn-primary" style="margin-top: 8px;" onclick="addMod()">Add Mod</button>
                </div>
                <div id="modList" style="margin-top: 16px;">
                    <p style="color: #666; font-size: 13px;">Loading mods...</p>
                </div>
            </div>

            <div class="card">
                <h3>Player URL</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Use this URL as a Browser Source in OBS.</p>
                <div class="url-box" id="playerUrl" onclick="copyPlayerUrl()">Loading...</div>
                <p style="color: #666; font-size: 12px; margin-top: 8px;">Click to copy</p>
            </div>
        </div>

        <div class="tab-content" id="tab-clips">
            <div class="card">
                <h3>Content Filtering</h3>

                <div class="form-group" id="blockedWordsGroup">
                    <label>Blocked Words</label>
                    <p style="color: #666; font-size: 12px; margin-bottom: 8px;">Clips with these words in the title will be hidden.</p>
                    <input type="text" id="newBlockedWord" placeholder="Add word and press Enter" onkeypress="if(event.key==='Enter')addBlockedWord()">
                    <div class="tags" id="blockedWordsTags"></div>
                </div>

                <div class="form-group" id="blockedClippersGroup">
                    <label>Blocked Clippers</label>
                    <p style="color: #666; font-size: 12px; margin-bottom: 8px;">All clips from these users will be hidden.</p>
                    <input type="text" id="newBlockedClipper" placeholder="Add clipper and press Enter" onkeypress="if(event.key==='Enter')addBlockedClipper()">
                    <div class="tags" id="blockedClippersTags"></div>
                </div>
            </div>

            <div class="card">
                <h3>Individual Clip Management</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Use the <a href="#" id="clipBrowserLink" style="color: #9147ff;">Clip Browser</a> to search and manage individual clips.</p>
            </div>
        </div>

        <div class="tab-content" id="tab-playlists">
            <div class="card">
                <h3>Playlists</h3>
                <p style="color: #adadb8;">Use the <a href="#" id="modDashboardLink" style="color: #9147ff;">Mod Dashboard</a> to create and manage playlists.</p>
            </div>
        </div>

        <div class="tab-content" id="tab-stats">
            <div class="stats-grid" id="statsGrid">
                <div class="stat-box">
                    <div class="stat-value" id="statTotal">-</div>
                    <div class="stat-label">Total Clips</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" id="statActive">-</div>
                    <div class="stat-label">Active Clips</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" id="statBlocked">-</div>
                    <div class="stat-label">Blocked Clips</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '';
        const INITIAL_KEY = <?= json_encode($key) ?>;
        const INITIAL_LOGIN = <?= json_encode($login) ?>;
        const OAUTH_AUTHORIZED = <?= json_encode($oauthAuthorized) ?>;
        const IS_SUPER_ADMIN = <?= json_encode($isSuperAdmin) ?>;

        let authKey = INITIAL_KEY;
        let authLogin = INITIAL_LOGIN;
        let authRole = '';
        let authInstance = '';
        let settings = {};

        // Auto-login if key provided or OAuth authorized
        if (INITIAL_KEY) {
            checkAuth(INITIAL_KEY, '');
        } else if (OAUTH_AUTHORIZED && INITIAL_LOGIN) {
            // OAuth user - check auth without key (server will validate OAuth session)
            checkAuth('', '');
        }

        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
            });
        });

        // Position picker
        document.querySelectorAll('.position-picker').forEach(picker => {
            picker.querySelectorAll('.position-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    picker.querySelectorAll('.position-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    const field = picker.id === 'hudPositionPicker' ? 'hud_position' : 'top_position';
                    saveSetting(field, btn.dataset.pos);
                });
            });
        });

        async function checkAuth(key, password) {
            try {
                let url = `${API_BASE}/dashboard_api.php?action=check_login`;
                if (key) url += `&key=${encodeURIComponent(key)}`;
                if (authLogin) url += `&login=${encodeURIComponent(authLogin)}`;
                if (password) url += `&password=${encodeURIComponent(password)}`;

                const res = await fetch(url);
                const data = await res.json();

                if (data.authenticated) {
                    authKey = key;
                    authLogin = data.login;
                    authRole = data.role;
                    showDashboard();
                    loadSettings();
                } else {
                    showError('Invalid credentials');
                }
            } catch (e) {
                showError('Connection error');
            }
        }

        function loginWithKey() {
            const key = document.getElementById('dashboardKey').value.trim();
            if (key) checkAuth(key, '');
        }

        function loginWithPassword() {
            const password = document.getElementById('modPassword').value;
            if (password) checkAuth('', password);
        }

        // Super admin quick access functions
        function goToChannel() {
            const channel = document.getElementById('adminChannelInput')?.value.trim().toLowerCase();
            if (channel) {
                window.location.href = `/dashboard.php?login=${encodeURIComponent(channel)}`;
            }
        }

        function goToModDashboard() {
            const channel = document.getElementById('adminChannelInput')?.value.trim().toLowerCase() || authLogin;
            window.location.href = `/mod_dashboard.php?login=${encodeURIComponent(channel)}`;
        }

        // Allow Enter key in admin channel input
        document.getElementById('adminChannelInput')?.addEventListener('keypress', e => {
            if (e.key === 'Enter') goToChannel();
        });

        document.querySelectorAll('#dashboardKey, #modPassword').forEach(el => {
            if (el) el.addEventListener('keypress', e => {
                if (e.key === 'Enter') {
                    if (el.id === 'dashboardKey') loginWithKey();
                    else loginWithPassword();
                }
            });
        });

        function showError(msg) {
            const el = document.getElementById('loginError');
            el.textContent = msg;
            el.style.display = 'block';
        }

        function showDashboard() {
            document.getElementById('loginScreen').style.display = 'none';
            document.getElementById('dashboard').classList.add('active');
            document.getElementById('channelName').textContent = authLogin;

            const badge = document.getElementById('roleBadge');
            badge.textContent = authRole.toUpperCase();
            badge.className = 'role-badge ' + authRole;

            // Hide elements based on role
            if (authRole === 'mod') {
                document.getElementById('blockedWordsGroup').style.display = 'none';
                document.getElementById('blockedClippersGroup').style.display = 'none';
                document.getElementById('refreshCard').style.display = 'none';
                document.getElementById('modManagementCard').style.display = 'none';
            } else {
                // Load mods list for streamers/admins
                loadMods();
            }

            // Update Clip Browser and Mod Dashboard links with login/key
            const clipBrowserLink = document.getElementById('clipBrowserLink');
            const modDashboardLink = document.getElementById('modDashboardLink');
            if (clipBrowserLink) {
                clipBrowserLink.href = `clip_search.php?login=${encodeURIComponent(authLogin)}&key=${encodeURIComponent(authKey)}`;
            }
            if (modDashboardLink) {
                modDashboardLink.href = `mod_dashboard.php?login=${encodeURIComponent(authLogin)}&key=${encodeURIComponent(authKey)}`;
            }

            // Player URL will be set after loading settings (when we have the instance)
        }

        function updatePlayerUrl() {
            let playerUrl = `https://gmgnrepeat.com/flop/clipplayer_mp4_reel.html?login=${encodeURIComponent(authLogin)}`;
            if (authInstance) {
                playerUrl += `&instance=${encodeURIComponent(authInstance)}`;
            }
            document.getElementById('playerUrl').textContent = playerUrl;
        }

        async function loadSettings() {
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=get_settings&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}`);
                const data = await res.json();

                if (data.error) {
                    console.error('Error loading settings:', data.error);
                    return;
                }

                settings = data.settings;
                authInstance = data.instance || '';

                // Update player URL with instance
                updatePlayerUrl();

                // HUD positions
                setPositionPicker('hudPositionPicker', settings.hud_position || 'tr');
                setPositionPicker('topPositionPicker', settings.top_position || 'br');

                // Voting
                document.getElementById('votingEnabled').checked = settings.voting_enabled;
                document.getElementById('voteFeedback').checked = settings.vote_feedback !== false;

                // Last refresh
                if (settings.last_refresh) {
                    document.getElementById('lastRefresh').textContent = new Date(settings.last_refresh).toLocaleString();
                }

                // Blocked words
                renderTags('blockedWordsTags', settings.blocked_words || [], removeBlockedWord);

                // Blocked clippers
                renderTags('blockedClippersTags', settings.blocked_clippers || [], removeBlockedClipper);

                // Stats
                if (data.stats) {
                    document.getElementById('statTotal').textContent = Number(data.stats.total).toLocaleString();
                    document.getElementById('statActive').textContent = Number(data.stats.active).toLocaleString();
                    document.getElementById('statBlocked').textContent = Number(data.stats.blocked).toLocaleString();
                }
            } catch (e) {
                console.error('Error loading settings:', e);
            }
        }

        function setPositionPicker(pickerId, pos) {
            const picker = document.getElementById(pickerId);
            picker.querySelectorAll('.position-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.pos === pos);
            });
        }

        function renderTags(containerId, items, removeCallback) {
            const container = document.getElementById(containerId);
            container.innerHTML = items.map(item => `
                <span class="tag">
                    ${escapeHtml(item)}
                    <span class="remove" onclick="${removeCallback.name}('${escapeHtml(item)}')">&times;</span>
                </span>
            `).join('');
        }

        async function saveSetting(field, value) {
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=save_settings&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}&field=${field}&value=${encodeURIComponent(value)}`);
                const data = await res.json();
                if (!data.success) {
                    console.error('Save failed:', data.error);
                }
            } catch (e) {
                console.error('Save error:', e);
            }
        }

        function saveVoting() {
            saveSetting('voting_enabled', document.getElementById('votingEnabled').checked);
        }

        function saveVoteFeedback() {
            saveSetting('vote_feedback', document.getElementById('voteFeedback').checked);
        }

        function addBlockedWord() {
            const input = document.getElementById('newBlockedWord');
            const word = input.value.trim().toLowerCase();
            if (!word) return;

            const words = settings.blocked_words || [];
            if (!words.includes(word)) {
                words.push(word);
                settings.blocked_words = words;
                saveSetting('blocked_words', JSON.stringify(words));
                renderTags('blockedWordsTags', words, removeBlockedWord);
            }
            input.value = '';
        }

        function removeBlockedWord(word) {
            const words = (settings.blocked_words || []).filter(w => w !== word);
            settings.blocked_words = words;
            saveSetting('blocked_words', JSON.stringify(words));
            renderTags('blockedWordsTags', words, removeBlockedWord);
        }

        function addBlockedClipper() {
            const input = document.getElementById('newBlockedClipper');
            const clipper = input.value.trim();
            if (!clipper) return;

            const clippers = settings.blocked_clippers || [];
            if (!clippers.includes(clipper)) {
                clippers.push(clipper);
                settings.blocked_clippers = clippers;
                saveSetting('blocked_clippers', JSON.stringify(clippers));
                renderTags('blockedClippersTags', clippers, removeBlockedClipper);
            }
            input.value = '';
        }

        function removeBlockedClipper(clipper) {
            const clippers = (settings.blocked_clippers || []).filter(c => c !== clipper);
            settings.blocked_clippers = clippers;
            saveSetting('blocked_clippers', JSON.stringify(clippers));
            renderTags('blockedClippersTags', clippers, removeBlockedClipper);
        }

        async function loadMods() {
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=get_mods&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}`);
                const data = await res.json();

                if (data.success) {
                    renderModList(data.mods || []);
                } else {
                    document.getElementById('modList').innerHTML = '<p style="color: #eb0400;">Error loading mods</p>';
                }
            } catch (e) {
                console.error('Error loading mods:', e);
            }
        }

        function renderModList(mods) {
            const container = document.getElementById('modList');
            if (mods.length === 0) {
                container.innerHTML = '<p style="color: #666; font-size: 13px;">No mods added yet. Add Twitch usernames above.</p>';
                return;
            }

            container.innerHTML = '<div class="tags">' + mods.map(mod => `
                <span class="tag">
                    ${escapeHtml(mod.mod_username)}
                    <span class="remove" onclick="removeMod('${escapeHtml(mod.mod_username)}')">&times;</span>
                </span>
            `).join('') + '</div>';
        }

        async function addMod() {
            const input = document.getElementById('newModUsername');
            const username = input.value.trim().toLowerCase();
            if (!username) return;

            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=add_mod&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}&mod_username=${encodeURIComponent(username)}`);
                const data = await res.json();

                const msgEl = document.getElementById('settingsMessage');
                if (data.success) {
                    renderModList(data.mods || []);
                    input.value = '';
                    msgEl.innerHTML = '<div class="message success">' + data.message + '</div>';
                } else {
                    msgEl.innerHTML = '<div class="message error">' + (data.error || 'Failed to add mod') + '</div>';
                }
                setTimeout(() => msgEl.innerHTML = '', 5000);
            } catch (e) {
                console.error('Error adding mod:', e);
            }
        }

        async function removeMod(username) {
            if (!confirm(`Remove ${username} as a mod?`)) return;

            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=remove_mod&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}&mod_username=${encodeURIComponent(username)}`);
                const data = await res.json();

                if (data.success) {
                    renderModList(data.mods || []);
                }
            } catch (e) {
                console.error('Error removing mod:', e);
            }
        }

        function refreshClips() {
            window.open(`refresh_clips.php?login=${encodeURIComponent(authLogin)}&key=${encodeURIComponent(authKey)}`, '_blank');
        }

        function copyPlayerUrl() {
            const url = document.getElementById('playerUrl').textContent;
            navigator.clipboard.writeText(url).then(() => {
                alert('URL copied to clipboard!');
            });
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    </script>
</body>
</html>
