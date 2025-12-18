<?php
/**
 * pclip.php - Force play a clip by its permanent seq number
 *
 * Looks up the clip by seq from the full clips_index file, not the current pool.
 * This means ANY clip can be replayed by its permanent number.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// Static data (clips_index) is in ./cache (read-only on Railway)
$staticDir = __DIR__ . "/cache";
// Runtime data (force_play) goes to /tmp on Railway
$runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";
if (!is_dir($runtimeDir)) @mkdir($runtimeDir, 0777, true);

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");
$seq   = (int)($_GET["seq"] ?? 0);
$key   = (string)($_GET["key"] ?? "");

// IMPORTANT: set a real secret here (used in Nightbot command)
$ADMIN_KEY = "flopjim2024";

if ($key !== $ADMIN_KEY) { http_response_code(403); echo "forbidden"; exit; }
if ($seq <= 0) { echo "Usage: !pclip <clip#>"; exit; }

// Load the full clips index to find the clip by its permanent seq
$indexFile = $staticDir . "/clips_index_" . $login . ".json";
if (!file_exists($indexFile)) {
  echo "Clip index not found.";
  exit;
}

$raw = @file_get_contents($indexFile);
if (!$raw) { echo "Could not read clip index."; exit; }

$data = json_decode($raw, true);
if (!is_array($data) || !isset($data["clips"]) || !is_array($data["clips"])) {
  echo "Invalid clip index format.";
  exit;
}

// Find clip by seq number
$clip = null;
foreach ($data["clips"] as $c) {
  if (isset($c["seq"]) && (int)$c["seq"] === $seq) {
    $clip = $c;
    break;
  }
}

if (!$clip) {
  $maxSeq = isset($data["max_seq"]) ? (int)$data["max_seq"] : count($data["clips"]);
  echo "Clip #{$seq} not found. Valid range: 1-{$maxSeq}";
  exit;
}

$clipId = (string)($clip["id"] ?? "");
if ($clipId === "") { echo "Clip #{$seq} missing id."; exit; }

$forcePath = $runtimeDir . "/force_play_" . $login . ".json";
$payload = [
  "login"    => $login,
  "seq"      => $seq,
  "clip_id"  => $clipId,
  "title"    => $clip["title"] ?? "",
  "nonce"    => (string)(time() . "_" . bin2hex(random_bytes(4))),
  "set_at"   => gmdate("c"),
];

@file_put_contents($forcePath, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);

$title = $clip["title"] ?? "(no title)";
echo "Playing Clip #{$seq}: {$title}";
