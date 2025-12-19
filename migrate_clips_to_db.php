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
 * Can be run via browser: migrate_clips_to_db.php?login=floppyjimmie&key=flopjim2024
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

// Auth check for web access
$ADMIN_KEY = getenv('ADMIN_KEY') ?: 'flopjim2024';
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
            blocked BOOLEAN DEFAULT FALSE,
            imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(login, clip_id),
            UNIQUE(login, seq)
        )
    ");

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

// Load JSON file
$jsonFile = __DIR__ . "/cache/clips_index_{$login}.json";
if (!file_exists($jsonFile)) {
    echo "ERROR: JSON file not found: $jsonFile\n";
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
echo "Found $totalClips clips to migrate.\n\n";

// Check existing count
$existingCount = $pdo->query("SELECT COUNT(*) FROM clips WHERE login = " . $pdo->quote($login))->fetchColumn();
echo "Existing clips in database for $login: $existingCount\n";

if ($existingCount > 0) {
    echo "\nWARNING: Clips already exist. This will skip existing clips (upsert mode).\n";
}

// Prepare insert statement (upsert - skip if exists)
$insertSql = "
    INSERT INTO clips (login, clip_id, seq, title, duration, created_at, view_count, game_id, video_id, vod_offset, blocked)
    VALUES (:login, :clip_id, :seq, :title, :duration, :created_at, :view_count, :game_id, :video_id, :vod_offset, :blocked)
    ON CONFLICT (login, clip_id) DO NOTHING
";
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

foreach ($clips as $i => $clip) {
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
            ':blocked' => $isBlocked ? 't' : 'f',
        ]);

        if ($stmt->rowCount() > 0) {
            $inserted++;
        } else {
            $skipped++; // Already existed
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
    }
}

$pdo->commit();

echo "\n=== Migration Complete ===\n";
echo "Total processed: $totalClips\n";
echo "Inserted: $inserted\n";
echo "Skipped: $skipped\n";
echo "Errors: $errors\n";

// Final count
$finalCount = $pdo->query("SELECT COUNT(*) FROM clips WHERE login = " . $pdo->quote($login))->fetchColumn();
echo "\nTotal clips in database for $login: $finalCount\n";

// Get max seq
$maxSeq = $pdo->query("SELECT MAX(seq) FROM clips WHERE login = " . $pdo->quote($login))->fetchColumn();
echo "Max seq number: $maxSeq\n";

echo "\nDone!\n";
