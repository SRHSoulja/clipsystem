<?php
/**
 * db_config.php - Database connection for persistent vote storage
 *
 * Railway provides DATABASE_URL environment variable when PostgreSQL is added.
 * Format: postgresql://user:password@host:port/database
 */

// Runtime directory for lock/stamp files. /tmp is writable on Railway
// (the app directory /app is read-only after build).
define('CLIPTV_RUNTIME_DIR', '/tmp/cliptv');

/**
 * Check if database schema has been bootstrapped this deploy.
 * Returns true if the stamp file exists (schema is ready).
 */
function db_is_bootstrapped() {
    static $confirmed = false;
    // Once we've seen the stamp, it will never disappear within this
    // request (filesystem is not cleared mid-request). Cache true only;
    // false must re-check so concurrent waiters see the stamp as soon
    // as the bootstrap runner writes it.
    if ($confirmed) return true;
    if (file_exists(CLIPTV_RUNTIME_DIR . '/db_bootstrapped.stamp')) {
        $confirmed = true;
        return true;
    }
    return false;
}

/**
 * Ensure schema is ready before any query runs.
 *
 * Behavior on the FIRST request(s) after deploy:
 *   - One process acquires flock(LOCK_EX), runs bootstrap, writes stamp.
 *   - Concurrent processes block on flock(LOCK_EX) until the winner finishes,
 *     then see the stamp and return immediately.
 *   - If bootstrap fails, the winner sends 503 and exits. Blocked processes
 *     re-check the stamp (still missing) and also send 503. Next request
 *     retries the bootstrap from scratch.
 *   - Timeout: 10 seconds. If the lock is held longer than that (stuck
 *     bootstrap), the waiter gives up and sends 503 rather than proceeding
 *     into missing tables.
 *
 * Behavior on all subsequent requests:
 *   - file_exists() returns true → function returns immediately (~0ms).
 */
function db_ensure_schema($pdo) {
    if (!$pdo || db_is_bootstrapped()) return;

    $runtimeDir = CLIPTV_RUNTIME_DIR;
    if (!is_dir($runtimeDir)) @mkdir($runtimeDir, 0755, true);

    $lockFile = $runtimeDir . '/db_bootstrap.lock';
    $fp = @fopen($lockFile, 'c+');
    if (!$fp) {
        _db_bootstrap_unavailable('Could not open lock file');
    }

    // Blocking lock with timeout — all concurrent requests queue here.
    // PHP's flock() has no native timeout, so we poll with LOCK_NB.
    $deadline = microtime(true) + 10.0; // 10 second timeout
    $acquired = false;
    while (microtime(true) < $deadline) {
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            $acquired = true;
            break;
        }
        usleep(50000); // 50ms between polls
    }

    if (!$acquired) {
        fclose($fp);
        // Check stamp one more time — bootstrap may have finished between
        // our last poll and the timeout.
        if (db_is_bootstrapped()) return;
        _db_bootstrap_unavailable('Schema bootstrap timed out (10s)');
    }

    // Lock acquired. Re-check stamp — a prior holder may have finished.
    if (db_is_bootstrapped()) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    // We are the bootstrap runner.
    try {
        require_once __DIR__ . '/db_bootstrap.php';
        run_db_bootstrap($pdo);
    } catch (Exception $e) {
        error_log("db_ensure_schema: bootstrap exception: " . $e->getMessage());
    }

    // Verify core tables exist before writing stamp.
    // Only these 4 tables are required for hot endpoints.
    $coreTables = ['sync_state', 'cliptv_viewers', 'cliptv_chat', 'cliptv_requests'];
    $missing = [];
    foreach ($coreTables as $table) {
        try {
            $pdo->query("SELECT 1 FROM {$table} LIMIT 0");
        } catch (PDOException $e) {
            $missing[] = $table;
        }
    }

    if ($missing) {
        flock($fp, LOCK_UN);
        fclose($fp);
        $list = implode(', ', $missing);
        error_log("db_ensure_schema: core tables missing after bootstrap: {$list}");
        _db_bootstrap_unavailable("Core tables missing: {$list}");
    }

    // Core tables verified — write stamp. Optional table failures
    // (init_votes_tables etc.) were logged but don't block.
    if (!file_exists($runtimeDir . '/db_bootstrapped.stamp')) {
        file_put_contents($runtimeDir . '/db_bootstrapped.stamp', date('c') . "\n");
    }

    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Fail closed: send 503 and exit. Never proceed into a request with
 * missing tables — that would produce confusing SQL errors for the user.
 */
