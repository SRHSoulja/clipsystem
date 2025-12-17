<?php
/**
 * vote_status.php - Get vote counts for the currently playing clip
 *
 * Uses PostgreSQL for persistent storage when DATABASE_URL is set.
 * Falls back to file storage (ephemeral on Railway) otherwise.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

// Use /tmp for runtime data on Railway, fall back to ./cache locally
$baseDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");

// Get current playing clip from runtime file
$curFile = $baseDir . "/now_playing_" . $login . ".json";

$cur = null;
if (file_exists($curFile)) {
  $cur = json_decode(@file_get_contents($curFile), true);
}
if (!is_array($cur) || !isset($cur["seq"], $cur["clip_id"])) {
  echo json_encode(["seq" => 0, "clip_id" => "", "up" => 0, "down" => 0]);
  exit;
}

$clipId = (string)$cur["clip_id"];
$seq = (int)$cur["seq"];
$up = 0;
$down = 0;

// Try database first for vote counts
$pdo = get_db_connection();

if ($pdo) {
  try {
    $stmt = $pdo->prepare("SELECT up_votes, down_votes FROM votes WHERE login = ? AND clip_id = ?");
    $stmt->execute([$login, $clipId]);
    $row = $stmt->fetch();
    if ($row) {
      $up = (int)$row['up_votes'];
      $down = (int)$row['down_votes'];
    }
  } catch (PDOException $e) {
    error_log("vote_status db error: " . $e->getMessage());
    // Fall through to file storage
  }
} else {
  // Fallback: File-based storage
  $votesFile = $baseDir . "/votes_" . $login . ".json";
  $votes = [];
  if (file_exists($votesFile)) {
    $votes = json_decode(@file_get_contents($votesFile), true);
    if (!is_array($votes)) $votes = [];
  }

  if (isset($votes[$clipId]) && is_array($votes[$clipId])) {
    $up = (int)($votes[$clipId]["up"] ?? 0);
    $down = (int)($votes[$clipId]["down"] ?? 0);
  }
}

echo json_encode([
  "seq" => $seq,
  "clip_id" => $clipId,
  "up" => $up,
  "down" => $down
]);
