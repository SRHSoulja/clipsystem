<?php
/**
 * dashboard.php - Streamer Dashboard
 *
 * Self-service dashboard for streamers to manage their clip reel.
 * Access: Twitch OAuth required. dashboard.php?login=username
 * Super admins (thearsondragon, cliparchive) can access any channel.
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Content-Security-Policy: upgrade-insecure-requests");

// Get pdo for nav
$pdo = get_db_connection();

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
$isArchivedStreamer = false;

if ($currentUser) {
    $isSuperAdmin = isSuperAdmin();
    if ($isSuperAdmin) {
        $oauthAuthorized = true;
        // Super admins can specify any login, or default to their own
        if (!$login) {
            $login = strtolower($currentUser['login']);
        }
    } elseif (!$login || $login === strtolower($currentUser['login'])) {
        // Regular users can access their own channel - but only if they're archived
        $login = strtolower($currentUser['login']);

        // Check if user is an archived streamer (has clips)
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT 1 FROM clips WHERE login = ? LIMIT 1");
                $stmt->execute([$login]);
                $isArchivedStreamer = (bool)$stmt->fetch();
            } catch (PDOException $e) {
                // Ignore - will deny access
            }
        }

        if ($isArchivedStreamer) {
            $oauthAuthorized = true;
        } else {
            // Not an archived streamer - redirect to dashboard hub with explanation
            header('Location: /channels?not_archived=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bangers&family=Inter&family=Montserrat&family=Oswald&family=Permanent+Marker&family=Poppins&family=Press+Start+2P&family=Roboto&display=swap" rel="stylesheet">
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

        /* View As Mode Styles */
        .view-as-banner {
            background: linear-gradient(90deg, #ff9800, #ff5722);
            color: white;
            padding: 10px 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
        }
        .view-as-banner button {
            background: rgba(0,0,0,0.3);
            border: none;
            color: white;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }
        .view-as-banner button:hover { background: rgba(0,0,0,0.5); }
        body.view-as-active .admin-bar-wrapper { display: none !important; }
        body.view-as-active #viewAsBanner { display: flex !important; }
        body.view-as-active .admin-only { display: none !important; }

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
        .btn-danger { background: #ff4757; color: white; }
        .btn-danger:hover { background: #ee5a24; }

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

        /* Banner Overlay */
        .banner-preview-container {
            background: #000;
            border-radius: 8px;
            position: relative;
            height: 200px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .banner-preview-container .placeholder-text {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            color: #3a3a3d; font-size: 14px;
        }
        .banner-preview {
            position: absolute; left: 0; right: 0;
            display: flex; align-items: center; justify-content: center;
            padding: 12px 24px; z-index: 1; transition: all 0.3s ease; font-weight: 600;
            overflow: hidden;
        }
        .banner-preview.pos-top { top: 0; }
        .banner-preview.pos-center { top: 50%; transform: translateY(-50%); }
        .banner-preview.pos-bottom { bottom: 0; }
        .banner-form-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
        }
        .banner-form-grid .full-width { grid-column: 1 / -1; }
        .banner-form-grid label {
            display: block; font-size: 13px; color: #adadb8; margin-bottom: 6px;
        }
        .banner-form-grid input[type="text"],
        .banner-form-grid select {
            width: 100%; padding: 8px 12px; background: #1f1f23; border: 1px solid #3a3a3d;
            border-radius: 4px; color: #efeff1; font-size: 14px;
        }
        .banner-form-grid select { cursor: pointer; }
        .color-input-group {
            display: flex; align-items: center; gap: 8px;
        }
        .color-input-group input[type="color"] {
            width: 40px; height: 36px; border: 1px solid #3a3a3d; border-radius: 4px;
            padding: 2px; background: #0e0e10; cursor: pointer;
        }
        .color-input-group input[type="text"] { width: 90px; font-family: monospace; }
        .slider-group {
            display: flex; align-items: center; gap: 12px;
        }
        .slider-group input[type="range"] {
            flex: 1; -webkit-appearance: none; height: 6px; background: #3a3a3d; border-radius: 3px;
        }
        .slider-group input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none; width: 16px; height: 16px; background: #9147ff; border-radius: 50%; cursor: pointer;
        }
        .slider-group .slider-value {
            min-width: 40px; text-align: right; color: #adadb8; font-size: 13px;
        }
        .option-selector { display: flex; gap: 8px; flex-wrap: wrap; }
        .option-btn {
            padding: 8px 16px; background: #26262c; border: 2px solid transparent;
            border-radius: 4px; color: #adadb8; cursor: pointer; font-size: 13px;
        }
        .option-btn:hover { background: #3a3a3d; }
        .option-btn.active {
            border-color: #9147ff; background: #9147ff33; color: #efeff1;
        }
        @keyframes bannerPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }
        /* bannerScroll keyframes set dynamically via JS to match container width */

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

        /* Mod entries with permissions */
        .mod-entry {
            background: #26262c;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 12px;
        }
        .mod-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .mod-name {
            font-weight: 600;
            color: #efeff1;
        }
        .btn-remove {
            background: transparent;
            border: none;
            color: #eb0400;
            font-size: 20px;
            cursor: pointer;
            padding: 0 8px;
            line-height: 1;
        }
        .btn-remove:hover {
            color: #ff6b6b;
        }
        .mod-permissions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .perm-checkbox {
            display: flex;
            align-items: center;
            gap: 4px;
            background: #3a3a3d;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            color: #adadb8;
            cursor: pointer;
        }
        .perm-checkbox:hover {
            background: #464649;
        }
        .perm-checkbox input[type="checkbox"] {
            width: 14px;
            height: 14px;
            cursor: pointer;
        }
        .perm-checkbox input[type="checkbox"]:checked + span {
            color: #9147ff;
        }

        /* Slider styling */
        .slider-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .slider-group label {
            font-size: 14px;
            color: #adadb8;
        }
        input[type="range"] {
            flex: 1;
            -webkit-appearance: none;
            appearance: none;
            height: 6px;
            background: #3a3a3d;
            border-radius: 3px;
            cursor: pointer;
        }
        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 18px;
            height: 18px;
            background: #9147ff;
            border-radius: 50%;
            cursor: pointer;
        }
        input[type="range"]::-moz-range-thumb {
            width: 18px;
            height: 18px;
            background: #9147ff;
            border-radius: 50%;
            cursor: pointer;
            border: none;
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
        .toggle-switch input:disabled + .toggle-slider { opacity: 0.5; cursor: not-allowed; }
        .toggle-switch.saving .toggle-slider:before {
            background: linear-gradient(90deg, #fff, #ccc, #fff);
            background-size: 200% 100%;
            animation: shimmer 1s infinite;
        }
        @keyframes shimmer {
            0% { background-position: 100% 0; }
            100% { background-position: -100% 0; }
        }

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

        /* Command Toggles */
        .command-group {
            background: #26262c;
            border-radius: 8px;
            padding: 16px;
        }
        .command-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #3a3a3d;
        }
        .command-toggle:last-child { border-bottom: none; }
        .command-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .command-info .command-name {
            color: #00ff7f;
            font-family: monospace;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .command-info .command-desc {
            color: #adadb8;
            font-size: 12px;
        }

        /* Role Tags */
        .role-tag {
            font-size: 9px;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .role-tag.viewer {
            background: #9147ff;
            color: white;
        }
        .role-tag.mod {
            background: #00ad03;
            color: white;
        }
        .role-tag.vip {
            background: #e91916;
            color: white;
        }

        /* Success/Error Messages */
        .message {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        .message.success { background: rgba(0, 173, 3, 0.2); border: 1px solid #00ad03; }
        .message.error { background: rgba(235, 4, 0, 0.2); border: 1px solid #eb0400; }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }
        .toast {
            background: #18181b;
            border: 1px solid #3a3a3d;
            border-radius: 8px;
            padding: 12px 16px;
            min-width: 280px;
            max-width: 400px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
            transform: translateX(120%);
            transition: transform 0.3s ease;
            pointer-events: auto;
        }
        .toast.show {
            transform: translateX(0);
        }
        .toast.success {
            border-color: #00ad03;
            background: linear-gradient(135deg, #18181b 0%, rgba(0, 173, 3, 0.1) 100%);
        }
        .toast.error {
            border-color: #eb0400;
            background: linear-gradient(135deg, #18181b 0%, rgba(235, 4, 0, 0.1) 100%);
        }
        .toast.info {
            border-color: #9147ff;
            background: linear-gradient(135deg, #18181b 0%, rgba(145, 71, 255, 0.1) 100%);
        }
        .toast-icon {
            font-size: 20px;
            flex-shrink: 0;
        }
        .toast-content {
            flex: 1;
        }
        .toast-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }
        .toast-message {
            font-size: 13px;
            color: #adadb8;
        }
        .toast-close {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 4px;
            font-size: 18px;
            line-height: 1;
        }
        .toast-close:hover {
            color: #efeff1;
        }

        /* Loading spinner for buttons */
        .btn.loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
        }
        .btn.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Mobile Responsive Styles */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 12px;
                text-align: center;
                padding: 12px 16px;
            }
            .header .user-info {
                flex-wrap: wrap;
                justify-content: center;
            }
            .tabs {
                overflow-x: auto;
                padding: 0 8px;
                -webkit-overflow-scrolling: touch;
            }
            .tab {
                padding: 12px 16px;
                white-space: nowrap;
                font-size: 14px;
            }
            .tab-content {
                padding: 16px;
            }
            .card {
                padding: 16px;
            }
            .position-picker {
                gap: 8px;
            }
            .position-btn {
                width: 50px;
                height: 40px;
                font-size: 11px;
            }
            .command-toggle {
                flex-wrap: wrap;
            }
            .command-info {
                flex: 1;
                min-width: 150px;
            }
            .command-info .command-name {
                flex-wrap: wrap;
            }
            .url-box {
                font-size: 11px;
                padding: 10px;
            }
            .tags {
                gap: 6px;
            }
            .tag {
                font-size: 12px;
                padding: 4px 10px;
            }
            /* Super admin quick access */
            .admin-quick-access {
                flex-wrap: wrap !important;
            }
            .admin-quick-access input {
                min-width: 150px;
            }
            /* Stats grid */
            .stats-grid {
                grid-template-columns: 1fr !important;
            }
            /* Toast positioning on mobile */
            .toast-container {
                top: auto;
                bottom: 20px;
                right: 10px;
                left: 10px;
            }
            .toast {
                min-width: auto;
                max-width: none;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 18px;
            }
            .role-badge {
                font-size: 10px;
                padding: 3px 6px;
            }
            .tab {
                padding: 10px 12px;
                font-size: 13px;
            }
            .card h3 {
                font-size: 15px;
            }
            .btn {
                padding: 10px 16px;
                font-size: 13px;
            }
            .toggle-switch {
                width: 44px;
                height: 24px;
            }
            .toggle-slider:before {
                height: 18px;
                width: 18px;
            }
            .toggle-switch input:checked + .toggle-slider:before {
                transform: translateX(20px);
            }
            .form-group label {
                font-size: 13px;
            }
            input[type="text"], select {
                padding: 10px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/nav.php'; ?>

    <!-- Toast Notification Container -->
    <div class="toast-container" id="toastContainer"></div>

    <div class="login-screen" id="loginScreen">
        <div class="login-box">
            <h1>Streamer Dashboard</h1>
            <div class="error" id="loginError"></div>
            <?php if ($oauthAuthorized): ?>
                <!-- OAuth user is logged in - auto-redirect handled by JS -->
                <p style="color: #adadb8; margin-bottom: 16px;">Loading dashboard for <strong><?= htmlspecialchars($login) ?></strong>...</p>
            <?php elseif ($login): ?>
                <p style="color: #adadb8; margin-bottom: 16px;">Channel: <strong><?= htmlspecialchars($login) ?></strong></p>
                <p style="color: #666; font-size: 13px; margin-bottom: 16px;">Login with Twitch to access your dashboard.</p>
                <a href="/auth/login.php?return=<?= urlencode('/dashboard/' . urlencode($login)) ?>" style="display: block; text-align: center; padding: 12px; background: #9147ff; color: white; border-radius: 4px; text-decoration: none;">Login with Twitch</a>
                <p style="color: #666; font-size: 12px; margin-top: 16px; text-align: center;">Looking to moderate? Go to the <a href="/mod/<?= urlencode($login) ?>" style="color: #9147ff;">Mod Dashboard</a> instead.</p>
            <?php else: ?>
                <p style="color: #adadb8; margin-bottom: 16px;">Enter your dashboard key or login with Twitch.</p>
                <input type="text" id="dashboardKey" placeholder="Dashboard Key" autofocus>
                <button onclick="loginWithKey()">Enter</button>
                <div style="text-align: center; margin: 16px 0; color: #666;">or</div>
                <a href="/auth/login.php?return=<?= urlencode('/dashboard.php') ?>" style="display: block; text-align: center; padding: 12px; background: #9147ff; color: white; border-radius: 4px; text-decoration: none;">Login with Twitch</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="dashboard" id="dashboard">
        <div class="header">
            <h1>Streamer Dashboard</h1>
            <div class="user-info" style="display: flex; align-items: center; gap: 12px;">
                <select id="channelSwitcher" onchange="switchChannel(this.value)" style="background: #26262c; color: #efeff1; border: 1px solid #3a3a3d; border-radius: 4px; padding: 6px 10px; font-size: 14px; cursor: pointer;">
                    <option value="" id="currentChannelOption">Loading...</option>
                </select>
                <span class="role-badge" id="roleBadge">MOD</span>
                <?php if ($isSuperAdmin): ?>
                <span class="role-badge" style="background: #eb0400;">SUPER ADMIN</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isSuperAdmin || $oauthAuthorized): ?>
        <!-- View As Mode Banner (shown when active) -->
        <div class="view-as-banner" id="viewAsBanner" style="display: none;">
            <svg viewBox="0 0 24 24" fill="currentColor" style="width:18px;height:18px;"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
            <span>Viewing as: <strong id="viewAsRoleName">Mod</strong></span>
            <span style="opacity:0.8;" id="viewAsDescription">(You're seeing what a mod would see)</span>
            <button onclick="exitViewAsMode()">Exit View Mode</button>
        </div>
        <?php endif; ?>

        <?php if ($isSuperAdmin): ?>
        <div class="admin-bar-wrapper admin-only">
            <div class="admin-bar" style="background: linear-gradient(90deg, #eb0400, #9147ff); padding: 12px 24px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                <span style="font-weight: bold;">Quick Access:</span>
                <input type="text" id="adminChannelInput" placeholder="Enter channel name..." style="padding: 8px 12px; border-radius: 4px; border: none; background: rgba(0,0,0,0.3); color: white; width: 200px;">
                <button onclick="goToChannel()" style="padding: 8px 16px; background: rgba(0,0,0,0.3); border: none; color: white; border-radius: 4px; cursor: pointer;">Go to Dashboard</button>
                <button onclick="goToModDashboard()" style="padding: 8px 16px; background: rgba(0,0,0,0.3); border: none; color: white; border-radius: 4px; cursor: pointer;">Go to Mod Dashboard</button>
                <a href="/admin.php" style="padding: 8px 16px; background: rgba(255,255,255,0.2); border: none; color: white; border-radius: 4px; text-decoration: none; font-weight: bold;">⚙️ Admin Dashboard</a>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;opacity:0.8;"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                    <select id="viewAsSelect" onchange="enterViewAsMode(this.value)" style="background: rgba(0,0,0,0.3); color: white; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; padding: 4px 8px; font-size: 13px; cursor: pointer;">
                        <option value="">View As...</option>
                        <option value="admin">Super Admin</option>
                        <option value="streamer">Streamer</option>
                        <option value="mod">Mod (default perms)</option>
                        <option value="mod_limited">Mod (minimal perms)</option>
                    </select>
                </div>
                <a href="/auth/logout.php" style="margin-left: auto; color: white; text-decoration: none; opacity: 0.8;">Logout</a>
            </div>
        </div>
        <?php elseif ($oauthAuthorized): ?>
        <div class="admin-bar-wrapper admin-only" style="background: linear-gradient(90deg, #9147ff, #772ce8); padding: 10px 24px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 14px;">
            <span style="font-weight: bold;">Your Channel</span>
            <a href="/mod/<?php echo urlencode($login); ?>" style="color: white; text-decoration: none; padding: 6px 12px; background: rgba(0,0,0,0.2); border-radius: 4px;">Mod Dashboard</a>
            <div style="display: flex; align-items: center; gap: 6px;">
                <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;opacity:0.8;"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                <select id="viewAsSelect" onchange="enterViewAsMode(this.value)" style="background: rgba(0,0,0,0.3); color: white; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; padding: 4px 8px; font-size: 13px; cursor: pointer;">
                    <option value="">View As...</option>
                    <option value="streamer">Streamer</option>
                    <option value="mod">Mod (default perms)</option>
                    <option value="mod_limited">Mod (minimal perms)</option>
                </select>
            </div>
            <a href="/auth/logout.php" style="margin-left: auto; color: white; text-decoration: none; opacity: 0.8;">Logout</a>
        </div>
        <?php endif; ?>

        <div class="tabs">
            <div class="tab active" data-tab="overlays">Overlays</div>
            <div class="tab" data-tab="bot">Bot</div>
            <div class="tab" data-tab="settings">Settings</div>
            <div class="tab" data-tab="weighting" data-permission="edit_weighting">Clip Weighting</div>
            <div class="tab" data-tab="clips" data-permission="block_clips">Clip Management</div>
            <div class="tab" data-tab="playlists" data-permission="manage_playlists">Playlists</div>
            <div class="tab" data-tab="stats" data-permission="view_stats">Stats</div>
        </div>

        <div class="tab-content active" id="tab-overlays">

            <div class="card">
                <h3>Player URL</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Use this URL as a Browser Source in OBS. All overlays below appear on this player.</p>
                <div class="url-box" id="playerUrl" onclick="copyPlayerUrl()">Loading...</div>
                <p style="color: #666; font-size: 12px; margin-top: 8px;">Click to copy</p>

                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #3a3a3d;">
                    <h4 style="color: #adadb8; font-size: 13px; margin-bottom: 10px;">Recommended OBS Browser Source Settings</h4>
                    <div style="display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; font-size: 13px; color: #adadb8;">
                        <span style="color: #666;">Width:</span><span style="color: #efeff1;">1920</span>
                        <span style="color: #666;">Height:</span><span style="color: #efeff1;">1080</span>
                        <span style="color: #666;">FPS:</span><span style="color: #efeff1;">30 (or match your canvas)</span>
                        <span style="color: #666;">Custom CSS:</span><span style="color: #efeff1;">Leave empty</span>
                    </div>
                    <div style="margin-top: 10px; font-size: 12px; color: #666; display: flex; flex-direction: column; gap: 4px;">
                        <span>&#9745; Control audio via OBS</span>
                        <span>&#9745; Shutdown source when not visible</span>
                        <span>&#9745; Refresh browser when scene becomes active</span>
                    </div>
                    <p style="color: #666; font-size: 11px; margin-top: 8px;">Tip: Check all three so clips stop playing and reset when you switch away from the scene.</p>
                </div>
            </div>

            <div class="card" data-permission="edit_hud">
                <h3>Top Clips Overlay Position</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Position of the !ctop overlay.</p>
                <div class="position-picker" id="topPositionPicker">
                    <button class="position-btn" data-pos="tl">Top Left</button>
                    <button class="position-btn" data-pos="tr">Top Right</button>
                    <button class="position-btn" data-pos="bl">Bottom Left</button>
                    <button class="position-btn" data-pos="br">Bottom Right</button>
                </div>
            </div>

            <div class="card" id="bannerOverlayCard" data-permission="edit_hud">
                <h3>Banner Overlay</h3>
                <p style="color: #adadb8; margin-bottom: 16px; font-size: 13px;">
                    Add a customizable text banner to your clip player. Great for BRB messages, announcements, or branding.
                </p>

                <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 20px;">
                    <label class="toggle-switch">
                        <input type="checkbox" id="bannerEnabled" onchange="updateBannerPreview(); debouncedSaveBanner();">
                        <span class="toggle-slider"></span>
                    </label>
                    <span>Enable Banner Overlay</span>
                </div>

                <!-- Live Preview -->
                <div class="banner-preview-container" id="bannerPreviewContainer">
                    <span class="placeholder-text">Banner Preview</span>
                    <div class="banner-preview pos-top" id="bannerPreview" style="display:none;">
                        <span id="bannerPreviewText">Be right back!</span>
                    </div>
                </div>

                <div class="banner-form-grid">
                    <div class="full-width">
                        <label>Banner Text (max 200 chars)</label>
                        <input type="text" id="bannerText" maxlength="200" placeholder="Be right back!" oninput="updateBannerPreview(); debouncedSaveBanner();">
                    </div>

                    <div>
                        <label>Text Color</label>
                        <div class="color-input-group">
                            <input type="color" id="bannerTextColor" value="#ffffff" oninput="syncColorText('bannerTextColor','bannerTextColorHex'); updateBannerPreview(); debouncedSaveBanner();">
                            <input type="text" id="bannerTextColorHex" value="#ffffff" maxlength="7" oninput="syncColorPicker('bannerTextColorHex','bannerTextColor'); updateBannerPreview(); debouncedSaveBanner();">
                        </div>
                    </div>

                    <div>
                        <label>Background Color</label>
                        <div class="color-input-group">
                            <input type="color" id="bannerBgColor" value="#9147ff" oninput="syncColorText('bannerBgColor','bannerBgColorHex'); updateBannerPreview(); debouncedSaveBanner();">
                            <input type="text" id="bannerBgColorHex" value="#9147ff" maxlength="7" oninput="syncColorPicker('bannerBgColorHex','bannerBgColor'); updateBannerPreview(); debouncedSaveBanner();">
                        </div>
                    </div>

                    <div>
                        <label>Background Opacity</label>
                        <div class="slider-group">
                            <input type="range" id="bannerOpacity" min="0" max="100" value="85" oninput="updateBannerPreview(); debouncedSaveBanner();">
                            <span class="slider-value" id="bannerOpacityValue">85%</span>
                        </div>
                    </div>

                    <div>
                        <label>Font Size</label>
                        <div class="slider-group">
                            <input type="range" id="bannerFontSize" min="12" max="72" value="32" oninput="updateBannerPreview(); debouncedSaveBanner();">
                            <span class="slider-value" id="bannerFontSizeValue">32px</span>
                        </div>
                    </div>

                    <div>
                        <label>Font</label>
                        <select id="bannerFontFamily" onchange="updateBannerPreview(); debouncedSaveBanner();">
                            <option value="Inter">Inter (Clean)</option>
                            <option value="Roboto">Roboto (Readable)</option>
                            <option value="Poppins">Poppins (Friendly)</option>
                            <option value="Montserrat">Montserrat (Bold)</option>
                            <option value="Press Start 2P">Press Start 2P (Retro)</option>
                            <option value="Permanent Marker">Permanent Marker (Handwritten)</option>
                            <option value="Bangers">Bangers (Comic)</option>
                            <option value="Oswald">Oswald (Condensed)</option>
                        </select>
                    </div>

                    <div>
                        <label>Position</label>
                        <div class="option-selector" id="bannerPositionSelector">
                            <button class="option-btn active" data-value="top" onclick="selectBannerOption('bannerPositionSelector', 'top')">Top</button>
                            <button class="option-btn" data-value="center" onclick="selectBannerOption('bannerPositionSelector', 'center')">Center</button>
                            <button class="option-btn" data-value="bottom" onclick="selectBannerOption('bannerPositionSelector', 'bottom')">Bottom</button>
                        </div>
                    </div>

                    <div>
                        <label>Shape</label>
                        <div class="option-selector" id="bannerShapeSelector">
                            <button class="option-btn active" data-value="rectangle" onclick="selectBannerOption('bannerShapeSelector', 'rectangle')">Rectangle</button>
                            <button class="option-btn" data-value="rounded" onclick="selectBannerOption('bannerShapeSelector', 'rounded')">Rounded</button>
                            <button class="option-btn" data-value="pill" onclick="selectBannerOption('bannerShapeSelector', 'pill')">Pill</button>
                        </div>
                    </div>

                    <div>
                        <label>Border</label>
                        <div class="option-selector" id="bannerBorderSelector">
                            <button class="option-btn active" data-value="none" onclick="selectBannerOption('bannerBorderSelector', 'none')">None</button>
                            <button class="option-btn" data-value="solid" onclick="selectBannerOption('bannerBorderSelector', 'solid')">Solid</button>
                            <button class="option-btn" data-value="glow" onclick="selectBannerOption('bannerBorderSelector', 'glow')">Glow</button>
                        </div>
                    </div>

                    <div>
                        <label>Animation</label>
                        <div class="option-selector" id="bannerAnimationSelector">
                            <button class="option-btn active" data-value="none" onclick="selectBannerOption('bannerAnimationSelector', 'none')">None</button>
                            <button class="option-btn" data-value="pulse" onclick="selectBannerOption('bannerAnimationSelector', 'pulse')">Pulse</button>
                            <button class="option-btn" data-value="scroll" onclick="selectBannerOption('bannerAnimationSelector', 'scroll')">Scroll</button>
                        </div>
                    </div>

                    <div id="scrollSpeedGroup" style="display:none;">
                        <label>Scroll Speed <span style="color:#666;font-weight:normal;font-size:12px;">(preview speed may differ from stream)</span></label>
                        <div class="slider-group">
                            <input type="range" id="bannerScrollSpeed" min="3" max="20" value="8" oninput="updateBannerPreview(); debouncedSaveBanner();">
                            <span class="slider-value" id="bannerScrollSpeedValue">8s</span>
                        </div>
                    </div>

                    <div class="full-width" style="border-top:1px solid #2a2a2d;padding-top:16px;margin-top:8px;">
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                            <label class="toggle-switch">
                                <input type="checkbox" id="bannerTimedEnabled" onchange="updateBannerPreview(); debouncedSaveBanner();">
                                <span class="toggle-slider"></span>
                            </label>
                            <span>Timed Display <span style="color:#666;font-size:12px;">Show banner on an interval instead of always</span></span>
                        </div>
                    </div>

                    <div id="timedDisplayGroup" style="display:none;">
                        <label>Show For</label>
                        <div class="slider-group">
                            <input type="range" id="bannerShowDuration" min="5" max="120" step="5" value="15" oninput="updateBannerPreview(); debouncedSaveBanner();">
                            <span class="slider-value" id="bannerShowDurationValue">15s</span>
                        </div>
                    </div>

                    <div id="timedIntervalGroup" style="display:none;">
                        <label>Every</label>
                        <div class="slider-group">
                            <input type="range" id="bannerInterval" min="1" max="30" value="5" oninput="updateBannerPreview(); debouncedSaveBanner();">
                            <span class="slider-value" id="bannerIntervalValue">5 min</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" data-permission="edit_hud">
                <h3>HUD Position</h3>
                <p style="color: #adadb8; margin-bottom: 12px; font-size: 13px;">Position of the clip info overlay on each player.</p>
                <div style="display:flex;gap:24px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:200px;">
                        <label style="color:#adadb8;font-size:13px;margin-bottom:8px;display:block;">Desktop ClipTV</label>
                        <div class="position-picker" id="hudPositionPicker" style="display:flex;gap:6px;">
                            <button class="position-btn" data-pos="tl">Left</button>
                            <button class="position-btn" data-pos="tc">Center</button>
                            <button class="position-btn" data-pos="tr">Right</button>
                        </div>
                    </div>
                    <div style="flex:1;min-width:200px;">
                        <label style="color:#adadb8;font-size:13px;margin-bottom:8px;display:block;">Discord Activity</label>
                        <div class="position-picker" id="discordHudPositionPicker" style="display:flex;gap:6px;">
                            <button class="position-btn" data-pos="tl">Left</button>
                            <button class="position-btn" data-pos="tc">Center</button>
                            <button class="position-btn" data-pos="tr">Right</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-content" id="tab-bot">
            <div id="botSetupPrompt" style="display:none; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border: 1px solid #9147ff40; border-radius: 12px; padding: 24px; margin-bottom: 20px; text-align: center;">
                <p style="color: #efeff1; font-size: 16px; font-weight: 600; margin-bottom: 8px;">Get started by inviting the bot to your channel</p>
                <p style="color: #adadb8; font-size: 13px; margin-bottom: 16px;">The ClipArchive bot powers all chat commands below. Invite it first, then customize your settings.</p>
                <button onclick="inviteBot()" class="btn btn-primary" style="font-size: 15px; padding: 10px 28px;">Invite Bot to Channel</button>
            </div>

            <div class="card" data-permission="edit_bot_settings">
                <h3>Chat Bot</h3>
                <p style="color: #adadb8; margin-bottom: 12px; font-size: 13px;">
                    The ClipArchive bot enables chat commands like !cclip, !like, !dislike, !cfind and more.
                </p>
                <div id="botStatusContainer" style="display: flex; align-items: center; gap: 16px; margin-bottom: 16px;">
                    <span id="botStatusIndicator" style="width: 12px; height: 12px; border-radius: 50%; background: #666;"></span>
                    <span id="botStatusText">Checking bot status...</span>
                </div>
                <div style="display: flex; gap: 12px;">
                    <button id="inviteBotBtn" onclick="inviteBot()" class="btn" style="display: none;">
                        Invite Bot to Channel
                    </button>
                    <button id="removeBotBtn" onclick="removeBot()" class="btn btn-danger" style="display: none;">
                        Remove Bot from Channel
                    </button>
                </div>
            </div>

            <div class="card" data-permission="edit_bot_settings">
                <h3>Bot Response Mode</h3>
                <div style="display: flex; align-items: center; gap: 16px;">
                    <label class="toggle-switch">
                        <input type="checkbox" id="silentPrefix" onchange="saveSilentPrefix()">
                        <span class="toggle-slider"></span>
                    </label>
                    <span>Silent mode (prefix responses with ! to hide from on-screen chat)</span>
                </div>
                <p style="color: #adadb8; margin-top: 8px; font-size: 13px;">
                    When enabled, bot responses start with "!" so they won't appear in chat overlays that filter out commands.
                    Useful if viewers use commands like !cfind frequently.
                </p>
            </div>

            <div class="card" data-permission="edit_voting">
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

            <div class="card" id="commandSettingsCard" data-permission="toggle_commands">
                <h3>Bot Commands</h3>
                <p style="color: #adadb8; margin-bottom: 16px;">Enable or disable individual chat commands for your channel.</p>

                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px;" id="commandToggles">
                    <!-- Viewer Commands -->
                    <div class="command-group">
                        <h4 style="color: #9147ff; font-size: 13px; margin-bottom: 8px; text-transform: uppercase;">Everyone</h4>
                        <div class="command-toggle" data-cmd="cclip">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('cclip', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!cclip <span class="role-tag viewer">Everyone</span></span>
                                <span class="command-desc">Show current/specific clip info</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="cfind">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('cfind', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!cfind <span class="role-tag viewer">Everyone</span></span>
                                <span class="command-desc">Search clips by title/game</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="like">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('like', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!like <span class="role-tag viewer">Everyone</span></span>
                                <span class="command-desc">Upvote a clip</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="dislike">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('dislike', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!dislike <span class="role-tag viewer">Everyone</span></span>
                                <span class="command-desc">Downvote a clip</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="cvote">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('cvote', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!cvote <span class="role-tag viewer">Everyone</span></span>
                                <span class="command-desc">Clear own votes</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="chelp">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('chelp', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!chelp <span class="role-tag viewer">Everyone</span></span>
                                <span class="command-desc">Show available commands</span>
                            </div>
                        </div>
                    </div>

                    <!-- Mod Commands -->
                    <div class="command-group">
                        <h4 style="color: #00ad03; font-size: 13px; margin-bottom: 8px; text-transform: uppercase;">Moderators Only</h4>
                        <div class="command-toggle" data-cmd="cplay">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('cplay', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!cplay <span class="role-tag mod">Mod</span></span>
                                <span class="command-desc">Play specific clip</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="cskip">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('cskip', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!cskip <span class="role-tag mod">Mod</span></span>
                                <span class="command-desc">Skip current clip</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="cprev">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('cprev', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!cprev <span class="role-tag mod">Mod</span></span>
                                <span class="command-desc">Go to previous clip</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="ccat">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('ccat', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!ccat <span class="role-tag mod">Mod</span></span>
                                <span class="command-desc">Filter by game/category</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="ctop">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('ctop', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!ctop <span class="role-tag mod">Mod</span></span>
                                <span class="command-desc">Show top voted clips overlay</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="chud">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('chud', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!chud <span class="role-tag mod">Mod</span></span>
                                <span class="command-desc">Move HUD position</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="cremove">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('cremove', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!cremove <span class="role-tag mod">Mod</span></span>
                                <span class="command-desc">Remove clip from pool</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="cadd">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('cadd', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!cadd <span class="role-tag mod">Mod</span></span>
                                <span class="command-desc">Restore removed clip</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="clikeon">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('clikeon', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!clikeon <span class="role-tag mod">Mod</span></span>
                                <span class="command-desc">Enable voting</span>
                            </div>
                        </div>
                        <div class="command-toggle" data-cmd="clikeoff">
                            <label class="toggle-switch">
                                <input type="checkbox" checked onchange="toggleCommand('clikeoff', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="command-info">
                                <span class="command-name">!clikeoff <span class="role-tag mod">Mod</span></span>
                                <span class="command-desc">Disable voting</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-content" id="tab-settings">
            <div id="settingsMessage"></div>

            <div class="card" id="refreshCard" data-permission="refresh_clips">
                <h3>Refresh Clips</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Fetch new clips from Twitch.</p>
                <p style="color: #666; font-size: 13px; margin-bottom: 12px;">Last refresh: <span id="lastRefresh">Never</span></p>
                <button class="btn btn-primary" id="refreshClipsBtn" onclick="refreshClips()">Get New Clips</button>
            </div>

            <div class="card" id="modManagementCard" data-permission="manage_mods">
                <h3>Mod Access & Permissions</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Add Twitch users who can access your Playlist Manager. Customize what each mod can do.</p>

                <div style="background: #0e0e10; border-radius: 6px; padding: 14px; margin-bottom: 16px;">
                    <p style="color: #adadb8; font-size: 13px; margin-bottom: 8px;">Share this link with your mods:</p>
                    <div class="url-box" id="modShareUrl" onclick="copyModUrl()" style="font-size: 13px;">Loading...</div>
                    <p style="color: #666; font-size: 11px; margin-top: 6px;">Click to copy. Mods can also find your channel at <strong>/channels</strong> after logging in with Twitch.</p>
                </div>

                <div class="form-group">
                    <input type="text" id="newModUsername" placeholder="Enter Twitch username" onkeypress="if(event.key==='Enter')addMod()">
                    <button class="btn btn-primary" id="addModBtn" style="margin-top: 8px;" onclick="addMod()">Add Mod</button>
                </div>
                <div id="modList" style="margin-top: 16px;">
                    <p style="color: #666; font-size: 13px;">Loading mods...</p>
                </div>
                <div id="permissionLegend" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #3a3a3d; display: none;">
                    <p style="color: #666; font-size: 12px; margin-bottom: 8px;"><strong>Permission Legend:</strong></p>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px; font-size: 11px; color: #888;">
                        <span title="Create/edit playlists">Playlists</span>
                        <span title="Hide individual clips">Block</span>
                        <span title="Change overlay positions">HUD</span>
                        <span title="Toggle voting settings">Voting</span>
                        <span title="Modify clip weights">Weights</span>
                        <span title="Bot response mode">Bot</span>
                        <span title="Access stats tab">Stats</span>
                        <span title="Enable/disable commands">Commands</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-content" id="tab-weighting">
            <div id="weightingMessage"></div>

            <div class="card">
                <h3>Clip Weighting System</h3>
                <p style="color: #adadb8; margin-bottom: 16px;">Customize how clips are ranked in your player. Adjust weights to prioritize different factors.</p>
                <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 24px;">
                    <label class="toggle-switch">
                        <input type="checkbox" id="weightingEnabled" onchange="saveWeighting()">
                        <span class="toggle-slider"></span>
                    </label>
                    <span>Enable custom weighting</span>
                </div>
            </div>

            <div class="card" id="presetsCard">
                <h3>Quick Presets</h3>
                <p style="color: #adadb8; margin-bottom: 16px;">Apply a preset configuration or customize manually below.</p>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <button class="btn" style="background: #3a3a3d;" onclick="applyPreset('balanced')">🎯 Balanced (Default)</button>
                    <button class="btn" style="background: #3a3a3d;" onclick="applyPreset('popular')">🔥 Popular Clips</button>
                    <button class="btn" style="background: #3a3a3d;" onclick="applyPreset('fresh')">✨ Fresh Content</button>
                    <button class="btn" style="background: #3a3a3d;" onclick="applyPreset('community')">👥 Community Picks</button>
                    <button class="btn" style="background: #3a3a3d;" onclick="applyPreset('random')">🎲 Pure Random</button>
                </div>
            </div>

            <div class="card" id="weightsCard">
                <h3>Base Weights</h3>
                <p style="color: #adadb8; margin-bottom: 16px;">Adjust how much each factor affects clip selection. <span style="color: #666;">(0 = disabled, 1 = normal, 2 = maximum)</span></p>

                <div style="display: grid; gap: 16px;">
                    <div class="slider-group">
                        <label>Recency <span style="color: #666; font-weight: normal;">(favor clips not recently played)</span></label>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <input type="range" id="weightRecency" min="0" max="2" step="0.1" value="1" onchange="updateWeightLabel('Recency'); saveWeighting()">
                            <span id="weightRecencyLabel" style="min-width: 40px;">1.0</span>
                        </div>
                    </div>

                    <div class="slider-group">
                        <label>Views <span style="color: #666; font-weight: normal;">(favor high view count clips)</span></label>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <input type="range" id="weightViews" min="0" max="2" step="0.1" value="1" onchange="updateWeightLabel('Views'); saveWeighting()">
                            <span id="weightViewsLabel" style="min-width: 40px;">1.0</span>
                        </div>
                    </div>

                    <div class="slider-group">
                        <label>Play Penalty <span style="color: #666; font-weight: normal;">(avoid recently played clips)</span></label>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <input type="range" id="weightPlayPenalty" min="0" max="2" step="0.1" value="1" onchange="updateWeightLabel('PlayPenalty'); saveWeighting()">
                            <span id="weightPlayPenaltyLabel" style="min-width: 40px;">1.0</span>
                        </div>
                    </div>

                    <div class="slider-group">
                        <label>Voting <span style="color: #666; font-weight: normal;">(community !like/!dislike impact)</span></label>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <input type="range" id="weightVoting" min="0" max="2" step="0.1" value="1" onchange="updateWeightLabel('Voting'); saveWeighting()">
                            <span id="weightVotingLabel" style="min-width: 40px;">1.0</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" id="durationBoostsCard">
                <h3>Duration Boosts</h3>
                <p style="color: #adadb8; margin-bottom: 16px;">Boost or penalize clips based on their length (-2 to +2).</p>

                <div style="display: grid; gap: 16px;">
                    <div style="display: flex; align-items: center; gap: 16px;">
                        <label class="toggle-switch">
                            <input type="checkbox" id="durationShortEnabled" onchange="saveWeighting()">
                            <span class="toggle-slider"></span>
                        </label>
                        <span style="min-width: 120px;">Short (&lt;30s)</span>
                        <input type="range" id="durationShortBoost" min="-2" max="2" step="0.5" value="0" onchange="updateDurationLabel('Short'); saveWeighting()">
                        <span id="durationShortLabel" style="min-width: 40px;">0</span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 16px;">
                        <label class="toggle-switch">
                            <input type="checkbox" id="durationMediumEnabled" onchange="saveWeighting()">
                            <span class="toggle-slider"></span>
                        </label>
                        <span style="min-width: 120px;">Medium (30-60s)</span>
                        <input type="range" id="durationMediumBoost" min="-2" max="2" step="0.5" value="0" onchange="updateDurationLabel('Medium'); saveWeighting()">
                        <span id="durationMediumLabel" style="min-width: 40px;">0</span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 16px;">
                        <label class="toggle-switch">
                            <input type="checkbox" id="durationLongEnabled" onchange="saveWeighting()">
                            <span class="toggle-slider"></span>
                        </label>
                        <span style="min-width: 120px;">Long (&gt;60s)</span>
                        <input type="range" id="durationLongBoost" min="-2" max="2" step="0.5" value="0" onchange="updateDurationLabel('Long'); saveWeighting()">
                        <span id="durationLongLabel" style="min-width: 40px;">0</span>
                    </div>
                </div>
            </div>

            <div class="card" id="categoryBoostsCard">
                <h3>Category Boosts</h3>
                <p style="color: #adadb8; margin-bottom: 16px;">Boost or penalize clips from specific games/categories.</p>

                <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                    <select id="categorySelect" style="flex: 1; padding: 10px; border: 1px solid #3a3a3d; border-radius: 4px; background: #0e0e10; color: #efeff1;">
                        <option value="">Select a category...</option>
                    </select>
                    <input type="number" id="categoryBoostValue" min="-2" max="2" step="0.5" value="1" placeholder="Boost" style="width: 80px; padding: 10px; border: 1px solid #3a3a3d; border-radius: 4px; background: #0e0e10; color: #efeff1;">
                    <button class="btn btn-primary" onclick="addCategoryBoost()">Add</button>
                </div>
                <div id="categoryBoostsList" class="tags"></div>
            </div>

            <div class="card" id="clipperBoostsCard">
                <h3>Clipper Boosts</h3>
                <p style="color: #adadb8; margin-bottom: 16px;">Boost or penalize clips from specific clippers.</p>

                <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                    <select id="clipperSelect" style="flex: 1; padding: 10px; border: 1px solid #3a3a3d; border-radius: 4px; background: #0e0e10; color: #efeff1;">
                        <option value="">Select a clipper...</option>
                    </select>
                    <input type="number" id="clipperBoostValue" min="-2" max="2" step="0.5" value="1" placeholder="Boost" style="width: 80px; padding: 10px; border: 1px solid #3a3a3d; border-radius: 4px; background: #0e0e10; color: #efeff1;">
                    <button class="btn btn-primary" onclick="addClipperBoost()">Add</button>
                </div>
                <div id="clipperBoostsList" class="tags"></div>
            </div>

            <div class="card" id="goldenClipsCard">
                <h3>Golden Clips</h3>
                <p style="color: #adadb8; margin-bottom: 16px;">Add specific clips to a "golden" list that always gets a boost. Use the clip number from <code style="background: #2a2a2e; padding: 2px 6px; border-radius: 3px;">!cnow</code> to identify clips.</p>

                <div style="display: flex; gap: 12px; margin-bottom: 12px; align-items: center;">
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-size: 12px; color: #666;">Clip #</label>
                        <input type="number" id="goldenClipSeq" min="1" placeholder="e.g. 42" style="width: 100px; padding: 10px; border: 1px solid #3a3a3d; border-radius: 4px; background: #0e0e10; color: #efeff1;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-size: 12px; color: #666;">Boost Amount</label>
                        <input type="number" id="goldenClipBoost" min="0" max="5" step="0.5" value="2" placeholder="2" style="width: 100px; padding: 10px; border: 1px solid #3a3a3d; border-radius: 4px; background: #0e0e10; color: #efeff1;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label style="font-size: 12px; color: transparent;">Add</label>
                        <button class="btn btn-primary" onclick="addGoldenClip()">Add Golden Clip</button>
                    </div>
                </div>
                <p style="color: #666; font-size: 12px; margin-bottom: 16px;">Higher boost values (1-5) increase how often the clip plays. Default boost is 2.</p>
                <div id="goldenClipsList" class="tags"></div>
            </div>

            <div class="card">
                <button class="btn" style="background: #3a3a3d;" onclick="resetWeighting()">Reset to Defaults</button>
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
                <div class="stat-box">
                    <div class="stat-value" id="statTotalVotes">-</div>
                    <div class="stat-label">Total Votes</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-top: 20px;">
                <div class="card">
                    <h3 style="display: flex; align-items: center; gap: 8px;">
                        <span style="color: #00ad03;">▲</span> Top Liked Clips
                    </h3>
                    <div id="topLikedList" style="margin-top: 12px;">
                        <p style="color: #666; font-size: 13px;">Loading...</p>
                    </div>
                </div>

                <div class="card">
                    <h3 style="display: flex; align-items: center; gap: 8px;">
                        <span style="color: #eb0400;">▼</span> Most Disliked Clips
                    </h3>
                    <div id="topDislikedList" style="margin-top: 12px;">
                        <p style="color: #666; font-size: 13px;">Loading...</p>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h3>Recent Voting Activity</h3>
                <div id="recentVotesActivity" style="margin-top: 12px;">
                    <p style="color: #666; font-size: 13px;">Loading...</p>
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

        // View As Mode state
        let viewAsMode = null;
        let userPermissions = ['view_dashboard', 'manage_playlists', 'block_clips', 'edit_hud', 'edit_voting', 'edit_weighting', 'edit_bot_settings', 'view_stats', 'toggle_commands', 'manage_mods', 'refresh_clips'];
        const actualPermissions = [...userPermissions]; // Store original for restoring
        const defaultModPermissions = ['view_dashboard', 'manage_playlists', 'block_clips'];
        const limitedModPermissions = ['view_dashboard'];
        const allPermissions = [...userPermissions];

        // Permission checking helper
        function hasPermission(perm) {
            return userPermissions.includes(perm);
        }

        // View As Mode functions
        function enterViewAsMode(role) {
            if (!role) {
                exitViewAsMode();
                return;
            }

            viewAsMode = role;
            document.body.classList.add('view-as-active');

            // Update banner
            const banner = document.getElementById('viewAsBanner');
            const roleName = document.getElementById('viewAsRoleName');
            const description = document.getElementById('viewAsDescription');

            const roleInfo = {
                'admin': { name: 'Super Admin', desc: 'Full access to all features', perms: allPermissions },
                'streamer': { name: 'Streamer', desc: 'Channel owner view (no super admin tools)', perms: allPermissions },
                'mod': { name: 'Mod (default)', desc: 'Default mod permissions: playlists, block clips', perms: defaultModPermissions },
                'mod_limited': { name: 'Mod (minimal)', desc: 'Only view access, no editing', perms: limitedModPermissions }
            };

            const info = roleInfo[role] || roleInfo['mod'];
            roleName.textContent = info.name;
            description.textContent = `(${info.desc})`;
            banner.style.display = 'flex';

            // Hide admin bars when viewing as non-admin
            if (role !== 'admin') {
                document.querySelectorAll('.admin-bar-wrapper').forEach(el => el.style.display = 'none');
            }

            // Apply permission restrictions
            userPermissions = [...info.perms];
            applyViewAsRestrictions();
        }

        function exitViewAsMode() {
            viewAsMode = null;
            document.body.classList.remove('view-as-active');

            // Hide banner
            const banner = document.getElementById('viewAsBanner');
            if (banner) banner.style.display = 'none';

            // Show admin bars again
            document.querySelectorAll('.admin-bar-wrapper').forEach(el => el.style.display = '');

            // Reset select
            const select = document.getElementById('viewAsSelect');
            if (select) select.value = '';

            // Restore original permissions
            userPermissions = [...actualPermissions];

            // Remove restrictions
            removeViewAsRestrictions();
        }

        function applyViewAsRestrictions() {
            // Show/hide elements based on data-permission attributes
            document.querySelectorAll('[data-permission]').forEach(el => {
                const perm = el.dataset.permission;
                el.style.display = userPermissions.includes(perm) ? '' : 'none';
            });

            // If the currently active tab is now hidden, switch to settings
            const activeTab = document.querySelector('.tab.active');
            if (activeTab && activeTab.style.display === 'none') {
                document.querySelector('.tab[data-tab="overlays"]').click();
            }
        }

        function removeViewAsRestrictions() {
            // Show all permission-gated elements
            document.querySelectorAll('[data-permission]').forEach(el => {
                el.style.display = '';
            });
        }

        // Loading state helpers
        function setLoading(element, loading) {
            if (loading) {
                element.classList.add('loading');
                element.disabled = true;
            } else {
                element.classList.remove('loading');
                element.disabled = false;
            }
        }

        // Toast notification system (XSS-safe)
        function showToast(type, title, message, duration = 3000) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;

            const icons = {
                success: '✓',
                error: '✕',
                info: 'ℹ'
            };

            // Build toast safely using textContent to prevent XSS
            const iconSpan = document.createElement('span');
            iconSpan.className = 'toast-icon';
            iconSpan.textContent = icons[type] || icons.info;

            const contentDiv = document.createElement('div');
            contentDiv.className = 'toast-content';

            const titleDiv = document.createElement('div');
            titleDiv.className = 'toast-title';
            titleDiv.textContent = title;
            contentDiv.appendChild(titleDiv);

            if (message) {
                const msgDiv = document.createElement('div');
                msgDiv.className = 'toast-message';
                msgDiv.textContent = message;
                contentDiv.appendChild(msgDiv);
            }

            const closeBtn = document.createElement('button');
            closeBtn.className = 'toast-close';
            closeBtn.innerHTML = '&times;';
            closeBtn.onclick = () => toast.remove();

            toast.appendChild(iconSpan);
            toast.appendChild(contentDiv);
            toast.appendChild(closeBtn);

            container.appendChild(toast);

            // Trigger animation
            requestAnimationFrame(() => {
                toast.classList.add('show');
            });

            // Auto-remove after duration
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }

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
                btn.addEventListener('click', async () => {
                    picker.querySelectorAll('.position-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    const fieldMap = { hudPositionPicker: 'hud_position', discordHudPositionPicker: 'discord_hud_position', topPositionPicker: 'top_position' };
                    const field = fieldMap[picker.id] || 'hud_position';
                    const posLabels = { tl: 'Top Left', tc: 'Top Center', tr: 'Top Right', bl: 'Bottom Left', br: 'Bottom Right' };
                    const labelMap = { hud_position: 'Desktop HUD', discord_hud_position: 'Discord HUD', top_position: 'Top Clips' };
                    const fieldLabel = labelMap[field] || 'HUD';
                    const success = await saveSetting(field, btn.dataset.pos, false);
                    if (success) {
                        showToast('success', `${fieldLabel} Position Updated`, `Now showing in ${posLabels[btn.dataset.pos]}`);
                    }
                });
            });
        });

        async function checkAuth(key, password) {
            try {
                let url = `${API_BASE}/dashboard_api.php?action=check_login`;
                if (key) url += `&key=${encodeURIComponent(key)}`;
                if (authLogin) url += `&login=${encodeURIComponent(authLogin)}`;
                if (password) url += `&password=${encodeURIComponent(password)}`;

                console.log('checkAuth - URL:', url);
                console.log('checkAuth - authLogin:', authLogin);

                const res = await fetch(url, { credentials: 'same-origin' });
                const data = await res.json();

                console.log('checkAuth - Response:', data);

                if (data.authenticated) {
                    authKey = key;
                    authLogin = data.login;
                    authRole = data.role;
                    showDashboard();
                    loadSettings();
                } else {
                    console.error('checkAuth - Authentication failed:', data);
                    showError('Invalid credentials');
                }
            } catch (e) {
                console.error('checkAuth - Exception:', e);
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
                window.location.href = `/dashboard/${encodeURIComponent(channel)}`;
            }
        }

        function goToModDashboard() {
            const channel = document.getElementById('adminChannelInput')?.value.trim().toLowerCase() || authLogin;
            window.location.href = `/mod/${encodeURIComponent(channel)}`;
        }

        // Load channels the user can access for the channel switcher
        async function loadAccessibleChannels() {
            const select = document.getElementById('channelSwitcher');
            // Set current channel as default while loading (safe - authLogin is server-validated)
            select.innerHTML = '';
            const defaultOption = document.createElement('option');
            defaultOption.value = authLogin;
            defaultOption.selected = true;
            defaultOption.textContent = authLogin;
            select.appendChild(defaultOption);

            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=get_accessible_channels`, { credentials: 'same-origin' });
                const data = await res.json();

                if (data.success && data.channels && data.channels.length > 0) {
                    // Clear and rebuild safely to prevent XSS
                    select.innerHTML = '';
                    data.channels.forEach(ch => {
                        const option = document.createElement('option');
                        option.value = ch.login;
                        option.selected = (ch.login === authLogin);
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
            if (login && login !== authLogin) {
                window.location.href = `/dashboard/${encodeURIComponent(login)}`;
            }
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

            // Load accessible channels for the dropdown
            loadAccessibleChannels();

            const badge = document.getElementById('roleBadge');
            badge.textContent = authRole.toUpperCase();
            badge.className = 'role-badge ' + authRole;

            // Hide elements based on role
            if (authRole === 'mod') {
                document.getElementById('blockedWordsGroup').style.display = 'none';
                document.getElementById('blockedClippersGroup').style.display = 'none';
                document.getElementById('refreshCard').style.display = 'none';
                document.getElementById('modManagementCard').style.display = 'none';
                document.getElementById('commandSettingsCard').style.display = 'none';
            } else {
                // Load mods list for streamers/admins
                loadMods();
            }

            // Update Clip Browser and Mod Dashboard links
            const clipBrowserLink = document.getElementById('clipBrowserLink');
            const modDashboardLink = document.getElementById('modDashboardLink');
            if (clipBrowserLink) {
                clipBrowserLink.href = `/search/${encodeURIComponent(authLogin)}`;
            }
            if (modDashboardLink) {
                modDashboardLink.href = `/mod/${encodeURIComponent(authLogin)}`;
            }

            // Player URL will be set after loading settings (when we have the instance)
        }

        function updatePlayerUrl() {
            let playerUrl = `https://clips.gmgnrepeat.com/clipplayer_mp4_reel.html?login=${encodeURIComponent(authLogin)}`;
            if (authInstance) {
                playerUrl += `&instance=${encodeURIComponent(authInstance)}`;
            }
            document.getElementById('playerUrl').textContent = playerUrl;
        }

        async function loadSettings() {
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=get_settings&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}`, { credentials: 'same-origin' });
                const data = await res.json();

                if (data.error) {
                    console.error('Error loading settings:', data.error);
                    return;
                }

                settings = data.settings;
                authInstance = data.instance || '';

                // Update player URL with instance
                updatePlayerUrl();
                updateModShareUrl();

                // HUD positions
                setPositionPicker('hudPositionPicker', settings.hud_position || 'tr');
                setPositionPicker('discordHudPositionPicker', settings.discord_hud_position || 'tr');
                setPositionPicker('topPositionPicker', settings.top_position || 'br');

                // Voting
                document.getElementById('votingEnabled').checked = settings.voting_enabled;
                document.getElementById('voteFeedback').checked = settings.vote_feedback !== false;

                // Bot response mode
                document.getElementById('silentPrefix').checked = settings.silent_prefix === true;

                // Last refresh
                if (settings.last_refresh) {
                    document.getElementById('lastRefresh').textContent = new Date(settings.last_refresh).toLocaleString();
                }

                // Blocked words
                renderTags('blockedWordsTags', settings.blocked_words || [], removeBlockedWord);

                // Blocked clippers
                renderTags('blockedClippersTags', settings.blocked_clippers || [], removeBlockedClipper);

                // Command settings
                loadCommandSettings(settings.command_settings || {});

                // Banner overlay
                loadBannerConfig(settings.banner_config || {});

                // Stats
                if (data.stats) {
                    document.getElementById('statTotal').textContent = Number(data.stats.total).toLocaleString();
                    document.getElementById('statActive').textContent = Number(data.stats.active).toLocaleString();
                    document.getElementById('statBlocked').textContent = Number(data.stats.blocked).toLocaleString();
                }

                // Check bot status
                checkBotStatus();
            } catch (e) {
                console.error('Error loading settings:', e);
            }
        }

        // Command settings object - tracks current state
        let commandSettings = {};

        function loadCommandSettings(savedSettings) {
            commandSettings = savedSettings || {};

            // Update all command toggles based on saved settings
            document.querySelectorAll('.command-toggle').forEach(toggle => {
                const cmd = toggle.dataset.cmd;
                const checkbox = toggle.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    // Default to true (enabled) if not explicitly set to false
                    checkbox.checked = commandSettings[cmd] !== false;
                }
            });
        }

        async function toggleCommand(cmdName, enabled) {
            // Update local state
            commandSettings[cmdName] = enabled;

            // Save to server
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=save_settings&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}&field=command_settings&value=${encodeURIComponent(JSON.stringify(commandSettings))}`, { credentials: 'same-origin' });
                const data = await res.json();
                if (!data.success) {
                    console.error('Failed to save command setting:', data.error);
                    // Revert checkbox on error
                    const toggle = document.querySelector(`.command-toggle[data-cmd="${cmdName}"] input`);
                    if (toggle) toggle.checked = !enabled;
                    commandSettings[cmdName] = !enabled;
                    showToast('error', 'Failed to Update', `Could not ${enabled ? 'enable' : 'disable'} ${cmdName}`);
                } else {
                    showToast('success', `${cmdName} ${enabled ? 'Enabled' : 'Disabled'}`, `Command has been ${enabled ? 'enabled' : 'disabled'} for chat`);
                }
            } catch (e) {
                console.error('Error saving command setting:', e);
                const toggle = document.querySelector(`.command-toggle[data-cmd="${cmdName}"] input`);
                if (toggle) toggle.checked = !enabled;
                commandSettings[cmdName] = !enabled;
                showToast('error', 'Connection Error', 'Could not save command setting');
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

        async function saveSetting(field, value, showNotification = true) {
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=save_settings&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}&field=${field}&value=${encodeURIComponent(value)}`, { credentials: 'same-origin' });
                const data = await res.json();
                if (!data.success) {
                    console.error('Save failed:', data.error);
                    if (showNotification) {
                        showToast('error', 'Save Failed', data.error || 'Could not save setting');
                    }
                    return false;
                }
                return true;
            } catch (e) {
                console.error('Save error:', e);
                if (showNotification) {
                    showToast('error', 'Connection Error', 'Could not connect to server');
                }
                return false;
            }
        }

        async function saveVoting() {
            const enabled = document.getElementById('votingEnabled').checked;
            const success = await saveSetting('voting_enabled', enabled, false);
            if (success) {
                showToast('success', 'Voting ' + (enabled ? 'Enabled' : 'Disabled'), 'Chat voting has been updated');
            }
        }

        async function saveVoteFeedback() {
            const enabled = document.getElementById('voteFeedback').checked;
            const success = await saveSetting('vote_feedback', enabled, false);
            if (success) {
                showToast('success', 'Vote Feedback ' + (enabled ? 'Enabled' : 'Disabled'), 'Chat feedback setting updated');
            }
        }

        async function saveSilentPrefix() {
            const enabled = document.getElementById('silentPrefix').checked;
            const success = await saveSetting('silent_prefix', enabled, false);
            if (success) {
                showToast('success', 'Silent Mode ' + (enabled ? 'Enabled' : 'Disabled'), 'Bot responses will ' + (enabled ? 'now start with ! to hide from on-screen chat' : 'appear normally in chat'));
            }
        }

        // ===== BANNER OVERLAY =====
        let bannerSaveTimeout = null;

        function selectBannerOption(selectorId, value) {
            document.querySelectorAll(`#${selectorId} .option-btn`).forEach(btn => {
                btn.classList.toggle('active', btn.dataset.value === value);
            });

            // Scroll animation requires full-width rectangle
            const isScroll = getSelectedOption('bannerAnimationSelector') === 'scroll';
            const shapeButtons = document.querySelectorAll('#bannerShapeSelector .option-btn');
            if (isScroll) {
                // Force rectangle when scroll is active
                shapeButtons.forEach(btn => {
                    btn.classList.toggle('active', btn.dataset.value === 'rectangle');
                    btn.disabled = true;
                    btn.style.opacity = btn.dataset.value === 'rectangle' ? '1' : '0.4';
                });
            } else {
                shapeButtons.forEach(btn => {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                });
            }

            updateBannerPreview();
            debouncedSaveBanner();
        }

        function getSelectedOption(selectorId) {
            const active = document.querySelector(`#${selectorId} .option-btn.active`);
            return active ? active.dataset.value : null;
        }

        function syncColorText(pickerId, textId) {
            document.getElementById(textId).value = document.getElementById(pickerId).value;
        }

        function syncColorPicker(textId, pickerId) {
            const val = document.getElementById(textId).value;
            if (/^#[0-9a-fA-F]{6}$/.test(val)) {
                document.getElementById(pickerId).value = val;
            }
        }

        function getBannerConfig() {
            return {
                enabled: document.getElementById('bannerEnabled').checked,
                text: document.getElementById('bannerText').value,
                text_color: document.getElementById('bannerTextColor').value,
                bg_color: document.getElementById('bannerBgColor').value,
                bg_opacity: parseInt(document.getElementById('bannerOpacity').value) / 100,
                font_family: document.getElementById('bannerFontFamily').value,
                font_size: parseInt(document.getElementById('bannerFontSize').value),
                position: getSelectedOption('bannerPositionSelector') || 'top',
                border_style: getSelectedOption('bannerBorderSelector') || 'none',
                animation: getSelectedOption('bannerAnimationSelector') || 'none',
                scroll_speed: 23 - (parseInt(document.getElementById('bannerScrollSpeed').value) || 15),
                shape: getSelectedOption('bannerShapeSelector') || 'rectangle',
                timed_enabled: document.getElementById('bannerTimedEnabled').checked,
                show_duration: parseInt(document.getElementById('bannerShowDuration').value) || 15,
                interval: parseInt(document.getElementById('bannerInterval').value) || 5
            };
        }

        function updateBannerPreview() {
            const config = getBannerConfig();
            const preview = document.getElementById('bannerPreview');
            const previewText = document.getElementById('bannerPreviewText');

            document.getElementById('bannerOpacityValue').textContent = Math.round(config.bg_opacity * 100) + '%';
            document.getElementById('bannerFontSizeValue').textContent = config.font_size + 'px';
            document.getElementById('bannerScrollSpeedValue').textContent = config.scroll_speed + 's';
            document.getElementById('scrollSpeedGroup').style.display = config.animation === 'scroll' ? '' : 'none';
            document.getElementById('bannerShowDurationValue').textContent = config.show_duration + 's';
            document.getElementById('bannerIntervalValue').textContent = config.interval + ' min';
            const showTimed = config.timed_enabled;
            document.getElementById('timedDisplayGroup').style.display = showTimed ? '' : 'none';
            document.getElementById('timedIntervalGroup').style.display = showTimed ? '' : 'none';

            if (!config.enabled) {
                preview.style.display = 'none';
                return;
            }
            preview.style.display = 'flex';

            previewText.textContent = config.text || 'Be right back!';

            const r = parseInt(config.bg_color.slice(1,3), 16);
            const g = parseInt(config.bg_color.slice(3,5), 16);
            const b = parseInt(config.bg_color.slice(5,7), 16);
            preview.style.background = `rgba(${r},${g},${b},${config.bg_opacity})`;
            preview.style.color = config.text_color;

            preview.style.fontFamily = `'${config.font_family}', sans-serif`;
            preview.style.fontSize = Math.max(10, Math.round(config.font_size * 0.6)) + 'px';

            preview.classList.remove('pos-top', 'pos-center', 'pos-bottom');
            preview.classList.add('pos-' + config.position);

            // Scroll animation forces full-width rectangle
            const effectiveShape = config.animation === 'scroll' ? 'rectangle' : config.shape;

            if (effectiveShape === 'pill') {
                preview.style.borderRadius = '50px';
                preview.style.width = 'auto';
                preview.style.left = '50%';
                preview.style.right = 'auto';
                preview.style.transform = config.position === 'center' ? 'translate(-50%, -50%)' : 'translateX(-50%)';
            } else if (effectiveShape === 'rounded') {
                preview.style.borderRadius = '12px';
                preview.style.width = 'auto';
                preview.style.left = '50%';
                preview.style.right = 'auto';
                preview.style.transform = config.position === 'center' ? 'translate(-50%, -50%)' : 'translateX(-50%)';
            } else {
                preview.style.borderRadius = '0';
                preview.style.width = '';
                preview.style.left = '0';
                preview.style.right = '0';
                preview.style.transform = config.position === 'center' ? 'translateY(-50%)' : '';
            }

            if (config.border_style === 'solid') {
                preview.style.border = '1px solid rgba(255,255,255,0.6)';
                preview.style.boxShadow = 'none';
            } else if (config.border_style === 'glow') {
                preview.style.border = 'none';
                preview.style.boxShadow = `0 0 15px ${config.bg_color}, 0 0 30px ${config.bg_color}40`;
            } else {
                preview.style.border = 'none';
                preview.style.boxShadow = 'none';
            }

            // Cancel any previous scroll animation
            if (previewText._scrollAnim) { previewText._scrollAnim.cancel(); previewText._scrollAnim = null; }
            previewText.style.animation = 'none';

            if (config.animation === 'pulse') {
                previewText.style.animation = 'bannerPulse 2s ease-in-out infinite';
            } else if (config.animation === 'scroll') {
                previewText.style.whiteSpace = 'nowrap';
                // Scale duration proportionally: player is ~1920px wide, preview is smaller
                const containerW = preview.offsetWidth || 400;
                const textW = previewText.offsetWidth || 100;
                const playerW = 1920;
                const playerDuration = (config.scroll_speed || 8) * 1000;
                const previewDuration = playerDuration * (containerW + textW) / (playerW + textW);
                previewText._scrollAnim = previewText.animate([
                    { transform: `translateX(${containerW / 2 + textW / 2}px)` },
                    { transform: `translateX(${-(containerW / 2 + textW / 2)}px)` }
                ], { duration: Math.max(previewDuration, 1000), iterations: Infinity, easing: 'linear' });
            }
        }

        function debouncedSaveBanner() {
            if (bannerSaveTimeout) clearTimeout(bannerSaveTimeout);
            bannerSaveTimeout = setTimeout(saveBannerConfig, 500);
        }

        async function saveBannerConfig() {
            const config = getBannerConfig();
            const success = await saveSetting('banner_config', JSON.stringify(config), false);
            if (success) {
                showToast('success', 'Banner Updated', 'Your banner overlay has been saved');
            }
        }

        function loadBannerConfig(config) {
            if (!config || typeof config !== 'object') config = {};

            document.getElementById('bannerEnabled').checked = !!config.enabled;
            document.getElementById('bannerText').value = config.text || '';
            document.getElementById('bannerTextColor').value = config.text_color || '#ffffff';
            document.getElementById('bannerTextColorHex').value = config.text_color || '#ffffff';
            document.getElementById('bannerBgColor').value = config.bg_color || '#9147ff';
            document.getElementById('bannerBgColorHex').value = config.bg_color || '#9147ff';
            document.getElementById('bannerOpacity').value = Math.round((config.bg_opacity ?? 0.85) * 100);
            document.getElementById('bannerFontSize').value = config.font_size || 32;
            document.getElementById('bannerScrollSpeed').value = 23 - (config.scroll_speed || 8);
            document.getElementById('bannerFontFamily').value = config.font_family || 'Inter';
            document.getElementById('bannerTimedEnabled').checked = !!config.timed_enabled;
            document.getElementById('bannerShowDuration').value = config.show_duration || 15;
            document.getElementById('bannerInterval').value = config.interval || 5;

            // Set option selectors without triggering save
            ['bannerPositionSelector', 'bannerBorderSelector', 'bannerAnimationSelector', 'bannerShapeSelector'].forEach(id => {
                const val = id === 'bannerPositionSelector' ? (config.position || 'top')
                    : id === 'bannerBorderSelector' ? (config.border_style || 'none')
                    : id === 'bannerAnimationSelector' ? (config.animation || 'none')
                    : (config.shape || 'rectangle');
                document.querySelectorAll(`#${id} .option-btn`).forEach(btn => {
                    btn.classList.toggle('active', btn.dataset.value === val);
                });
            });

            updateBannerPreview();
        }

        // Bot management functions
        let botIsActive = false;

        async function checkBotStatus() {
            try {
                const res = await fetch(`/bot_api.php?action=status&channel=${encodeURIComponent(authLogin)}`, {
                    credentials: 'same-origin'
                });
                const data = await res.json();

                if (data.error) {
                    console.error('Bot status error:', data.error);
                    document.getElementById('botStatusText').textContent = 'Unable to check bot status';
                    return;
                }

                botIsActive = data.bot_active === true;
                updateBotStatusUI();
            } catch (e) {
                console.error('Failed to check bot status:', e);
                document.getElementById('botStatusText').textContent = 'Unable to check bot status';
            }
        }

        function updateBotStatusUI() {
            const indicator = document.getElementById('botStatusIndicator');
            const text = document.getElementById('botStatusText');
            const inviteBtn = document.getElementById('inviteBotBtn');
            const removeBtn = document.getElementById('removeBotBtn');
            const setupPrompt = document.getElementById('botSetupPrompt');

            if (botIsActive) {
                indicator.style.background = '#00ad03';
                text.textContent = 'Bot is active in your channel';
                inviteBtn.style.display = 'none';
                removeBtn.style.display = 'inline-block';
                if (setupPrompt) setupPrompt.style.display = 'none';
            } else {
                indicator.style.background = '#ff4757';
                text.textContent = 'Bot is not in your channel';
                inviteBtn.style.display = 'inline-block';
                removeBtn.style.display = 'none';
                if (setupPrompt) setupPrompt.style.display = 'block';
            }
        }

        async function inviteBot() {
            const btn = document.getElementById('inviteBotBtn');
            btn.disabled = true;
            btn.textContent = 'Inviting...';

            try {
                const res = await fetch(`/bot_api.php?action=add&channel=${encodeURIComponent(authLogin)}`, {
                    credentials: 'same-origin'
                });
                const data = await res.json();

                if (data.success) {
                    showToast('success', 'Bot Invited', 'The bot will join your channel shortly');
                    botIsActive = true;
                    updateBotStatusUI();
                } else {
                    showToast('error', 'Error', data.error || 'Failed to invite bot');
                }
            } catch (e) {
                showToast('error', 'Error', 'Failed to invite bot');
            }

            btn.disabled = false;
            btn.textContent = 'Invite Bot to Channel';
        }

        async function removeBot() {
            if (!confirm('Are you sure you want to remove the bot from your channel?')) {
                return;
            }

            const btn = document.getElementById('removeBotBtn');
            btn.disabled = true;
            btn.textContent = 'Removing...';

            try {
                const res = await fetch(`/bot_api.php?action=remove&channel=${encodeURIComponent(authLogin)}`, {
                    credentials: 'same-origin'
                });
                const data = await res.json();

                if (data.success) {
                    showToast('success', 'Bot Removed', 'The bot will leave your channel shortly');
                    botIsActive = false;
                    updateBotStatusUI();
                } else {
                    showToast('error', 'Error', data.error || 'Failed to remove bot');
                }
            } catch (e) {
                showToast('error', 'Error', 'Failed to remove bot');
            }

            btn.disabled = false;
            btn.textContent = 'Remove Bot from Channel';
        }

        async function addBlockedWord() {
            const input = document.getElementById('newBlockedWord');
            const word = input.value.trim().toLowerCase();
            if (!word) return;

            // Validation
            if (word.length < 2) {
                showToast('error', 'Too Short', 'Blocked word must be at least 2 characters');
                return;
            }
            if (word.length > 50) {
                showToast('error', 'Too Long', 'Blocked word must be 50 characters or less');
                return;
            }

            const words = settings.blocked_words || [];
            if (words.includes(word)) {
                showToast('info', 'Already Blocked', `"${word}" is already in the blocked list`);
                input.value = '';
                return;
            }
            if (words.length >= 100) {
                showToast('error', 'Limit Reached', 'Maximum 100 blocked words allowed');
                return;
            }

            words.push(word);
            settings.blocked_words = words;
            const success = await saveSetting('blocked_words', JSON.stringify(words), false);
            if (success) {
                renderTags('blockedWordsTags', words, removeBlockedWord);
                showToast('success', 'Word Blocked', `Clips with "${word}" in the title will be hidden`);
            } else {
                // Revert on failure
                settings.blocked_words = words.filter(w => w !== word);
                showToast('error', 'Failed to Block', 'Could not save blocked word');
            }
            input.value = '';
        }

        async function removeBlockedWord(word) {
            const words = (settings.blocked_words || []).filter(w => w !== word);
            const oldWords = settings.blocked_words;
            settings.blocked_words = words;
            const success = await saveSetting('blocked_words', JSON.stringify(words), false);
            if (success) {
                renderTags('blockedWordsTags', words, removeBlockedWord);
                showToast('success', 'Word Unblocked', `"${word}" removed from blocked list`);
            } else {
                settings.blocked_words = oldWords;
                showToast('error', 'Failed to Unblock', 'Could not remove blocked word');
            }
        }

        async function addBlockedClipper() {
            const input = document.getElementById('newBlockedClipper');
            let clipper = input.value.trim().toLowerCase();
            if (!clipper) return;

            // Validation - Twitch username rules
            clipper = clipper.replace(/[^a-z0-9_]/g, '');
            if (clipper.length < 3) {
                showToast('error', 'Invalid Username', 'Twitch username must be at least 3 characters');
                return;
            }
            if (clipper.length > 25) {
                showToast('error', 'Invalid Username', 'Twitch username must be 25 characters or less');
                return;
            }

            const clippers = settings.blocked_clippers || [];
            if (clippers.includes(clipper)) {
                showToast('info', 'Already Blocked', `"${clipper}" is already in the blocked list`);
                input.value = '';
                return;
            }
            if (clippers.length >= 50) {
                showToast('error', 'Limit Reached', 'Maximum 50 blocked clippers allowed');
                return;
            }

            clippers.push(clipper);
            settings.blocked_clippers = clippers;
            const success = await saveSetting('blocked_clippers', JSON.stringify(clippers), false);
            if (success) {
                renderTags('blockedClippersTags', clippers, removeBlockedClipper);
                showToast('success', 'Clipper Blocked', `Clips by "${clipper}" will be hidden`);
            } else {
                settings.blocked_clippers = clippers.filter(c => c !== clipper);
                showToast('error', 'Failed to Block', 'Could not save blocked clipper');
            }
            input.value = '';
        }

        async function removeBlockedClipper(clipper) {
            const clippers = (settings.blocked_clippers || []).filter(c => c !== clipper);
            const oldClippers = settings.blocked_clippers;
            settings.blocked_clippers = clippers;
            const success = await saveSetting('blocked_clippers', JSON.stringify(clippers), false);
            if (success) {
                renderTags('blockedClippersTags', clippers, removeBlockedClipper);
                showToast('success', 'Clipper Unblocked', `"${clipper}" removed from blocked list`);
            } else {
                settings.blocked_clippers = oldClippers;
                showToast('error', 'Failed to Unblock', 'Could not remove blocked clipper');
            }
        }

        async function loadMods() {
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=get_mods&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}`, { credentials: 'same-origin' });
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

        // Permission definitions for UI
        const modPermissions = {
            'manage_playlists': { label: 'Playlists', desc: 'Create/edit playlists' },
            'block_clips': { label: 'Block', desc: 'Hide individual clips' },
            'edit_hud': { label: 'HUD', desc: 'Change overlay positions' },
            'edit_voting': { label: 'Voting', desc: 'Toggle voting settings' },
            'edit_weighting': { label: 'Weights', desc: 'Modify clip weights' },
            'edit_bot_settings': { label: 'Bot', desc: 'Bot response mode' },
            'view_stats': { label: 'Stats', desc: 'Access stats tab' },
            'toggle_commands': { label: 'Commands', desc: 'Enable/disable commands' }
        };

        function renderModList(mods) {
            const container = document.getElementById('modList');
            const legend = document.getElementById('permissionLegend');

            if (mods.length === 0) {
                container.innerHTML = '<p style="color: #666; font-size: 13px;">No mods added yet. Add Twitch usernames above.</p>';
                legend.style.display = 'none';
                return;
            }

            legend.style.display = 'block';

            container.innerHTML = mods.map(mod => {
                const perms = mod.permissions || [];
                const permCheckboxes = Object.entries(modPermissions).map(([key, info]) => {
                    const checked = perms.includes(key) ? 'checked' : '';
                    return `
                        <label class="perm-checkbox" title="${info.desc}">
                            <input type="checkbox" ${checked} onchange="toggleModPermission('${escapeHtml(mod.mod_username)}', '${key}', this.checked)">
                            <span>${info.label}</span>
                        </label>
                    `;
                }).join('');

                return `
                    <div class="mod-entry">
                        <div class="mod-header">
                            <span class="mod-name">${escapeHtml(mod.mod_username)}</span>
                            <button class="btn-remove" onclick="removeMod('${escapeHtml(mod.mod_username)}')" title="Remove mod">&times;</button>
                        </div>
                        <div class="mod-permissions">
                            ${permCheckboxes}
                        </div>
                    </div>
                `;
            }).join('');
        }

        async function toggleModPermission(modUsername, permission, granted) {
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=set_mod_permission&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}&mod_username=${encodeURIComponent(modUsername)}&permission=${encodeURIComponent(permission)}&granted=${granted ? '1' : '0'}`, { credentials: 'same-origin' });
                const data = await res.json();

                if (data.success) {
                    const action = granted ? 'granted' : 'revoked';
                    showToast('success', 'Permission Updated', `${modPermissions[permission]?.label || permission} ${action} for ${modUsername}`);
                } else {
                    // Revert checkbox on failure
                    loadMods();
                    showToast('error', 'Failed to Update', data.error || 'Could not update permission');
                }
            } catch (e) {
                console.error('Error updating permission:', e);
                loadMods();
                showToast('error', 'Connection Error', 'Could not update permission');
            }
        }

        async function addMod() {
            const input = document.getElementById('newModUsername');
            const btn = document.getElementById('addModBtn');
            const username = input.value.trim().toLowerCase();
            if (!username) return;

            setLoading(btn, true);
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=add_mod&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}&mod_username=${encodeURIComponent(username)}`, { credentials: 'same-origin' });
                const data = await res.json();

                if (data.success) {
                    renderModList(data.mods || []);
                    input.value = '';
                    showToast('success', 'Mod Added', `${username} can now access the mod dashboard`);
                } else {
                    showToast('error', 'Failed to Add Mod', data.error || 'Could not add mod');
                }
            } catch (e) {
                console.error('Error adding mod:', e);
                showToast('error', 'Connection Error', 'Could not add mod');
            } finally {
                setLoading(btn, false);
            }
        }

        async function removeMod(username) {
            if (!confirm(`Remove ${username} as a mod?`)) return;

            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=remove_mod&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}&mod_username=${encodeURIComponent(username)}`, { credentials: 'same-origin' });
                const data = await res.json();

                if (data.success) {
                    renderModList(data.mods || []);
                    showToast('success', 'Mod Removed', `${username} no longer has mod access`);
                } else {
                    showToast('error', 'Failed to Remove', data.error || 'Could not remove mod');
                }
            } catch (e) {
                console.error('Error removing mod:', e);
                showToast('error', 'Connection Error', 'Could not remove mod');
            }
        }

        function refreshClips() {
            showToast('info', 'Refreshing Clips', 'Opening refresh page in new tab...');
            window.open(`/refresh_clips.php?login=${encodeURIComponent(authLogin)}`, '_blank');
        }

        function copyPlayerUrl() {
            const url = document.getElementById('playerUrl').textContent;
            navigator.clipboard.writeText(url).then(() => {
                showToast('success', 'URL Copied', 'Player URL copied to clipboard');
            }).catch(() => {
                showToast('error', 'Copy Failed', 'Could not copy URL - try selecting and copying manually');
            });
        }

        function updateModShareUrl() {
            const el = document.getElementById('modShareUrl');
            if (el && authLogin) {
                el.textContent = `${window.location.origin}/mod/${authLogin}`;
            }
        }

        function copyModUrl() {
            const url = document.getElementById('modShareUrl').textContent;
            navigator.clipboard.writeText(url).then(() => {
                showToast('success', 'Link Copied', 'Mod link copied to clipboard - send it to your mods!');
            }).catch(() => {
                showToast('error', 'Copy Failed', 'Could not copy URL');
            });
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Vote stats for Stats tab
        async function loadVoteStats() {
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=get_vote_stats&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}`, { credentials: 'same-origin' });
                const data = await res.json();

                if (!data.success) {
                    console.error('Failed to load vote stats:', data.error);
                    return;
                }

                // Update total votes stat
                document.getElementById('statTotalVotes').textContent = data.totals.total.toLocaleString();

                // Render top liked clips
                const likedList = document.getElementById('topLikedList');
                if (data.top_liked.length === 0) {
                    likedList.innerHTML = '<p style="color: #666; font-size: 13px;">No votes yet</p>';
                } else {
                    likedList.innerHTML = data.top_liked.map((clip, i) => `
                        <div style="display: flex; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid #3a3a3d;">
                            <span style="color: #666; font-size: 12px; width: 20px;">${i + 1}.</span>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(clip.title || 'Untitled')}">#${clip.seq} - ${escapeHtml(clip.title || 'Untitled')}</div>
                            </div>
                            <div style="display: flex; gap: 8px; font-size: 12px;">
                                <span style="color: #00ad03;">▲ ${clip.up_votes}</span>
                                <span style="color: #eb0400;">▼ ${clip.down_votes}</span>
                            </div>
                        </div>
                    `).join('');
                }

                // Render top disliked clips
                const dislikedList = document.getElementById('topDislikedList');
                if (data.top_disliked.length === 0) {
                    dislikedList.innerHTML = '<p style="color: #666; font-size: 13px;">No dislikes yet</p>';
                } else {
                    dislikedList.innerHTML = data.top_disliked.map((clip, i) => `
                        <div style="display: flex; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid #3a3a3d;">
                            <span style="color: #666; font-size: 12px; width: 20px;">${i + 1}.</span>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(clip.title || 'Untitled')}">#${clip.seq} - ${escapeHtml(clip.title || 'Untitled')}</div>
                            </div>
                            <div style="display: flex; gap: 8px; font-size: 12px;">
                                <span style="color: #00ad03;">▲ ${clip.up_votes}</span>
                                <span style="color: #eb0400;">▼ ${clip.down_votes}</span>
                            </div>
                        </div>
                    `).join('');
                }

                // Render recent votes
                const recentList = document.getElementById('recentVotesActivity');
                if (data.recent_votes.length === 0) {
                    recentList.innerHTML = '<p style="color: #666; font-size: 13px;">No voting activity yet</p>';
                } else {
                    recentList.innerHTML = data.recent_votes.map(vote => {
                        const time = new Date(vote.voted_at).toLocaleString();
                        const icon = vote.vote_dir === 'up' ? '▲' : '▼';
                        const color = vote.vote_dir === 'up' ? '#00ad03' : '#eb0400';
                        return `
                            <div style="display: flex; align-items: center; gap: 12px; padding: 6px 0; border-bottom: 1px solid #2a2a2d; font-size: 13px;">
                                <span style="color: ${color}; font-size: 14px;">${icon}</span>
                                <span style="color: #9147ff;">${escapeHtml(vote.username)}</span>
                                <span style="color: #666;">voted on</span>
                                <span style="flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(vote.title || 'Untitled')}">#${vote.seq}</span>
                                <span style="color: #666; font-size: 11px;">${time}</span>
                            </div>
                        `;
                    }).join('');
                }
            } catch (e) {
                console.error('Error loading vote stats:', e);
            }
        }

        // Load vote stats when Stats tab is clicked
        document.querySelector('.tab[data-tab="stats"]')?.addEventListener('click', loadVoteStats);

        // ============ WEIGHTING FUNCTIONS ============
        let weightingConfig = null;
        let weightingCategories = [];
        let weightingClippers = [];
        let weightingSaveTimeout = null;

        function showMessage(elementId, message, type = 'success') {
            const el = document.getElementById(elementId);
            if (!el) return;
            el.innerHTML = `<div class="message ${type}" style="padding: 10px; border-radius: 4px; margin-bottom: 16px;">${escapeHtml(message)}</div>`;
            setTimeout(() => { el.innerHTML = ''; }, 4000);
        }

        function updateWeightLabel(name) {
            const slider = document.getElementById('weight' + name);
            const label = document.getElementById('weight' + name + 'Label');
            if (slider && label) {
                label.textContent = parseFloat(slider.value).toFixed(1);
            }
        }

        function updateDurationLabel(name) {
            const slider = document.getElementById('duration' + name + 'Boost');
            const label = document.getElementById('duration' + name + 'Label');
            if (slider && label) {
                const val = parseFloat(slider.value);
                label.textContent = val > 0 ? '+' + val : val;
            }
        }

        async function loadWeighting() {
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=get_weighting&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}`, { credentials: 'same-origin' });
                const data = await res.json();

                if (data.success && data.config) {
                    weightingConfig = data.config;
                    weightingCategories = data.available_categories || [];
                    weightingClippers = data.available_clippers || [];

                    // Populate UI
                    document.getElementById('weightingEnabled').checked = weightingConfig.enabled;
                    document.getElementById('weightRecency').value = weightingConfig.weights?.recency ?? 1;
                    document.getElementById('weightViews').value = weightingConfig.weights?.views ?? 1;
                    document.getElementById('weightPlayPenalty').value = weightingConfig.weights?.play_penalty ?? 1;
                    document.getElementById('weightVoting').value = weightingConfig.weights?.voting ?? 1;

                    updateWeightLabel('Recency');
                    updateWeightLabel('Views');
                    updateWeightLabel('PlayPenalty');
                    updateWeightLabel('Voting');

                    // Duration boosts
                    const db = weightingConfig.duration_boosts || {};
                    document.getElementById('durationShortEnabled').checked = db.short?.enabled || false;
                    document.getElementById('durationShortBoost').value = db.short?.boost ?? 0;
                    document.getElementById('durationMediumEnabled').checked = db.medium?.enabled || false;
                    document.getElementById('durationMediumBoost').value = db.medium?.boost ?? 0;
                    document.getElementById('durationLongEnabled').checked = db.long?.enabled || false;
                    document.getElementById('durationLongBoost').value = db.long?.boost ?? 0;

                    updateDurationLabel('Short');
                    updateDurationLabel('Medium');
                    updateDurationLabel('Long');

                    // Populate category dropdown
                    const catSelect = document.getElementById('categorySelect');
                    catSelect.innerHTML = '<option value="">Select a category...</option>';
                    weightingCategories.forEach(cat => {
                        catSelect.innerHTML += `<option value="${cat.game_id}" data-name="${escapeHtml(cat.name || 'Unknown')}">${escapeHtml(cat.name || 'Unknown')} (${cat.count})</option>`;
                    });

                    // Populate clipper dropdown
                    const clipperSelect = document.getElementById('clipperSelect');
                    clipperSelect.innerHTML = '<option value="">Select a clipper...</option>';
                    weightingClippers.forEach(c => {
                        clipperSelect.innerHTML += `<option value="${escapeHtml(c.name)}">${escapeHtml(c.name)} (${c.count})</option>`;
                    });

                    renderCategoryBoosts();
                    renderClipperBoosts();
                    renderGoldenClips();
                }
            } catch (e) {
                console.error('Failed to load weighting config:', e);
            }
        }

        function renderCategoryBoosts() {
            const container = document.getElementById('categoryBoostsList');
            const boosts = weightingConfig?.category_boosts || [];
            if (boosts.length === 0) {
                container.innerHTML = '<span style="color: #666; font-size: 13px;">No category boosts set</span>';
                return;
            }
            container.innerHTML = boosts.map(b => {
                const sign = b.boost > 0 ? '+' : '';
                return `<span class="tag">${escapeHtml(b.name || b.game_id)} (${sign}${b.boost}) <span class="remove" onclick="removeCategoryBoost('${b.game_id}')">×</span></span>`;
            }).join('');
        }

        function renderClipperBoosts() {
            const container = document.getElementById('clipperBoostsList');
            const boosts = weightingConfig?.clipper_boosts || [];
            if (boosts.length === 0) {
                container.innerHTML = '<span style="color: #666; font-size: 13px;">No clipper boosts set</span>';
                return;
            }
            container.innerHTML = boosts.map(b => {
                const sign = b.boost > 0 ? '+' : '';
                return `<span class="tag">${escapeHtml(b.name)} (${sign}${b.boost}) <span class="remove" onclick="removeClipperBoost('${escapeHtml(b.name)}')">×</span></span>`;
            }).join('');
        }

        function renderGoldenClips() {
            const container = document.getElementById('goldenClipsList');
            const goldens = weightingConfig?.golden_clips || [];
            if (goldens.length === 0) {
                container.innerHTML = '<span style="color: #666; font-size: 13px;">No golden clips set</span>';
                return;
            }
            container.innerHTML = goldens.map(g => {
                return `<span class="tag" style="background: linear-gradient(135deg, #ffd700 0%, #ffaa00 100%); color: #000;">
                    #${g.seq} (+${g.boost})${g.title ? ' - ' + escapeHtml(g.title.substring(0, 20)) : ''}
                    <span class="remove" onclick="removeGoldenClip(${g.seq})" style="color: #000;">×</span>
                </span>`;
            }).join('');
        }

        async function saveWeighting() {
            // Debounce saves
            if (weightingSaveTimeout) clearTimeout(weightingSaveTimeout);
            weightingSaveTimeout = setTimeout(async () => {
                const config = {
                    enabled: document.getElementById('weightingEnabled').checked,
                    weights: {
                        recency: parseFloat(document.getElementById('weightRecency').value),
                        views: parseFloat(document.getElementById('weightViews').value),
                        play_penalty: parseFloat(document.getElementById('weightPlayPenalty').value),
                        voting: parseFloat(document.getElementById('weightVoting').value)
                    },
                    duration_boosts: {
                        short: {
                            enabled: document.getElementById('durationShortEnabled').checked,
                            max: 30,
                            boost: parseFloat(document.getElementById('durationShortBoost').value)
                        },
                        medium: {
                            enabled: document.getElementById('durationMediumEnabled').checked,
                            min: 30, max: 60,
                            boost: parseFloat(document.getElementById('durationMediumBoost').value)
                        },
                        long: {
                            enabled: document.getElementById('durationLongEnabled').checked,
                            min: 60,
                            boost: parseFloat(document.getElementById('durationLongBoost').value)
                        }
                    },
                    category_boosts: weightingConfig?.category_boosts || [],
                    clipper_boosts: weightingConfig?.clipper_boosts || [],
                    golden_clips: weightingConfig?.golden_clips || []
                };

                try {
                    const res = await fetch(`${API_BASE}/dashboard_api.php?action=save_weighting&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ config }),
                        credentials: 'same-origin'
                    });
                    const data = await res.json();
                    if (data.success) {
                        weightingConfig = config;
                        showMessage('weightingMessage', 'Weighting settings saved', 'success');
                    } else {
                        showMessage('weightingMessage', data.error || 'Failed to save', 'error');
                    }
                } catch (e) {
                    showMessage('weightingMessage', 'Network error', 'error');
                }
            }, 500);
        }

        async function addCategoryBoost() {
            const select = document.getElementById('categorySelect');
            const gameId = select.value;
            const name = select.options[select.selectedIndex]?.dataset?.name || '';
            const boost = parseFloat(document.getElementById('categoryBoostValue').value) || 1;

            if (!gameId) return;

            // Check if already exists
            if ((weightingConfig?.category_boosts || []).some(b => b.game_id === gameId)) {
                showMessage('weightingMessage', 'Category already in list', 'error');
                return;
            }

            if (!weightingConfig) weightingConfig = { category_boosts: [] };
            if (!weightingConfig.category_boosts) weightingConfig.category_boosts = [];

            weightingConfig.category_boosts.push({ game_id: gameId, name, boost });
            renderCategoryBoosts();
            select.value = '';
            await saveWeighting();
        }

        async function removeCategoryBoost(gameId) {
            if (!weightingConfig?.category_boosts) return;
            weightingConfig.category_boosts = weightingConfig.category_boosts.filter(b => b.game_id !== gameId);
            renderCategoryBoosts();
            await saveWeighting();
        }

        async function addClipperBoost() {
            const select = document.getElementById('clipperSelect');
            const name = select.value.toLowerCase();
            const boost = parseFloat(document.getElementById('clipperBoostValue').value) || 1;

            if (!name) return;

            if ((weightingConfig?.clipper_boosts || []).some(b => b.name === name)) {
                showMessage('weightingMessage', 'Clipper already in list', 'error');
                return;
            }

            if (!weightingConfig) weightingConfig = { clipper_boosts: [] };
            if (!weightingConfig.clipper_boosts) weightingConfig.clipper_boosts = [];

            weightingConfig.clipper_boosts.push({ name, boost });
            renderClipperBoosts();
            select.value = '';
            await saveWeighting();
        }

        async function removeClipperBoost(name) {
            if (!weightingConfig?.clipper_boosts) return;
            weightingConfig.clipper_boosts = weightingConfig.clipper_boosts.filter(b => b.name !== name);
            renderClipperBoosts();
            await saveWeighting();
        }

        async function addGoldenClip() {
            const seqInput = document.getElementById('goldenClipSeq');
            const seq = parseInt(seqInput.value) || 0;
            const boost = parseFloat(document.getElementById('goldenClipBoost').value) || 2;

            if (seq <= 0) {
                showMessage('weightingMessage', 'Please enter a valid clip number', 'error');
                return;
            }

            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=add_golden_clip&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ seq, boost }),
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (data.success) {
                    if (!weightingConfig) weightingConfig = { golden_clips: [] };
                    if (!weightingConfig.golden_clips) weightingConfig.golden_clips = [];
                    weightingConfig.golden_clips.push({ seq, boost, title: data.title || '' });
                    renderGoldenClips();
                    seqInput.value = '';
                    showMessage('weightingMessage', `Added golden clip #${seq}`, 'success');
                } else {
                    showMessage('weightingMessage', data.error || 'Failed to add golden clip', 'error');
                }
            } catch (e) {
                showMessage('weightingMessage', 'Network error', 'error');
            }
        }

        async function removeGoldenClip(seq) {
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=remove_golden_clip&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ seq }),
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (data.success) {
                    if (weightingConfig?.golden_clips) {
                        weightingConfig.golden_clips = weightingConfig.golden_clips.filter(g => g.seq !== seq);
                    }
                    renderGoldenClips();
                    showMessage('weightingMessage', `Removed golden clip #${seq}`, 'success');
                }
            } catch (e) {
                showMessage('weightingMessage', 'Network error', 'error');
            }
        }

        async function resetWeighting() {
            if (!confirm('Reset all weighting settings to defaults?')) return;

            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=reset_weighting&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}`, {
                    method: 'POST',
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (data.success) {
                    showMessage('weightingMessage', 'Weighting reset to defaults', 'success');
                    await loadWeighting();
                } else {
                    showMessage('weightingMessage', data.error || 'Failed to reset', 'error');
                }
            } catch (e) {
                showMessage('weightingMessage', 'Network error', 'error');
            }
        }

        // Preset configurations
        const WEIGHT_PRESETS = {
            balanced: {
                name: 'Balanced',
                recency: 1.0,
                views: 1.0,
                play_penalty: 1.0,
                voting: 1.0
            },
            popular: {
                name: 'Popular Clips',
                recency: 0.5,
                views: 2.0,
                play_penalty: 0.8,
                voting: 1.5
            },
            fresh: {
                name: 'Fresh Content',
                recency: 2.0,
                views: 0.5,
                play_penalty: 2.0,
                voting: 0.5
            },
            community: {
                name: 'Community Picks',
                recency: 0.5,
                views: 0.5,
                play_penalty: 1.0,
                voting: 2.0
            },
            random: {
                name: 'Pure Random',
                recency: 0,
                views: 0,
                play_penalty: 0,
                voting: 0
            }
        };

        function applyPreset(presetName) {
            const preset = WEIGHT_PRESETS[presetName];
            if (!preset) return;

            // Update sliders
            document.getElementById('weightRecency').value = preset.recency;
            document.getElementById('weightViews').value = preset.views;
            document.getElementById('weightPlayPenalty').value = preset.play_penalty;
            document.getElementById('weightVoting').value = preset.voting;

            // Update labels
            updateWeightLabel('Recency');
            updateWeightLabel('Views');
            updateWeightLabel('PlayPenalty');
            updateWeightLabel('Voting');

            // Save
            saveWeighting();
            showMessage('weightingMessage', `Applied "${preset.name}" preset`, 'success');
        }

        // Load weighting when tab is clicked
        document.querySelector('.tab[data-tab="weighting"]')?.addEventListener('click', loadWeighting);
    </script>
</body>
</html>
