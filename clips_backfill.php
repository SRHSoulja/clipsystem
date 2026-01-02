<?php
// clips_backfill.php
// One-time backfill: builds a local clip catalog for the past N years.
// Usage (browser):  clips_backfill.php?login=floppyjimmie&years=5
// Usage (cli):      php clips_backfill.php login=floppyjimmie years=5
//
// Fresh mode (re-fetch all clips, delete existing DB entries):
//   clips_backfill.php?login=floppyjimmie&years=5&fresh=1
//
// This script now writes directly to the database as clips are fetched,
// eliminating the need for a separate migration step.

// Buffer output so we can add auto-redirect header
ob_start();

function load_env($path) {
  if (!file_exists($path)) return;

  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);

    if ($line === '') continue;
    if ($line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;

    [$k, $v] = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v));
  }
}

function arg($name, $default = null) {
  // Check URL query params first (for browser access)
  if (isset($_GET[$name])) {
    return $_GET[$name];
  }

  // Check CLI arguments
  $prefix = $name . '=';
  foreach ($_SERVER['argv'] ?? [] as $a) {
    if (strpos($a, $prefix) === 0) {
      return substr($a, strlen($prefix));
    }
  }
  return $default;
}

function curl_post($url, $data, $headers = []) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($data),
    CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
    CURLOPT_TIMEOUT => 30,
  ]);
  $out = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $out];
}

function curl_get($url, $headers = []) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 30,
  ]);
  $out = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $out];
}

function iso_utc($ts) { return gmdate('c', $ts); }

function sleep_backoff($attempt) {
  $base = min(8, 1 + $attempt); // 2..9 seconds
  usleep($base * 1000000);
}

load_env(__DIR__ . '/.env');

// Require super admin OAuth for web access (skip for CLI)
$isWeb = php_sapi_name() !== 'cli';
if ($isWeb) {
  require_once __DIR__ . '/includes/twitch_oauth.php';
  $currentUser = getCurrentUser();
  if (!$currentUser || !isSuperAdmin()) {
    http_response_code(403);
    echo "Forbidden - super admin OAuth required\n";
    exit;
  }
}

// Load database config
require_once __DIR__ . '/db_config.php';

$TWITCH_CLIENT_ID = getenv('TWITCH_CLIENT_ID') ?: '';
$TWITCH_CLIENT_SECRET = getenv('TWITCH_CLIENT_SECRET') ?: '';
if (!$TWITCH_CLIENT_ID || !$TWITCH_CLIENT_SECRET) {
  http_response_code(500);
  echo "Missing TWITCH_CLIENT_ID or TWITCH_CLIENT_SECRET\n";
  exit;
}

$login = strtolower(trim((string) arg('login', 'floppyjimmie')));
$years = (int) arg('years', 5);
if ($years < 1) $years = 1;
if ($years > 10) $years = 10;

// Chunking support for web execution (Railway has ~30s timeout)
$startWindow = (int) arg('window', 1);  // Which window to start from (1-indexed)
$maxWindows = (int) arg('maxwin', 5);   // Max windows per request
if ($maxWindows < 1) $maxWindows = 1;
if ($maxWindows > 20) $maxWindows = 20;
$freshMode = arg('fresh', '0') === '1'; // Fresh mode: delete everything and re-fetch
$startTime = time();

echo "Backfill starting for login={$login}, years={$years}\n";
echo "Writing directly to database (no migration needed)\n\n";

// Connect to database
$pdo = get_db_connection();
if (!$pdo) {
  http_response_code(500);
  echo "ERROR: Could not connect to database. Check DATABASE_URL.\n";
  exit;
}
echo "Connected to PostgreSQL.\n";

// Ensure clips table exists with all columns
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
  } catch (PDOException $e) {}

  // Indexes for fast lookups
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_login ON clips(login)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_seq ON clips(login, seq)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_clip_id ON clips(login, clip_id)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_created ON clips(login, created_at)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_game ON clips(login, game_id)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_blocked ON clips(login, blocked)");

  echo "Database table ready.\n";
} catch (PDOException $e) {
  http_response_code(500);
  echo "ERROR creating table: " . $e->getMessage() . "\n";
  exit;
}

// Fresh mode: delete existing clips on first window
if ($freshMode && $startWindow === 1) {
  echo "🔄 FRESH MODE: Deleting existing clips for $login...\n";
  try {
    $deleteStmt = $pdo->prepare("DELETE FROM clips WHERE login = ?");
    $deleteStmt->execute([$login]);
    $deleted = $deleteStmt->rowCount();
    echo "  Deleted $deleted existing clips.\n";
  } catch (PDOException $e) {
    echo "  Warning: Could not delete existing clips: " . $e->getMessage() . "\n";
  }
}

