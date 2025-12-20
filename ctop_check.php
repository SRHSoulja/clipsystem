<?php
/**
 * ctop_check.php - Check for pending top clips display request
 *
 * Returns the top clips data if a request is pending.
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

$login = clean_login($_GET["login"] ?? "");

$pdo = get_db_connection();
if (!$pdo) {
  echo json_encode(["active" => false]);
  exit;
}

try {
  // Check for recent request (within 30 seconds)
  $stmt = $pdo->prepare("
    SELECT count, nonce, requested_at
    FROM ctop_requests
    WHERE login = ? AND requested_at > NOW() - INTERVAL '30 seconds'
  ");
  $stmt->execute([$login]);
  $request = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$request) {
    echo json_encode(["active" => false]);
    exit;
  }

  $count = (int)$request['count'];

  // Get top clips
  $stmt = $pdo->prepare("
    SELECT v.seq, v.title, v.up_votes, v.down_votes,
           (v.up_votes - v.down_votes) as net_score
    FROM votes v
    WHERE v.login = ? AND (v.up_votes - v.down_votes) > 0
    ORDER BY net_score DESC, v.up_votes DESC
    LIMIT ?
  ");
  $stmt->execute([$login, $count]);
  $topClips = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Clear the request so it doesn't repeat
  $stmt = $pdo->prepare("DELETE FROM ctop_requests WHERE login = ?");
  $stmt->execute([$login]);

  echo json_encode([
    "active" => true,
    "nonce" => $request['nonce'],
    "count" => $count,
    "clips" => $topClips
  ], JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
  error_log("ctop_check error: " . $e->getMessage());
  echo json_encode(["active" => false]);
}
