<?php
/**
 * cremove.php - Remove a clip from the pool by its seq number
 *
 * Mod-only command to permanently remove unwanted clips.
 * Adds the clip to a blocklist so it won't appear again.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// Static data (clips_index) is in ./cache (read-only on Railway)
$staticDir = __DIR__ . "/cache";
// Runtime data goes to /tmp on Railway
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

// Same admin key as pclip
$ADMIN_KEY = "flopjim2024";

if ($key !== $ADMIN_KEY) { http_response_code(403); echo "forbidden"; exit; }
if ($seq <= 0) { echo "Usage: !cremove <clip#>"; exit; }

// Load the clips index to find the clip
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

// Load existing blocklist
$blocklistFile = $runtimeDir . "/blocklist_" . $login . ".json";
$blocklist = [];
if (file_exists($blocklistFile)) {
  $blockRaw = @file_get_contents($blocklistFile);
  $blocklist = $blockRaw ? json_decode($blockRaw, true) : [];
  if (!is_array($blocklist)) $blocklist = [];
}

// Check if already blocked
if (in_array($clipId, array_column($blocklist, "clip_id"))) {
  $title = $clip["title"] ?? "(no title)";
  echo "Clip #{$seq} already removed: {$title}";
  exit;
}

// Add to blocklist
$blocklist[] = [
  "clip_id"    => $clipId,
  "seq"        => $seq,
  "title"      => $clip["title"] ?? "",
  "removed_at" => gmdate("c"),
];

// Save blocklist
@file_put_contents($blocklistFile, json_encode($blocklist, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);

$title = $clip["title"] ?? "(no title)";
$count = count($blocklist);
echo "Removed Clip #{$seq}: {$title} ({$count} total blocked)";
