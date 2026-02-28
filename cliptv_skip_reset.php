<?php
/**
 * cliptv_skip_reset.php - Reset all skip votes for a channel
 *
 * Called when a new clip starts playing to clear previous skip votes.
 *
 * POST:
 *   - login: channel login
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

try {
  $stmt = $pdo->prepare("UPDATE cliptv_viewers SET wants_skip = FALSE WHERE login = ?");
  $stmt->execute([$login]);

  echo json_encode([
    "ok" => true,
    "login" => $login,
    "message" => "Skip votes reset"
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(["error" => "Database error"]);
}