function _db_bootstrap_unavailable($reason) {
    error_log("db_ensure_schema: " . $reason);
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json');
        header('Retry-After: 2');
    }
    echo json_encode([
        'error' => 'Service starting up, please retry in a moment.',
        'retry_after' => 2
    ]);
    exit;
}

function get_db_connection() {
    static $cached = null;

    if ($cached !== null) {
        return $cached ?: null;
    }

    $dbUrl = getenv('DATABASE_URL');

    if (!$dbUrl) {
        // Return null if no database configured - will fall back to file storage
        $cached = false;
        return null;
    }

    // Parse the DATABASE_URL
    $parsed = parse_url($dbUrl);

    if (!$parsed) {
        error_log("Failed to parse DATABASE_URL");
        $cached = false;
        return null;
    }

    $host = $parsed['host'] ?? 'localhost';
    $port = $parsed['port'] ?? 5432;
    $user = $parsed['user'] ?? '';
    $pass = $parsed['pass'] ?? '';
    $dbname = ltrim($parsed['path'] ?? '', '/');

    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $cached = $pdo;
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        $cached = false;
        return null;
    }
}

/**
 * Initialize the votes tables if they don't exist.
 * Skipped when db_bootstrap has already run (stamp file exists).
 */
function init_votes_tables($pdo) {
    if (!$pdo) return false;
    if (db_is_bootstrapped()) return true;

    try {
        // Votes table - stores aggregate vote counts per clip
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS votes (
                id SERIAL PRIMARY KEY,
                login VARCHAR(64) NOT NULL,
                clip_id VARCHAR(255) NOT NULL,
                seq INTEGER NOT NULL,
                title TEXT,
                up_votes INTEGER DEFAULT 0,
                down_votes INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(login, clip_id)
            )
        ");

        // Vote ledger - tracks individual votes to prevent duplicates
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vote_ledger (
                id SERIAL PRIMARY KEY,
                login VARCHAR(64) NOT NULL,
                clip_id VARCHAR(255) NOT NULL,
                username VARCHAR(64) NOT NULL,
                vote_dir VARCHAR(10) NOT NULL,
                voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(login, clip_id, username)
            )
        ");

        // Index for faster lookups
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_votes_login ON votes(login)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_votes_seq ON votes(login, seq)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ledger_lookup ON vote_ledger(login, clip_id, username)");

        // Blocklist table - stores permanently removed clips
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS blocklist (
                id SERIAL PRIMARY KEY,
                login VARCHAR(64) NOT NULL,
                clip_id VARCHAR(255) NOT NULL,
                seq INTEGER NOT NULL,
                title TEXT,
                removed_by VARCHAR(64),
                removed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(login, clip_id)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_blocklist_login ON blocklist(login)");

        // Playlist active table - tracks currently playing playlist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS playlist_active (
                id SERIAL PRIMARY KEY,
                login VARCHAR(64) NOT NULL UNIQUE,
                playlist_id INTEGER NOT NULL,
                current_index INTEGER DEFAULT 0,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Games cache table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS games_cache (
                game_id VARCHAR(32) PRIMARY KEY,
                name VARCHAR(255),
                box_art_url TEXT,
                fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Clip plays table - tracks when clips were last played for rotation
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS clip_plays (
                id SERIAL PRIMARY KEY,
                login VARCHAR(64) NOT NULL,
                clip_id VARCHAR(255) NOT NULL,
                play_count INTEGER DEFAULT 1,
                last_played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(login, clip_id)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clip_plays_login ON clip_plays(login)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clip_plays_last ON clip_plays(login, last_played_at)");

        // Skip events table - tracks when clips are skipped (vote, mod, error)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS skip_events (
                id SERIAL PRIMARY KEY,
                login VARCHAR(64) NOT NULL,
                clip_id VARCHAR(256) NOT NULL,
                skip_type VARCHAR(16) NOT NULL DEFAULT 'vote',
                skipped_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_skip_events_login ON skip_events(login)");

        // Command requests table - replaces file-based state for reliability at scale
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS command_requests (
                login VARCHAR(64) NOT NULL,
                command_type VARCHAR(32) NOT NULL,
                payload JSONB,
                nonce VARCHAR(32),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                consumed BOOLEAN DEFAULT FALSE,
                PRIMARY KEY (login, command_type)
            )
        ");

        // Optimized index for clips pagination with blocked filter
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clips_active_seq ON clips(login, seq DESC) WHERE blocked = false");

        // Channel mods table - stores authorized moderators per channel
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS channel_mods (
                id SERIAL PRIMARY KEY,
                channel_login VARCHAR(64) NOT NULL,
                mod_username VARCHAR(64) NOT NULL,
                added_by VARCHAR(64),
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(channel_login, mod_username)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_channel_mods_channel ON channel_mods(channel_login)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_channel_mods_mod ON channel_mods(mod_username)");

        // Page views table - tracks visitors to search/browse pages
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS page_views (
                id SERIAL PRIMARY KEY,
                login VARCHAR(64) NOT NULL,
                page VARCHAR(32) NOT NULL DEFAULT 'search',
                viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_views_login ON page_views(login)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_views_login_page ON page_views(login, page)");

        // Known users table - tracks all Twitch users who have logged in
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS known_users (
                id SERIAL PRIMARY KEY,
                twitch_id VARCHAR(64) NOT NULL UNIQUE,
                login VARCHAR(64) NOT NULL,
                display_name VARCHAR(64),
                profile_image_url TEXT,
                user_type VARCHAR(16) NOT NULL DEFAULT 'viewer',
                first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                login_count INTEGER DEFAULT 1
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_known_users_login ON known_users(login)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_known_users_type ON known_users(user_type)");

        // Viewer peaks table - tracks peak concurrent viewer counts per channel
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS viewer_peaks (
                login VARCHAR(64) PRIMARY KEY,
                peak_viewers INTEGER DEFAULT 0,
                peak_at TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Add silent_prefix column to channel_settings if it doesn't exist
        try {
            $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS silent_prefix BOOLEAN DEFAULT FALSE");
        } catch (PDOException $e) {
            // Column might already exist
        }

        // Suspicious voters table - tracks flagged accounts and vote activity
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS suspicious_voters (
                id SERIAL PRIMARY KEY,
                username VARCHAR(64) NOT NULL UNIQUE,
                twitch_user_id VARCHAR(64),
                total_votes INTEGER DEFAULT 0,
                votes_last_hour INTEGER DEFAULT 0,
                votes_last_day INTEGER DEFAULT 0,
                downvote_ratio NUMERIC(5,4) DEFAULT 0,
                first_vote_at TIMESTAMP,
                last_vote_at TIMESTAMP,
                flagged BOOLEAN DEFAULT FALSE,
                flag_reason TEXT,
                flagged_at TIMESTAMP,
                reviewed BOOLEAN DEFAULT FALSE,
                reviewed_by VARCHAR(64),
                reviewed_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_suspicious_flagged ON suspicious_voters(flagged, reviewed)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_suspicious_username ON suspicious_voters(username)");

        // Vote rate limit tracking - for real-time rate limiting
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vote_rate_limits (
                username VARCHAR(64) PRIMARY KEY,
                vote_count INTEGER DEFAULT 0,
                window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Live clips cache - stores Twitch API results for non-archived streamers
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS clips_live_cache (
                id SERIAL PRIMARY KEY,
                login VARCHAR(64) NOT NULL,
                clip_id VARCHAR(255) NOT NULL,
                title TEXT,
                duration INTEGER,
                created_at TIMESTAMP,
                view_count INTEGER DEFAULT 0,
                game_id VARCHAR(64),
                thumbnail_url TEXT,
                creator_name VARCHAR(64),
                url TEXT,
                cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(login, clip_id)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_live_cache_login_cached ON clips_live_cache(login, cached_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_live_cache_login_views ON clips_live_cache(login, view_count DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_live_cache_login_date ON clips_live_cache(login, created_at DESC)");

        // Self-service archive jobs - tracks archive progress per streamer
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS archive_jobs (
                id SERIAL PRIMARY KEY,
                login VARCHAR(64) NOT NULL,
                broadcaster_id VARCHAR(64),
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                started_by VARCHAR(64),
                total_windows INTEGER DEFAULT 0,
                current_window INTEGER DEFAULT 0,
                clips_found INTEGER DEFAULT 0,
                clips_inserted INTEGER DEFAULT 0,
                error_message TEXT,
                archive_start TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP,
                UNIQUE(login)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_archive_jobs_status ON archive_jobs(status)");
        // Add archive_start column if table already existed
        try { $pdo->exec("ALTER TABLE archive_jobs ADD COLUMN IF NOT EXISTS archive_start TIMESTAMP"); } catch (PDOException $e) { /* already exists */ }

        return true;
    } catch (PDOException $e) {
        error_log("Failed to create tables: " . $e->getMessage());
        return false;
    }
}
