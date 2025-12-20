<?php
/**
 * ctop.php - Request top clips popup display
 *
 * Sets a request for the player to show top voted clips overlay.
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
$count = (int)($_GET["count"] ?? 5);

// Clamp count between 3 and 10
$count = max(3, min(10, $count));

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

// Create ctop_requests table if needed
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS ctop_requests (
      login VARCHAR(64) PRIMARY KEY,
      count INTEGER DEFAULT 5,
      nonce VARCHAR(32),
      requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
  ");
} catch (PDOException $e) {
  error_log("ctop table creation error: " . $e->getMessage());
}

// Get top clips data
$topClips = [];
try {
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
} catch (PDOException $e) {
  error_log("ctop query error: " . $e->getMessage());
}

if (empty($topClips)) {
  echo "No voted clips found.";
  exit;
}

// Generate nonce to prevent duplicate triggers
$nonce = bin2hex(random_bytes(8));

// Insert/update request
try {
  $stmt = $pdo->prepare("
    INSERT INTO ctop_requests (login, count, nonce, requested_at)
    VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ON CONFLICT (login) DO UPDATE SET
      count = EXCLUDED.count,
      nonce = EXCLUDED.nonce,
      requested_at = CURRENT_TIMESTAMP
  ");
  $stmt->execute([$login, $count, $nonce]);

  echo "Showing top {$count} clips...";
} catch (PDOException $e) {
  echo "Error: " . $e->getMessage();
}
