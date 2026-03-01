<?php
/**
 * discord/channel_browse.php - Browse all archived channels
 *
 * Returns all channels with clips, sorted by clip count.
 * Used by the Discord Activity channel picker's default view.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../db_config.php';

$debug = isset($_GET['debug']);

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode($debug ? ['error' => 'no_db', 'DATABASE_URL' => getenv('DATABASE_URL') ? 'set' : 'not_set'] : []);
    exit;
}

try {
    // First check if clips table has any rows at all
    if ($debug) {
        $check = $pdo->query("SELECT COUNT(*) as total FROM clips");
        $total = $check->fetch(PDO::FETCH_ASSOC);
        $check2 = $pdo->query("SELECT COUNT(*) as unblocked FROM clips WHERE blocked = FALSE");
        $unblocked = $check2->fetch(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->query("
        SELECT c.login,
               MAX(cs.display_name) as display_name,
               MAX(cs.profile_image_url) as profile_image_url,
               COUNT(*) as clip_count
        FROM clips c
        LEFT JOIN channel_settings cs ON cs.login = c.login
        WHERE c.blocked = FALSE
        GROUP BY c.login
        ORDER BY clip_count DESC
        LIMIT 50
    ");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($debug) {
        echo json_encode(['total_clips' => $total['total'] ?? 0, 'unblocked' => $unblocked['unblocked'] ?? 0, 'channels' => count($results), 'results' => $results]);
    } else {
        echo json_encode($results);
    }
} catch (PDOException $e) {
    error_log("Channel browse error: " . $e->getMessage());
    echo json_encode($debug ? ['error' => $e->getMessage()] : []);
}
