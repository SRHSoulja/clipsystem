<?php
/**
 * votes_api.php - Get vote counts for a clip
 *
 * GET params:
 *   login - Streamer login
 *   clip_id - Clip ID to get votes for
 *
 * Returns: { up_votes, down_votes }
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

$login = clean_login($_GET["login"] ?? "");
$clipId = trim($_GET["clip_id"] ?? "");

if (!$clipId) {
  echo json_encode(["up_votes" => 0, "down_votes" => 0]);
  exit;
}

$pdo = get_db_connection();

if ($pdo) {
  try {
    $stmt = $pdo->prepare("
      SELECT up_votes, down_votes
      FROM votes
      WHERE login = ? AND clip_id = ?
    ");
    $stmt->execute([$login, $clipId]);
    $votes = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($votes) {
      echo json_encode([
        "up_votes" => (int)$votes["up_votes"],
        "down_votes" => (int)$votes["down_votes"]
      ]);
    } else {
      echo json_encode(["up_votes" => 0, "down_votes" => 0]);
    }
  } catch (PDOException $e) {
    echo json_encode(["up_votes" => 0, "down_votes" => 0, "error" => $e->getMessage()]);
  }
} else {
  echo json_encode(["up_votes" => 0, "down_votes" => 0]);
}
