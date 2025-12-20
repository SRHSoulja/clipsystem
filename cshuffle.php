<?php
/**
 * cshuffle.php - Request a fresh shuffle of the clip pool
 *
 * Sets a flag for the player to fetch a new random 300-clip pool.
 * Uses file-based storage since shuffle requests need to work even
 * if the player hasn't consumed the previous one.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/db_config.php';

set_cors_headers();
handle_options_request();
header("Content-Type: text/plain; charset=utf-8");

$login = clean_login($_GET["login"] ?? "");
require_admin_auth();

// Runtime data directory
$runtimeDir = get_runtime_dir();

// Generate nonce to trigger shuffle
$nonce = bin2hex(random_bytes(8));

$payload = [
  "login" => $login,
  "nonce" => $nonce,
  "set_at" => gmdate("c"),
];

$shufflePath = $runtimeDir . "/shuffle_request_" . $login . ".json";
$result = file_put_contents($shufflePath, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);

if ($result === false) {
  error_log("cshuffle: Failed to write shuffle request to $shufflePath");
  echo "Error: Could not save shuffle request";
  exit;
}

echo "Shuffling fresh 300 clips from catalog...";
