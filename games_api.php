<?php
/**
 * games_api.php - Fetch and cache Twitch game names
 *
 * Requires TWITCH_CLIENT_ID and TWITCH_CLIENT_SECRET env vars.
 * Caches game names in database for fast lookups.
 * The 'resolve' action requires super admin OAuth.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

function json_response($data) {
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}

function json_error($msg, $code = 400) {
  http_response_code($code);
  json_response(["error" => $msg]);
}

$action = $_GET['action'] ?? 'get';
$ids = $_GET['ids'] ?? '';

$pdo = get_db_connection();
if (!$pdo) {
  json_error("Database unavailable", 500);
}

// Create games cache table
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS games_cache (
      game_id VARCHAR(64) PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      box_art_url TEXT,
      fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
  ");
} catch (PDOException $e) {
  error_log("games_api table error: " . $e->getMessage());
}

// Get Twitch access token
function getTwitchToken() {
  $clientId = getenv('TWITCH_CLIENT_ID');
  $clientSecret = getenv('TWITCH_CLIENT_SECRET');

  if (!$clientId || !$clientSecret) {
    return null;
  }

  // Check for cached token in file
  $tokenFile = is_writable("/tmp") ? "/tmp/twitch_token.json" : __DIR__ . "/cache/twitch_token.json";
  if (file_exists($tokenFile)) {
    $cached = json_decode(file_get_contents($tokenFile), true);
    if ($cached && isset($cached['expires_at']) && $cached['expires_at'] > time() + 300) {
      return $cached['access_token'];
    }
  }

  // Get new token
  $ch = curl_init('https://id.twitch.tv/oauth2/token');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
      'client_id' => $clientId,
      'client_secret' => $clientSecret,
      'grant_type' => 'client_credentials'
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($httpCode !== 200) {
    error_log("Twitch token error: " . $response);
    return null;
  }

  $data = json_decode($response, true);
  if (!$data || !isset($data['access_token'])) {
    return null;
  }

  // Cache token
  $data['expires_at'] = time() + ($data['expires_in'] ?? 3600) - 60;
  @file_put_contents($tokenFile, json_encode($data));

  return $data['access_token'];
}

// Fetch games from Twitch API
function fetchGamesFromTwitch($gameIds) {
  $clientId = getenv('TWITCH_CLIENT_ID');
  $token = getTwitchToken();

  if (!$clientId || !$token) {
    return [];
  }

  $games = [];

  // Twitch allows up to 100 IDs per request
  foreach (array_chunk($gameIds, 100) as $chunk) {
    $query = implode('&', array_map(fn($id) => "id=" . urlencode($id), $chunk));
    $url = "https://api.twitch.tv/helix/games?" . $query;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_HTTPHEADER => [
        "Client-ID: $clientId",
        "Authorization: Bearer $token"
      ],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
      $data = json_decode($response, true);
      if (isset($data['data'])) {
        foreach ($data['data'] as $game) {
          $games[$game['id']] = [
            'id' => $game['id'],
            'name' => $game['name'],
            'box_art_url' => $game['box_art_url'] ?? ''
          ];
        }
      }
    }
  }

  return $games;
}

switch ($action) {
  case 'get':
    // Get game names for given IDs
    if (!$ids) {
      json_response(["games" => []]);
    }

    $idList = array_filter(array_map('trim', explode(',', $ids)));
    if (empty($idList)) {
      json_response(["games" => []]);
    }

    // Check cache first
    $placeholders = implode(',', array_fill(0, count($idList), '?'));
    $stmt = $pdo->prepare("SELECT game_id, name, box_art_url FROM games_cache WHERE game_id IN ($placeholders)");
    $stmt->execute($idList);

    $cached = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $cached[$row['game_id']] = $row;
    }

    // Find missing IDs
    $missing = array_diff($idList, array_keys($cached));

    // Fetch missing from Twitch
    if (!empty($missing)) {
      $fetched = fetchGamesFromTwitch(array_values($missing));

      // Cache the results
      if (!empty($fetched)) {
        $stmt = $pdo->prepare("
          INSERT INTO games_cache (game_id, name, box_art_url)
          VALUES (?, ?, ?)
          ON CONFLICT (game_id) DO UPDATE SET name = EXCLUDED.name, box_art_url = EXCLUDED.box_art_url, fetched_at = CURRENT_TIMESTAMP
        ");

        foreach ($fetched as $game) {
          $stmt->execute([$game['id'], $game['name'], $game['box_art_url']]);
          $cached[$game['id']] = $game;
        }
      }
    }

    json_response(["games" => $cached]);
    break;

  case 'all':
    // Get all cached games (for dropdown)
    $stmt = $pdo->query("SELECT game_id, name FROM games_cache ORDER BY name");
    $games = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $games[$row['game_id']] = $row['name'];
    }
    json_response(["games" => $games]);
    break;

  case 'resolve':
    // Resolve all missing game names for a login (or all logins if not specified)
    $login = isset($_GET['login']) ? strtolower(trim(preg_replace('/[^a-z0-9_]/', '', $_GET['login']))) : '';

    // Auth check - require super admin OAuth
    $currentUser = getCurrentUser();
    if (!$currentUser || !isSuperAdmin()) {
      json_error("Unauthorized - super admin access required", 401);
    }

    // Find all game_ids in clips that aren't in games_cache
    if ($login) {
      $stmt = $pdo->prepare("
        SELECT DISTINCT c.game_id
        FROM clips c
        LEFT JOIN games_cache g ON c.game_id = g.game_id
        WHERE c.login = ? AND c.game_id IS NOT NULL AND c.game_id != '' AND g.game_id IS NULL
      ");
      $stmt->execute([$login]);
    } else {
      $stmt = $pdo->query("
        SELECT DISTINCT c.game_id
        FROM clips c
        LEFT JOIN games_cache g ON c.game_id = g.game_id
        WHERE c.game_id IS NOT NULL AND c.game_id != '' AND g.game_id IS NULL
      ");
    }
    $missingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($missingIds)) {
      json_response(["message" => "All game names already cached", "resolved" => 0]);
    }

    // Fetch from Twitch
    $fetched = fetchGamesFromTwitch($missingIds);
    $resolved = 0;

    if (!empty($fetched)) {
      $insertStmt = $pdo->prepare("
        INSERT INTO games_cache (game_id, name, box_art_url)
        VALUES (?, ?, ?)
        ON CONFLICT (game_id) DO UPDATE SET name = EXCLUDED.name, box_art_url = EXCLUDED.box_art_url, fetched_at = CURRENT_TIMESTAMP
      ");

      foreach ($fetched as $game) {
        try {
          $insertStmt->execute([$game['id'], $game['name'], $game['box_art_url']]);
          $resolved++;
        } catch (PDOException $e) {
          // Ignore
        }
      }
    }

    json_response([
      "message" => "Resolved $resolved of " . count($missingIds) . " missing game names",
      "resolved" => $resolved,
      "total_missing" => count($missingIds),
      "games" => $fetched
    ]);
    break;

  default:
    json_error("Unknown action");
}
