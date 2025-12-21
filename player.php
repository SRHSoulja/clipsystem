<?php
/**
 * player.php - Embeddable clip player wrapper
 *
 * Serves the clip player with proper headers for cross-origin embedding.
 * Use this URL for embedding in iframes on external sites.
 */

// Allow embedding from any origin
header("X-Frame-Options: ALLOWALL");
header("Content-Security-Policy: frame-ancestors *");
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

// Pass through all query parameters
$query = $_SERVER['QUERY_STRING'] ?? '';

// Read and output the player HTML
$playerPath = __DIR__ . '/clipplayer_mp4_reel.html';
if (file_exists($playerPath)) {
    readfile($playerPath);
} else {
    echo "Player not found";
}
