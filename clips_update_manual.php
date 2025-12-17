<?php
// clips_update_manual.php
// Manual clip updater for your local catalog index.
// PHP 7 compatible (no str_starts_with / str_contains).
//
// Usage:
//   php clips_update_manual.php login=floppyjimmie days=30
//   php clips_update_manual.php login=floppyjimmie since=2025-01-01
//   php clips_update_manual.php login=floppyjimmie
//
// Writes/updates:
//   ./cache/clips_index_<login>.json
//
// Notes:
// - Dedupe is by clip "id".
// - Fetches clips from Twitch Helix API.
// - Default behavior: fetch last 14 days (safe window) unless "days" or "since" given.

date_default_timezone_set('UTC');

function load_env($path) {
  if (!file_exists($path)) return;
  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;
    if ($line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    list($k, $v) = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v));
  }
}

function safe_login($s) {
  $s = strtolower(trim($s));
  return preg_replace('/[^a-z0-9_]/', '_', $s);
}

function arg($name, $default = null) {
  global $argv;
  foreach ($argv as $i => $a) {
    if ($i === 0) continue;
    if (strpos($a, $name . '=') === 0) return substr($a, strlen($name) + 1);
  }
  return $default;
}

function twitch_post($url, $data) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($data),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 30,
  ]);
  $out = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  return [$code, $out, $err];
}

function twitch_get($url, $headers) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 30,
  ]);
  $out = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  return [$code, $out, $err];
}

function read_json($path) {
  $raw = @file_get_contents($path);
  if ($raw === false) return null;
  $j = json_decode($raw, true);
  return is_array($j) ? $j : null;
}

function write_json($path, $arr) {
  $json = json_encode($arr, JSON_UNESCAPED_SLASHES);
  @file_put_contents($path, $json);
}

function iso($ts) {
  return gmdate('c', $ts);
}

function parse_iso($s) {
  if (!$s) return 0;
  $t = strtotime($s);
  return $t ? $t : 0;
}

// ---------------------- config / inputs ----------------------
load_env(__DIR__ . '/.env');

$TWITCH_CLIENT_ID = getenv('TWITCH_CLIENT_ID') ?: '';
$TWITCH_CLIENT_SECRET = getenv('TWITCH_CLIENT_SECRET') ?: '';

if (!$TWITCH_CLIENT_ID || !$TWITCH_CLIENT_SECRET) {
  fwrite(STDERR, "Missing TWITCH_CLIENT_ID or TWITCH_CLIENT_SECRET in .env\n");
  exit(1);
}

$login = strtolower(trim(arg('login', 'floppyjimmie')));
$safe = safe_login($login);

$daysArg  = arg('days', null);     // number
$sinceArg = arg('since', null);    // YYYY-MM-DD or ISO
$dryRun   = arg('dry', '0') === '1';

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

$indexFile = $cacheDir . "/clips_index_{$safe}.json";

// Determine fetch window:
// 1) if since= provided, use it
// 2) else if days= provided, use now - days
// 3) else if index exists, use max(created_at) - 7 days safety
// 4) else default to last 14 days
$now = time();
$startedAtTs = 0;

$existing = read_json($indexFile);
$hasExisting = $existing && isset($existing['clips']) && is_array($existing['clips']);

if ($sinceArg) {
  $t = parse_iso($sinceArg);
  if (!$t) {
    fwrite(STDERR, "Invalid since= value. Use YYYY-MM-DD or ISO.\n");
    exit(1);
  }
  $startedAtTs = $t;
} elseif ($daysArg !== null) {
  $d = intval($daysArg);
  if ($d < 1) $d = 1;
  if ($d > 3650) $d = 3650;
  $startedAtTs = $now - ($d * 86400);
} elseif ($hasExisting) {
  // find latest created_at in existing, then rewind 7 days
  $maxTs = 0;
  foreach ($existing['clips'] as $c) {
    $t = parse_iso(isset($c['created_at']) ? $c['created_at'] : '');
    if ($t > $maxTs) $maxTs = $t;
  }
  if ($maxTs > 0) $startedAtTs = max(0, $maxTs - (7 * 86400));
  else $startedAtTs = $now - (14 * 86400);
} else {
  $startedAtTs = $now - (14 * 86400);
}

$endedAtTs = $now;

// ---------------------- auth ----------------------
list($code, $raw, $err) = twitch_post('https://id.twitch.tv/oauth2/token', [
  'client_id' => $TWITCH_CLIENT_ID,
  'client_secret' => $TWITCH_CLIENT_SECRET,
  'grant_type' => 'client_credentials',
]);

if ($code < 200 || $code >= 300) {
  fwrite(STDERR, "Token request failed HTTP {$code}\n{$raw}\n{$err}\n");
  exit(1);
}

$token = json_decode($raw, true);
$access = isset($token['access_token']) ? $token['access_token'] : '';
if (!$access) {
  fwrite(STDERR, "Token response missing access_token\n");
  exit(1);
}

