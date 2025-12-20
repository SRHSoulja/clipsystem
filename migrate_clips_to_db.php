<?php
/**
 * migrate_clips_to_db.php - Migrates clips from JSON to PostgreSQL
 *
 * Run this once to populate the clips table from clips_index_*.json
 *
 * Usage:
 *   php migrate_clips_to_db.php [login]
 *   Default login: floppyjimmie
 *
 * Browser usage:
 *   migrate_clips_to_db.php?login=floppyjimmie&key=YOUR_ADMIN_KEY
 *
 * Options:
 *   &update=1  - Update existing clips with creator_name from JSON
 *   &fresh=1   - Delete all existing clips for this login before importing
 *   &offset=N  - Start from clip N (for chunked processing)
 *   &chunk=N   - Process N clips per request (default 2000)
 *
 * Note: fresh=1 only affects the specified login, other logins are preserved.
 */

// Buffer output so we can add auto-redirect header
ob_start();

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

// Auth check for web access
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';
if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? '';
    if ($key !== $ADMIN_KEY) {
        http_response_code(403);
        echo "Forbidden. Use ?key=YOUR_ADMIN_KEY";
        exit;
    }
}

// Get login from CLI arg or query param
$login = 'floppyjimmie';
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $login = strtolower(trim($argv[1]));
} elseif (isset($_GET['login'])) {
    $login = strtolower(trim($_GET['login']));
}
$login = preg_replace('/[^a-z0-9_]/', '', $login);

// Chunking support for web execution (Railway has ~30s timeout)
$startOffset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$chunkSize = isset($_GET['chunk']) ? max(100, min(5000, (int)$_GET['chunk'])) : 2000;
$freshMode = isset($_GET['fresh']) && $_GET['fresh'] === '1';
$startTime = time();

echo "=== Clips Migration to PostgreSQL ===\n";
echo "Login: $login\n\n";

// Connect to database
$pdo = get_db_connection();
if (!$pdo) {
    echo "ERROR: Could not connect to database. Check DATABASE_URL.\n";
    exit(1);
}
echo "Connected to PostgreSQL.\n";

