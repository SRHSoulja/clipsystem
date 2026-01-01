<?php
/**
 * live_clips.php - AJAX endpoint for fetching live Twitch clips with pagination
 *
 * Used by clip_search.php to load more clips in live mode.
 * Supports cursor-based pagination from Twitch API.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../includes/twitch_api.php';

function json_response($data) {
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}

function json_error($msg, $code = 400) {
  http_response_code($code);
  json_response(["error" => $msg]);
}

function clean_login($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "";
}

$login = clean_login($_GET["login"] ?? "");
$cursor = $_GET["cursor"] ?? null;
$batchSize = max(50, min(100, (int)($_GET["batch"] ?? 100)));
$dateRange = $_GET["range"] ?? "year";

// Validate date range
$validRanges = ['week', 'month', '3months', '6months', 'year', '2years', '3years', 'all'];
if (!in_array($dateRange, $validRanges)) {
  $dateRange = 'year';
}

if (!$login) {
  json_error("Missing login parameter");
}

$twitchApi = new TwitchAPI();

if (!$twitchApi->isConfigured()) {
  json_error("Twitch API not configured", 500);
}

// Fetch clips batch with cursor
$result = $twitchApi->getClipsBatch($login, $cursor, $batchSize, $dateRange);

if (isset($result['error'])) {
  json_error($result['error']);
}

// Get game names for the clips
$pdo = get_db_connection();
$gameNames = [];
$gameIds = array_unique(array_filter(array_column($result['clips'], 'game_id')));

if (!empty($gameIds) && $pdo) {
  try {
    $placeholders = implode(',', array_fill(0, count($gameIds), '?'));
    $stmt = $pdo->prepare("SELECT game_id, name FROM games_cache WHERE game_id IN ($placeholders)");
    $stmt->execute(array_values($gameIds));
    $gameNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
  } catch (PDOException $e) {
    // Ignore - proceed without game names
  }

  // Fetch missing game names from Twitch API
  $missingIds = array_diff($gameIds, array_keys($gameNames));
  if (!empty($missingIds)) {
    $fetched = $twitchApi->getGamesByIds(array_values($missingIds));
    foreach ($fetched as $gid => $info) {
      $gameNames[$gid] = $info['name'];
      // Cache for future
      try {
        $stmt = $pdo->prepare("INSERT INTO games_cache (game_id, name) VALUES (?, ?) ON CONFLICT (game_id) DO UPDATE SET name = EXCLUDED.name");
        $stmt->execute([$gid, $info['name']]);
      } catch (PDOException $e) {
        // Ignore cache errors
      }
    }
  }
}

// Add game names to clips
foreach ($result['clips'] as &$clip) {
  $clip['game_name'] = $gameNames[$clip['game_id']] ?? '';
}
unset($clip);

json_response([
  "success" => true,
  "clips" => $result['clips'],
  "cursor" => $result['cursor'],
  "has_more" => $result['has_more'],
  "count" => count($result['clips'])
]);
