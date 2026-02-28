<?php
/**
 * populate_thumbnails.php - One-click thumbnail population
 *
 * Adds the thumbnail_url column if needed, then fetches all missing
 * thumbnails from Twitch API automatically.
 *
 * Usage: populate_thumbnails.php?login=floppyjimmie&key=YOUR_ADMIN_KEY
 */

set_time_limit(600); // 10 minutes max
header("Content-Type: text/html; charset=utf-8");

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

$TWITCH_CLIENT_ID = getenv('TWITCH_CLIENT_ID') ?: '';
$TWITCH_CLIENT_SECRET = getenv('TWITCH_CLIENT_SECRET') ?: '';

?>
<!DOCTYPE html>
<html>
<head>
  <title>Populate Thumbnails</title>
  <style>
    body { font-family: monospace; background: #1a1a2e; color: #eee; padding: 20px; }
    .log { background: #0d0d1a; padding: 15px; border-radius: 8px; max-height: 600px; overflow-y: auto; }
    .success { color: #4ade80; }
    .error { color: #f87171; }
    .info { color: #60a5fa; }
    .warn { color: #fbbf24; }
    h1 { color: #9147ff; }
  </style>
</head>
<body>
  <h1>Populate Thumbnails - <?= htmlspecialchars($login) ?></h1>
  <div class="log">
<?php

function log_msg($msg, $class = '') {
    echo "<div class=\"$class\">$msg</div>\n";
    ob_flush();
    flush();
}

// Check Twitch credentials
if (!$TWITCH_CLIENT_ID || !$TWITCH_CLIENT_SECRET) {
    log_msg("ERROR: Missing TWITCH_CLIENT_ID or TWITCH_CLIENT_SECRET", "error");
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    log_msg("ERROR: Could not connect to database.", "error");
    exit;
}

log_msg("Connected to database.", "success");

// Add thumbnail_url column if needed
try {
    $result = $pdo->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = 'clips' AND column_name = 'thumbnail_url'
    ");
    if (!$result->fetch()) {
        $pdo->exec("ALTER TABLE clips ADD COLUMN thumbnail_url TEXT");
        log_msg("Added thumbnail_url column to clips table.", "success");
    } else {
        log_msg("thumbnail_url column already exists.", "info");
    }
} catch (PDOException $e) {
    log_msg("Column check error: " . $e->getMessage(), "warn");
}

// Count clips without thumbnails
$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM clips
    WHERE login = ? AND (thumbnail_url IS NULL OR thumbnail_url = '')
");
$countStmt->execute([$login]);
$totalMissing = (int)$countStmt->fetchColumn();

log_msg("Clips missing thumbnails: $totalMissing", "info");

if ($totalMissing == 0) {
    log_msg("All clips have thumbnails! Nothing to do.", "success");
    echo "</div></body></html>";
    exit;
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
    log_msg("ERROR: Could not get Twitch access token.", "error");
    exit;
}

log_msg("Got Twitch access token.", "success");

// Process in batches
$batchSize = 100;
$totalUpdated = 0;
$totalNotFound = 0;
$batchNum = 0;

while (true) {
    $batchNum++;

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
        break;
    }

    log_msg("Batch $batchNum: Processing " . count($clips) . " clips...", "info");

    // Fetch from Twitch API
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
        log_msg("Twitch API error (HTTP $code), waiting and retrying...", "warn");
        sleep(5);
        continue;
    }

    $data = json_decode($response, true);
    $twitchClips = $data['data'] ?? [];

    // Build lookup map
    $thumbnails = [];
    foreach ($twitchClips as $tc) {
        $thumbnails[$tc['id']] = $tc['thumbnail_url'] ?? '';
    }

    // Update database
    $updateStmt = $pdo->prepare("UPDATE clips SET thumbnail_url = ? WHERE id = ?");
    $batchUpdated = 0;
    $batchNotFound = 0;

    foreach ($clips as $clip) {
        $clipId = $clip['clip_id'];
        $dbId = $clip['id'];

        if (isset($thumbnails[$clipId]) && $thumbnails[$clipId]) {
            $updateStmt->execute([$thumbnails[$clipId], $dbId]);
            $batchUpdated++;
        } else {
            // Clip not found - mark with placeholder to avoid re-checking
            $updateStmt->execute(['NOT_FOUND', $dbId]);
            $batchNotFound++;
        }
    }

    $totalUpdated += $batchUpdated;
    $totalNotFound += $batchNotFound;

    log_msg("  Updated: $batchUpdated, Not found: $batchNotFound", $batchUpdated > 0 ? "success" : "warn");

    // Rate limit protection
    usleep(500000); // 0.5 second between batches
}

log_msg("", "");
log_msg("=== COMPLETE ===", "success");
log_msg("Total thumbnails fetched: $totalUpdated", "success");
log_msg("Clips not found on Twitch: $totalNotFound", "warn");

?>
  </div>
  <p style="margin-top: 20px; color: #888;">Done! You can close this page.</p>
</body>
</html>
