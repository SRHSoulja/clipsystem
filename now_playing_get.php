<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function safe_login($s) {
  $s = strtolower(trim($s));
  return preg_replace('/[^a-z0-9_]/', '_', $s);
}

$login = isset($_GET['login']) ? safe_login($_GET['login']) : '';
if ($login === '') { http_response_code(400); echo "missing login"; exit; }

// Use /tmp for runtime data on Railway, fall back to ./cache locally
$cacheDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";
$path = $cacheDir . "/now_playing_{$login}.json";
$raw = @file_get_contents($path);
if ($raw === false) { echo "No clip yet"; exit; }

$j = json_decode($raw, true);
$url = isset($j['url']) ? trim($j['url']) : '';
$seq = isset($j['seq']) ? (int)$j['seq'] : 0;

if ($url === '') { echo "No clip yet"; exit; }

// Output format: "Clip #1234: https://clips.twitch.tv/..."
if ($seq > 0) {
  echo "Clip #{$seq}: {$url}";
} else {
  echo $url;
}
