<?php
/**
 * apply.php - Application Form for Clip Archiving
 *
 * Allows Twitch streamers to request having their clips archived.
 * Requires Twitch OAuth login to verify identity.
 * Applications are stored for admin review.
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$pdo = get_db_connection();
$currentUser = getCurrentUser();
$message = '';
$messageType = '';
$alreadyArchived = false;
$existingApplication = null;

// Check if user is already archived or has pending application
if ($currentUser && $pdo) {
    $userLogin = strtolower($currentUser['login']);

    // Check if already archived
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ?");
        $stmt->execute([$userLogin]);
        $alreadyArchived = (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        // Ignore
    }

    // Check for existing application
    if (!$alreadyArchived) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM archive_applications WHERE twitch_login = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$userLogin]);
            $existingApplication = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Table might not exist yet - will be created on first submission
        }
    }
}

// Handle form submission
if ($currentUser && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply') {
    if ($alreadyArchived) {
        $message = "Your channel is already archived!";
        $messageType = 'info';
    } elseif ($existingApplication && $existingApplication['status'] === 'pending') {
        $message = "You already have a pending application.";
        $messageType = 'info';
    } else {
        $reason = trim($_POST['reason'] ?? '');
        $followerCount = (int)($_POST['follower_count'] ?? 0);
        $averageViewers = (int)($_POST['average_viewers'] ?? 0);
        $streamingYears = (float)($_POST['streaming_years'] ?? 0);

        if (strlen($reason) < 20) {
            $message = "Please provide more detail about why you'd like to be archived (at least 20 characters).";
            $messageType = 'error';
        } else {
            try {
                // Ensure table exists
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS archive_applications (
                        id SERIAL PRIMARY KEY,
                        twitch_login VARCHAR(64) NOT NULL,
                        twitch_display_name VARCHAR(64),
                        twitch_id VARCHAR(32),
                        profile_image_url TEXT,
                        reason TEXT,
                        follower_count INTEGER DEFAULT 0,
                        average_viewers INTEGER DEFAULT 0,
                        streaming_years DECIMAL(3,1) DEFAULT 0,
                        status VARCHAR(20) DEFAULT 'pending',
                        admin_notes TEXT,
                        reviewed_by VARCHAR(64),
                        reviewed_at TIMESTAMP,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ");

                // Insert application
                $stmt = $pdo->prepare("
                    INSERT INTO archive_applications
                    (twitch_login, twitch_display_name, twitch_id, profile_image_url, reason, follower_count, average_viewers, streaming_years)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    strtolower($currentUser['login']),
                    $currentUser['display_name'],
                    $currentUser['id'] ?? null,
                    $currentUser['profile_image_url'] ?? null,
                    $reason,
                    $followerCount,
                    $averageViewers,
                    $streamingYears
                ]);

                $message = "Your application has been submitted! We'll review it and get back to you.";
                $messageType = 'success';

                // Refresh existing application status
                $stmt = $pdo->prepare("SELECT * FROM archive_applications WHERE twitch_login = ? ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([strtolower($currentUser['login'])]);
                $existingApplication = $stmt->fetch(PDO::FETCH_ASSOC);

            } catch (PDOException $e) {
                error_log("Application submission error: " . $e->getMessage());
                $message = "An error occurred. Please try again later.";
                $messageType = 'error';
            }
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
    <title>Apply for Archiving - ClipArchive</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0e0e10;
            color: #efeff1;
            min-height: 100vh;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 24px;
        }
        h1 {
            color: #9147ff;
            margin-bottom: 8px;
        }
        .subtitle {
            color: #adadb8;
            margin-bottom: 24px;
        }
        .card {
            background: #18181b;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 20px;
        }
        .card h2 {
            color: #efeff1;
            margin-bottom: 16px;
            font-size: 18px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #efeff1;
            font-weight: 500;
        }
        .form-group .hint {
            font-size: 13px;
            color: #adadb8;
            margin-top: 4px;
        }
        input[type="text"], input[type="number"], textarea, select {
            width: 100%;
            padding: 12px;
            background: #0e0e10;
            border: 1px solid #3d3d42;
            border-radius: 4px;
            color: #efeff1;
            font-size: 14px;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #9147ff;
        }
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        .row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 500px) {
            .row { grid-template-columns: 1fr; }
        }
        button {
            background: #9147ff;
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            width: 100%;
        }
        button:hover { background: #772ce8; }
        button:disabled {
            background: #3d3d42;
            cursor: not-allowed;
        }
        .message {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .message.success {
            background: rgba(0, 166, 126, 0.2);
            border: 1px solid #00a67e;
            color: #00d9a5;
        }
        .message.error {
            background: rgba(235, 4, 0, 0.2);
            border: 1px solid #eb0400;
            color: #ff6b6b;
        }
        .message.info {
            background: rgba(145, 71, 255, 0.2);
            border: 1px solid #9147ff;
            color: #bf94ff;
        }
        .login-prompt {
            text-align: center;
            padding: 60px 20px;
        }
        .login-prompt h2 {
            margin-bottom: 16px;
        }
        .login-prompt p {
            color: #adadb8;
            margin-bottom: 24px;
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
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .status-badge.pending {
            background: rgba(251, 191, 36, 0.2);
            color: #fbbf24;
        }
        .status-badge.approved {
            background: rgba(0, 166, 126, 0.2);
            color: #00d9a5;
        }
        .status-badge.denied {
            background: rgba(235, 4, 0, 0.2);
            color: #ff6b6b;
        }
        .application-status {
            text-align: center;
            padding: 40px;
        }
        .application-status h2 {
            margin-bottom: 16px;
        }
        .application-status p {
            color: #adadb8;
            margin-bottom: 8px;
        }
        .already-archived {
            text-align: center;
            padding: 40px;
        }
        .already-archived h2 {
            color: #00d9a5;
            margin-bottom: 16px;
        }
        .already-archived a {
            color: #9147ff;
            text-decoration: none;
        }
        .already-archived a:hover {
            text-decoration: underline;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding: 12px;
            background: #26262c;
            border-radius: 8px;
        }
        .user-info img {
            width: 48px;
            height: 48px;
            border-radius: 50%;
        }
        .user-info .name {
            font-weight: 600;
            color: #efeff1;
        }
        .user-info .login {
            font-size: 13px;
            color: #adadb8;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/nav.php'; ?>

    <div class="container" style="padding-top: 20px;">
        <h1>Apply for Clip Archiving</h1>
        <p class="subtitle">Request to have your Twitch clips archived in ClipArchive</p>

        <?php if (!$currentUser): ?>
        <div class="login-prompt">
            <h2>Login Required</h2>
            <p>Please login with your Twitch account to apply for clip archiving.</p>
            <a href="/auth/login.php?return=<?= urlencode('/apply.php') ?>" class="login-btn">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.64 5.93h1.43v4.28h-1.43m3.93-4.28H17v4.28h-1.43M7 2L3.43 5.57v12.86h4.28V22l3.58-3.57h2.85L20.57 12V2m-1.43 9.29l-2.85 2.85h-2.86l-2.5 2.5v-2.5H7.71V3.43h11.43z"/></svg>
                Login with Twitch
            </a>
        </div>

        <?php elseif ($alreadyArchived): ?>
        <div class="card already-archived">
            <h2>You're Already Archived!</h2>
            <p>Your channel already has clips in ClipArchive.</p>
            <p style="margin-top: 16px;">
                <a href="/search/<?= urlencode($currentUser['login']) ?>">Browse your clips</a> |
                <a href="/dashboard/<?= urlencode($currentUser['login']) ?>">Streamer Dashboard</a>
            </p>
        </div>

        <?php elseif ($existingApplication): ?>
        <div class="card application-status">
            <h2>Application Status</h2>
            <p style="margin-bottom: 16px;">
                <span class="status-badge <?= htmlspecialchars($existingApplication['status']) ?>">
                    <?= htmlspecialchars(ucfirst($existingApplication['status'])) ?>
                </span>
            </p>
            <p>Submitted: <?= date('F j, Y', strtotime($existingApplication['created_at'])) ?></p>
            <?php if ($existingApplication['status'] === 'pending'): ?>
            <p>We'll review your application and get back to you soon.</p>
            <?php elseif ($existingApplication['status'] === 'approved'): ?>
            <p style="color: #00d9a5;">Your application was approved! Your clips will be archived soon.</p>
            <?php elseif ($existingApplication['status'] === 'denied'): ?>
            <p style="color: #ff6b6b;">Unfortunately, your application was not approved at this time.</p>
            <?php if (!empty($existingApplication['admin_notes'])): ?>
            <p style="margin-top: 12px; color: #adadb8;">Note: <?= htmlspecialchars($existingApplication['admin_notes']) ?></p>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php else: ?>

        <?php if ($message): ?>
        <div class="message <?= htmlspecialchars($messageType) ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="user-info">
                <?php if (!empty($currentUser['profile_image_url'])): ?>
                <img src="<?= htmlspecialchars($currentUser['profile_image_url']) ?>" alt="">
                <?php endif; ?>
                <div>
                    <div class="name"><?= htmlspecialchars($currentUser['display_name']) ?></div>
                    <div class="login">@<?= htmlspecialchars($currentUser['login']) ?></div>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="apply">

                <div class="form-group">
                    <label>Why would you like your clips archived?</label>
                    <textarea name="reason" required placeholder="Tell us about your channel and why you'd like to use ClipArchive..."></textarea>
                    <div class="hint">Help us understand your use case (BRB screen, highlight reel, etc.)</div>
                </div>

                <div class="row">
                    <div class="form-group">
                        <label>Followers</label>
                        <input type="number" name="follower_count" min="0" placeholder="0">
                        <div class="hint">Approximate count</div>
                    </div>
                    <div class="form-group">
                        <label>Avg Viewers</label>
                        <input type="number" name="average_viewers" min="0" placeholder="0">
                        <div class="hint">Typical viewership</div>
                    </div>
                    <div class="form-group">
                        <label>Years Streaming</label>
                        <input type="number" name="streaming_years" min="0" max="20" step="0.5" placeholder="0">
                        <div class="hint">How long?</div>
                    </div>
                </div>

                <button type="submit">Submit Application</button>
            </form>
        </div>

        <div class="card" style="background: #26262c;">
            <h2 style="font-size: 16px; margin-bottom: 12px;">What happens next?</h2>
            <ul style="color: #adadb8; padding-left: 20px; line-height: 1.8;">
                <li>We review applications within a few days</li>
                <li>If approved, we'll archive your Twitch clips automatically</li>
                <li>You'll get access to the Streamer Dashboard to customize settings</li>
                <li>Your clips will be available for playback in OBS</li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
