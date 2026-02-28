<?php
/**
 * my_channels.php - Dashboard Hub
 *
 * Central entry point for all dashboard access:
 * - Super admins: Access to all channels + admin panel
 * - Archived streamers: Access to their own channel dashboard
 * - Mods: Access to channels they moderate
 * - Others: Helpful message about getting access
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

$currentUser = getCurrentUser();
$isSuperAdmin = $currentUser ? isSuperAdmin() : false;
$pdo = get_db_connection();

header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Content-Security-Policy: upgrade-insecure-requests");

// Determine user's access rights
$isArchivedStreamer = false;
$ownClipCount = 0;
$modChannels = [];

if ($currentUser && $pdo) {
    $userLogin = strtolower($currentUser['login']);

    // Check if user is an archived streamer
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as clip_count FROM clips WHERE login = ?");
        $stmt->execute([$userLogin]);
        $result = $stmt->fetch();
        if ($result && $result['clip_count'] > 0) {
            $isArchivedStreamer = true;
            $ownClipCount = (int)$result['clip_count'];
        }
    } catch (PDOException $e) {
        // Ignore
    }

    // Get channels user moderates
    try {
        $stmt = $pdo->prepare("
            SELECT cm.channel_login, cm.added_at,
                   (SELECT COUNT(*) FROM clips WHERE login = cm.channel_login) as clip_count
            FROM channel_mods cm
            WHERE cm.mod_username = ?
            ORDER BY cm.channel_login ASC
        ");
        $stmt->execute([$userLogin]);
        $modChannels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table might not exist
    }
}

// Get list of all archived streamers for super admin
$allStreamers = [];
if ($isSuperAdmin && $pdo) {
    try {
        $stmt = $pdo->query("
            SELECT login, COUNT(*) as clip_count
            FROM clips
            WHERE blocked = FALSE
            GROUP BY login
            ORDER BY login ASC
        ");
        $allStreamers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Ignore
    }
}

$hasAnyAccess = $isSuperAdmin || $isArchivedStreamer || count($modChannels) > 0;

// Check if user was redirected from dashboard due to not being archived
$notArchivedRedirect = isset($_GET['not_archived']) && $_GET['not_archived'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <title>Dashboard Hub - ClipArchive</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0e0e10;
            color: #efeff1;
            min-height: 100vh;
        }

        .page-header {
            padding: 24px 24px 0;
            max-width: 900px;
            margin: 0 auto;
        }
        .page-header h1 {
            font-size: 24px;
            color: #efeff1;
            margin-bottom: 8px;
        }
        .page-header p {
            color: #adadb8;
            font-size: 14px;
        }

        .badge {
            background: #9147ff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            text-transform: uppercase;
        }
        .badge.admin { background: #eb0400; }
        .badge.streamer { background: #00a67e; }
        .badge.mod { background: #bf94ff; }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 24px;
        }

        .login-prompt {
            text-align: center;
            padding: 80px 20px;
        }
        .login-prompt h2 {
            margin-bottom: 16px;
            color: #efeff1;
            font-size: 28px;
        }
        .login-prompt p {
            color: #adadb8;
            margin-bottom: 24px;
            font-size: 16px;
        }
        .login-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 32px;
            background: #9147ff;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
        }
        .login-btn:hover { background: #772ce8; }
        .login-btn svg { width: 24px; height: 24px; }

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
            display: flex;
            align-items: center;
            gap: 8px;
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
        .channel-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .channel-name {
            font-size: 16px;
            font-weight: 500;
            color: #efeff1;
        }
        .channel-meta {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
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
            transition: background 0.2s;
        }
        .btn-primary { background: #9147ff; }
        .btn-primary:hover { background: #772ce8; }
        .btn-secondary { background: #3a3a3d; }
        .btn-secondary:hover { background: #464649; }
        .btn-danger { background: #eb0400; }
        .btn-danger:hover { background: #c40300; }

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
        .admin-controls {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .admin-input-row {
            display: flex;
            gap: 8px;
            flex: 1;
            min-width: 300px;
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

        .no-access {
            text-align: center;
            padding: 60px 20px;
        }
        .no-access h2 {
            margin-bottom: 16px;
            color: #efeff1;
        }
        .no-access p {
            color: #adadb8;
            margin-bottom: 12px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }
        .no-access .info-box {
            background: #26262c;
            border-radius: 8px;
            padding: 20px;
            margin-top: 24px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
            text-align: left;
        }
        .no-access .info-box h3 {
            margin-bottom: 12px;
            color: #9147ff;
        }
        .no-access .info-box ul {
            color: #adadb8;
            padding-left: 20px;
        }
        .no-access .info-box li {
            margin-bottom: 8px;
        }

        .streamer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        .streamer-card {
            background: #26262c;
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .streamer-card:hover {
            background: #2f2f35;
        }
        .streamer-card a {
            color: #efeff1;
            text-decoration: none;
            font-weight: 500;
        }
        .streamer-card .clip-count {
            font-size: 12px;
            color: #666;
        }

        .warning-banner {
            background: linear-gradient(90deg, #eb0400, #ff6b35);
            padding: 16px 24px;
            text-align: center;
            color: white;
        }
        .warning-banner p {
            color: white;
            margin: 0;
        }
        .warning-banner strong {
            display: block;
            margin-bottom: 4px;
        }

    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/nav.php'; ?>

    <?php if ($notArchivedRedirect): ?>
    <div class="warning-banner">
        <p><strong>Channel Not Archived</strong>
        Your channel doesn't have any clips archived yet. <a href="/apply.php" style="color: white; text-decoration: underline;">Apply for archiving</a> to get started.</p>
    </div>
    <?php endif; ?>

    <?php if (!$currentUser): ?>
    <div class="login-prompt">
        <h2>Dashboard Hub</h2>
        <p>Login with Twitch to access your channel dashboard or channels you moderate.</p>
        <a href="/auth/login.php?return=<?= urlencode('/channels') ?>" class="login-btn">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.64 5.93h1.43v4.28h-1.43m3.93-4.28H17v4.28h-1.43M7 2L3.43 5.57v12.86h4.28V22l3.58-3.57h2.85L20.57 12V2m-1.43 9.29l-2.85 2.85h-2.86l-2.5 2.5v-2.5H7.71V3.43h11.43z"/></svg>
            Login with Twitch
        </a>
    </div>
    <?php else: ?>

    <div class="page-header">
        <h1>Dashboard Hub</h1>
        <p>
            Logged in as <strong><?= htmlspecialchars($currentUser['display_name']) ?></strong>
            <?php if ($isSuperAdmin): ?>
            <span class="badge admin">Super Admin</span>
            <?php elseif ($isArchivedStreamer): ?>
            <span class="badge streamer">Streamer</span>
            <?php elseif (count($modChannels) > 0): ?>
            <span class="badge mod">Mod</span>
            <?php endif; ?>
        </p>
    </div>

    <div class="container">
        <?php if ($isSuperAdmin): ?>
        <div class="super-admin-section">
            <h2>Super Admin Access</h2>
            <p>You have access to all channels and the admin panel.</p>
            <div class="admin-controls">
                <div class="admin-input-row">
                    <input type="text" id="adminChannelInput" placeholder="Enter channel name...">
                    <button class="btn btn-secondary" onclick="goToChannel('mod')">Mod Dashboard</button>
                    <button class="btn btn-secondary" onclick="goToChannel('streamer')">Streamer Dashboard</button>
                </div>
                <a href="/admin.php" class="btn btn-danger">Admin Panel</a>
            </div>
        </div>

        <div class="card">
            <h2>All Archived Streamers (<?= count($allStreamers) ?>)</h2>
            <div class="streamer-grid">
                <?php foreach ($allStreamers as $streamer): ?>
                <div class="streamer-card">
                    <a href="/dashboard/<?= urlencode($streamer['login']) ?>"><?= htmlspecialchars($streamer['login']) ?></a>
                    <span class="clip-count"><?= number_format($streamer['clip_count']) ?> clips</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isArchivedStreamer): ?>
        <div class="card">
            <h2>
                <span class="badge streamer" style="font-size: 10px;">OWNER</span>
                Your Channel
            </h2>
            <div class="channel-list">
                <div class="channel-item">
                    <div class="channel-info">
                        <div>
                            <div class="channel-name"><?= htmlspecialchars($currentUser['display_name']) ?></div>
                            <div class="channel-meta"><?= number_format($ownClipCount) ?> clips archived</div>
                        </div>
                    </div>
                    <div class="channel-actions">
                        <a href="/dashboard/<?= urlencode($currentUser['login']) ?>" class="btn btn-primary">Streamer Dashboard</a>
                        <a href="/mod/<?= urlencode($currentUser['login']) ?>" class="btn btn-secondary">Playlist Manager</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (count($modChannels) > 0): ?>
        <div class="card">
            <h2>
                <span class="badge mod" style="font-size: 10px;">MOD</span>
                Channels You Moderate
            </h2>
            <div class="channel-list">
                <?php foreach ($modChannels as $ch): ?>
                <div class="channel-item">
                    <div class="channel-info">
                        <div>
                            <div class="channel-name"><?= htmlspecialchars($ch['channel_login'] ?? '') ?></div>
                            <div class="channel-meta">
                                <?= number_format($ch['clip_count'] ?? 0) ?> clips
                                <?php if (!empty($ch['added_at'])): ?>
                                &middot; Added <?= date('M j, Y', strtotime($ch['added_at'])) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="channel-actions">
                        <a href="/mod/<?= urlencode($ch['channel_login'] ?? '') ?>" class="btn btn-primary">Mod Dashboard</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$hasAnyAccess): ?>
        <div class="no-access">
            <h2>No Dashboard Access Yet</h2>
            <p>You don't currently have access to any channel dashboards.</p>

            <div class="info-box">
                <h3>How to get access:</h3>
                <ul>
                    <li><strong>As a streamer:</strong> <a href="/apply.php" style="color: #9147ff;">Apply to get your clips archived</a></li>
                    <li><strong>As a mod:</strong> Ask a streamer to add your Twitch username to their mod list in their dashboard settings</li>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function goToChannel(type) {
            const channel = document.getElementById('adminChannelInput')?.value.trim().toLowerCase();
            if (!channel) return;

            if (type === 'mod') {
                window.location.href = `/mod/${encodeURIComponent(channel)}`;
            } else {
                window.location.href = `/dashboard/${encodeURIComponent(channel)}`;
            }
        }

        document.getElementById('adminChannelInput')?.addEventListener('keypress', e => {
            if (e.key === 'Enter') goToChannel('streamer');
        });
    </script>
    <?php endif; ?>
</body>
</html>
