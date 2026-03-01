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
// Flush output progressively so user sees progress
if (function_exists('ob_implicit_flush')) ob_implicit_flush(true);
while (ob_get_level()) ob_end_flush();

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

// Determine platform (from query param or channel_settings)
$platform = strtolower(trim($_GET['platform'] ?? ''));

echo "<pre style='background:#1a1a2e;color:#0f0;padding:20px;font-family:monospace;'>\n";
echo "=== Refresh Clips for {$login} ===\n\n";

// Get database connection
$pdo = get_db_connection();
if (!$pdo) {
    die("Database connection failed");
}

// Get platform from channel_settings if not provided in URL
if (!$platform || !in_array($platform, ['twitch', 'kick'])) {
    try {
        $stmt = $pdo->prepare("SELECT platform FROM channel_settings WHERE login = ?");
        $stmt->execute([$login]);
        $plat = $stmt->fetchColumn();
        $platform = ($plat && in_array($plat, ['twitch', 'kick'])) ? $plat : 'twitch';
    } catch (PDOException $e) {
        $platform = 'twitch';
    }
}
echo "Platform: " . strtoupper($platform) . ($platform === 'kick' ? ' (Experimental)' : '') . "\n\n";

// Get current max seq and most recent clip date
$stmt = $pdo->prepare("SELECT MAX(seq) as max_seq, MAX(created_at) as latest_date, COUNT(*) as total FROM clips WHERE login = ?");
$stmt->execute([$login]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$maxSeq = (int)($row['max_seq'] ?? 0);
$latestDate = $row['latest_date'] ?? null;
$totalClips = (int)($row['total'] ?? 0);

// Get last refresh timestamp from channel_settings
$lastRefresh = null;
try {
    $stmt = $pdo->prepare("SELECT last_refresh FROM channel_settings WHERE login = ?");
    $stmt->execute([$login]);
    $csRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $lastRefresh = $csRow['last_refresh'] ?? null;
} catch (PDOException $e) {
    // Table/column might not exist
}

echo "Current clips in database: {$totalClips}\n";
echo "Max seq number: {$maxSeq}\n";
echo "Most recent clip date: " . ($latestDate ?? 'none') . "\n";
echo "Last refresh: " . ($lastRefresh ?? 'never') . "\n\n";

if (!$latestDate) {
    die("No existing clips found. Use the Add User feature to do initial backfill.");
}

// Get existing clip IDs to avoid duplicates
$stmt = $pdo->prepare("SELECT clip_id FROM clips WHERE login = ?");
$stmt->execute([$login]);
$existingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
$existingSet = array_flip($existingIds);
echo "Loaded " . count($existingIds) . " existing clip IDs\n\n";

// === Platform-specific clip fetching ===
$newClips = [];

if ($platform === 'kick') {
    // === KICK CLIP FETCH ===
    require_once __DIR__ . '/includes/kick_api.php';
    $kickApi = new KickAPI();

    echo "Fetching clips from Kick for {$login}...\n";

    $channelInfo = $kickApi->getChannelInfo($login);
    if (!$channelInfo) {
        die("Could not find Kick channel: {$login}");
    }
    echo "Kick channel: {$channelInfo['display_name']} (ID: {$channelInfo['id']})\n\n";

    // Fetch all clips (sorted by most recent)
    $page = 1;
    $totalFetched = 0;
    $maxPages = 50;

    while ($page <= $maxPages) {
        echo "Page {$page}...\n";
        $result = $kickApi->getClips($login, 'recent', $page);

        if (empty($result['clips'])) {
            echo "  No more clips\n";
            break;
        }

        $pageNew = 0;
        foreach ($result['clips'] as $clip) {
            $clipId = $clip['clip_id'];
            if (isset($existingSet[$clipId])) {
                continue;
            }
            $newClips[] = $clip;
            $pageNew++;
        }

        $totalFetched += count($result['clips']);
        if ($pageNew > 0) {
            echo "  Found {$pageNew} new clips (total new: " . count($newClips) . ")\n";
        }

        if (!$result['has_more']) break;
        $page++;
        usleep(200000); // 200ms delay
    }

    echo "\nFetched {$totalFetched} total clips from Kick, " . count($newClips) . " are new\n\n";

    // Skip the Twitch section
    goto insert_clips;
}

// === TWITCH CLIP FETCH ===
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

// Fetch clips from Twitch API using time windows
// Start from the last refresh timestamp (minus 24h safety margin).
// If never refreshed before, fall back to the most recent clip date minus 24h.
// Duplicates are harmless thanks to ON CONFLICT DO NOTHING.
$baseDate = $lastRefresh ? strtotime($lastRefresh) : strtotime($latestDate);
$fetchStart = $baseDate - 86400; // 24 hours before last refresh for safety
$now = time();
$windowDays = 7; // 7-day windows (Twitch API requires ended_at or defaults to 1 week)
$windowSec = $windowDays * 24 * 60 * 60;
$totalWindows = (int)ceil(($now - $fetchStart) / $windowSec);

echo "Fetching clips from: " . date('c', $fetchStart) . "\n";
echo "To: " . date('c', $now) . "\n";
echo "Time span: " . round(($now - $fetchStart) / 86400) . " days across {$totalWindows} windows\n";
echo "(Starting from last refresh" . ($lastRefresh ? "" : " (never refreshed, using latest clip date)") . " minus 24h)\n\n";

$newClips = [];
$totalPages = 0;

for ($w = 0; $w < $totalWindows; $w++) {
    $windowStart = $fetchStart + ($w * $windowSec);
    $windowEnd = min($now, $windowStart + $windowSec);
    $startDate = date('c', $windowStart);
    $endDate = date('c', $windowEnd);

    echo "Window " . ($w + 1) . "/{$totalWindows}: {$startDate} → {$endDate}\n";

    $cursor = null;
    $pages = 0;
    $windowNew = 0;

    do {
        $url = "https://api.twitch.tv/helix/clips?broadcaster_id={$broadcasterId}&first=100"
             . "&started_at=" . urlencode($startDate)
             . "&ended_at=" . urlencode($endDate);
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
            echo "  API request failed, skipping window.\n";
            break;
        }

        $clipsData = json_decode($clipsRes, true);
        $clips = $clipsData['data'] ?? [];
        $cursor = $clipsData['pagination']['cursor'] ?? null;
        $pages++;
        $totalPages++;

        foreach ($clips as $clip) {
            $clipId = $clip['id'];

            // Skip if we already have this clip
            if (isset($existingSet[$clipId])) {
                continue;
            }

            $newClips[] = $clip;
            $windowNew++;
        }

        // Small delay to be nice to API
        usleep(100000);

    } while ($cursor && $pages < 30); // 30 pages per window safety limit

    if ($windowNew > 0) {
        echo "  Found {$windowNew} new clips (total new: " . count($newClips) . ")\n";
    }
}

