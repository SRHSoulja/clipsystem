<?php
/**
 * setup_streamers_table.php - Create streamers table for dashboard auth
 * Run once: php setup_streamers_table.php
 */
require_once __DIR__ . '/db_config.php';

$pdo = get_db_connection();
if (!$pdo) {
    die("Database connection failed\n");
}

echo "Creating streamers table...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS streamers (
            login VARCHAR(50) PRIMARY KEY,
            streamer_key VARCHAR(64) UNIQUE NOT NULL,
            mod_password VARCHAR(64),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "  - streamers table created\n";

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_streamers_key ON streamers(streamer_key)");
    echo "  - index created\n";

    echo "\nDone!\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
