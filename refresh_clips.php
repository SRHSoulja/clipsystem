<?php
/**
 * refresh_clips.php - Fetch NEW clips only
 *
 * This script:
 * 1. Gets the most recent clip date from the database
 * 2. Fetches clips from Twitch API created AFTER that date
 * 3. Adds only NEW clips with proper seq numbers (max+1, max+2, etc.)
 * 4. Skips any clips that already exist (by clip_id)
 *
 * Usage: refresh_clips.php?login=floppyjimmie (requires OAuth login)
 */

header("Content-Type: text/html; charset=utf-8");
set_time_limit(300);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

$login = strtolower(trim(preg_replace('/[^a-z0-9_]/', '', $_GET['login'] ?? '')));

// Auth - require OAuth (own channel or super admin)
$isAuthorized = false;
$currentUser = getCurrentUser();

if ($currentUser) {
    $oauthUsername = strtolower($currentUser['login']);
    // Own channel access
    if ($oauthUsername === $login) {
        $isAuthorized = true;
    }
    // Super admin access
    elseif (isSuperAdmin()) {
        $isAuthorized = true;
    }
}

if (!$isAuthorized) {
    die("Forbidden - OAuth login required (own channel or super admin)");
}

if (!$login) {
    die("Missing login parameter");
}

echo "<pre style='background:#1a1a2e;color:#0f0;padding:20px;font-family:monospace;'>\n";
echo "=== Refresh Clips for {$login} ===\n\n";

// Get database connection
$pdo = get_db_connection();
if (!$pdo) {
    die("Database connection failed");
}

// Get current max seq and most recent clip date
$stmt = $pdo->prepare("SELECT MAX(seq) as max_seq, MAX(created_at) as latest_date, COUNT(*) as total FROM clips WHERE login = ?");
$stmt->execute([$login]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$maxSeq = (int)($row['max_seq'] ?? 0);
$latestDate = $row['latest_date'] ?? null;
$totalClips = (int)($row['total'] ?? 0);

echo "Current clips in database: {$totalClips}\n";
echo "Max seq number: {$maxSeq}\n";
echo "Most recent clip date: " . ($latestDate ?? 'none') . "\n\n";

if (!$latestDate) {
    die("No existing clips found. Use the Add User feature to do initial backfill.");
}

// Get existing clip IDs to avoid duplicates
$stmt = $pdo->prepare("SELECT clip_id FROM clips WHERE login = ?");
$stmt->execute([$login]);
$existingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
$existingSet = array_flip($existingIds);
echo "Loaded " . count($existingIds) . " existing clip IDs\n\n";

// Get Twitch access token
$clientId = getenv('TWITCH_CLIENT_ID');
$clientSecret = getenv('TWITCH_CLIENT_SECRET');

if (!$clientId || !$clientSecret) {
    die("Missing Twitch API credentials");
}

echo "Getting Twitch access token...\n";
$tokenRes = file_get_contents("https://id.twitch.tv/oauth2/token", false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials'
        ])
    ]
]));
$tokenData = json_decode($tokenRes, true);
$accessToken = $tokenData['access_token'] ?? null;

if (!$accessToken) {
    die("Failed to get Twitch access token");
}
echo "Got access token\n\n";

// Get broadcaster ID
echo "Getting broadcaster ID for {$login}...\n";
$userRes = file_get_contents("https://api.twitch.tv/helix/users?login={$login}", false, stream_context_create([
    'http' => [
        'header' => "Client-ID: {$clientId}\r\nAuthorization: Bearer {$accessToken}"
    ]
]));
$userData = json_decode($userRes, true);
$broadcasterId = $userData['data'][0]['id'] ?? null;

if (!$broadcasterId) {
    die("Could not find broadcaster ID for {$login}");
}
echo "Broadcaster ID: {$broadcasterId}\n\n";

// Fetch clips from Twitch API (starting from 1 day BEFORE the latest clip)
// This ensures we catch any clips that failed to insert previously
$startDate = date('c', strtotime($latestDate) - 86400);
echo "Fetching clips created after: {$startDate}\n";
echo "(Going back 1 day to catch any previously missed clips)\n\n";

$newClips = [];
$cursor = null;
$pages = 0;
$maxPages = 50; // Safety limit

do {
    $url = "https://api.twitch.tv/helix/clips?broadcaster_id={$broadcasterId}&first=100&started_at=" . urlencode($startDate);
    if ($cursor) {
        $url .= "&after=" . urlencode($cursor);
    }

    $clipsRes = @file_get_contents($url, false, stream_context_create([
        'http' => [
            'header' => "Client-ID: {$clientId}\r\nAuthorization: Bearer {$accessToken}",
            'timeout' => 30
        ]
    ]));

    if (!$clipsRes) {
        echo "API request failed, stopping.\n";
        break;
    }

    $clipsData = json_decode($clipsRes, true);
    $clips = $clipsData['data'] ?? [];
    $cursor = $clipsData['pagination']['cursor'] ?? null;
    $pages++;

    foreach ($clips as $clip) {
        $clipId = $clip['id'];

        // Skip if we already have this clip
        if (isset($existingSet[$clipId])) {
            continue;
        }

        $newClips[] = $clip;
    }

    echo "Page {$pages}: Found " . count($clips) . " clips, " . count($newClips) . " new so far\n";

    // Small delay to be nice to API
    usleep(100000);

} while ($cursor && $pages < $maxPages);