// Create clips table
echo "Creating clips table...\n";
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clips (
            id SERIAL PRIMARY KEY,
            login VARCHAR(64) NOT NULL,
            clip_id VARCHAR(255) NOT NULL,
            seq INTEGER NOT NULL,
            title TEXT,
            duration INTEGER,
            created_at TIMESTAMP,
            view_count INTEGER DEFAULT 0,
            game_id VARCHAR(64),
            video_id VARCHAR(64),
            vod_offset INTEGER,
            thumbnail_url TEXT,
            creator_name VARCHAR(64),
            blocked BOOLEAN DEFAULT FALSE,
            imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(login, clip_id),
            UNIQUE(login, seq)
        )
    ");

    // Add creator_name column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE clips ADD COLUMN IF NOT EXISTS creator_name VARCHAR(64)");
    } catch (PDOException $e) {
        // Column might already exist, ignore error
    }

    // Indexes for fast lookups
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_login ON clips(login)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_seq ON clips(login, seq)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_clip_id ON clips(login, clip_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_created ON clips(login, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_game ON clips(login, game_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_blocked ON clips(login, blocked)");

    echo "Table and indexes created.\n";
} catch (PDOException $e) {
    echo "ERROR creating table: " . $e->getMessage() . "\n";
    exit(1);
}

// Load JSON file - check /tmp first (where backfill writes on Railway), then static cache
$tmpJsonFile = "/tmp/clipsystem_cache/clips_index_{$login}.json";
$staticJsonFile = __DIR__ . "/cache/clips_index_{$login}.json";

if (file_exists($tmpJsonFile)) {
    $jsonFile = $tmpJsonFile;
    echo "Using JSON from /tmp cache (fresh backfill data)\n";
} elseif (file_exists($staticJsonFile)) {
    $jsonFile = $staticJsonFile;
    echo "Using JSON from static cache (deployed with app)\n";
} else {
    echo "ERROR: JSON file not found in /tmp or static cache\n";
    echo "Run clips_backfill.php first to fetch clips from Twitch.\n";
    exit(1);
}

echo "Loading JSON from: $jsonFile\n";
$raw = file_get_contents($jsonFile);
$data = json_decode($raw, true);

if (!$data || !isset($data['clips']) || !is_array($data['clips'])) {
    echo "ERROR: Invalid JSON format.\n";
    exit(1);
}

$clips = $data['clips'];
$totalClips = count($clips);
echo "Found $totalClips clips in JSON.\n";

// Count how many clips have creator_name data
$withCreatorName = 0;
foreach ($clips as $c) {
    if (!empty($c['creator_name'])) $withCreatorName++;
}
echo "Clips with creator_name in JSON: $withCreatorName\n";
if ($withCreatorName === 0) {
    echo "WARNING: No creator_name data in JSON! Run clips_backfill.php first to fetch creator names from Twitch.\n";
}

// Apply chunking for web requests
$isWeb = php_sapi_name() !== 'cli';
if ($isWeb && $startOffset > 0) {
    echo "Starting from offset: $startOffset (chunk size: $chunkSize)\n";
}
$endOffset = $isWeb ? min($totalClips, $startOffset + $chunkSize) : $totalClips;
echo "Processing clips $startOffset to $endOffset of $totalClips\n\n";

// Check existing count
$existingCount = $pdo->query("SELECT COUNT(*) FROM clips WHERE login = " . $pdo->quote($login))->fetchColumn();
echo "Existing clips in database for $login: $existingCount\n";

// Fresh mode: delete all existing clips for this login (preserves other logins)
if ($freshMode && $startOffset === 0 && $existingCount > 0) {
    echo "\n🔄 FRESH MODE: Deleting $existingCount existing clips for $login...\n";
    $deleteStmt = $pdo->prepare("DELETE FROM clips WHERE login = ?");
    $deleteStmt->execute([$login]);
    echo "  Deleted. Database ready for fresh import.\n";
    $existingCount = 0;
}

if ($existingCount > 0) {
    echo "\nWARNING: Clips already exist. This will skip existing clips (upsert mode).\n";
}

// Check if we should update existing clips (for adding new fields like creator_name)
$updateMode = isset($_GET['update']) && $_GET['update'] === '1';

// Prepare insert statement
if ($updateMode) {
    // Upsert mode - update creator_name if clip exists
    $insertSql = "
        INSERT INTO clips (login, clip_id, seq, title, duration, created_at, view_count, game_id, video_id, vod_offset, thumbnail_url, creator_name, blocked)
        VALUES (:login, :clip_id, :seq, :title, :duration, :created_at, :view_count, :game_id, :video_id, :vod_offset, :thumbnail_url, :creator_name, :blocked)
        ON CONFLICT (login, clip_id) DO UPDATE SET creator_name = COALESCE(EXCLUDED.creator_name, clips.creator_name)
    ";
    echo "MODE: Update existing clips with creator_name from JSON\n";
} else {
    // Skip mode - don't touch existing clips
    $insertSql = "
        INSERT INTO clips (login, clip_id, seq, title, duration, created_at, view_count, game_id, video_id, vod_offset, thumbnail_url, creator_name, blocked)
        VALUES (:login, :clip_id, :seq, :title, :duration, :created_at, :view_count, :game_id, :video_id, :vod_offset, :thumbnail_url, :creator_name, :blocked)
        ON CONFLICT (login, clip_id) DO NOTHING
    ";
    echo "MODE: Skip existing clips (add &update=1 to update creator_name)\n";
}
$stmt = $pdo->prepare($insertSql);

// Load existing blocklist to mark blocked clips
$blockedIds = [];
try {
    $blockStmt = $pdo->prepare("SELECT clip_id FROM blocklist WHERE login = ?");
    $blockStmt->execute([$login]);
    while ($row = $blockStmt->fetch()) {
        $blockedIds[$row['clip_id']] = true;
    }
    echo "Loaded " . count($blockedIds) . " blocked clip IDs.\n";
} catch (PDOException $e) {
    echo "Note: Could not load blocklist (table may not exist yet).\n";
}

// Batch insert
echo "\nMigrating clips...\n";
$inserted = 0;
$skipped = 0;
$errors = 0;
$batchSize = 100;

$pdo->beginTransaction();

for ($i = $startOffset; $i < $endOffset; $i++) {
    $clip = $clips[$i];
    $clipId = $clip['id'] ?? '';
    if (!$clipId) {
        $skipped++;
        continue;
    }

    $seq = (int)($clip['seq'] ?? 0);
    if ($seq <= 0) {
        $skipped++;
        continue;
    }

    // Parse created_at timestamp
    $createdAt = null;
    if (!empty($clip['created_at'])) {
        $ts = strtotime($clip['created_at']);
        if ($ts) {
            $createdAt = date('Y-m-d H:i:s', $ts);
        }
    }

    $isBlocked = isset($blockedIds[$clipId]) ? true : false;

    try {
        $stmt->execute([
            ':login' => $login,
            ':clip_id' => $clipId,
            ':seq' => $seq,
            ':title' => $clip['title'] ?? null,
            ':duration' => isset($clip['duration']) ? (int)$clip['duration'] : null,
            ':created_at' => $createdAt,
            ':view_count' => isset($clip['view_count']) ? (int)$clip['view_count'] : 0,
            ':game_id' => !empty($clip['game_id']) ? $clip['game_id'] : null,
            ':video_id' => !empty($clip['video_id']) ? $clip['video_id'] : null,
            ':vod_offset' => isset($clip['vod_offset']) && $clip['vod_offset'] !== null ? (int)$clip['vod_offset'] : null,
            ':thumbnail_url' => !empty($clip['thumbnail_url']) ? $clip['thumbnail_url'] : null,
            ':creator_name' => !empty($clip['creator_name']) ? $clip['creator_name'] : null,
            ':blocked' => $isBlocked ? 't' : 'f',
        ]);

        $rowsAffected = $stmt->rowCount();
        if ($rowsAffected > 0) {
            $inserted++;
        } else {
            $skipped++; // Already existed (no change)
        }
    } catch (PDOException $e) {
        $errors++;
        if ($errors <= 5) {
            echo "Error on clip #$seq ($clipId): " . $e->getMessage() . "\n";
        }
    }

    // Progress update
    if (($i + 1) % $batchSize === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        $pct = round(($i + 1) / $totalClips * 100);
        echo "  Progress: " . ($i + 1) . "/$totalClips ($pct%) - Inserted: $inserted, Skipped: $skipped\n";

        // Check timeout for web requests (stop after 20 seconds to avoid 504)
        if ($isWeb && (time() - $startTime) > 20) {
            echo "\n⚠️  TIMEOUT PREVENTION: Stopping to avoid gateway timeout.\n";
            $nextOffset = $i + 1;
            break;
        }
    }
}

// Track where we stopped for chunked processing
$nextOffset = isset($nextOffset) ? $nextOffset : $endOffset;

$pdo->commit();

echo "\n=== Chunk Complete ===\n";
echo "Processed: " . ($nextOffset - $startOffset) . " clips (from $startOffset to $nextOffset)\n";
echo "Inserted: $inserted\n";
echo "Skipped: $skipped\n";
echo "Errors: $errors\n";

// Final count
$finalCount = $pdo->query("SELECT COUNT(*) FROM clips WHERE login = " . $pdo->quote($login))->fetchColumn();
echo "\nTotal clips in database for $login: $finalCount\n";

// Get max seq
$maxSeq = $pdo->query("SELECT MAX(seq) FROM clips WHERE login = " . $pdo->quote($login))->fetchColumn();
echo "Max seq number: $maxSeq\n";

// Determine if we need to continue
$needsContinue = $isWeb && $nextOffset < $totalClips;
$freshParam = $freshMode ? "&fresh=1" : "";
$fromBackfill = isset($_GET['from_backfill']);
$nextUrl = $needsContinue
    ? "migrate_clips_to_db.php?login=$login&key=" . urlencode($_GET['key'] ?? '') . "&offset=$nextOffset&chunk=$chunkSize" . ($updateMode ? "&update=1" : "") . $freshParam . ($fromBackfill ? "&from_backfill=1" : "")
    : null;

// Success URL - redirect to admin with success message
$successUrl = "admin.php?success=" . urlencode($login) . "&clips=" . $totalClips;
$playerUrl = "https://gmgnrepeat.com/flop/clipplayer_mp4_reel.html?login=" . urlencode($login);

if ($needsContinue) {
    echo "\n🔄 AUTO-CONTINUING in 2 seconds...\n";
    echo "Next chunk: $nextOffset to " . min($totalClips, $nextOffset + $chunkSize) . " of $totalClips\n";
} else {
    echo "\n✅ All clips migrated!\n";
    echo "\n📺 Player URL: $playerUrl\n";
}

// Get buffered output and send with proper headers
$output = ob_get_clean();

if ($needsContinue) {
    // HTML output with auto-refresh
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'>";
    echo "<meta http-equiv='refresh' content='2;url=" . htmlspecialchars($nextUrl) . "'>";
    echo "<title>Migration - Offset $startOffset</title>";
    echo "<style>body{background:#1a1a2e;color:#0f0;font-family:monospace;padding:20px;font-size:14px;line-height:1.4;} a{color:#0ff;}</style>";
    echo "</head><body><pre>" . htmlspecialchars($output) . "</pre>";
    echo "<p><a href='" . htmlspecialchars($nextUrl) . "'>Click here if not redirected...</a></p>";
    echo "</body></html>";
} elseif ($isWeb && $fromBackfill) {
    // Coming from backfill flow - redirect to admin with success
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'>";
    echo "<meta http-equiv='refresh' content='3;url=" . htmlspecialchars($successUrl) . "'>";
    echo "<title>Migration Complete</title>";
    echo "<style>body{background:#1a1a2e;color:#0f0;font-family:monospace;padding:20px;font-size:14px;line-height:1.4;} a{color:#0ff;} .url{background:#0a0a1e;padding:10px;border-radius:4px;margin:10px 0;word-break:break-all;}</style>";
    echo "</head><body><pre>" . htmlspecialchars($output) . "</pre>";
    echo "<div class='url'>📺 Player URL:<br><a href='" . htmlspecialchars($playerUrl) . "'>" . htmlspecialchars($playerUrl) . "</a></div>";
    echo "<p>🔄 Redirecting to admin panel...</p>";
    echo "<p><a href='" . htmlspecialchars($successUrl) . "'>Click here if not redirected...</a></p>";
    echo "</body></html>";
} else {
    // Plain text output
    header('Content-Type: text/plain; charset=utf-8');
    echo $output;
}