// Get current max seq for this login (for incremental mode)
$maxSeq = 0;
if (!$freshMode || $startWindow > 1) {
  try {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(seq), 0) FROM clips WHERE login = ?");
    $stmt->execute([$login]);
    $maxSeq = (int)$stmt->fetchColumn();
    echo "Current max seq: $maxSeq\n";
  } catch (PDOException $e) {}
}

// Load existing clip IDs to avoid duplicates
$existingClipIds = [];
try {
  $stmt = $pdo->prepare("SELECT clip_id FROM clips WHERE login = ?");
  $stmt->execute([$login]);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $existingClipIds[$row['clip_id']] = true;
  }
  echo "Existing clips in DB: " . count($existingClipIds) . "\n";
} catch (PDOException $e) {}

// Load blocklist
$blockedIds = [];
try {
  $blockStmt = $pdo->prepare("SELECT clip_id FROM blocklist WHERE login = ?");
  $blockStmt->execute([$login]);
  while ($row = $blockStmt->fetch()) {
    $blockedIds[$row['clip_id']] = true;
  }
  if (count($blockedIds) > 0) {
    echo "Loaded " . count($blockedIds) . " blocked clip IDs.\n";
  }
} catch (PDOException $e) {}

echo "\n";

// App token
[$code, $raw] = curl_post('https://id.twitch.tv/oauth2/token', [
  'client_id' => $TWITCH_CLIENT_ID,
  'client_secret' => $TWITCH_CLIENT_SECRET,
  'grant_type' => 'client_credentials',
]);
if ($code < 200 || $code >= 300) {
  http_response_code(500);
  echo "Token request failed http={$code}\n{$raw}\n";
  exit;
}
$token = json_decode($raw, true)['access_token'] ?? '';
if (!$token) {
  http_response_code(500);
  echo "Missing access_token in token response\n";
  exit;
}

$headers = [
  "Authorization: Bearer {$token}",
  "Client-Id: {$TWITCH_CLIENT_ID}",
];

// User lookup
[$uCode, $uRaw] = curl_get('https://api.twitch.tv/helix/users?login=' . urlencode($login), $headers);
if ($uCode < 200 || $uCode >= 300) {
  http_response_code(500);
  echo "User lookup failed http={$uCode}\n{$uRaw}\n";
  exit;
}
$userJson = json_decode($uRaw, true);
$broadcasterId = $userJson['data'][0]['id'] ?? '';
if (!$broadcasterId) {
  http_response_code(404);
  echo "User not found\n";
  exit;
}

$now = time();
$startAll = $now - ($years * 365 * 24 * 60 * 60);

$windowDays = 30; // 30-day chunks
$windowSec = $windowDays * 24 * 60 * 60;

$totalWindows = (int) ceil(($now - $startAll) / $windowSec);

// Calculate which windows to process this request
$endWindow = $isWeb ? min($totalWindows, $startWindow + $maxWindows - 1) : $totalWindows;
echo "Processing windows $startWindow to $endWindow of $totalWindows\n\n";

// Skip to the starting window
$windowStart = $startAll + (($startWindow - 1) * $windowSec);
$windowsProcessed = 0;
$stoppedEarly = false;
$totalInserted = 0;
$totalAlreadyExist = 0;
$totalErrors = 0;

// Prepare batch insert statement
$insertSql = "
  INSERT INTO clips (login, clip_id, seq, title, duration, created_at, view_count, game_id, video_id, vod_offset, thumbnail_url, creator_name, blocked)
  VALUES (:login, :clip_id, :seq, :title, :duration, :created_at, :view_count, :game_id, :video_id, :vod_offset, :thumbnail_url, :creator_name, :blocked)
  ON CONFLICT (login, clip_id) DO UPDATE SET
    title = EXCLUDED.title,
    view_count = EXCLUDED.view_count,
    creator_name = COALESCE(EXCLUDED.creator_name, clips.creator_name),
    thumbnail_url = EXCLUDED.thumbnail_url
";
$insertStmt = $pdo->prepare($insertSql);

// Collect clips for this chunk to assign seq numbers properly
$newClips = [];