echo "\n";

if (empty($newClips)) {
    echo "No new clips found!\n";
    echo "</pre>";
    echo "<p><a href='admin.php'>Back to Admin</a></p>";
    exit;
}

echo "Found " . count($newClips) . " new clips to add\n\n";

// Sort by created_at ascending so oldest new clips get lower seq numbers
usort($newClips, function($a, $b) {
    return strcmp($a['created_at'], $b['created_at']);
});

// Insert new clips with proper seq numbers
$inserted = 0;
$errors = 0;
$nextSeq = $maxSeq + 1;

$insertStmt = $pdo->prepare("
    INSERT INTO clips (login, clip_id, seq, title, duration, created_at, view_count, game_id, video_id, vod_offset, creator_name, thumbnail_url, blocked)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, false)
");

foreach ($newClips as $clip) {
    try {
        $insertStmt->execute([
            $login,
            $clip['id'],
            $nextSeq,
            $clip['title'] ?? '',
            (int)round($clip['duration'] ?? 0),
            $clip['created_at'],
            $clip['view_count'] ?? 0,
            $clip['game_id'] ?? null,
            $clip['video_id'] ?? null,
            $clip['vod_offset'] ?? null,
            $clip['creator_name'] ?? '',
            $clip['thumbnail_url'] ?? ''
        ]);

        $inserted++;
        $nextSeq++;

        if ($inserted % 50 == 0) {
            echo "Inserted {$inserted} clips...\n";
        }
    } catch (PDOException $e) {
        $errors++;
        if ($errors <= 5) {
            echo "Error inserting clip {$clip['id']}: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n=== Complete ===\n";
echo "Inserted: {$inserted} new clips\n";
echo "Errors: {$errors}\n";
echo "New max seq: " . ($nextSeq - 1) . "\n";

// Get updated total
$stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ?");
$stmt->execute([$login]);
$newTotal = $stmt->fetchColumn();
echo "Total clips now: {$newTotal}\n";

// === Resolve missing game names ===
echo "\n=== Resolving Game Names ===\n";

// Get all unique game_ids for this user that aren't in games_cache
$stmt = $pdo->prepare("
    SELECT DISTINCT c.game_id
    FROM clips c
    LEFT JOIN games_cache g ON c.game_id = g.game_id
    WHERE c.login = ? AND c.game_id IS NOT NULL AND c.game_id != '' AND g.game_id IS NULL
");
$stmt->execute([$login]);
$missingGameIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($missingGameIds)) {
    echo "All game names are already cached!\n";
} else {
    echo "Found " . count($missingGameIds) . " games without names, fetching from Twitch...\n";

    // Fetch games in batches of 100 (Twitch limit)
    $resolved = 0;
    foreach (array_chunk($missingGameIds, 100) as $chunk) {
        $query = implode('&', array_map(fn($id) => "id=" . urlencode($id), $chunk));
        $url = "https://api.twitch.tv/helix/games?" . $query;

        $gamesRes = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'header' => "Client-ID: {$clientId}\r\nAuthorization: Bearer {$accessToken}",
                'timeout' => 10
            ]
        ]));

        if ($gamesRes) {
            $gamesData = json_decode($gamesRes, true);
            if (isset($gamesData['data'])) {
                $insertGameStmt = $pdo->prepare("
                    INSERT INTO games_cache (game_id, name, box_art_url)
                    VALUES (?, ?, ?)
                    ON CONFLICT (game_id) DO UPDATE SET name = EXCLUDED.name, box_art_url = EXCLUDED.box_art_url
                ");

                foreach ($gamesData['data'] as $game) {
                    try {
                        $insertGameStmt->execute([
                            $game['id'],
                            $game['name'],
                            $game['box_art_url'] ?? ''
                        ]);
                        echo "  Cached: {$game['name']} ({$game['id']})\n";
                        $resolved++;
                    } catch (PDOException $e) {
                        // Ignore duplicates
                    }
                }
            }
        }
        usleep(100000); // Small delay
    }
    echo "Resolved {$resolved} game names\n";
}

// Update last_refresh timestamp in channel_settings
try {
    $stmt = $pdo->prepare("
        INSERT INTO channel_settings (login, last_refresh)
        VALUES (?, CURRENT_TIMESTAMP)
        ON CONFLICT (login) DO UPDATE SET last_refresh = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$login]);
    echo "\nUpdated last_refresh timestamp\n";
} catch (PDOException $e) {
    echo "\nNote: Could not update last_refresh: " . $e->getMessage() . "\n";
}

echo "</pre>\n";
echo "<p><a href='admin.php'>Back to Admin</a></p>";
