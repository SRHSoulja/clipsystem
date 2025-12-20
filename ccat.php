<?php
/**
 * ccat.php - Set category filter for clip reel
 *
 * Mods can use !ccat <game_name> to filter clips to a specific game.
 * Use !ccat off to disable the filter.
 * Commands auto-route to the streamer's instance for isolation.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/dashboard_auth.php';
require_once __DIR__ . '/db_config.php';

set_cors_headers();
handle_options_request();
set_nocache_headers();
header("Content-Type: text/plain; charset=utf-8");

$login = clean_login($_GET["login"] ?? "");
$category = trim($_GET["category"] ?? "");

require_admin_auth();

// Get streamer's instance for command isolation
$auth = new DashboardAuth();
$instance = $auth->getStreamerInstance($login) ?: "";

if ($category === "") {
  echo "Usage: !ccat <game> to filter, !ccat off to exit";
  exit;
}

// Runtime data directory
$runtimeDir = get_runtime_dir();

// Handle "off" to clear category filter - also handle common variations
$catLower = strtolower($category);
if ($catLower === "off" || $catLower === "clear" || $catLower === "all" || $catLower === "exit" || $catLower === "reset" || $catLower === "none") {
  $fileSuffix = $instance ? "_{$instance}" : "";
  $filterPath = $runtimeDir . "/category_filter_" . $login . $fileSuffix . ".json";
  if (file_exists($filterPath)) {
    @unlink($filterPath);
  }
  // Also clear generic file if instance exists
  if ($instance) {
    $genericPath = $runtimeDir . "/category_filter_" . $login . ".json";
    if (file_exists($genericPath)) {
      @unlink($genericPath);
    }
  }
  // Clean up category clips cache files
  $cachePattern = $runtimeDir . "/category_clips_" . $login . "_*.json";
  foreach (glob($cachePattern) as $cacheFile) {
    @unlink($cacheFile);
  }
  echo "Category filter cleared - playing all games";
  exit;
}

// Look up the game by name (fuzzy match)
$pdo = get_db_connection();

if (!$pdo) {
  echo "Database unavailable";
  exit;
}

$matchedGames = [];
$availableGames = [];

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

// Build display name
if (count($matchedGames) === 1) {
  $displayName = $matchedGames[0]['name'];
} else {
  // Show search term + count of games
  $displayName = ucfirst($category) . " (" . count($matchedGames) . " games)";
}

$payload = json_encode([
  "login" => $login,
  "game_ids" => $gameIds,  // Array of game IDs
  "game_id" => $gameIds[0],  // Keep single ID for backwards compat
  "game_name" => $displayName,
  "game_names" => $gameNames,  // All matched game names
  "clip_count" => $totalClips,
  "nonce" => (string)(time() . "_" . bin2hex(random_bytes(4))),
  "set_at" => gmdate("c"),
], JSON_UNESCAPED_SLASHES);

// Write to BOTH generic and instance-specific paths
// Always write generic file (for basic sources)
$genericPath = $runtimeDir . "/category_filter_" . $login . ".json";
$result = file_put_contents($genericPath, $payload, LOCK_EX);

if ($result === false) {
  error_log("ccat: Failed to write category filter to $genericPath");
  echo "Error: Could not save category filter";
  exit;
}

// Also write instance-specific file if streamer has instance
if ($instance) {
  $instancePath = $runtimeDir . "/category_filter_" . $login . "_" . $instance . ".json";
  @file_put_contents($instancePath, $payload, LOCK_EX);
}

echo "Category set to {$displayName} ({$totalClips} clips)";
