<?php
header('Content-Type: text/plain; charset=utf-8');

function safe_login($s) {
  $s = strtolower(trim($s));
  return preg_replace('/[^a-z0-9_]/', '_', $s);
}

$login = isset($_GET['login']) ? safe_login($_GET['login']) : '';
if ($login === '') { http_response_code(400); echo "missing login"; exit; }

$path = __DIR__ . "/cache/now_playing_{$login}.json";
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
