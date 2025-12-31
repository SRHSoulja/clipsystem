<?php
/**
 * live_clips_api.php - AJAX endpoint for fetching clips from Twitch API
 *
 * Used for background/progressive loading of clips in live mode.
 * Returns JSON with clips data and pagination cursor.
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_api.php';

function clean_login($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "";
}

$login = clean_login($_GET['streamer'] ?? $_GET['login'] ?? '');
$cursor = $_GET['cursor'] ?? null;
$dateRange = $_GET['range'] ?? 'year';
$query = trim($_GET['q'] ?? '');
$clipper = trim($_GET['clipper'] ?? '');
$batchSize = min(100, max(20, (int)($_GET['batch'] ?? 100)));

// Validate date range
$validRanges = ['week', 'month', '3months', '6months', 'year', '2years', '3years', 'all'];
if (!in_array($dateRange, $validRanges)) {
  $dateRange = 'year';
}

if (!$login) {
  echo json_encode(['error' => 'Missing login parameter', 'clips' => []]);
  exit;
}

$twitchApi = new TwitchAPI();

if (!$twitchApi->isConfigured()) {
  echo json_encode(['error' => 'Twitch API not configured', 'clips' => []]);
  exit;
}

// Fetch batch of clips
$result = $twitchApi->getClipsBatch($login, $cursor, $batchSize, $dateRange);

if (isset($result['error'])) {
  echo json_encode(['error' => $result['error'], 'clips' => []]);
  exit;
}

$clips = $result['clips'];

// Apply filters if provided
if ($query) {
  $queryWords = preg_split('/\s+/', trim($query));
  $queryWords = array_filter($queryWords, function($w) { return strlen($w) >= 2; });

  if (!empty($queryWords)) {
    $clips = array_filter($clips, function($clip) use ($queryWords) {
      $title = strtolower($clip['title'] ?? '');
      foreach ($queryWords as $word) {
        if (stripos($title, strtolower($word)) === false) {
          return false;
        }
      }
      return true;
    });
    $clips = array_values($clips);
  }
}

if ($clipper) {
  $clips = array_filter($clips, function($clip) use ($clipper) {
    return stripos($clip['creator_name'] ?? '', $clipper) !== false;
  });
  $clips = array_values($clips);
}

// Get game names for any new game IDs
$gameIds = array_unique(array_filter(array_column($clips, 'game_id')));
$gameNames = [];

if (!empty($gameIds)) {
  $pdo = get_db_connection();

  // Try database cache first
  if ($pdo) {
    try {
      $placeholders = implode(',', array_fill(0, count($gameIds), '?'));
      $stmt = $pdo->prepare("SELECT game_id, name FROM games_cache WHERE game_id IN ($placeholders)");
      $stmt->execute($gameIds);
      $gameNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
      // Ignore
    }
  }

  // Fetch missing from API
  $missingIds = array_filter($gameIds, function($id) use ($gameNames) {
    return !isset($gameNames[$id]);
  });

  if (!empty($missingIds)) {
    $apiGames = $twitchApi->getGamesByIds($missingIds);
    foreach ($apiGames as $gid => $gameInfo) {
      $gameNames[$gid] = $gameInfo['name'];

      // Cache to database
      if ($pdo) {
        try {
          $insertStmt = $pdo->prepare("INSERT INTO games_cache (game_id, name) VALUES (?, ?) ON CONFLICT (game_id) DO UPDATE SET name = EXCLUDED.name");
          $insertStmt->execute([$gid, $gameInfo['name']]);
        } catch (PDOException $e) {
          // Ignore
        }
      }
    }
  }
}

// Add game names to clips
foreach ($clips as &$clip) {
  $clip['game_name'] = $gameNames[$clip['game_id']] ?? '';
}
unset($clip);

echo json_encode([
  'clips' => $clips,
  'cursor' => $result['cursor'],
  'has_more' => $result['has_more'],
  'count' => count($clips),
  'game_names' => $gameNames
]);
