<?php
// clips_backfill.php
// One-time backfill: builds a local clip catalog for the past N years.
// Usage (browser):  clips_backfill.php?login=floppyjimmie&years=5
// Usage (cli):      php clips_backfill.php login=floppyjimmie years=5
//
// Fresh mode (re-fetch all clips with creator_name, ignoring cache):
//   clips_backfill.php?login=floppyjimmie&years=5&fresh=1
//
// Note: Without fresh=1, existing clips keep their seq numbers.
//       New clips get seq numbers starting from max+1.

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
$freshMode = arg('fresh', '0') === '1'; // Fresh mode: delete cache and re-fetch everything
$isWeb = php_sapi_name() !== 'cli';
$startTime = time();

// On Railway, /app/cache is read-only, use /tmp for writable storage
$cacheDir = is_writable('/tmp') ? '/tmp/clipsystem_cache' : __DIR__ . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);

// Also check the static cache dir for existing data to resume from
$staticCacheDir = __DIR__ . '/cache';

$safeLogin = preg_replace('/[^a-z0-9_]/', '_', $login);
$indexFile = $cacheDir . '/clips_index_' . $safeLogin . '.json';
$metaFile  = $cacheDir . '/clips_index_' . $safeLogin . '.meta.json';

// Check for existing index in static cache (deployed with app) as fallback
$staticIndexFile = $staticCacheDir . '/clips_index_' . $safeLogin . '.json';

echo "Backfill starting for login={$login}, years={$years}\n";
echo "Index file: {$indexFile}\n";
echo "Cache dir writable: " . (is_writable($cacheDir) ? "yes" : "no") . "\n";

// Fresh mode: delete existing cache files on first window
if ($freshMode && $startWindow === 1) {
  echo "🔄 FRESH MODE: Deleting existing cache to re-fetch all clips with creator_name...\n";
  if (file_exists($indexFile)) {
    @unlink($indexFile);
    echo "  Deleted: $indexFile\n";
  }
  if (file_exists($metaFile)) {
    @unlink($metaFile);
    echo "  Deleted: $metaFile\n";
  }
  // Note: static cache is read-only on Railway, can't delete it there
  echo "  Starting fresh - all clips will be re-fetched from Twitch API\n";
}
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

// Load existing index if present (allows resume or rebuild)
$seen = [];
$clips = [];

