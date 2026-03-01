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

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode([]);
    exit;
}

// Ensure display_name column exists
try { $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS display_name VARCHAR(255)"); } catch (PDOException $e) {}

try {
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
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
    error_log("Channel browse error: " . $e->getMessage());
    echo json_encode([]);
}
