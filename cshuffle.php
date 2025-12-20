<?php
/**
 * cshuffle.php - Request a fresh shuffle of the clip pool
 *
 * Sets a flag for the player to fetch a new random 300-clip pool.
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

// Runtime data is in /tmp on Railway
$runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";
if (!is_dir($runtimeDir)) @mkdir($runtimeDir, 0777, true);

// Generate nonce to trigger shuffle
$nonce = bin2hex(random_bytes(8));

$payload = [
  "login" => $login,
  "nonce" => $nonce,
  "set_at" => gmdate("c"),
];

$shufflePath = $runtimeDir . "/shuffle_request_" . $login . ".json";
@file_put_contents($shufflePath, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);

echo "Shuffling fresh 300 clips from catalog...";
