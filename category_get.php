<?php
/**
 * category_get.php - Get the current category filter
 *
 * Returns the active category filter and list of matching clip IDs.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

// Runtime data is in /tmp on Railway
$runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");
$filterPath = $runtimeDir . "/category_filter_" . $login . ".json";

if (!file_exists($filterPath)) {
  echo json_encode(["active" => false]);
  exit;
}

$raw = @file_get_contents($filterPath);
if (!$raw) {
  echo json_encode(["active" => false]);
  exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || !isset($data["game_id"])) {
  echo json_encode(["active" => false]);
  exit;
}

// Get the list of clip IDs matching this category
$clipIds = [];
$pdo = get_db_connection();

if ($pdo) {
  try {
    $stmt = $pdo->prepare("
      SELECT clip_id FROM clips
      WHERE login = ? AND game_id = ? AND blocked = false
      ORDER BY seq
    ");
    $stmt->execute([$login, $data["game_id"]]);
    while ($row = $stmt->fetch()) {
      $clipIds[] = $row['clip_id'];
    }
  } catch (PDOException $e) {
    error_log("category_get db error: " . $e->getMessage());
  }
}

echo json_encode([
  "active" => true,
  "game_id" => $data["game_id"],
  "game_name" => $data["game_name"] ?? "",
  "nonce" => $data["nonce"] ?? "",
  "set_at" => $data["set_at"] ?? "",
  "clip_ids" => $clipIds,
  "clip_count" => count($clipIds)
], JSON_UNESCAPED_SLASHES);
