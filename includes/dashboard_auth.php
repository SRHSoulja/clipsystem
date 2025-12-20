<?php
/**
 * dashboard_auth.php - Authentication helper for streamer dashboard
 *
 * Three-tier access:
 * 1. Super Admin - ADMIN_KEY env var, full access to all channels
 * 2. Streamer - unique streamer_key, full access to own channel
 * 3. Mod - channel's mod_password, limited access to channel
 */

require_once __DIR__ . '/../db_config.php';

class DashboardAuth {
    const ROLE_NONE = 0;
    const ROLE_MOD = 1;
    const ROLE_STREAMER = 2;
    const ROLE_ADMIN = 3;

    private $pdo;
    private $adminKey;
    private $role = self::ROLE_NONE;
    private $login = '';

    public function __construct() {
        $this->pdo = get_db_connection();
        $this->adminKey = getenv('ADMIN_KEY') ?: '';
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
                    mod_password VARCHAR(64),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_streamers_key ON streamers(streamer_key)");
        } catch (PDOException $e) {
            // Table might already exist, ignore
        }
    }

    /**
     * Authenticate with a key parameter
     * Returns: ['role' => int, 'login' => string] or false
     */
    public function authenticateWithKey($key, $requestedLogin = null) {
        if (!$key || !$this->pdo) {
            return false;
        }

        // Check if it's the super admin key
        if ($this->adminKey && $key === $this->adminKey) {
            $this->role = self::ROLE_ADMIN;
            $this->login = $requestedLogin ?: '';
            return ['role' => self::ROLE_ADMIN, 'login' => $this->login];
        }

        // Check if it's a streamer key
        try {
            $stmt = $this->pdo->prepare("SELECT login FROM streamers WHERE streamer_key = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $this->role = self::ROLE_STREAMER;
                $this->login = $row['login'];
                return ['role' => self::ROLE_STREAMER, 'login' => $this->login];
            }
        } catch (PDOException $e) {
            // Table might not exist yet
        }

        return false;
    }

    /**
     * Authenticate with mod password
     * Returns: ['role' => int, 'login' => string] or false
     */
    public function authenticateWithPassword($login, $password) {
        if (!$login || !$password || !$this->pdo) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT mod_password FROM streamers WHERE login = ?");
            $stmt->execute([$login]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && $row['mod_password'] && $row['mod_password'] === $password) {
                $this->role = self::ROLE_MOD;
                $this->login = $login;
                return ['role' => self::ROLE_MOD, 'login' => $login];
            }
        } catch (PDOException $e) {
            // Table might not exist yet
        }

        return false;
    }

    /**
     * Check if current role can perform an action
     */
    public function canDo($action) {
        $permissions = [
            'view' => self::ROLE_MOD,
            'change_hud' => self::ROLE_MOD,
            'toggle_voting' => self::ROLE_MOD,
            'block_clip' => self::ROLE_MOD,
            'add_blocked_words' => self::ROLE_STREAMER,
            'add_blocked_clippers' => self::ROLE_STREAMER,
            'refresh_clips' => self::ROLE_STREAMER,
            'manage_playlists' => self::ROLE_STREAMER,
            'change_mod_password' => self::ROLE_STREAMER,
            'regenerate_key' => self::ROLE_ADMIN,
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
     * Generate a new streamer key
     */
    public static function generateKey() {
        return bin2hex(random_bytes(16)); // 32 char hex string
    }

    /**
     * Create or update a streamer entry
     */
    public function createStreamer($login) {
        if (!$this->pdo) return false;

        $key = self::generateKey();

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO streamers (login, streamer_key)
                VALUES (?, ?)
                ON CONFLICT (login) DO UPDATE SET streamer_key = EXCLUDED.streamer_key
                RETURNING streamer_key
            ");
            $stmt->execute([$login, $key]);
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get streamer key for a login
     */
    public function getStreamerKey($login) {
        if (!$this->pdo) return null;

        try {
            $stmt = $this->pdo->prepare("SELECT streamer_key FROM streamers WHERE login = ?");
            $stmt->execute([$login]);
            return $stmt->fetchColumn() ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Set mod password for a channel
     */
    public function setModPassword($login, $password) {
        if (!$this->pdo) return false;

        try {
            $stmt = $this->pdo->prepare("UPDATE streamers SET mod_password = ? WHERE login = ?");
            $stmt->execute([$password ?: null, $login]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}
