<?php
/**
 * cskip.php - Skip the current clip (mod command)
 *
 * Sets a "skip requested" flag that the player will read and act on.
 * Works for both playlist mode and normal shuffle mode.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: text/plain; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

function clean_login($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");
$key   = (string)($_GET["key"] ?? "");

$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

if ($key !== $ADMIN_KEY) {
  echo "forbidden";
  exit;
}

$pdo = get_db_connection();
if (!$pdo) {
  echo "Database unavailable";
  exit;
}

// Create skip_requests table if needed
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS skip_requests (
      login VARCHAR(64) PRIMARY KEY,
      requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
  ");
} catch (PDOException $e) {
  error_log("cskip table creation error: " . $e->getMessage());
}

// Insert/update skip request for this login
try {
  $stmt = $pdo->prepare("
    INSERT INTO skip_requests (login, requested_at)
    VALUES (?, CURRENT_TIMESTAMP)
    ON CONFLICT (login) DO UPDATE SET requested_at = CURRENT_TIMESTAMP
  ");
  $stmt->execute([$login]);

  echo "Skipping current clip...";
} catch (PDOException $e) {
  echo "Error: " . $e->getMessage();
}
