<?php
/**
 * channel_search.php - Search archived streamers by name
 *
 * Used by the Discord Activity channel picker.
 * Returns JSON array of matching channels with clip counts.
 *
 * GET Parameters:
 *   - q: Search query (min 2 chars)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$q = strtolower(trim($_GET['q'] ?? ''));
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

require_once __DIR__ . '/db_config.php';

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT c.login,
               MAX(cs.display_name) as display_name,
               MAX(cs.profile_image_url) as profile_image_url,
               COUNT(*) as clip_count
        FROM clips c
        LEFT JOIN channel_settings cs ON cs.login = c.login
        WHERE c.blocked = FALSE AND c.login LIKE ?
        GROUP BY c.login
        ORDER BY clip_count DESC
        LIMIT 20
    ");
    $stmt->execute(['%' . $q . '%']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($results);
} catch (PDOException $e) {
    error_log("Channel search error: " . $e->getMessage());
    echo json_encode([]);
}
