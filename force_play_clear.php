<?php
/**
 * force_play_clear.php - Clear the force play file after it's been processed
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// Use /tmp for runtime data on Railway, fall back to ./cache locally
$baseDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");
$path = $baseDir . "/force_play_" . $login . ".json";

if (file_exists($path)) {
  @unlink($path);
}

echo "ok";
