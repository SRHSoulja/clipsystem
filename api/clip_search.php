<?php
/**
 * api/clip_search.php - Lightweight clip search API for the remote control
 *
 * Usage: api/clip_search.php?login=abbabox&q=wizard&limit=50
 *
 * Returns JSON array of matching clips from the database.
 * Searches title, creator_name, and game_name.
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

// Build search query
$whereClauses = ["login = ?", "blocked = FALSE"];
$params = [$login];

if ($query !== '') {
    // Split into words, require all words to match (AND search)
    $words = preg_split('/\s+/', $query);
    $words = array_filter($words, function($w) { return strlen($w) >= 1; });
    $words = array_values($words);

    foreach ($words as $word) {
        // Check if it's a pure number (seq search)
        if (ctype_digit($word)) {
            $whereClauses[] = "(title ILIKE ? OR creator_name ILIKE ? OR CAST(seq AS TEXT) = ?)";
            $params[] = '%' . $word . '%';
            $params[] = '%' . $word . '%';
            $params[] = $word;
        } else {
            $whereClauses[] = "(title ILIKE ? OR creator_name ILIKE ? OR game_name ILIKE ?)";
            $params[] = '%' . $word . '%';
            $params[] = '%' . $word . '%';
            $params[] = '%' . $word . '%';
        }
    }
}

$whereSQL = implode(' AND ', $whereClauses);

try {
    $stmt = $pdo->prepare("
        SELECT seq, clip_id, title, duration, created_at, view_count,
               game_id, game_name, creator_name
        FROM clips
        WHERE {$whereSQL}
        ORDER BY seq DESC
        LIMIT ?
    ");
    $params[] = $limit;
    $stmt->execute($params);
    $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format for the remote control
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
