<?php
/**
 * cskip.php - Skip the current clip (mod command)
 *
 * Sets a "skip requested" flag that the player will read and act on.
 * Uses PostgreSQL command_requests table for reliability.
 * Commands auto-route to the streamer's instance for isolation.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/dashboard_auth.php';
require_once __DIR__ . '/db_config.php';

set_cors_headers();
handle_options_request();
header("Content-Type: text/plain; charset=utf-8");

$login = clean_login($_GET["login"] ?? "");
require_admin_auth();

// Get streamer's instance for command isolation
$auth = new DashboardAuth();
$instance = $auth->getStreamerInstance($login) ?: "";

$pdo = get_db_connection();
if (!$pdo) {
  echo "Database unavailable";
  exit;
}

// Generate nonce for this request
$nonce = bin2hex(random_bytes(8));

// Insert/update skip request using command_requests table
try {
  $stmt = $pdo->prepare("
    INSERT INTO command_requests (login, command_type, nonce, created_at, consumed)
    VALUES (?, 'skip', ?, CURRENT_TIMESTAMP, FALSE)
    ON CONFLICT (login, command_type) DO UPDATE SET
      nonce = EXCLUDED.nonce,
      created_at = CURRENT_TIMESTAMP,
      consumed = FALSE
  ");
  $stmt->execute([$login, $nonce]);

  // Write file-based request to streamer's instance
  $runtimeDir = get_runtime_dir();
  $fileSuffix = $instance ? "_{$instance}" : "";
  $skipPath = $runtimeDir . "/skip_request_" . $login . $fileSuffix . ".json";
  @file_put_contents($skipPath, json_encode([
    "nonce" => $nonce,
    "set_at" => date('c')
  ]));

  echo "Skipping current clip...";
} catch (PDOException $e) {
  error_log("cskip error: " . $e->getMessage());
  echo "Error: " . $e->getMessage();
}
