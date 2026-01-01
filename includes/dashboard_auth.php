<?php
/**
 * dashboard_auth.php - Authentication helper for streamer dashboard
 *
 * Two-tier access via Twitch OAuth:
 * 1. Super Admin - thearsondragon, cliparchive - full access to all channels
 * 2. Streamer/Mod - access via OAuth (own channel or channel_mods table)
 *
 * Note: ADMIN_KEY and streamer_key authentication have been removed.
 * All authentication now uses Twitch OAuth via twitch_oauth.php
 */

require_once __DIR__ . '/../db_config.php';

class DashboardAuth {
    const ROLE_NONE = 0;
    const ROLE_MOD = 1;
    const ROLE_STREAMER = 2;
    const ROLE_ADMIN = 3;

    private $pdo;
    private $role = self::ROLE_NONE;
    private $login = '';

    public function __construct() {
        $this->pdo = get_db_connection();
        $this->ensureTableExists();
    }

    /**
     * Create streamers table if it doesn't exist
     */
    private function ensureTableExists() {
        if (!$this->pdo) return;

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS streamers (
                    login VARCHAR(50) PRIMARY KEY,
                    streamer_key VARCHAR(64) UNIQUE NOT NULL,
                    instance VARCHAR(32),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_streamers_key ON streamers(streamer_key)");
            // Add instance column if it doesn't exist (for existing tables)
            $this->pdo->exec("ALTER TABLE streamers ADD COLUMN IF NOT EXISTS instance VARCHAR(32)");
        } catch (PDOException $e) {
            // Table might already exist, ignore
        }
    }

    /**
     * Set role for permission checks (called after OAuth validation)
     */
    public function setRole($role, $login = '') {
        $this->role = $role;
        $this->login = $login;
    }

    /**
     * Check if current role can perform an action
     */
    public function canDo($action) {
        $permissions = [
            'view' => self::ROLE_MOD,
            'change_hud' => self::ROLE_MOD,
            'toggle_voting' => self::ROLE_MOD,
            'toggle_commands' => self::ROLE_STREAMER,
            'block_clip' => self::ROLE_MOD,
            'add_blocked_words' => self::ROLE_STREAMER,
            'add_blocked_clippers' => self::ROLE_STREAMER,
            'refresh_clips' => self::ROLE_STREAMER,
            'manage_playlists' => self::ROLE_MOD,
            'manage_mods' => self::ROLE_STREAMER,
            'access_other_channels' => self::ROLE_ADMIN,
        ];

        $required = $permissions[$action] ?? self::ROLE_ADMIN;
        return $this->role >= $required;
    }

    public function getRole() { return $this->role; }
    public function getLogin() { return $this->login; }
    public function getRoleName() {
        switch ($this->role) {
            case self::ROLE_ADMIN: return 'admin';
            case self::ROLE_STREAMER: return 'streamer';
            case self::ROLE_MOD: return 'mod';
            default: return 'none';
        }
    }

    /**
     * Generate a new instance ID (for command isolation)
     */
    public static function generateInstance() {
        return bin2hex(random_bytes(8)); // 16 char hex string
    }

    /**
     * Create or update a streamer entry
     * Used when archiving a new streamer
     */
    public function createStreamer($login) {
        if (!$this->pdo) return false;

        // Generate a streamer_key for database compatibility (legacy column)
        $key = bin2hex(random_bytes(16));
        $instance = self::generateInstance();

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO streamers (login, streamer_key, instance)
                VALUES (?, ?, ?)
                ON CONFLICT (login) DO UPDATE SET
                    instance = COALESCE(streamers.instance, EXCLUDED.instance)
                RETURNING login
            ");
            $stmt->execute([$login, $key, $instance]);
            return $stmt->fetchColumn() ? true : false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get streamer instance for a login
     * Creates the streamer row if needed (for channels with clips but no dashboard entry)
     */
    public function getStreamerInstance($login) {
        if (!$this->pdo || !$login) return null;

        try {
            $stmt = $this->pdo->prepare("SELECT instance FROM streamers WHERE login = ?");
            $stmt->execute([$login]);
            $instance = $stmt->fetchColumn();

            // Generate instance if missing
            if (!$instance) {
                $instance = self::generateInstance();
                // Use upsert to handle both existing rows without instance and new rows
                $key = bin2hex(random_bytes(16)); // Legacy column
                $stmt = $this->pdo->prepare("
                    INSERT INTO streamers (login, streamer_key, instance)
                    VALUES (?, ?, ?)
                    ON CONFLICT (login) DO UPDATE SET instance = COALESCE(streamers.instance, EXCLUDED.instance)
                    RETURNING instance
                ");
                $stmt->execute([$login, $key, $instance]);
                $instance = $stmt->fetchColumn() ?: $instance;
            }

            return $instance ?: null;
        } catch (PDOException $e) {
            error_log("getStreamerInstance error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a streamer exists in the database
     */
    public function streamerExists($login) {
        if (!$this->pdo || !$login) return false;

        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM streamers WHERE login = ?");
            $stmt->execute([$login]);
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }
}
