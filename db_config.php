<?php
/**
 * db_config.php - Database connection for persistent vote storage
 *
 * Railway provides DATABASE_URL environment variable when PostgreSQL is added.
 * Format: postgresql://user:password@host:port/database
 */

function get_db_connection() {
    $dbUrl = getenv('DATABASE_URL');

    if (!$dbUrl) {
        // Return null if no database configured - will fall back to file storage
        return null;
    }

    // Parse the DATABASE_URL
    $parsed = parse_url($dbUrl);

    if (!$parsed) {
        error_log("Failed to parse DATABASE_URL");
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
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Initialize the votes tables if they don't exist
 */
function init_votes_tables($pdo) {
    if (!$pdo) return false;

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

        return true;
    } catch (PDOException $e) {
        error_log("Failed to create tables: " . $e->getMessage());
        return false;
    }
}
