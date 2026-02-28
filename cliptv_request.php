<?php
/**
 * cliptv_request.php - Clip request system for ClipTV
 *
 * Viewers can request clips. All viewers see the request notification
 * with a countdown. After timeout, the clip plays for everyone.
 *
 * POST: Submit a clip request or clear it
 *   - login: channel login
 *   - action: 'request' (default) or 'clear'
 *   - clip_id, clip_seq, clip_title, clip_game, clip_creator, clip_duration, requester_id
 *
 * GET: Check for active requests
 *   - login: channel login
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

// Create table if needed
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS cliptv_requests (
    login VARCHAR(50) PRIMARY KEY,
    clip_id VARCHAR(100) NOT NULL,
    clip_seq INT DEFAULT 0,
    clip_title TEXT,
    clip_game TEXT,
    clip_creator VARCHAR(100),
    clip_duration FLOAT DEFAULT 30,
    requester_id VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    played BOOLEAN DEFAULT FALSE
  )");
} catch (PDOException $e) {
  // Table exists
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'request';

  if ($action === 'clear') {
    try {
      $stmt = $pdo->prepare("UPDATE cliptv_requests SET played = TRUE WHERE login = ?");
      $stmt->execute([$login]);
      echo json_encode(["ok" => true]);
    } catch (PDOException $e) {
      echo json_encode(["error" => "Database error"]);
    }
    exit;
  }

  // Submit new request
  $clipId = $_POST['clip_id'] ?? '';
  $clipSeq = intval($_POST['clip_seq'] ?? 0);
  $clipTitle = $_POST['clip_title'] ?? '';
  $clipGame = $_POST['clip_game'] ?? '';
  $clipCreator = $_POST['clip_creator'] ?? '';
  $clipDuration = floatval($_POST['clip_duration'] ?? 30);
  $requesterId = $_POST['requester_id'] ?? '';

  if (!$clipId) {
    http_response_code(400);
    echo json_encode(["error" => "clip_id required"]);
    exit;
  }

  try {
    $stmt = $pdo->prepare("
      INSERT INTO cliptv_requests (login, clip_id, clip_seq, clip_title, clip_game, clip_creator, clip_duration, requester_id, created_at, played)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), FALSE)
      ON CONFLICT (login) DO UPDATE SET
        clip_id = ?, clip_seq = ?, clip_title = ?, clip_game = ?, clip_creator = ?,
        clip_duration = ?, requester_id = ?, created_at = NOW(), played = FALSE
    ");
    $stmt->execute([
      $login, $clipId, $clipSeq, $clipTitle, $clipGame, $clipCreator, $clipDuration, $requesterId,
      $clipId, $clipSeq, $clipTitle, $clipGame, $clipCreator, $clipDuration, $requesterId
    ]);
    echo json_encode(["ok" => true, "login" => $login, "clip_id" => $clipId]);
  } catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database error"]);
  }
  exit;
}

// GET - check for active request (within last 15 seconds)
try {
  $stmt = $pdo->prepare("
    SELECT clip_id, clip_seq, clip_title, clip_game, clip_creator, clip_duration, requester_id,
           EXTRACT(EPOCH FROM (NOW() - created_at)) as age_seconds
    FROM cliptv_requests
    WHERE login = ? AND played = FALSE
      AND created_at > NOW() - INTERVAL '15 seconds'
  ");
  $stmt->execute([$login]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    echo json_encode([
      "has_request" => true,
      "clip_id" => $row['clip_id'],
      "clip_seq" => intval($row['clip_seq']),
      "clip_title" => $row['clip_title'],
      "clip_game" => $row['clip_game'],
      "clip_creator" => $row['clip_creator'],
      "clip_duration" => floatval($row['clip_duration']),
      "requester_id" => $row['requester_id'],
      "age_seconds" => floatval($row['age_seconds'])
    ]);
  } else {
    echo json_encode(["has_request" => false]);
  }
} catch (PDOException $e) {
  echo json_encode(["has_request" => false]);
}
