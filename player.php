<?php
/**
 * player.php - Embeddable clip player wrapper
 *
 * Serves the clip player with proper headers for cross-origin embedding.
 * Use this URL for embedding in iframes on external sites.
 *
 * Modes:
 *   ?sync=1 - Synchronized "TV channel" mode (all viewers see same clip)
 *   (default) - Independent mode (each viewer sees random clips)
 */

// Allow embedding from any origin
header("X-Frame-Options: ALLOWALL");
header("Content-Security-Policy: frame-ancestors *");
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

// Check for sync mode
$syncMode = isset($_GET['sync']) && $_GET['sync'] === '1';

// Select appropriate player
if ($syncMode) {
    $playerPath = __DIR__ . '/clipplayer_sync.html';
} else {
    $playerPath = __DIR__ . '/clipplayer_mp4_reel.html';
}

if (!file_exists($playerPath)) {
    echo "Player not found";
    exit;
}

// Build OG meta tags for /tv/ link previews
$ogTags = '';
$login = strtolower(trim(preg_replace('/[^a-z0-9_]/', '', $_GET['login'] ?? '')));

if ($login && $syncMode) {
    require_once __DIR__ . '/db_config.php';
    $clipCount = 0;
    $profileImage = '';
    $displayName = $login;

    $pdo = get_db_connection();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ? AND blocked = FALSE");
            $stmt->execute([$login]);
            $clipCount = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT profile_image_url, display_name FROM channel_settings WHERE login = ?");
            $stmt->execute([$login]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $profileImage = $row['profile_image_url'] ?? '';
                $displayName = $row['display_name'] ?: $login;
            }
        } catch (PDOException $e) { /* ignore */ }
    }

    $ogTitle = htmlspecialchars($displayName) . " - Clip TV";
    $ogDesc = $clipCount > 0
        ? number_format($clipCount) . " clips. Watch " . htmlspecialchars($displayName) . "'s best Twitch moments live with chat."
        : "Watch " . htmlspecialchars($displayName) . "'s best Twitch moments live with chat.";
    $ogImage = $profileImage ?: 'https://clips.gmgnrepeat.com/tapefacectv.png';
    $ogUrl = 'https://clips.gmgnrepeat.com/tv/' . urlencode($login);

    $ogTags = '
  <!-- Open Graph -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="' . $ogTitle . '">
  <meta property="og:description" content="' . $ogDesc . '">
  <meta property="og:image" content="' . htmlspecialchars($ogImage) . '">
  <meta property="og:url" content="' . htmlspecialchars($ogUrl) . '">
  <meta name="theme-color" content="#9147ff">
  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="' . $ogTitle . '">
  <meta name="twitter:description" content="' . $ogDesc . '">
  <meta name="twitter:image" content="' . htmlspecialchars($ogImage) . '">';
}

// Read HTML and inject OG tags after <head>
$html = file_get_contents($playerPath);
if ($ogTags) {
    $html = preg_replace('/<head>/i', '<head>' . $ogTags, $html, 1);
}
echo $html;
