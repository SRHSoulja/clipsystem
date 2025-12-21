<?php
/**
 * sync_state.php - Global synchronized playback state for TV channel mode
 *
 * GET: Returns current playing clip and playback position for all viewers
 * POST: Updates current clip (called by the "master" scheduler or first viewer)
 *
 * This enables all viewers to see the same clip at the same time.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

function clean_login($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? $_POST["login"] ?? "");
$pdo = get_db_connection();

if (!$pdo) {
  http_response_code(500);
  echo json_encode(["error" => "no database"]);
  exit;
}

// Ensure sync_state table exists
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS sync_state (
    login VARCHAR(50) PRIMARY KEY,
    clip_id VARCHAR(100),
    clip_url TEXT,
    clip_title TEXT,
    clip_curator VARCHAR(100),
    clip_duration FLOAT DEFAULT 30,
    clip_seq INT DEFAULT 0,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    playlist_index INT DEFAULT 0,
    playlist_ids TEXT DEFAULT '[]',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  )");
  // Add clip_seq column if it doesn't exist (for existing tables)
  try {
    $pdo->exec("ALTER TABLE sync_state ADD COLUMN IF NOT EXISTS clip_seq INT DEFAULT 0");
  } catch (PDOException $e) {
    // Column might already exist, ignore
  }
} catch (PDOException $e) {
  // Table exists, continue
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Update current playing clip
  $clipId = $_POST['clip_id'] ?? '';
  $clipUrl = $_POST['clip_url'] ?? '';
  $clipTitle = $_POST['clip_title'] ?? '';
  $clipCurator = $_POST['clip_curator'] ?? '';
  $clipDuration = floatval($_POST['clip_duration'] ?? 30);
  $clipSeq = intval($_POST['clip_seq'] ?? 0);
  $playlistIndex = intval($_POST['playlist_index'] ?? 0);
  $playlistIds = $_POST['playlist_ids'] ?? '[]';

  try {
    $stmt = $pdo->prepare("
      INSERT INTO sync_state (login, clip_id, clip_url, clip_title, clip_curator, clip_duration, clip_seq, started_at, playlist_index, playlist_ids, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, NOW())
      ON CONFLICT (login) DO UPDATE SET
        clip_id = ?,
        clip_url = ?,
        clip_title = ?,
        clip_curator = ?,
        clip_duration = ?,
        clip_seq = ?,
        started_at = NOW(),
        playlist_index = ?,
        playlist_ids = ?,
        updated_at = NOW()
    ");
    $stmt->execute([
      $login, $clipId, $clipUrl, $clipTitle, $clipCurator, $clipDuration, $clipSeq, $playlistIndex, $playlistIds,
      $clipId, $clipUrl, $clipTitle, $clipCurator, $clipDuration, $clipSeq, $playlistIndex, $playlistIds
    ]);

    echo json_encode([
      "ok" => true,
      "login" => $login,
      "clip_id" => $clipId,
      "started_at" => date('c')
    ]);
  } catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "database error: " . $e->getMessage()]);
  }
  exit;
}

// GET - return current sync state
try {
  $stmt = $pdo->prepare("SELECT * FROM sync_state WHERE login = ?");
  $stmt->execute([$login]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    // Calculate current position in clip
    $startedAt = strtotime($row['started_at']);
    $now = time();
    $elapsed = $now - $startedAt;
    $duration = floatval($row['clip_duration']);

    // Check if clip has ended
    $clipEnded = $elapsed >= $duration;

    echo json_encode([
      "login" => $login,
      "clip_id" => $row['clip_id'],
      "clip_url" => $row['clip_url'],
      "clip_title" => $row['clip_title'],
      "clip_curator" => $row['clip_curator'],
      "clip_duration" => $duration,
      "clip_seq" => intval($row['clip_seq'] ?? 0),
      "started_at" => $row['started_at'],
      "current_position" => min($elapsed, $duration),
      "clip_ended" => $clipEnded,
      "playlist_index" => intval($row['playlist_index']),
      "playlist_ids" => $row['playlist_ids'],
      "has_state" => true
    ]);
  } else {
    // No sync state yet - first viewer will become the controller
    echo json_encode([
      "login" => $login,
      "has_state" => false,
      "message" => "No sync state - initialize playback"
    ]);
  }
} catch (PDOException $e) {
  echo json_encode([
    "login" => $login,
    "has_state" => false,
    "error" => $e->getMessage()
  ]);
}
