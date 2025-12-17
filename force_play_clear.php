<?php
/**
 * force_play_clear.php - Clear the force play file after it's been processed
 */
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$baseDir = __DIR__ . "/cache";

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
