<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function safe_login($s) {
  $s = strtolower(trim($s));
  return preg_replace('/[^a-z0-9_]/', '_', $s);
}

$login = isset($_GET['login']) ? safe_login($_GET['login']) : '';
if ($login === '') {
  http_response_code(400);
  echo json_encode(["error" => "missing login"]);
  exit;
}

// Use /tmp for runtime data on Railway, fall back to ./cache locally
$cacheDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";
$path = $cacheDir . "/now_playing_{$login}.json";
$raw = @file_get_contents($path);
if ($raw === false) {
  echo json_encode(["error" => "No clip yet", "seq" => 0]);
  exit;
}

$j = json_decode($raw, true);
$url = isset($j['url']) ? trim($j['url']) : '';
$seq = isset($j['seq']) ? (int)$j['seq'] : 0;
$clipId = isset($j['clip_id']) ? $j['clip_id'] : '';
$title = isset($j['title']) ? $j['title'] : '';

if ($url === '') {
  echo json_encode(["error" => "No clip yet", "seq" => 0]);
  exit;
}

// Return JSON with clip info
echo json_encode([
  "seq" => $seq,
  "clip_id" => $clipId,
  "url" => $url,
  "title" => $title,
  "login" => $login
], JSON_UNESCAPED_SLASHES);
