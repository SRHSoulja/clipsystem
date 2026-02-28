<?php
/**
 * fetch_thumbnails.php - Fetch missing thumbnail URLs from Twitch API
 *
 * This script fetches thumbnail_url for clips that don't have one.
 * It processes clips in batches to respect Twitch API rate limits.
 *
 * Usage:
 *   Via browser: fetch_thumbnails.php?login=floppyjimmie&key=YOUR_ADMIN_KEY&batch=100
 */

header("Content-Type: text/plain; charset=utf-8");

// Load env
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
  foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    if (strpos($line, '=') === false) continue;
    list($k, $v) = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v));
  }
}

require_once __DIR__ . '/db_config.php';

// Auth check
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';
$key = $_GET['key'] ?? '';
if ($ADMIN_KEY === '' || !hash_equals($ADMIN_KEY, (string)$key)) {
    http_response_code(403);
    echo "Forbidden. Use ?key=YOUR_ADMIN_KEY";
    exit;
}

$login = strtolower(preg_replace('/[^a-z0-9_]/', '', $_GET['login'] ?? 'floppyjimmie'));
$batchSize = min(100, max(10, (int)($_GET['batch'] ?? 50)));

$TWITCH_CLIENT_ID = getenv('TWITCH_CLIENT_ID') ?: '';
$TWITCH_CLIENT_SECRET = getenv('TWITCH_CLIENT_SECRET') ?: '';

if (!$TWITCH_CLIENT_ID || !$TWITCH_CLIENT_SECRET) {
    echo "ERROR: Missing TWITCH_CLIENT_ID or TWITCH_CLIENT_SECRET\n";
    exit(1);
}

echo "=== Fetch Thumbnail URLs ===\n\n";
echo "Login: $login\n";
echo "Batch size: $batchSize\n\n";

$pdo = get_db_connection();
if (!$pdo) {
    echo "ERROR: Could not connect to database.\n";
    exit(1);
}

// Add thumbnail_url column if it doesn't exist
try {
    $result = $pdo->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = 'clips' AND column_name = 'thumbnail_url'
    ");
    if (!$result->fetch()) {
        $pdo->exec("ALTER TABLE clips ADD COLUMN thumbnail_url TEXT");
        echo "Added thumbnail_url column.\n";
    }
} catch (PDOException $e) {
    echo "Note: " . $e->getMessage() . "\n";
}

// Count clips without thumbnails
$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM clips
    WHERE login = ? AND (thumbnail_url IS NULL OR thumbnail_url = '')
");
$countStmt->execute([$login]);
$missing = (int)$countStmt->fetchColumn();

echo "Clips missing thumbnail: $missing\n\n";

if ($missing == 0) {
    echo "All clips have thumbnails!\n";
    exit(0);
}

// Get Twitch access token
function getTwitchToken() {
    global $TWITCH_CLIENT_ID, $TWITCH_CLIENT_SECRET;

    $ch = curl_init('https://id.twitch.tv/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $TWITCH_CLIENT_ID,
            'client_secret' => $TWITCH_CLIENT_SECRET,
            'grant_type' => 'client_credentials'
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

$token = getTwitchToken();
if (!$token) {
    echo "ERROR: Could not get Twitch access token.\n";
    exit(1);
}

echo "Got Twitch access token.\n\n";

// Fetch clips missing thumbnails
$stmt = $pdo->prepare("
    SELECT id, clip_id FROM clips
    WHERE login = ? AND (thumbnail_url IS NULL OR thumbnail_url = '')
    ORDER BY seq DESC
    LIMIT ?
");
$stmt->execute([$login, $batchSize]);
$clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($clips)) {
    echo "No clips to process.\n";
    exit(0);
}

echo "Processing " . count($clips) . " clips...\n\n";

// Twitch API allows fetching up to 100 clips at once
$clipIds = array_column($clips, 'clip_id');
$query = implode('&', array_map(fn($id) => "id=" . urlencode($id), $clipIds));
$url = "https://api.twitch.tv/helix/clips?" . $query;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        "Client-ID: $TWITCH_CLIENT_ID",
        "Authorization: Bearer $token"
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    echo "ERROR: Twitch API returned HTTP $code\n";
    echo $response . "\n";
    exit(1);
}

$data = json_decode($response, true);
$twitchClips = $data['data'] ?? [];

echo "Twitch returned " . count($twitchClips) . " clips.\n\n";

// Build lookup map
$thumbnails = [];
foreach ($twitchClips as $tc) {
    $thumbnails[$tc['id']] = $tc['thumbnail_url'] ?? '';
}

// Update database
$updateStmt = $pdo->prepare("UPDATE clips SET thumbnail_url = ? WHERE id = ?");
$updated = 0;
$notFound = 0;

foreach ($clips as $clip) {
    $clipId = $clip['clip_id'];
    $dbId = $clip['id'];

    if (isset($thumbnails[$clipId]) && $thumbnails[$clipId]) {
        $updateStmt->execute([$thumbnails[$clipId], $dbId]);
        $updated++;
        echo "Updated: $clipId\n";
    } else {
        // Clip not found on Twitch (may be deleted)
        $notFound++;
        echo "Not found: $clipId\n";
    }
}

echo "\n=== Done ===\n";
echo "Updated: $updated\n";
echo "Not found on Twitch: $notFound\n";
echo "Remaining: " . ($missing - $updated) . "\n";

if ($missing - $updated > 0) {
    echo "\nRun again to fetch more thumbnails.\n";
}
