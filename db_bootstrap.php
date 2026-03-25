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
    $errors = [];

    // Helper: run DDL, log failure but don't abort
    $exec = function($sql, $label) use ($pdo, &$errors) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            $errors[] = "$label: " . $e->getMessage();
            error_log("db_bootstrap: $label failed: " . $e->getMessage());
        }
    };

    // ── Tables used by the 5 hot endpoints ──────────────────────────

    // sync_state.php + sync_state_heartbeat.php
    $exec("CREATE TABLE IF NOT EXISTS sync_state (
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
    )", "sync_state");
    $exec("ALTER TABLE sync_state ADD COLUMN IF NOT EXISTS clip_seq INT DEFAULT 0", "sync_state.clip_seq");
    $exec("ALTER TABLE sync_state ADD COLUMN IF NOT EXISTS clip_created_at TIMESTAMP", "sync_state.clip_created_at");

    // cliptv_viewers.php
    $exec("CREATE TABLE IF NOT EXISTS cliptv_viewers (
        id SERIAL PRIMARY KEY,
        login VARCHAR(50) NOT NULL,
        viewer_id VARCHAR(64) NOT NULL,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        wants_skip BOOLEAN DEFAULT FALSE,
        clip_id VARCHAR(100),
        UNIQUE(login, viewer_id)
    )", "cliptv_viewers");
    $exec("ALTER TABLE cliptv_viewers ADD COLUMN IF NOT EXISTS clip_id VARCHAR(100)", "cliptv_viewers.clip_id");
    $exec("CREATE INDEX IF NOT EXISTS idx_cliptv_viewers_last_seen ON cliptv_viewers(last_seen)", "cliptv_viewers.idx");

    // cliptv_chat.php
    $exec("CREATE TABLE IF NOT EXISTS cliptv_chat (
        id SERIAL PRIMARY KEY,
        login VARCHAR(50) NOT NULL,
        user_id VARCHAR(50),
        username VARCHAR(64) NOT NULL,
        display_name VARCHAR(64) NOT NULL,
        message TEXT NOT NULL,
        scope VARCHAR(10) NOT NULL DEFAULT 'stream',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )", "cliptv_chat");
    $exec("CREATE INDEX IF NOT EXISTS idx_cliptv_chat_login ON cliptv_chat(login, id)", "cliptv_chat.idx_login");
    $exec("CREATE INDEX IF NOT EXISTS idx_cliptv_chat_cleanup ON cliptv_chat(created_at)", "cliptv_chat.idx_cleanup");
    $exec("CREATE INDEX IF NOT EXISTS idx_cliptv_chat_scope ON cliptv_chat(scope, id)", "cliptv_chat.idx_scope");
    $exec("ALTER TABLE cliptv_chat ADD COLUMN IF NOT EXISTS scope VARCHAR(10) NOT NULL DEFAULT 'stream'", "cliptv_chat.scope");

    // cliptv_request.php
    $exec("CREATE TABLE IF NOT EXISTS cliptv_requests (
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
    )", "cliptv_requests");

    if ($errors) {
        error_log("db_bootstrap: " . count($errors) . " non-fatal error(s): " . implode('; ', $errors));
    }

    // ── Tables from init_votes_tables (used by other endpoints) ─────
    // Non-fatal: if these fail, the hot endpoints still work.
    // The vote/clip tables are created by their own endpoints on demand.
    try {
        if (function_exists('init_votes_tables')) {
            init_votes_tables($pdo);
        }
    } catch (Exception $e) {
        error_log("db_bootstrap: init_votes_tables failed (non-fatal): " . $e->getMessage());
    }

    // ── Write stamp file ────────────────────────────────────────────
    // Stamp is written by db_ensure_schema after verifying core tables.
    // When run from CLI, write it here directly.
    if (php_sapi_name() === 'cli') {
        $stampDir = defined('CLIPTV_RUNTIME_DIR') ? CLIPTV_RUNTIME_DIR : '/tmp/cliptv';
        if (!is_dir($stampDir)) @mkdir($stampDir, 0755, true);
        file_put_contents($stampDir . '/db_bootstrapped.stamp', date('c') . "\n");
    }
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
