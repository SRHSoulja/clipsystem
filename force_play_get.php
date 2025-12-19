<?php
/**
 * force_play_get.php - Get the current force play request
 *
 * Returns the full clip data so the player can play ANY clip,
 * not just clips in the current pool.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// Static data (clips_index) is in ./cache (read-only on Railway)
$staticDir = __DIR__ . "/cache";
// Runtime data (force_play) goes to /tmp on Railway
$runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");
$path = $runtimeDir . "/force_play_" . $login . ".json";

if (!file_exists($path)) { echo "{}"; exit; }

$raw = @file_get_contents($path);
if (!$raw) { echo "{}"; exit; }

$data = json_decode($raw, true);
if (!is_array($data) || !isset($data["clip_id"])) { echo "{}"; exit; }

// Look up the full clip data from the index (static dir, not runtime)
$clipId = $data["clip_id"];
$indexFile = $staticDir . "/clips_index_" . $login . ".json";

$foundInIndex = false;
if (file_exists($indexFile)) {
  $indexRaw = @file_get_contents($indexFile);
  $indexData = $indexRaw ? json_decode($indexRaw, true) : null;
  if (is_array($indexData) && isset($indexData["clips"]) && is_array($indexData["clips"])) {
    foreach ($indexData["clips"] as $c) {
      if (isset($c["id"]) && $c["id"] === $clipId) {
        // Merge full clip data into response so player can use it directly
        $data["clip"] = $c;
        $foundInIndex = true;
        break;
      }
    }
  }
}

// If clip not found in index (e.g., playlist clip), create clip data from force_play data
if (!$foundInIndex && isset($data["clip_id"])) {
  $data["clip"] = [
    "id" => $data["clip_id"],
    "title" => $data["title"] ?? "",
    "duration" => $data["duration"] ?? 30,
    "seq" => $data["seq"] ?? 0,
  ];
}

echo json_encode($data, JSON_UNESCAPED_SLASHES);
