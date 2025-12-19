<?php
/**
 * clip_info.php - Get clip info by seq number (public, no auth required)
 *
 * Usage: clip_info.php?login=floppyjimmie&seq=123
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

function clean_login($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");
$seq = (int)($_GET["seq"] ?? 0);

if ($seq <= 0) {
  http_response_code(400);
  echo json_encode(["error" => "Invalid seq number"]);
  exit;
}

$pdo = get_db_connection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(["error" => "Database unavailable"]);
  exit;
}

$stmt = $pdo->prepare("
  SELECT seq, clip_id, title, duration, view_count, game_id, created_at, thumbnail_url, creator_name
  FROM clips
  WHERE login = ? AND seq = ? AND blocked = FALSE
");
$stmt->execute([$login, $seq]);
$clip = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$clip) {
  http_response_code(404);
  echo json_encode(["error" => "Clip not found"]);
  exit;
}

// Build URL
$clipUrl = "https://clips.twitch.tv/" . $clip['clip_id'];

echo json_encode([
  "seq" => (int)$clip['seq'],
  "clip_id" => $clip['clip_id'],
  "title" => $clip['title'],
  "url" => $clipUrl,
  "duration" => (float)$clip['duration'],
  "view_count" => (int)$clip['view_count'],
  "creator_name" => $clip['creator_name'],
  "created_at" => $clip['created_at']
], JSON_UNESCAPED_SLASHES);
