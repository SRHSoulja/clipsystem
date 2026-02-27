<?php
/**
 * api/clip_search.php - Lightweight clip search API for the remote control
 *
 * Usage: api/clip_search.php?login=abbabox&q=wizard&limit=50
 *
 * Returns JSON array of matching clips from the database.
 * Searches title, creator_name, and game name (via games_cache).
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

require_once __DIR__ . '/../db_config.php';

$login = strtolower(preg_replace('/[^a-z0-9_]/', '', $_GET['login'] ?? ''));
$query = trim($_GET['q'] ?? '');
$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));

if (!$login) {
    echo json_encode(["error" => "Missing login", "clips" => []]);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode(["error" => "Database unavailable", "clips" => []]);
    exit;
}

// Build search query - JOIN games_cache for game names
$whereClauses = ["c.login = ?", "c.blocked = FALSE"];
$params = [$login];

if ($query !== '') {
    $words = preg_split('/\s+/', $query);
    $words = array_filter($words, function($w) { return strlen($w) >= 1; });
    $words = array_values($words);

    foreach ($words as $word) {
        if (ctype_digit($word)) {
            $whereClauses[] = "(c.title ILIKE ? OR c.creator_name ILIKE ? OR CAST(c.seq AS TEXT) = ?)";
            $params[] = '%' . $word . '%';
            $params[] = '%' . $word . '%';
            $params[] = $word;
        } else {
            $whereClauses[] = "(c.title ILIKE ? OR c.creator_name ILIKE ? OR gc.name ILIKE ?)";
            $params[] = '%' . $word . '%';
            $params[] = '%' . $word . '%';
            $params[] = '%' . $word . '%';
        }
    }
}

$whereSQL = implode(' AND ', $whereClauses);
$limitInt = (int)$limit;

try {
    $stmt = $pdo->prepare("
        SELECT c.seq, c.clip_id, c.title, c.duration, c.created_at, c.view_count,
               c.game_id, gc.name AS game_name, c.creator_name
        FROM clips c
        LEFT JOIN games_cache gc ON c.game_id = gc.game_id
        WHERE {$whereSQL}
        ORDER BY c.seq DESC
        LIMIT {$limitInt}
    ");
    $stmt->execute($params);
    $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = array_map(function($c) {
        return [
            "id" => $c['clip_id'],
            "seq" => (int)$c['seq'],
            "title" => $c['title'],
            "duration" => (float)$c['duration'],
            "created_at" => $c['created_at'],
            "view_count" => (int)$c['view_count'],
            "game_id" => $c['game_id'],
            "game_name" => $c['game_name'],
            "creator_name" => $c['creator_name']
        ];
    }, $clips);

    echo json_encode(["clips" => $results, "count" => count($results)]);
} catch (PDOException $e) {
    error_log("clip_search API error: " . $e->getMessage());
    echo json_encode(["error" => "Search failed", "clips" => []]);
}
