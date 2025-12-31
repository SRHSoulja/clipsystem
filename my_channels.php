<?php
/**
 * my_channels.php - Shows mods which channels they have access to
 *
 * Requires Twitch OAuth login. Shows list of channels where user is a mod.
 */
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Content-Security-Policy: upgrade-insecure-requests");

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

$currentUser = getCurrentUser();
$isSuperAdmin = $currentUser ? isSuperAdmin() : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Channels - ClipArchive</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0e0e10;
            color: #efeff1;
            min-height: 100vh;
        }

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
        .header .badge {
            background: #9147ff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            text-transform: uppercase;
        }
        .header .badge.admin { background: #eb0400; }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 24px;
        }

        .login-prompt {
            text-align: center;
            padding: 60px 20px;
        }
        .login-prompt h2 {
            margin-bottom: 16px;
            color: #efeff1;
        }
        .login-prompt p {
            color: #adadb8;
            margin-bottom: 24px;
        }
        .login-btn {
            display: inline-block;
            padding: 12px 32px;
            background: #9147ff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 16px;
        }
        .login-btn:hover { background: #772ce8; }

        .card {
            background: #18181b;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card h2 {
            margin-bottom: 16px;
            color: #efeff1;
            font-size: 18px;
        }
        .card p {
            color: #adadb8;
            font-size: 14px;
        }

        .channel-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .channel-item {
            background: #26262c;
            border-radius: 8px;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .channel-name {
            font-size: 16px;
            font-weight: 500;
            color: #efeff1;
        }
        .channel-since {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        .channel-actions {
            display: flex;
            gap: 8px;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            color: white;
        }
        .btn-primary { background: #9147ff; }
        .btn-primary:hover { background: #772ce8; }
        .btn-secondary { background: #3a3a3d; }
        .btn-secondary:hover { background: #464649; }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #adadb8;
        }
        .empty-state h3 {
            margin-bottom: 12px;
            color: #efeff1;
        }

        .super-admin-section {
            background: linear-gradient(135deg, #eb0400 0%, #9147ff 100%);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .super-admin-section h2 {
            color: white;
            margin-bottom: 12px;
        }
        .super-admin-section p {
            color: rgba(255,255,255,0.8);
            margin-bottom: 16px;
        }
        .admin-input-row {
            display: flex;
            gap: 8px;
        }
        .admin-input-row input {
            flex: 1;
            padding: 10px 12px;
            border: none;
            border-radius: 4px;
            background: rgba(0,0,0,0.3);
            color: white;
            font-size: 14px;
        }
        .admin-input-row input::placeholder { color: rgba(255,255,255,0.5); }

        .logout-link {
            color: #adadb8;
            text-decoration: none;
        }
        .logout-link:hover { color: #efeff1; }
    </style>
</head>
<body>
    <?php if (!$currentUser): ?>
    <div class="login-prompt">
        <h2>My Channels</h2>
        <p>Login with Twitch to see which channels you can moderate.</p>
        <a href="/auth/login.php?return=<?= urlencode('/my_channels.php') ?>" class="login-btn">Login with Twitch</a>
    </div>
    <?php else: ?>
    <div class="header">
        <h1>My Channels</h1>
        <div class="user-info">
            <span>Logged in as <strong><?= htmlspecialchars($currentUser['display_name']) ?></strong></span>
            <?php if ($isSuperAdmin): ?>
            <span class="badge admin">Super Admin</span>
            <?php endif; ?>
            <a href="/auth/logout.php" class="logout-link">Logout</a>
        </div>
    </div>

    <div class="container">
        <?php if ($isSuperAdmin): ?>
        <div class="super-admin-section">
            <h2>Super Admin Access</h2>
            <p>You have access to all channels. Enter a channel name to go directly to their dashboard.</p>
            <div class="admin-input-row">
                <input type="text" id="adminChannelInput" placeholder="Enter channel name...">
                <button class="btn btn-secondary" onclick="goToModDashboard()">Mod Dashboard</button>
                <button class="btn btn-secondary" onclick="goToStreamerDashboard()">Streamer Dashboard</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Your Own Channel</h2>
            <div class="channel-list">
                <div class="channel-item">
                    <div>
                        <div class="channel-name"><?= htmlspecialchars($currentUser['display_name']) ?></div>
                        <div class="channel-since">Your channel</div>
                    </div>
                    <div class="channel-actions">
                        <a href="/mod_dashboard.php?login=<?= urlencode($currentUser['login']) ?>" class="btn btn-primary">Mod Dashboard</a>
                        <a href="/dashboard.php?login=<?= urlencode($currentUser['login']) ?>" class="btn btn-secondary">Streamer Dashboard</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Channels You Moderate</h2>
            <div id="channelList">
                <div class="empty-state">
                    <p>Loading...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        async function loadChannels() {
            try {
                const res = await fetch('/dashboard_api.php?action=my_channels');
                const data = await res.json();

                const container = document.getElementById('channelList');

                if (!data.success) {
                    container.innerHTML = '<div class="empty-state"><p>Error loading channels</p></div>';
                    return;
                }

                if (!data.channels || data.channels.length === 0) {
                    container.innerHTML = `
                        <div class="empty-state">
                            <h3>No channels yet</h3>
                            <p>You haven't been added as a mod to any channels.<br>
                            Ask a streamer to add your username in their dashboard settings.</p>
                        </div>
                    `;
                    return;
                }

                container.innerHTML = '<div class="channel-list">' + data.channels.map(ch => `
                    <div class="channel-item">
                        <div>
                            <div class="channel-name">${escapeHtml(ch.channel_login)}</div>
                            <div class="channel-since">Added ${new Date(ch.added_at).toLocaleDateString()}</div>
                        </div>
                        <div class="channel-actions">
                            <a href="/mod_dashboard.php?login=${encodeURIComponent(ch.channel_login)}" class="btn btn-primary">Mod Dashboard</a>
                        </div>
                    </div>
                `).join('') + '</div>';
            } catch (e) {
                console.error('Error loading channels:', e);
                document.getElementById('channelList').innerHTML = '<div class="empty-state"><p>Error loading channels</p></div>';
            }
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function goToModDashboard() {
            const channel = document.getElementById('adminChannelInput')?.value.trim().toLowerCase();
            if (channel) {
                window.location.href = `/mod_dashboard.php?login=${encodeURIComponent(channel)}`;
            }
        }

        function goToStreamerDashboard() {
            const channel = document.getElementById('adminChannelInput')?.value.trim().toLowerCase();
            if (channel) {
                window.location.href = `/dashboard.php?login=${encodeURIComponent(channel)}`;
            }
        }

        document.getElementById('adminChannelInput')?.addEventListener('keypress', e => {
            if (e.key === 'Enter') goToModDashboard();
        });

        loadChannels();
    </script>
    <?php endif; ?>
</body>
</html>
