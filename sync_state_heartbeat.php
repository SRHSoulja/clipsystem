<?php
/**
 * sync_state_heartbeat.php - Controller heartbeat for sync state
 *
 * POST: Update the updated_at timestamp to indicate controller is still active
 *   - login: channel login
 *
 * This allows non-controller viewers to detect when the controller has left
 * and take over as the new controller.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
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

$login = clean_login($_POST["login"] ?? "");

$pdo = get_db_connection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(["error" => "no database"]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["error" => "POST only"]);
  exit;
}

try {
  $stmt = $pdo->prepare("
    UPDATE sync_state
    SET updated_at = NOW()
    WHERE login = ?
  ");
  $stmt->execute([$login]);

  echo json_encode([
    "ok" => true,
    "login" => $login
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(["error" => "Database error"]);
}