$headers = [
  "Authorization: Bearer {$access}",
  "Client-Id: {$TWITCH_CLIENT_ID}",
];

// ---------------------- user lookup ----------------------
list($uCode, $uRaw, $uErr) = twitch_get('https://api.twitch.tv/helix/users?login=' . urlencode($login), $headers);
if ($uCode < 200 || $uCode >= 300) {
  fwrite(STDERR, "User lookup failed HTTP {$uCode}\n{$uRaw}\n{$uErr}\n");
  exit(1);
}
$userJson = json_decode($uRaw, true);
$broadcasterId = isset($userJson['data'][0]['id']) ? $userJson['data'][0]['id'] : '';
if (!$broadcasterId) {
  fwrite(STDERR, "User not found: {$login}\n");
  exit(1);
}

// ---------------------- load existing + build seen set ----------------------
$clips = [];
$seen = [];

if ($hasExisting) {
  $clips = $existing['clips'];
  foreach ($clips as $c) {
    if (!isset($c['id'])) continue;
    $seen[$c['id']] = true;
  }
} else {
  $existing = [
    "login" => $login,
    "broadcaster_id" => $broadcasterId,
    "created_at" => iso($now),
    "clips" => [],
    "meta" => [],
  ];
  $clips = [];
}

// ---------------------- fetch pages ----------------------
$startedAt = iso($startedAtTs);
$endedAt   = iso($endedAtTs);

fwrite(STDOUT, "Updating catalog for {$login}\n");
fwrite(STDOUT, "Window: started_at={$startedAt}  ended_at={$endedAt}\n");
fwrite(STDOUT, "Existing clips: " . count($clips) . "\n");

$newCount = 0;
$pageCount = 0;
$after = '';
$maxLoops = 50; // safety cap

while ($maxLoops-- > 0) {
  $pageCount++;

  $url = 'https://api.twitch.tv/helix/clips?broadcaster_id=' . urlencode($broadcasterId)
    . '&first=100'
    . '&started_at=' . urlencode($startedAt)
    . '&ended_at=' . urlencode($endedAt);

  if ($after) $url .= '&after=' . urlencode($after);

  list($cCode, $cRaw, $cErr) = twitch_get($url, $headers);
  if ($cCode < 200 || $cCode >= 300) {
    fwrite(STDERR, "Clips fetch failed HTTP {$cCode}\n{$cRaw}\n{$cErr}\n");
    break;
  }

  $cJson = json_decode($cRaw, true);
  $data = isset($cJson['data']) ? $cJson['data'] : [];
  if (!$data || !is_array($data) || count($data) === 0) break;

  foreach ($data as $c) {
    $id = isset($c['id']) ? $c['id'] : '';
    if (!$id) continue;

    if (isset($seen[$id])) continue;

    $seen[$id] = true;
    $newCount++;

    $clips[] = [
      "id" => $id,
      "title" => isset($c["title"]) ? $c["title"] : "",
      "duration" => isset($c["duration"]) ? $c["duration"] : 0,
      "created_at" => isset($c["created_at"]) ? $c["created_at"] : "",
      "view_count" => isset($c["view_count"]) ? $c["view_count"] : 0,
      "game_id" => isset($c["game_id"]) ? $c["game_id"] : "",
      "video_id" => isset($c["video_id"]) ? $c["video_id"] : "",
      "vod_offset" => array_key_exists("vod_offset", $c) ? $c["vod_offset"] : null,
    ];
  }

  $next = isset($cJson['pagination']['cursor']) ? $cJson['pagination']['cursor'] : '';
  if (!$next || $next === $after) break;
  $after = $next;

  // small sleep to be nice to API (optional)
  usleep(150000);
}

// Sort by created_at ascending (nice for sanity; optional)
usort($clips, function($a, $b) {
  $ta = parse_iso(isset($a['created_at']) ? $a['created_at'] : '');
  $tb = parse_iso(isset($b['created_at']) ? $b['created_at'] : '');
  if ($ta === $tb) return 0;
  return ($ta < $tb) ? -1 : 1;
});

// Write back
$existing['login'] = $login;
$existing['broadcaster_id'] = $broadcasterId;
$existing['updated_at'] = iso(time());
$existing['clips'] = $clips;
$existing['meta'] = [
  "added_this_run" => $newCount,
  "pages_fetched" => $pageCount,
  "started_at" => $startedAt,
  "ended_at" => $endedAt,
];

fwrite(STDOUT, "New clips added: {$newCount}\n");
fwrite(STDOUT, "Total clips now: " . count($clips) . "\n");

if ($dryRun) {
  fwrite(STDOUT, "Dry run, not writing file.\n");
  exit(0);
}

write_json($indexFile, $existing);
fwrite(STDOUT, "Wrote: {$indexFile}\n");
exit(0);