// In fresh mode, ONLY load from writable cache (which has fresh data from previous windows)
// NEVER load from static cache in fresh mode - it has old data without creator_name
if ($freshMode) {
  if ($startWindow === 1) {
    echo "Fresh mode: starting fresh, will fetch all clips from Twitch\n";
  } else if (file_exists($indexFile)) {
    // Window 2+: load from writable cache (has fresh data from window 1+)
    echo "Fresh mode: loading from writable cache: $indexFile\n";
    $existing = json_decode(file_get_contents($indexFile), true);
    if (is_array($existing) && isset($existing['clips']) && is_array($existing['clips'])) {
      foreach ($existing['clips'] as $c) {
        if (!isset($c['id'])) continue;
        $seen[$c['id']] = true;
        $clips[] = $c;
      }
      echo "Loaded existing clips: " . count($clips) . "\n";
    }
  } else {
    echo "Fresh mode window $startWindow: no writable cache found, starting empty\n";
  }
} else {
  // Normal mode: try writable cache first, then fall back to static cache
  $loadFrom = null;
  if (file_exists($indexFile)) {
    $loadFrom = $indexFile;
    echo "Loading from writable cache: $indexFile\n";
  } elseif (file_exists($staticIndexFile)) {
    $loadFrom = $staticIndexFile;
    echo "Loading from static cache: $staticIndexFile\n";
  }

  if ($loadFrom) {
    $existing = json_decode(file_get_contents($loadFrom), true);
    if (is_array($existing) && isset($existing['clips']) && is_array($existing['clips'])) {
      foreach ($existing['clips'] as $c) {
        if (!isset($c['id'])) continue;
        $seen[$c['id']] = true;
        $clips[] = $c;
      }
      echo "Loaded existing clips: " . count($clips) . "\n";
    }
  }
}

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
      $id = $c['id'] ?? '';
      if (!$id) continue;
      if (isset($seen[$id])) continue;

      $seen[$id] = true;
      $clips[] = [
        "id" => $id,
        "title" => $c["title"] ?? "",
        "duration" => $c["duration"] ?? 0,
        "created_at" => $c["created_at"] ?? "",
        "view_count" => $c["view_count"] ?? 0,
        "game_id" => $c["game_id"] ?? "",
        "video_id" => $c["video_id"] ?? "",
        "vod_offset" => $c["vod_offset"] ?? null,
        "thumbnail_url" => $c["thumbnail_url"] ?? "",
        "creator_name" => $c["creator_name"] ?? "",
      ];
      $addedThisWindow++;
    }

    $next = $cJson['pagination']['cursor'] ?? '';
    if (!$next || $next === $after) break;
    $after = $next;

    // small pace to be nice to Twitch
    usleep(120000);
  }

  echo "  Added this window: {$addedThisWindow}, total: " . count($clips) . "\n";

  // Save progress after each window
  // Sort oldest-first so seq=1 is oldest clip
  usort($clips, function($a, $b) {
    return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
  });

  // Assign seq numbers - PRESERVE existing seq numbers, only assign to new clips
  // In fresh mode: assign 1..N (full re-index)
  // In normal mode: keep existing seq, assign new clips starting from max+1
  if ($freshMode) {
    // Fresh mode: re-assign all seq numbers (full rebuild)
    foreach ($clips as $i => &$clip) {
      $clip['seq'] = $i + 1;
    }
    unset($clip);
  } else {
    // Incremental mode: preserve existing seq, assign new ones at end
    // Find max existing seq
    $maxExistingSeq = 0;
    foreach ($clips as $clip) {
      if (isset($clip['seq']) && $clip['seq'] > $maxExistingSeq) {
        $maxExistingSeq = $clip['seq'];
      }
    }

    // Assign seq to clips that don't have one (new clips)
    $nextSeq = $maxExistingSeq + 1;
    foreach ($clips as &$clip) {
      if (!isset($clip['seq']) || $clip['seq'] <= 0) {
        $clip['seq'] = $nextSeq++;
      }
    }
    unset($clip);
  }

  $out = [
    "login" => $login,
    "broadcaster_id" => $broadcasterId,
    "years" => $years,
    "built_at" => gmdate('c'),
    "count" => count($clips),
    "max_seq" => count($clips),
    "clips" => $clips,
  ];
  file_put_contents($indexFile, json_encode($out, JSON_UNESCAPED_SLASHES));

  $meta = [
    "login" => $login,
    "broadcaster_id" => $broadcasterId,
    "last_window_end" => $endedAt,
    "updated_at" => gmdate('c'),
    "count" => count($clips),
  ];
  file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_SLASHES));

  $windowStart = $windowEnd;
  $windowsProcessed++;
  echo "\n";
}

// Calculate next window for continuation
$nextWindow = $stoppedEarly ? $w : ($w > $totalWindows ? 0 : $w);

// Count clips with creator_name
$withCreatorName = 0;
foreach ($clips as $c) {
  if (!empty($c['creator_name'])) $withCreatorName++;
}

// Determine if we need to continue and build next URL
$needsContinue = $isWeb && $nextWindow > 0 && $nextWindow <= $totalWindows;
$freshParam = $freshMode ? "&fresh=1" : "";
$nextUrl = $needsContinue ? "clips_backfill.php?login=$login&years=$years&window=$nextWindow&maxwin=$maxWindows$freshParam" : null;

echo "=== Chunk Complete ===\n";
echo "Windows processed: $windowsProcessed\n";
echo "Total clips indexed: " . count($clips) . "\n";
echo "Clips with creator_name: $withCreatorName\n";

if ($needsContinue) {
  echo "\n🔄 AUTO-CONTINUING in 2 seconds...\n";
  echo "Next: window $nextWindow to " . min($totalWindows, $nextWindow + $maxWindows - 1) . " of $totalWindows\n";
} else {
  echo "\n✅ All windows complete! Ready for migration.\n";
  echo "Run: migrate_clips_to_db.php?login=$login&key=YOUR_KEY&update=1\n";
}

// Get buffered output and send with proper headers
$output = ob_get_clean();

if ($isWeb && $needsContinue) {
  // HTML output with auto-refresh
  header('Content-Type: text/html; charset=utf-8');
  echo "<!DOCTYPE html><html><head><meta charset='utf-8'>";
  echo "<meta http-equiv='refresh' content='2;url=" . htmlspecialchars($nextUrl) . "'>";
  echo "<title>Clips Backfill - Window $startWindow</title>";
  echo "<style>body{background:#1a1a2e;color:#0f0;font-family:monospace;padding:20px;font-size:14px;line-height:1.4;} a{color:#0ff;}</style>";
  echo "</head><body><pre>" . htmlspecialchars($output) . "</pre>";
  echo "<p><a href='" . htmlspecialchars($nextUrl) . "'>Click here if not redirected...</a></p>";
  echo "</body></html>";
} else {
  // Plain text output
  header('Content-Type: text/plain; charset=utf-8');
  echo $output;
}
