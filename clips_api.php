<?php
/**
 * clips_api.php - API for browsing all clips with pagination
 *
 * Used by mod dashboard to load all clips efficiently.
 * Supports pagination, search, and game filtering.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

function json_response($data) {
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}

function json_error($msg, $code = 400) {
  http_response_code($code);
  json_response(["error" => $msg]);
}

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

// Get Twitch access token
function getTwitchToken() {
  $clientId = getenv('TWITCH_CLIENT_ID');
  $clientSecret = getenv('TWITCH_CLIENT_SECRET');

  if (!$clientId || !$clientSecret) {
    return null;
  }

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
    return null;
  }

  $data = json_decode($response, true);
  return $data['access_token'] ?? null;
}

// Fetch game names from Twitch API and cache them
function fetchGamesFromTwitch($gameIds, $pdo) {
  $clientId = getenv('TWITCH_CLIENT_ID');
  $token = getTwitchToken();

  if (!$clientId || !$token || empty($gameIds)) {
    return [];
  }

  $gameNames = [];

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
          $gameNames[$game['id']] = $game['name'];

          // Cache in database
          try {
            $stmt = $pdo->prepare("
              INSERT INTO games_cache (game_id, name, box_art_url)
              VALUES (?, ?, ?)
              ON CONFLICT (game_id) DO UPDATE SET name = EXCLUDED.name, fetched_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$game['id'], $game['name'], $game['box_art_url'] ?? '']);
          } catch (PDOException $e) {
            // Ignore cache errors
          }
        }
      }
    }
  }

  return $gameNames;
}

$login   = clean_login($_GET["login"] ?? "");
$key     = (string)($_GET["key"] ?? "");
$page    = max(1, (int)($_GET["page"] ?? 1));
$perPage = min(500, max(50, (int)($_GET["per_page"] ?? 200)));
$search  = trim((string)($_GET["q"] ?? ""));
$gameId  = trim((string)($_GET["game_id"] ?? ""));
$action  = $_GET["action"] ?? "list";

// Verify admin key for protected actions
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';
if ($key !== $ADMIN_KEY) {
  json_error("Forbidden", 403);
}

$pdo = get_db_connection();
if (!$pdo) {
  json_error("Database unavailable", 500);
}

switch ($action) {
  case 'list':
    // Build query with optional filters
    $whereClauses = ["login = ?", "blocked = FALSE"];
    $params = [$login];

    // Multi-word search (AND logic) - searches title only
    if ($search) {
      $searchWords = preg_split('/\s+/', trim($search));
      $searchWords = array_filter($searchWords, function($w) { return strlen($w) >= 2; });
      foreach ($searchWords as $word) {
        $whereClauses[] = "title ILIKE ?";
        $params[] = '%' . $word . '%';
      }
    }

    // Creator filter
    $creator = isset($_GET['creator']) ? trim($_GET['creator']) : '';
    if ($creator) {
      $whereClauses[] = "creator_name ILIKE ?";
      $params[] = '%' . $creator . '%';
    }

    // Game filter
    if ($gameId) {
      $whereClauses[] = "game_id = ?";
      $params[] = $gameId;
    }

    $whereSQL = implode(' AND ', $whereClauses);

    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE {$whereSQL}");
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalCount / $perPage);

    // Get paginated clips
    $offset = ($page - 1) * $perPage;
    $paginatedParams = array_merge($params, [$perPage, $offset]);

    $stmt = $pdo->prepare("
      SELECT seq, clip_id, title, duration, view_count, game_id, created_at, thumbnail_url, creator_name
      FROM clips
      WHERE {$whereSQL}
      ORDER BY seq DESC
      LIMIT ? OFFSET ?
    ");
    $stmt->execute($paginatedParams);
    $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_response([
      "clips" => $clips,
      "total" => $totalCount,
      "page" => $page,
      "per_page" => $perPage,
      "total_pages" => $totalPages
    ]);
    break;

  case 'games':
    // Get unique game_ids with counts
    $stmt = $pdo->prepare("
      SELECT game_id, COUNT(*) as count
      FROM clips
      WHERE login = ? AND blocked = FALSE AND game_id IS NOT NULL AND game_id != ''
      GROUP BY game_id
      ORDER BY count DESC
      LIMIT 100
    ");
    $stmt->execute([$login]);
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch game names from cache
    $gameIds = array_column($games, 'game_id');
    $gameNames = [];

    if (!empty($gameIds)) {
      $placeholders = implode(',', array_fill(0, count($gameIds), '?'));
      $stmt = $pdo->prepare("SELECT game_id, name FROM games_cache WHERE game_id IN ($placeholders)");
      $stmt->execute($gameIds);
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $gameNames[$row['game_id']] = $row['name'];
      }

      // Find missing game IDs and fetch from Twitch API
      $missingIds = array_diff($gameIds, array_keys($gameNames));
      if (!empty($missingIds)) {
        $fetched = fetchGamesFromTwitch(array_values($missingIds), $pdo);
        $gameNames = array_merge($gameNames, $fetched);
      }
    }

    // Combine with counts
    $result = [];
    foreach ($games as $g) {
      $result[] = [
        'game_id' => $g['game_id'],
        'name' => $gameNames[$g['game_id']] ?? "Game {$g['game_id']}",
        'count' => (int)$g['count']
      ];
    }

    // Sort: Just Chatting, IRL, I'm Only Sleeping first, then alphabetical
    usort($result, function($a, $b) {
      $priority = ['Just Chatting' => 0, 'IRL' => 1, "I'm Only Sleeping" => 2];
      $prioA = $priority[$a['name']] ?? 999;
      $prioB = $priority[$b['name']] ?? 999;
      if ($prioA !== $prioB) return $prioA - $prioB;
      return strcasecmp($a['name'], $b['name']);
    });

    json_response(["games" => $result]);
    break;

  case 'stats':
    // Get clip stats
    $stmt = $pdo->prepare("
      SELECT
        COUNT(*) as total,
        COUNT(*) FILTER (WHERE blocked = FALSE) as active,
        COUNT(*) FILTER (WHERE blocked = TRUE) as blocked
      FROM clips
      WHERE login = ?
    ");
    $stmt->execute([$login]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    json_response(["stats" => $stats]);
    break;

  default:
    json_error("Unknown action");
}
