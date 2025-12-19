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

    // Multi-word search (AND logic)
    if ($search) {
      $searchWords = preg_split('/\s+/', trim($search));
      $searchWords = array_filter($searchWords, function($w) { return strlen($w) >= 2; });
      foreach ($searchWords as $word) {
        $whereClauses[] = "title ILIKE ?";
        $params[] = '%' . $word . '%';
      }
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
      SELECT seq, clip_id, title, duration, view_count, game_id, created_at
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

    // Fetch game names
    $gameIds = array_column($games, 'game_id');
    $gameNames = [];

    if (!empty($gameIds)) {
      $placeholders = implode(',', array_fill(0, count($gameIds), '?'));
      $stmt = $pdo->prepare("SELECT game_id, name FROM games_cache WHERE game_id IN ($placeholders)");
      $stmt->execute($gameIds);
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $gameNames[$row['game_id']] = $row['name'];
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
