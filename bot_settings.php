<?php
/**
 * bot_settings.php - Get/Set bot behavior settings for a channel
 *
 * GET without set: Returns current settings (silent_prefix)
 * GET with set: Sets setting value (requires authentication)
 *
 * Settings:
 *   silent_prefix - If true, bot prepends ! to responses to hide from on-screen chat
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
if (isset($_GET['set_silent_prefix']) || isset($_POST['set_silent_prefix'])) {
  // Requires admin key
  $key = $_GET["key"] ?? $_POST["key"] ?? "";
  if ($ADMIN_KEY === '' || !hash_equals($ADMIN_KEY, (string)$key)) {
    http_response_code(403);
    echo json_encode(["error" => "forbidden"]);
    exit;
  }

  $enabled = (int)($_GET['set_silent_prefix'] ?? $_POST['set_silent_prefix'] ?? 0) ? true : false;

  if ($pdo) {
    try {
      // Ensure column exists
      try {
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS silent_prefix BOOLEAN DEFAULT FALSE");
      } catch (PDOException $e) {
        // Column might already exist
      }

      $stmt = $pdo->prepare("
        INSERT INTO channel_settings (login, silent_prefix, updated_at)
        VALUES (?, ?, NOW())
        ON CONFLICT (login) DO UPDATE SET silent_prefix = ?, updated_at = NOW()
      ");
      $stmt->execute([$login, $enabled ? 'true' : 'false', $enabled ? 'true' : 'false']);

      echo json_encode([
        "ok" => true,
        "login" => $login,
        "silent_prefix" => $enabled
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

// GET - return current bot settings
if ($pdo) {
  try {
    $stmt = $pdo->prepare("SELECT silent_prefix FROM channel_settings WHERE login = ?");
    $stmt->execute([$login]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Default to false if no setting exists
    $silentPrefix = false;
    if ($row && isset($row['silent_prefix'])) {
      $silentPrefix = (bool)$row['silent_prefix'];
    }

    echo json_encode([
      "login" => $login,
      "silent_prefix" => $silentPrefix
    ]);
  } catch (PDOException $e) {
    // Table/column might not exist yet, return defaults
    echo json_encode([
      "login" => $login,
      "silent_prefix" => false
    ]);
  }
} else {
  // No database, default to disabled
  echo json_encode([
    "login" => $login,
    "silent_prefix" => false
  ]);
}
