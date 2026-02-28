<?php
/**
 * cshuffle.php - Request a fresh shuffle of the clip pool
 *
 * Sets a flag for the player to fetch a new random 300-clip pool.
 * Uses file-based storage since shuffle requests need to work even
 * if the player hasn't consumed the previous one.
 * Commands auto-route to the streamer's instance for isolation.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/dashboard_auth.php';
require_once __DIR__ . '/includes/twitch_oauth.php';
require_once __DIR__ . '/db_config.php';

set_cors_headers();
handle_options_request();
header("Content-Type: text/plain; charset=utf-8");

$login = clean_login($_GET["login"] ?? "");

// Auth: OAuth (own channel, super admin, or mod) + ADMIN_KEY fallback
$isAuthorized = false;
$key = $_GET['key'] ?? $_POST['key'] ?? '';
$adminKey = getenv('ADMIN_KEY') ?: '';
if ($adminKey !== '' && hash_equals($adminKey, (string)$key)) {
  $isAuthorized = true;
}
if (!$isAuthorized) {
  $currentUser = getCurrentUser();
  if ($currentUser) {
    $oauthUsername = strtolower($currentUser['login']);
    if ($oauthUsername === $login) {
      $isAuthorized = true;
    } elseif (isSuperAdmin()) {
      $isAuthorized = true;
    } else {
      $pdoCheck = get_db_connection();
      if ($pdoCheck) {
        try {
          $stmt = $pdoCheck->prepare("SELECT 1 FROM channel_mods WHERE channel_login = ? AND mod_username = ?");
          $stmt->execute([$login, $oauthUsername]);
          if ($stmt->fetch()) { $isAuthorized = true; }
        } catch (PDOException $e) {}
      }
    }
  }
}
if (!$isAuthorized) { http_response_code(403); echo "forbidden"; exit; }

// Get streamer's instance for command isolation
$auth = new DashboardAuth();
$instance = $auth->getStreamerInstance($login) ?: "";

// Runtime data directory
$runtimeDir = get_runtime_dir();

// Generate nonce to trigger shuffle
$nonce = bin2hex(random_bytes(8));

$payload = json_encode([
  "login" => $login,
  "nonce" => $nonce,
  "set_at" => gmdate("c"),
], JSON_UNESCAPED_SLASHES);

// Write to BOTH generic and instance-specific paths
// Always write generic file (for basic sources)
$genericPath = $runtimeDir . "/shuffle_request_" . $login . ".json";
$result = file_put_contents($genericPath, $payload, LOCK_EX);

if ($result === false) {
  error_log("cshuffle: Failed to write shuffle request to $genericPath");
  echo "Error: Could not save shuffle request";
  exit;
}

// Also write instance-specific file if streamer has instance
if ($instance) {
  $instancePath = $runtimeDir . "/shuffle_request_" . $login . "_" . $instance . ".json";
  @file_put_contents($instancePath, $payload, LOCK_EX);
}

echo "Shuffling fresh 300 clips from catalog...";