for ($w = $startWindow; $w <= $endWindow; $w++) {
  // Check timeout for web requests (stop after 20 seconds to avoid 504)
  if ($isWeb && (time() - $startTime) > 20) {
    echo "\n⚠️  TIMEOUT PREVENTION: Stopping to avoid gateway timeout.\n";
    $stoppedEarly = true;
    break;
  }

  $windowEnd = min($now, $windowStart + $windowSec);
  $startedAt = iso_utc($windowStart);
  $endedAt   = iso_utc($windowEnd);

  echo "Window {$w}/{$totalWindows}: {$startedAt} -> {$endedAt}\n";

  $after = '';
  $pages = 0;
  $addedThisWindow = 0;

  while ($pages < 30) { // safety: 30 pages * 100 = 3000 per window
    $pages++;

    $url = 'https://api.twitch.tv/helix/clips?broadcaster_id=' . urlencode($broadcasterId)
      . '&first=100'
      . '&started_at=' . urlencode($startedAt)
      . '&ended_at=' . urlencode($endedAt);

    if ($after) $url .= '&after=' . urlencode($after);

    $attempt = 0;
    while (true) {
      [$cCode, $cRaw] = curl_get($url, $headers);

      // retry on rate limit or transient errors
      if ($cCode === 429 || $cCode >= 500) {
        $attempt++;
        if ($attempt > 6) break;
        echo "  Twitch http={$cCode}, retry {$attempt}\n";
        sleep_backoff($attempt);
        continue;
      }
      break;
    }

    if ($cCode < 200 || $cCode >= 300) {
      echo "  Stop window due to http={$cCode}\n";
      break;
    }

    $cJson = json_decode($cRaw, true);
    $data = $cJson['data'] ?? [];
    if (!$data) break;

    foreach ($data as $c) {
      $clipId = $c['id'] ?? '';
      if (!$clipId) continue;

      // Skip if already in DB
      if (isset($existingClipIds[$clipId])) {
        $totalAlreadyExist++;
        continue;
      }

      // Parse created_at timestamp
      $createdAt = null;
      if (!empty($c['created_at'])) {
        $ts = strtotime($c['created_at']);
        if ($ts) {
          $createdAt = date('Y-m-d H:i:s', $ts);
        }
      }

      $newClips[] = [
        'clip_id' => $clipId,
        'title' => $c['title'] ?? null,
        'duration' => isset($c['duration']) ? (int)$c['duration'] : null,
        'created_at' => $createdAt,
        'created_at_raw' => $c['created_at'] ?? '',
        'view_count' => isset($c['view_count']) ? (int)$c['view_count'] : 0,
        'game_id' => !empty($c['game_id']) ? $c['game_id'] : null,
        'video_id' => !empty($c['video_id']) ? $c['video_id'] : null,
        'vod_offset' => isset($c['vod_offset']) && $c['vod_offset'] !== null ? (int)$c['vod_offset'] : null,
        'thumbnail_url' => !empty($c['thumbnail_url']) ? $c['thumbnail_url'] : null,
        'creator_name' => !empty($c['creator_name']) ? $c['creator_name'] : null,
        'blocked' => isset($blockedIds[$clipId]),
      ];

      $existingClipIds[$clipId] = true; // Mark as seen
      $addedThisWindow++;
    }

    $next = $cJson['pagination']['cursor'] ?? '';
    if (!$next || $next === $after) break;
    $after = $next;

    // small pace to be nice to Twitch
    usleep(120000);
  }

  echo "  Fetched this window: {$addedThisWindow}\n";

  $windowStart = $windowEnd;
  $windowsProcessed++;
}

// Sort new clips by created_at (oldest first) and assign seq numbers
if (count($newClips) > 0) {
  usort($newClips, function($a, $b) {
    return strcmp($a['created_at_raw'], $b['created_at_raw']);
  });

  echo "\nInserting " . count($newClips) . " new clips into database...\n";

  // DEBUG: Verify these clips don't already exist in DB
  foreach ($newClips as $nc) {
    $checkStmt = $pdo->prepare("SELECT clip_id FROM clips WHERE login = ? AND clip_id = ?");
    $checkStmt->execute([$login, $nc['clip_id']]);
    $exists = $checkStmt->fetch();
    if ($exists) {
      echo "  ⚠️ BUG: {$nc['clip_id']} already in DB but not in existingClipIds!\n";
    } else {
      echo "  + {$nc['clip_id']} (genuinely new)\n";
    }
  }

  $pdo->beginTransaction();
  $nextSeq = $maxSeq + 1;
  $batchCount = 0;

  foreach ($newClips as $clip) {
    try {
      $insertStmt->execute([
        ':login' => $login,
        ':clip_id' => $clip['clip_id'],
        ':seq' => $nextSeq,
        ':title' => $clip['title'],
        ':duration' => $clip['duration'],
        ':created_at' => $clip['created_at'],
        ':view_count' => $clip['view_count'],
        ':game_id' => $clip['game_id'],
        ':video_id' => $clip['video_id'],
        ':vod_offset' => $clip['vod_offset'],
        ':thumbnail_url' => $clip['thumbnail_url'],
        ':creator_name' => $clip['creator_name'],
        ':blocked' => $clip['blocked'] ? 't' : 'f',
      ]);

      if ($insertStmt->rowCount() > 0) {
        $totalInserted++;
        $nextSeq++;
      }

      $batchCount++;
      if ($batchCount % 100 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  Progress: $batchCount/" . count($newClips) . " inserted\n";
      }
    } catch (PDOException $e) {
      // Likely duplicate or constraint violation
      $totalErrors++;
    }
  }

  $pdo->commit();
  echo "  Done! Inserted: $totalInserted\n";
  if ($totalErrors > 0) {
    echo "  Errors: $totalErrors\n";
  }
}

