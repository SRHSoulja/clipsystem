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

        return true;
    } catch (PDOException $e) {
        error_log("Failed to create tables: " . $e->getMessage());
        return false;
    }
}
