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
if (!is_array($data) || (!isset($data["game_id"]) && !isset($data["game_ids"]))) {
  echo json_encode(["active" => false]);
  exit;
}

// Support both single game_id and multiple game_ids
$gameIds = isset($data["game_ids"]) ? $data["game_ids"] : [$data["game_id"]];

// Get the full clip data matching this category (supports multiple game IDs)
$clipIds = [];
$categoryClips = [];
$pdo = get_db_connection();

if ($pdo && !empty($gameIds)) {
  try {
    // Build placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($gameIds), '?'));
    $params = array_merge([$login], $gameIds);

    $stmt = $pdo->prepare("
      SELECT c.clip_id as id, c.seq, c.title, c.duration, c.created_at, c.view_count,
             c.game_id, c.video_id, c.vod_offset, c.creator_name, c.thumbnail_url,
             g.name as game_name
      FROM clips c
      LEFT JOIN games_cache g ON c.game_id = g.game_id
      WHERE c.login = ? AND c.game_id IN ({$placeholders}) AND c.blocked = false
      ORDER BY c.created_at DESC
    ");
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $clipIds[] = $row['id'];
      $categoryClips[] = $row;
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
  "clips" => $categoryClips,
  "clip_count" => count($clipIds)
], JSON_UNESCAPED_SLASHES);
