<?php
/**
 * migrate.php - Run database migrations
 *
 * Access this endpoint once to create missing tables.
 * Can be run multiple times safely (uses CREATE TABLE IF NOT EXISTS).
 */

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/db_config.php';

$pdo = get_db_connection();

if (!$pdo) {
    echo json_encode(["error" => "No database connection"]);
    exit;
}

$result = init_votes_tables($pdo);

if ($result) {
    echo json_encode([
        "success" => true,
        "message" => "All tables created/verified successfully"
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Failed to create tables - check logs"
    ]);
}
