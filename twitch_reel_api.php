<?php
// twitch_reel_api.php (PHP 7 compatible, serves from local catalog index)
// Requires: cache/clips_index_<login>.json produced by your backfill script.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function load_env($path) {
  if (!file_exists($path)) return;
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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

function read_json_file($path) {
  $raw = @file_get_contents($path);
  if ($raw === false) return null;
  $j = json_decode($raw, true);
  return is_array($j) ? $j : null;
}

function write_json_file($path, $arr) {
  @file_put_contents($path, json_encode($arr, JSON_UNESCAPED_SLASHES));
}

function parse_iso_time($iso) {
  if (!$iso) return 0;
  $t = strtotime($iso);
  return $t ? $t : 0;
}

// ---- env (optional, kept for consistency) ----
load_env(__DIR__ . '/.env');

// ---- query params ----
$login = isset($_GET['login']) ? strtolower(trim($_GET['login'])) : 'floppyjimmie';

// days is now used as "recency window" for weighting; set days=0 to ignore recency weighting
$days  = isset($_GET['days']) ? intval($_GET['days']) : 180;
if ($days < 0) $days = 0;
if ($days > 3650) $days = 3650;

$pool  = isset($_GET['pool']) ? intval($_GET['pool']) : 400;
if ($pool < 50) $pool = 50;
if ($pool > 2000) $pool = 2000; // allow bigger, but keep response sane

// ---- small output cache (prevents OBS rapid refresh reshuffling constantly) ----
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

$safe = safe_login($login);
$cacheKey = "reel_{$safe}_days{$days}_pool{$pool}.json";
$cacheFile = $cacheDir . '/' . $cacheKey;

// keep short so it still feels fresh, but doesn't churn constantly
$cacheTtlSeconds = 90;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtlSeconds)) {
  readfile($cacheFile);
  exit;
}

// ---- read catalog index ----
$indexFile = $cacheDir . "/clips_index_{$safe}.json";
$index = read_json_file($indexFile);

if (!$index || !isset($index['clips']) || !is_array($index['clips']) || !count($index['clips'])) {
  http_response_code(500);
  $out = [
    "error" => "Missing or empty catalog index. Expected: {$indexFile}",
    "login" => $login,
  ];
  echo json_encode($out, JSON_UNESCAPED_SLASHES);
  exit;
}

$all = $index['clips'];
$totalAll = count($all);

$now = time();
$recentCut = ($days > 0) ? ($now - ($days * 86400)) : 0;

// Buckets to keep variety:
// - 60% recent (within days window)
// - 30% mid (older than days, within 2 years)
// - 10% old (older than 2 years)
$recent = [];
$mid = [];
$old = [];

$twoYearsCut = $now - (730 * 86400);

foreach ($all as $c) {
  $ts = parse_iso_time(isset($c['created_at']) ? $c['created_at'] : '');
  if ($ts <= 0) {
    $old[] = $c;
    continue;
  }

  if ($days > 0 && $ts >= $recentCut) {
    $recent[] = $c;
  } elseif ($ts >= $twoYearsCut) {
    $mid[] = $c;
  } else {
    $old[] = $c;
  }
}

// If days=0, treat everything as "mid" for a balanced shuffle
if ($days === 0) {
  $mid = $all;
  $recent = [];
  $old = [];
}

function shuffle_in_place(&$arr) {
  // stronger shuffle than default in some environments by reseeding
  for ($i = count($arr) - 1; $i > 0; $i--) {
    $j = random_int(0, $i);
    $tmp = $arr[$i];
    $arr[$i] = $arr[$j];
    $arr[$j] = $tmp;
  }
}

shuffle_in_place($recent);
shuffle_in_place($mid);
shuffle_in_place($old);

// Determine target counts
$wantRecent = (int)floor($pool * 0.60);
$wantMid    = (int)floor($pool * 0.30);
$wantOld    = $pool - $wantRecent - $wantMid;

// If a bucket is short, spill into others
$pick = [];

$take = function($src, $n) {
  if ($n <= 0) return [];
  return array_slice($src, 0, min($n, count($src)));
};

$pickRecent = $take($recent, $wantRecent);
$pickMid    = $take($mid, $wantMid);
$pickOld    = $take($old, $wantOld);

$pick = array_merge($pickRecent, $pickMid, $pickOld);

// Fill remainder from anywhere (recent -> mid -> old -> all)
$remain = $pool - count($pick);
if ($remain > 0) $pick = array_merge($pick, $take(array_slice($recent, count($pickRecent)), $remain));
$remain = $pool - count($pick);
if ($remain > 0) $pick = array_merge($pick, $take(array_slice($mid, count($pickMid)), $remain));
$remain = $pool - count($pick);
if ($remain > 0) $pick = array_merge($pick, $take(array_slice($old, count($pickOld)), $remain));
$remain = $pool - count($pick);
if ($remain > 0) {
  shuffle_in_place($all);
  $pick = array_merge($pick, $take($all, $remain));
}

// Final dedupe by ID (paranoia-safe)
$seen = [];
$clips = [];
foreach ($pick as $c) {
  $id = isset($c['id']) ? $c['id'] : '';
  if (!$id) continue;
  if (isset($seen[$id])) continue;
  $seen[$id] = true;
  $clips[] = $c;
  if (count($clips) >= $pool) break;
}

$out = [
  "login" => $login,
  "source" => "local_index",
  "index_total" => $totalAll,
  "days_window" => $days,
  "count" => count($clips),
  "clips" => $clips,
  "fetched_at" => gmdate('c'),
];

write_json_file($cacheFile, $out);
echo json_encode($out, JSON_UNESCAPED_SLASHES);
