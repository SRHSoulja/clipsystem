<?php
/**
 * prev_check.php - Check if a "go to previous clip" was requested
 *
 * Called by the player to check for pending prev requests.
 * Uses atomic DELETE+RETURNING to prevent duplicate triggers.
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
  echo json_encode(["prev" => false]);
  exit;
}

try {
  // Atomic: DELETE and return in one query to avoid race conditions
  $stmt = $pdo->prepare("
    DELETE FROM prev_requests
    WHERE login = ? AND requested_at > NOW() - INTERVAL '5 seconds'
    RETURNING requested_at
  ");
  $stmt->execute([$login]);
  $row = $stmt->fetch();

  echo json_encode(["prev" => $row ? true : false]);
} catch (PDOException $e) {
  error_log("prev_check error: " . $e->getMessage());
  echo json_encode(["prev" => false]);
}
