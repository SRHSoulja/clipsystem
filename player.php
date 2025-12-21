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

if (file_exists($playerPath)) {
    readfile($playerPath);
} else {
    echo "Player not found";
}
