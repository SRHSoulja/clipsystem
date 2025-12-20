<?php
/**
 * force_play_get.php - Get the current force play request
 *
 * Returns the full clip data so the player can play ANY clip,
 * not just clips in the current pool.
 */
require_once __DIR__ . '/includes/helpers.php';

set_cors_headers();
handle_options_request();
set_nocache_headers();
header("Content-Type: application/json; charset=utf-8");

// Static data (clips_index) is in ./cache (read-only on Railway)
$staticDir = __DIR__ . "/cache";
// Runtime data (force_play) goes to /tmp on Railway
$runtimeDir = get_runtime_dir();

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

// Build clip object from force_play data (from database, always authoritative)
// This ensures duration is always correct
$data["clip"] = [
  "id" => $data["clip_id"],
  "title" => $data["title"] ?? "",
  "duration" => $data["duration"] ?? 30,
  "seq" => $data["seq"] ?? 0,
  "creator_name" => $data["creator_name"] ?? "",
];

// Optionally enrich with additional data from JSON index (if available)
if (file_exists($indexFile)) {
  $indexRaw = @file_get_contents($indexFile);
  $indexData = $indexRaw ? json_decode($indexRaw, true) : null;
  if (is_array($indexData) && isset($indexData["clips"]) && is_array($indexData["clips"])) {
    foreach ($indexData["clips"] as $c) {
      if (isset($c["id"]) && $c["id"] === $clipId) {
        // Merge extra fields from index, but keep duration/title from force_play (database)
        if (isset($c["view_count"])) $data["clip"]["view_count"] = $c["view_count"];
        if (isset($c["game_id"])) $data["clip"]["game_id"] = $c["game_id"];
        if (isset($c["thumbnail_url"])) $data["clip"]["thumbnail_url"] = $c["thumbnail_url"];
        break;
      }
    }
  }
}

echo json_encode($data, JSON_UNESCAPED_SLASHES);
