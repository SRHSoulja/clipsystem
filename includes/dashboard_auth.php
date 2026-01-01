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

    // Default permissions granted when a mod is added
    const DEFAULT_MOD_PERMISSIONS = [
        'view_dashboard',
        'manage_playlists',
        'block_clips'
    ];

    // All available permissions
    const ALL_PERMISSIONS = [
        'view_dashboard' => 'Access dashboard',
        'manage_playlists' => 'Create/edit playlists',
        'block_clips' => 'Hide individual clips',
        'edit_hud' => 'Change overlay positions',
        'edit_voting' => 'Toggle voting settings',
        'edit_weighting' => 'Modify clip weights',
        'edit_bot_settings' => 'Bot response mode',
        'view_stats' => 'Access stats tab',
        'toggle_commands' => 'Enable/disable commands'
    ];

    /**
     * Create tables if they don't exist
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

            // Create mod_permissions table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS mod_permissions (
                    id SERIAL PRIMARY KEY,
                    channel_login VARCHAR(64) NOT NULL,
                    mod_username VARCHAR(64) NOT NULL,
                    permission VARCHAR(64) NOT NULL,
                    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(channel_login, mod_username, permission)
                )
            ");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_mod_permissions_channel ON mod_permissions(channel_login)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_mod_permissions_mod ON mod_permissions(mod_username)");
        } catch (PDOException $e) {
            // Tables might already exist, ignore
        }
    }

    /**
     * Set role for permission checks (called after OAuth validation)
     */
    public function setRole($role, $login = '') {
        $this->role = $role;
        $this->login = $login;
    }

    // Map actions to permission names
    private static $actionToPermission = [
        'view' => 'view_dashboard',
        'change_hud' => 'edit_hud',
        'toggle_voting' => 'edit_voting',
        'toggle_commands' => 'toggle_commands',
        'block_clip' => 'block_clips',
        'add_blocked_words' => null, // Streamer only
        'add_blocked_clippers' => null, // Streamer only
        'refresh_clips' => null, // Streamer only
        'manage_playlists' => 'manage_playlists',
        'manage_mods' => null, // Streamer only
        'access_other_channels' => null, // Admin only
        'edit_weighting' => 'edit_weighting',
        'edit_bot_settings' => 'edit_bot_settings',
        'view_stats' => 'view_stats',
    ];

    /**
     * Check if current role can perform an action
     * For mods, checks the mod_permissions table
     */
    public function canDo($action, $channelLogin = null) {
        // Streamer-only actions
        $streamerOnly = ['add_blocked_words', 'add_blocked_clippers', 'refresh_clips', 'manage_mods'];
        if (in_array($action, $streamerOnly)) {
            return $this->role >= self::ROLE_STREAMER;
        }

        // Admin-only actions
        if ($action === 'access_other_channels') {
            return $this->role >= self::ROLE_ADMIN;
        }

        // Admin and Streamer always have full access
        if ($this->role >= self::ROLE_STREAMER) {
            return true;
        }

        // For mods, check the mod_permissions table
        if ($this->role === self::ROLE_MOD) {
            $permName = self::$actionToPermission[$action] ?? null;
            if (!$permName) {
                return false; // Unknown action or not allowed for mods
            }
            $channel = $channelLogin ?: $this->login;
            return $this->hasModPermission($channel, $permName);
        }

        return false;
    }

    /**
     * Check if a mod has a specific permission for a channel
     */
    public function hasModPermission($channelLogin, $permission, $modUsername = null) {
        if (!$this->pdo) return false;

        // Use the current user's OAuth info if mod username not specified
        if (!$modUsername) {
            require_once __DIR__ . '/twitch_oauth.php';
            $user = getCurrentUser();
            if (!$user) return false;
            $modUsername = strtolower($user['login']);
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT 1 FROM mod_permissions
                WHERE channel_login = ? AND mod_username = ? AND permission = ?
            ");
            $stmt->execute([strtolower($channelLogin), strtolower($modUsername), $permission]);
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get all permissions for a mod on a channel
     */
    public function getModPermissions($channelLogin, $modUsername) {
        if (!$this->pdo) return [];

        try {
            $stmt = $this->pdo->prepare("
                SELECT permission FROM mod_permissions
                WHERE channel_login = ? AND mod_username = ?
            ");
            $stmt->execute([strtolower($channelLogin), strtolower($modUsername)]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Grant a permission to a mod
     */
    public function grantModPermission($channelLogin, $modUsername, $permission) {
        if (!$this->pdo) return false;
        if (!isset(self::ALL_PERMISSIONS[$permission])) return false;

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO mod_permissions (channel_login, mod_username, permission)
                VALUES (?, ?, ?)
                ON CONFLICT (channel_login, mod_username, permission) DO NOTHING
            ");
            $stmt->execute([strtolower($channelLogin), strtolower($modUsername), $permission]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Revoke a permission from a mod
     */
    public function revokeModPermission($channelLogin, $modUsername, $permission) {
        if (!$this->pdo) return false;

        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM mod_permissions
                WHERE channel_login = ? AND mod_username = ? AND permission = ?
            ");
            $stmt->execute([strtolower($channelLogin), strtolower($modUsername), $permission]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Grant default permissions when adding a mod
     */
    public function grantDefaultModPermissions($channelLogin, $modUsername) {
        foreach (self::DEFAULT_MOD_PERMISSIONS as $perm) {
            $this->grantModPermission($channelLogin, $modUsername, $perm);
        }
    }

    /**
     * Remove all permissions for a mod (when removing them)
     */
    public function revokeAllModPermissions($channelLogin, $modUsername) {
        if (!$this->pdo) return false;

        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM mod_permissions
                WHERE channel_login = ? AND mod_username = ?
            ");
            $stmt->execute([strtolower($channelLogin), strtolower($modUsername)]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get all mods with their permissions for a channel
     */
    public function getChannelModsWithPermissions($channelLogin) {
        if (!$this->pdo) return [];

        try {
            // Get all mods from channel_mods table
            $stmt = $this->pdo->prepare("
                SELECT mod_username, added_at FROM channel_mods
                WHERE channel_login = ?
                ORDER BY mod_username
            ");
            $stmt->execute([strtolower($channelLogin)]);
            $mods = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get permissions for each mod
            foreach ($mods as &$mod) {
                $mod['permissions'] = $this->getModPermissions($channelLogin, $mod['mod_username']);
            }

            return $mods;
        } catch (PDOException $e) {
            return [];
        }
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
