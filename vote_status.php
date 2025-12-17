<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$baseDir = __DIR__ . "/cache";

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");

$curFile = $baseDir . "/now_playing_" . $login . ".json";
$votesFile = $baseDir . "/votes_" . $login . ".json";

$cur = null;
if (file_exists($curFile)) {
  $cur = json_decode(@file_get_contents($curFile), true);
}
if (!is_array($cur) || !isset($cur["seq"], $cur["clip_id"])) {
  echo json_encode(["seq" => 0, "clip_id" => "", "up" => 0, "down" => 0]);
  exit;
}

$clipId = (string)$cur["clip_id"];

$votes = [];
if (file_exists($votesFile)) {
  $votes = json_decode(@file_get_contents($votesFile), true);
  if (!is_array($votes)) $votes = [];
}

$up = 0; $down = 0;
if (isset($votes[$clipId]) && is_array($votes[$clipId])) {
  $up = (int)($votes[$clipId]["up"] ?? 0);
  $down = (int)($votes[$clipId]["down"] ?? 0);
}

echo json_encode([
  "seq" => (int)$cur["seq"],
  "clip_id" => $clipId,
  "up" => $up,
  "down" => $down
]);
