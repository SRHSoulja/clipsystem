<?php
/**
 * db_bootstrap.php - One-time database schema initialization
 *
 * Runs all CREATE TABLE / ALTER TABLE / CREATE INDEX statements.
 * Called automatically on first request after deploy (via db_ensure_schema),
 * or manually: php db_bootstrap.php
 *
 * Safe to run repeatedly — all statements use IF NOT EXISTS.
 * Writes a stamp file so subsequent requests skip schema work entirely.
 */

function run_db_bootstrap($pdo) {
    // ── Tables used by the 5 hot endpoints ──────────────────────────

    // sync_state.php + sync_state_heartbeat.php
    $pdo->exec("CREATE TABLE IF NOT EXISTS sync_state (
        login VARCHAR(50) PRIMARY KEY,
        clip_id VARCHAR(100),
        clip_url TEXT,
        clip_title TEXT,
        clip_curator VARCHAR(100),
        clip_duration FLOAT DEFAULT 30,
        clip_seq INT DEFAULT 0,
        clip_created_at TIMESTAMP,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        playlist_index INT DEFAULT 0,
        playlist_ids TEXT DEFAULT '[]',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    try { $pdo->exec("ALTER TABLE sync_state ADD COLUMN IF NOT EXISTS clip_seq INT DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE sync_state ADD COLUMN IF NOT EXISTS clip_created_at TIMESTAMP"); } catch (PDOException $e) {}

    // cliptv_viewers.php
    $pdo->exec("CREATE TABLE IF NOT EXISTS cliptv_viewers (
        id SERIAL PRIMARY KEY,
        login VARCHAR(50) NOT NULL,
        viewer_id VARCHAR(64) NOT NULL,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        wants_skip BOOLEAN DEFAULT FALSE,
        clip_id VARCHAR(100),
        UNIQUE(login, viewer_id)
    )");
    try { $pdo->exec("ALTER TABLE cliptv_viewers ADD COLUMN IF NOT EXISTS clip_id VARCHAR(100)"); } catch (PDOException $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cliptv_viewers_last_seen ON cliptv_viewers(last_seen)");

    // cliptv_chat.php
    $pdo->exec("CREATE TABLE IF NOT EXISTS cliptv_chat (
        id SERIAL PRIMARY KEY,
        login VARCHAR(50) NOT NULL,
        user_id VARCHAR(50),
        username VARCHAR(64) NOT NULL,
        display_name VARCHAR(64) NOT NULL,
        message TEXT NOT NULL,
        scope VARCHAR(10) NOT NULL DEFAULT 'stream',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cliptv_chat_login ON cliptv_chat(login, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cliptv_chat_cleanup ON cliptv_chat(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cliptv_chat_scope ON cliptv_chat(scope, id)");
    try { $pdo->exec("ALTER TABLE cliptv_chat ADD COLUMN IF NOT EXISTS scope VARCHAR(10) NOT NULL DEFAULT 'stream'"); } catch (PDOException $e) {}

    // cliptv_request.php
    $pdo->exec("CREATE TABLE IF NOT EXISTS cliptv_requests (
        login VARCHAR(50) PRIMARY KEY,
        clip_id VARCHAR(100) NOT NULL,
        clip_seq INT DEFAULT 0,
        clip_title TEXT,
        clip_game TEXT,
        clip_creator VARCHAR(100),
        clip_duration FLOAT DEFAULT 30,
        requester_id VARCHAR(64),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        played BOOLEAN DEFAULT FALSE
    )");

    // ── Tables from init_votes_tables (used by other endpoints) ─────

    if (function_exists('init_votes_tables')) {
        init_votes_tables($pdo);
    }

    // ── Write stamp file ────────────────────────────────────────────

    $stampDir = dirname(__FILE__) . '/cache';
    if (!is_dir($stampDir)) {
        @mkdir($stampDir, 0755, true);
    }
    file_put_contents($stampDir . '/db_bootstrapped.stamp', date('c') . "\n");
}

// CLI mode: run directly with `php db_bootstrap.php`
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/db_config.php';
    $pdo = get_db_connection();
    if (!$pdo) {
        fwrite(STDERR, "db_bootstrap: no DATABASE_URL configured\n");
        exit(1);
    }
    echo "db_bootstrap: connected\n";
    run_db_bootstrap($pdo);
    echo "db_bootstrap: complete\n";
}
