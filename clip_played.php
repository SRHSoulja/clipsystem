<?php
/**
 * clip_played.php - Record when a clip is played
 *
 * Uses PostgreSQL for persistent storage when DATABASE_URL is set.
 * Upserts play count and last_played_at timestamp.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/analytics.php';

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

function json_response($success, $message, $data = null) {
  $response = [
    'success' => $success,
    'message' => $message
  ];
  if ($data !== null) {
    $response['data'] = $data;
  }
  echo json_encode($response, JSON_UNESCAPED_SLASHES);
  exit;
}

// Accept both GET and POST
$login = clean_login($_REQUEST["login"] ?? "");
$clipId = trim((string)($_REQUEST["clip_id"] ?? ""));
$skipped = ($_REQUEST["skipped"] ?? "") === "1";
$skipType = trim((string)($_REQUEST["skip_type"] ?? "vote"));

if ($clipId === "") {
  json_response(false, "Missing clip_id parameter");
}

// Validate skip_type
if ($skipped && !in_array($skipType, ['vote', 'mod'], true)) {
  $skipType = 'vote';
}

// Try to get database connection
$pdo = get_db_connection();

if (!$pdo) {
  json_response(false, "Database connection not available");
}

try {
  // Ensure tables exist
  init_votes_tables($pdo);

  // Upsert into clip_plays table
  // If exists: increment play_count and update last_played_at
  // If not exists: insert with play_count=1
  $stmt = $pdo->prepare("
    INSERT INTO clip_plays (login, clip_id, play_count, last_played_at)
    VALUES (?, ?, 1, CURRENT_TIMESTAMP)
    ON CONFLICT (login, clip_id) DO UPDATE SET
      play_count = clip_plays.play_count + 1,
      last_played_at = CURRENT_TIMESTAMP
  ");
  $stmt->execute([$login, $clipId]);

  // Track analytics event
  track_event('clip_play', ['channel' => $login, 'clip_id' => $clipId, 'skipped' => $skipped ? 'yes' : 'no']);

  // Record skip event if this was a manual skip (vote-to-skip or mod skip)
  if ($skipped) {
    $stmt = $pdo->prepare("
      INSERT INTO skip_events (login, clip_id, skip_type)
      VALUES (?, ?, ?)
    ");
    $stmt->execute([$login, $clipId, $skipType]);
  }

  // Get the updated play count
  $stmt = $pdo->prepare("SELECT play_count, last_played_at FROM clip_plays WHERE login = ? AND clip_id = ?");
  $stmt->execute([$login, $clipId]);
  $row = $stmt->fetch();

  if ($row) {
    json_response(true, $skipped ? "Skip recorded" : "Play recorded", [
      'play_count' => (int)$row['play_count'],
      'last_played_at' => $row['last_played_at']
    ]);
  } else {
    json_response(false, "Failed to retrieve play count after insert");
  }

} catch (PDOException $e) {
  error_log("clip_played error: " . $e->getMessage());
  json_response(false, "Database error");
}
