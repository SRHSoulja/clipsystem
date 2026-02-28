<?php
/**
 * cliptv_command.php - Handle ClipTV commands (like skip) from any viewer
 *
 * When majority vote to skip, any viewer can trigger this.
 * The command is stored and the controller picks it up on next poll.
 *
 * POST:
 *   - login: channel login
 *   - command_type: type of command (skip)
 *   - nonce: unique identifier to prevent duplicates
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
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
  $pdo->exec("CREATE TABLE IF NOT EXISTS cliptv_commands (
    id SERIAL PRIMARY KEY,
    login VARCHAR(50) NOT NULL,
    command_type VARCHAR(32) NOT NULL,
    nonce VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    consumed BOOLEAN DEFAULT FALSE,
    UNIQUE(login, command_type)
  )");
} catch (PDOException $e) {
  // Table exists
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Store a command
  $commandType = $_POST['command_type'] ?? '';
  $nonce = $_POST['nonce'] ?? '';

  if (!$commandType) {
    http_response_code(400);
    echo json_encode(["error" => "command_type required"]);
    exit;
  }

  // Only allow 'skip' for now
  if ($commandType !== 'skip') {
    http_response_code(400);
    echo json_encode(["error" => "invalid command_type"]);
    exit;
  }

  try {
    // Upsert - only if not consumed or newer
    $stmt = $pdo->prepare("
      INSERT INTO cliptv_commands (login, command_type, nonce, created_at, consumed)
      VALUES (?, ?, ?, NOW(), FALSE)
      ON CONFLICT (login, command_type) DO UPDATE SET
        nonce = EXCLUDED.nonce,
        created_at = NOW(),
        consumed = FALSE
    ");
    $stmt->execute([$login, $commandType, $nonce]);

    echo json_encode([
      "ok" => true,
      "login" => $login,
      "command_type" => $commandType
    ]);
  } catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database error"]);
  }
  exit;
}

// GET - check for pending commands (controller polls this)
try {
  $stmt = $pdo->prepare("
    SELECT command_type, nonce, created_at
    FROM cliptv_commands
    WHERE login = ? AND consumed = FALSE
    ORDER BY created_at DESC
    LIMIT 1
  ");
  $stmt->execute([$login]);
  $row = $stmt->fetch();

  if ($row) {
    // Mark as consumed
    $stmt = $pdo->prepare("
      UPDATE cliptv_commands
      SET consumed = TRUE
      WHERE login = ? AND command_type = ?
    ");
    $stmt->execute([$login, $row['command_type']]);

    echo json_encode([
      "has_command" => true,
      "command_type" => $row['command_type'],
      "nonce" => $row['nonce']
    ]);
  } else {
    echo json_encode([
      "has_command" => false
    ]);
  }
} catch (PDOException $e) {
  echo json_encode([
    "has_command" => false,
    "error" => "Database error"
  ]);
}
