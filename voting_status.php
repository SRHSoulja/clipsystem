<?php
/**
 * voting_status.php - Get/Set voting enabled status for a channel
 *
 * GET without set: Returns current voting_enabled status
 * GET with set: Sets voting_enabled (requires key)
 *
 * Used by the bot to check if !like/!dislike should work,
 * and to persist !clikeon/!clikeoff state across restarts.
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
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

$pdo = get_db_connection();

// Check if setting value
if (isset($_GET['set']) || isset($_POST['set'])) {
  // Set voting status - requires admin key
  $key = $_GET["key"] ?? $_POST["key"] ?? "";
  if ($ADMIN_KEY === '' || !hash_equals($ADMIN_KEY, (string)$key)) {
    http_response_code(403);
    echo json_encode(["error" => "forbidden"]);
    exit;
  }

  $enabled = (int)($_GET['set'] ?? $_POST['set'] ?? 1) ? true : false;

  if ($pdo) {
    try {
      // Ensure channel_settings table exists with voting_enabled column
      $pdo->exec("CREATE TABLE IF NOT EXISTS channel_settings (
        login VARCHAR(50) PRIMARY KEY,
        hud_position VARCHAR(10) DEFAULT 'tr',
        top_position VARCHAR(10) DEFAULT 'br',
        voting_enabled BOOLEAN DEFAULT TRUE,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )");

      // Add voting_enabled column if it doesn't exist (for existing tables)
      try {
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS voting_enabled BOOLEAN DEFAULT TRUE");
      } catch (PDOException $e) {
        // Column might already exist, ignore
      }

      $stmt = $pdo->prepare("
        INSERT INTO channel_settings (login, voting_enabled, updated_at)
        VALUES (?, ?, NOW())
        ON CONFLICT (login) DO UPDATE SET voting_enabled = ?, updated_at = NOW()
      ");
      $stmt->execute([$login, $enabled ? 'true' : 'false', $enabled ? 'true' : 'false']);

      echo json_encode([
        "ok" => true,
        "login" => $login,
        "voting_enabled" => $enabled
      ]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(["error" => "Database error"]);
    }
  } else {
    http_response_code(500);
    echo json_encode(["error" => "no database"]);
  }
  exit;
}

// GET - return current voting status and vote_feedback
if ($pdo) {
  try {
    $stmt = $pdo->prepare("SELECT voting_enabled, vote_feedback FROM channel_settings WHERE login = ?");
    $stmt->execute([$login]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Default to true if no setting exists
    $enabled = true;
    $feedback = true;
    if ($row) {
      if (isset($row['voting_enabled'])) {
        $enabled = (bool)$row['voting_enabled'];
      }
      if (isset($row['vote_feedback'])) {
        $feedback = (bool)$row['vote_feedback'];
      }
    }

    echo json_encode([
      "login" => $login,
      "voting_enabled" => $enabled,
      "vote_feedback" => $feedback
    ]);
  } catch (PDOException $e) {
    // Table might not exist yet, return defaults (enabled)
    echo json_encode([
      "login" => $login,
      "voting_enabled" => true,
      "vote_feedback" => true
    ]);
  }
} else {
  // No database, default to enabled
  echo json_encode([
    "login" => $login,
    "voting_enabled" => true,
    "vote_feedback" => true
  ]);
}
