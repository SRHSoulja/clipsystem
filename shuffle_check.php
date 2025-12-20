<?php
/**
 * shuffle_check.php - Check for pending shuffle request
 *
 * Returns shuffle request if pending, then clears it.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function clean_login($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");

// Runtime data is in /tmp on Railway
$runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";

$shufflePath = $runtimeDir . "/shuffle_request_" . $login . ".json";

if (!file_exists($shufflePath)) {
  echo json_encode(["shuffle" => false]);
  exit;
}

$raw = @file_get_contents($shufflePath);
if (!$raw) {
  echo json_encode(["shuffle" => false]);
  exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || !isset($data["nonce"])) {
  echo json_encode(["shuffle" => false]);
  exit;
}

// Check if request is recent (within 30 seconds)
$setAt = isset($data["set_at"]) ? strtotime($data["set_at"]) : 0;
if ($setAt && (time() - $setAt) > 30) {
  // Stale request, delete and ignore
  @unlink($shufflePath);
  echo json_encode(["shuffle" => false]);
  exit;
}

// Delete the file atomically (one-shot)
@unlink($shufflePath);

echo json_encode([
  "shuffle" => true,
  "nonce" => $data["nonce"]
]);
