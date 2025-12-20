<?php
/**
 * ccat.php - Set category filter for clip reel
 *
 * Mods can use !ccat <game_name> to filter clips to a specific game.
 * Use !ccat off to disable the filter.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

// Runtime data goes to /tmp on Railway
$runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";
if (!is_dir($runtimeDir)) @mkdir($runtimeDir, 0777, true);

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");
$category = trim($_GET["category"] ?? "");
$key = (string)($_GET["key"] ?? "");

// Load from environment (set ADMIN_KEY in Railway)
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

if ($key !== $ADMIN_KEY) { http_response_code(403); echo "forbidden"; exit; }
if ($category === "") { echo "Usage: !ccat <game> to filter, !ccat off to exit"; exit; }

// Handle "off" to clear category filter - also handle common variations
$catLower = strtolower($category);
if ($catLower === "off" || $catLower === "clear" || $catLower === "all" || $catLower === "exit" || $catLower === "reset" || $catLower === "none") {
  $filterPath = $runtimeDir . "/category_filter_" . $login . ".json";
  if (file_exists($filterPath)) {
    @unlink($filterPath);
  }
  echo "Category filter cleared - playing all games";
  exit;
}

// Look up the game by name (fuzzy match)
$pdo = get_db_connection();
$matchedGame = null;
$availableGames = [];

if (!$pdo) {
  echo "Database unavailable";
  exit;
}

try {
  // Get all games this channel has clips for that match the search term
  $stmt = $pdo->prepare("
    SELECT DISTINCT c.game_id, COALESCE(g.name, c.game_id) as name, COUNT(*) as clip_count
    FROM clips c
    LEFT JOIN games_cache g ON c.game_id = g.game_id
    WHERE c.login = ? AND c.blocked = false AND c.game_id IS NOT NULL AND c.game_id != ''
      AND LOWER(COALESCE(g.name, c.game_id)) LIKE LOWER(?)
    GROUP BY c.game_id, g.name
    ORDER BY clip_count DESC
  ");
  $stmt->execute([$login, "%" . $category . "%"]);
  $matchedGames = $stmt->fetchAll();

  // If no matches, get list of available games
  if (empty($matchedGames)) {
    $stmt = $pdo->prepare("
      SELECT DISTINCT c.game_id, COALESCE(g.name, c.game_id) as name, COUNT(*) as clip_count
      FROM clips c
      LEFT JOIN games_cache g ON c.game_id = g.game_id
      WHERE c.login = ? AND c.blocked = false AND c.game_id IS NOT NULL AND c.game_id != ''
      GROUP BY c.game_id, g.name
      ORDER BY clip_count DESC
      LIMIT 30
    ");
    $stmt->execute([$login]);
    $availableGames = $stmt->fetchAll();
  }
} catch (PDOException $e) {
  error_log("ccat db error: " . $e->getMessage());
  echo "Database error: " . $e->getMessage();
  exit;
}

if (empty($matchedGames)) {
  if (!empty($availableGames)) {
    $names = array_column($availableGames, 'name');
    echo "Game not found. Available: " . implode(", ", array_slice($names, 0, 10));
    if (count($names) > 10) echo "...";
  } else {
    echo "Game '$category' not found.";
  }
  exit;
}

// Calculate total clips across all matched games
$totalClips = array_sum(array_column($matchedGames, 'clip_count'));
$gameIds = array_column($matchedGames, 'game_id');
$gameNames = array_column($matchedGames, 'name');

if ($totalClips === 0) {
  echo "No clips found matching '$category'";
  exit;
}

// Save the category filter (supports multiple game IDs)
$filterPath = $runtimeDir . "/category_filter_" . $login . ".json";

// Build display name
if (count($matchedGames) === 1) {
  $displayName = $matchedGames[0]['name'];
} else {
  // Show search term + count of games
  $displayName = ucfirst($category) . " (" . count($matchedGames) . " games)";
}

$payload = [
  "login" => $login,
  "game_ids" => $gameIds,  // Array of game IDs
  "game_id" => $gameIds[0],  // Keep single ID for backwards compat
  "game_name" => $displayName,
  "game_names" => $gameNames,  // All matched game names
  "clip_count" => $totalClips,
  "nonce" => (string)(time() . "_" . bin2hex(random_bytes(4))),
  "set_at" => gmdate("c"),
];

@file_put_contents($filterPath, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);

echo "Category set to {$displayName} ({$totalClips} clips)";