echo "\n";

insert_clips:

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
    INSERT INTO clips (login, clip_id, seq, title, duration, created_at, view_count, game_id, video_id, vod_offset, creator_name, thumbnail_url, blocked, platform, mp4_url)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, false, ?, ?)
    ON CONFLICT (login, clip_id) DO NOTHING
");

$skipped = 0;

foreach ($newClips as $clip) {
    try {
        $clipId = $clip['clip_id'] ?? $clip['id'] ?? '';
        $clipPlatform = $clip['platform'] ?? $platform;
        $clipMp4 = $clip['mp4_url'] ?? null;
        $insertStmt->execute([
            $login,
            $clipId,
            $nextSeq,
            $clip['title'] ?? '',
            (int)round($clip['duration'] ?? 0),
            $clip['created_at'],
            $clip['view_count'] ?? 0,
            $clip['game_id'] ?? null,
            $clip['video_id'] ?? null,
            $clip['vod_offset'] ?? null,
            $clip['creator_name'] ?? '',
            $clip['thumbnail_url'] ?? '',
            $clipPlatform,
            $clipMp4
        ]);

        if ($insertStmt->rowCount() > 0) {
            $inserted++;
            $nextSeq++;
        } else {
            $skipped++; // Already existed in DB
        }

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
if ($skipped > 0) echo "Skipped: {$skipped} (already in database)\n";
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
} elseif ($platform === 'kick') {
    // For Kick, game names are already in the clip data (game_name field)
    // Insert them directly from the clips we just fetched
    echo "Found " . count($missingGameIds) . " games without names, using Kick clip data...\n";
    $insertGameStmt = $pdo->prepare("
        INSERT INTO games_cache (game_id, name, box_art_url)
        VALUES (?, ?, '')
        ON CONFLICT (game_id) DO UPDATE SET name = EXCLUDED.name
    ");
    $resolved = 0;
    foreach ($newClips as $clip) {
        $gid = $clip['game_id'] ?? '';
        $gname = $clip['game_name'] ?? '';
        if ($gid && $gname && in_array($gid, $missingGameIds)) {
            try {
                $insertGameStmt->execute([$gid, $gname]);
                echo "  Cached: {$gname} ({$gid})\n";
                $resolved++;
            } catch (PDOException $e) { /* ignore */ }
        }
    }
    echo "Resolved {$resolved} game names from Kick clip data\n";
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
