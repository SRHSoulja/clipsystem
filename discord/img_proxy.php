<?php
/**
 * discord/img_proxy.php - Proxy external images for Discord Activity
 *
 * Discord's Activity iframe blocks direct external image loads.
 * This proxies Twitch CDN profile images through our own domain.
 *
 * GET ?url=https://static-cdn.jtvnw.net/...
 */
$url = $_GET['url'] ?? '';

// Only allow Twitch CDN domains
if (!$url || !preg_match('#^https://(static-cdn\.jtvnw\.net|clips-media-assets\d*\.twitch\.tv)/#', $url)) {
    http_response_code(400);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_USERAGENT => 'ClipTV/1.0',
]);
$data = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode === 200 && $data) {
    header('Content-Type: ' . ($contentType ?: 'image/png'));
    header('Cache-Control: public, max-age=86400');
    header('Access-Control-Allow-Origin: *');
    echo $data;
} else {
    http_response_code(404);
}
