<?php
/**
 * clip_mp4_url.php - Get signed MP4 download URL for a Twitch clip
 *
 * Uses Twitch's internal GQL API to get the signed video URL.
 * This is the same method Twitch's web player uses.
 *
 * Usage:
 *   clip_mp4_url.php?slug=ClipSlugHere
 *   clip_mp4_url.php?id=ClipSlugHere  (alias)
 *
 * Returns JSON with the MP4 URL(s) and quality options.
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get clip slug/id
$slug = $_GET['slug'] ?? $_GET['id'] ?? '';
$slug = trim($slug);

if (!$slug) {
    http_response_code(400);
    echo json_encode(["error" => "Missing slug or id parameter"]);
    exit;
}

// Twitch's internal client ID (used by their web player)
$clientId = "kimne78kx3ncx6brgo4mv6wki5h1ko";

// The persisted query hash for VideoAccessToken_Clip
$queryHash = "36b89d2507fce29e5ca551df756d27c1cfe079e2609642b4390aa4c35796eb11";

// Build GQL request
$payload = json_encode([
    "operationName" => "VideoAccessToken_Clip",
    "variables" => [
        "slug" => $slug
    ],
    "extensions" => [
        "persistedQuery" => [
            "version" => 1,
            "sha256Hash" => $queryHash
        ]
    ]
]);

// Make request to Twitch GQL
$ch = curl_init("https://gql.twitch.tv/gql");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        "Client-ID: $clientId",
        "Content-Type: application/json"
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(["error" => "Request failed: $error"]);
    exit;
}

if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode(["error" => "Twitch returned HTTP $httpCode", "response" => $response]);
    exit;
}

$data = json_decode($response, true);

if (!$data || !isset($data['data']['clip'])) {
    http_response_code(404);
    echo json_encode(["error" => "Clip not found or invalid response", "response" => $data]);
    exit;
}

$clip = $data['data']['clip'];

// Extract playback access token
$token = $clip['playbackAccessToken']['value'] ?? null;
$sig = $clip['playbackAccessToken']['signature'] ?? null;

if (!$token || !$sig) {
    http_response_code(500);
    echo json_encode(["error" => "No playback token in response"]);
    exit;
}

// Get video qualities and build signed URLs
$qualities = $clip['videoQualities'] ?? [];
$urls = [];

foreach ($qualities as $q) {
    $sourceUrl = $q['sourceURL'] ?? '';
    if (!$sourceUrl) continue;

    // Add signature and token to URL
    $separator = strpos($sourceUrl, '?') !== false ? '&' : '?';
    $signedUrl = $sourceUrl . $separator . http_build_query([
        'sig' => $sig,
        'token' => $token
    ]);

    $urls[] = [
        'quality' => $q['quality'] ?? 'unknown',
        'frameRate' => $q['frameRate'] ?? 30,
        'url' => $signedUrl
    ];
}

// Sort by quality (highest first)
usort($urls, function($a, $b) {
    return (int)$b['quality'] - (int)$a['quality'];
});

// Output
$result = [
    'slug' => $slug,
    'title' => $clip['title'] ?? '',
    'broadcaster' => $clip['broadcaster']['displayName'] ?? '',
    'duration' => $clip['durationSeconds'] ?? 0,
    'qualities' => $urls,
    'mp4_url' => $urls[0]['url'] ?? null  // Best quality
];

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