echo "\nAPI returned " . ($totalInserted + $totalAlreadyExist) . " clips (" . $totalAlreadyExist . " already in DB)\n";

// Calculate next window for continuation
$nextWindow = $stoppedEarly ? $w : ($w > $totalWindows ? 0 : $w);

// Determine if we need to continue
$needsContinue = $isWeb && $nextWindow > 0 && $nextWindow <= $totalWindows;
$freshParam = $freshMode ? "&fresh=1" : "";
$nextUrl = $needsContinue ? "clips_backfill.php?login=$login&years=$years&window=$nextWindow&maxwin=$maxWindows$freshParam" : null;

// Success URL - redirect to admin with success message
$finalClipCount = 0;
try {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ?");
  $stmt->execute([$login]);
  $finalClipCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

$successUrl = "admin.php?success=" . urlencode($login) . "&clips=" . $finalClipCount;

echo "\n=== Chunk Complete ===\n";
echo "Windows processed: $windowsProcessed\n";
echo "Clips inserted this chunk: $totalInserted\n";
echo "Total clips in database: $finalClipCount\n";

if ($needsContinue) {
  echo "\n🔄 AUTO-CONTINUING in 2 seconds...\n";
  echo "Next: window $nextWindow to " . min($totalWindows, $nextWindow + $maxWindows - 1) . " of $totalWindows\n";
} else {
  echo "\n✅ Backfill complete!\n";

  // Create streamer entry for dashboard access
  echo "\n🔑 Setting up dashboard access...\n";
  try {
    require_once __DIR__ . '/includes/dashboard_auth.php';
    $auth = new DashboardAuth();
    if (!$auth->streamerExists($login)) {
      if ($auth->createStreamer($login)) {
        echo "  ✓ Dashboard access created for '$login'\n";
      }
    } else {
      echo "  ✓ Dashboard access already exists\n";
    }
  } catch (Exception $e) {
    echo "  Note: Could not setup dashboard: " . $e->getMessage() . "\n";
  }

  // Register channel for bot
  echo "\n🤖 Registering channel for bot commands...\n";
  try {
    $stmt = $pdo->prepare("
      INSERT INTO bot_channels (login, enabled, added_at)
      VALUES (?, true, CURRENT_TIMESTAMP)
      ON CONFLICT (login) DO NOTHING
    ");
    $stmt->execute([$login]);
    if ($stmt->rowCount() > 0) {
      echo "  ✓ Channel registered - bot can now respond to commands when invited\n";
    } else {
      echo "  ✓ Channel already registered for bot\n";
    }
  } catch (PDOException $e) {
    echo "  Note: Could not register bot channel: " . $e->getMessage() . "\n";
  }

  echo "\n💡 Streamer can access their dashboard at /dashboard/$login\n";
}

// Get buffered output and send with proper headers
$output = ob_get_clean();

if ($isWeb && $needsContinue) {
  // HTML output with auto-refresh to next window
  header('Content-Type: text/html; charset=utf-8');
  echo "<!DOCTYPE html><html><head><meta charset='utf-8'>";
  echo "<meta http-equiv='refresh' content='2;url=" . htmlspecialchars($nextUrl) . "'>";
  echo "<title>Clips Backfill - Window $startWindow</title>";
  echo "<style>body{background:#1a1a2e;color:#0f0;font-family:monospace;padding:20px;font-size:14px;line-height:1.4;} a{color:#0ff;}</style>";
  echo "</head><body><pre>" . htmlspecialchars($output) . "</pre>";
  echo "<p><a href='" . htmlspecialchars($nextUrl) . "'>Click here if not redirected...</a></p>";
  echo "</body></html>";
} elseif ($isWeb) {
  // Backfill complete - redirect to admin
  header('Content-Type: text/html; charset=utf-8');
  echo "<!DOCTYPE html><html><head><meta charset='utf-8'>";
  echo "<meta http-equiv='refresh' content='3;url=" . htmlspecialchars($successUrl) . "'>";
  echo "<title>Clips Backfill Complete</title>";
  echo "<style>body{background:#1a1a2e;color:#0f0;font-family:monospace;padding:20px;font-size:14px;line-height:1.4;} a{color:#0ff;}</style>";
  echo "</head><body><pre>" . htmlspecialchars($output) . "</pre>";
  echo "<p>🔄 Redirecting to admin panel...</p>";
  echo "<p><a href='" . htmlspecialchars($successUrl) . "'>Click here if not redirected...</a></p>";
  echo "</body></html>";
} else {
  // Plain text output (CLI)
  header('Content-Type: text/plain; charset=utf-8');
  echo $output;
}
