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
  // First try exact match in games_cache (case-insensitive)
  $stmt = $pdo->prepare("SELECT DISTINCT game_id, name FROM games_cache WHERE LOWER(name) = LOWER(?) LIMIT 1");
  $stmt->execute([$category]);
  $row = $stmt->fetch();

  if ($row) {
    $matchedGame = $row;
  } else {
    // Try fuzzy match with LIKE in games_cache
    $stmt = $pdo->prepare("SELECT DISTINCT game_id, name FROM games_cache WHERE LOWER(name) LIKE LOWER(?) ORDER BY name LIMIT 5");
    $stmt->execute(["%" . $category . "%"]);
    $matches = $stmt->fetchAll();

    if (count($matches) === 1) {
      $matchedGame = $matches[0];
    } elseif (count($matches) > 1) {
      // Multiple matches - show options
      $names = array_column($matches, 'name');
      echo "Multiple matches: " . implode(", ", $names) . " - be more specific";
      exit;
    }
  }

  // If still no match, get list of available games for this channel from clips table
  if (!$matchedGame) {
    // First check what games this channel actually has clips for
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

    // Try to match by partial name in available games
    foreach ($availableGames as $game) {
      if (stripos($game['name'], $category) !== false) {
        $matchedGame = $game;
        break;
      }
    }
  }
} catch (PDOException $e) {
  error_log("ccat db error: " . $e->getMessage());
  echo "Database error: " . $e->getMessage();
  exit;
}

if (!$matchedGame) {
  if (!empty($availableGames)) {
    $names = array_column($availableGames, 'name');
    echo "Game not found. Available: " . implode(", ", array_slice($names, 0, 10));
    if (count($names) > 10) echo "...";
  } else {
    echo "Game '$category' not found.";
  }
  exit;
}

// Count how many clips are available for this game
$clipCount = 0;
if ($pdo) {
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ? AND game_id = ? AND blocked = false");
    $stmt->execute([$login, $matchedGame['game_id']]);
    $clipCount = (int)$stmt->fetchColumn();
  } catch (PDOException $e) {
    error_log("pcategory count error: " . $e->getMessage());
  }
}

if ($clipCount === 0) {
  echo "No clips found for {$matchedGame['name']}";
  exit;
}

// Save the category filter
$filterPath = $runtimeDir . "/category_filter_" . $login . ".json";
$payload = [
  "login" => $login,
  "game_id" => $matchedGame['game_id'],
  "game_name" => $matchedGame['name'],
  "clip_count" => $clipCount,
  "nonce" => (string)(time() . "_" . bin2hex(random_bytes(4))),
  "set_at" => gmdate("c"),
];

@file_put_contents($filterPath, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);

echo "Category set to {$matchedGame['name']} ({$clipCount} clips)";
