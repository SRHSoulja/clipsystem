# Streamer Dashboard Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a self-service dashboard where streamers can manage their clip reel settings, filter content, create playlists, and view stats without needing admin access.

**Architecture:** Extends the existing mod_dashboard.php pattern with three-tier authentication (super admin, streamer, mod). Uses existing playlist_api.php and clips_api.php as foundation. Adds new filtering columns to channel_settings table.

**Tech Stack:** PHP 8, PostgreSQL, vanilla JavaScript, existing Twitch-themed CSS

---

## Phase 1: Database Schema & Authentication Foundation

### Task 1: Create streamers table

**Files:**
- Create: `c:\Users\Eric\Downloads\clipsystem\setup_streamers_table.php`

**Step 1: Create the migration script**

```php
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
```

**Step 2: Run the migration**

Run: `php c:\Users\Eric\Downloads\clipsystem\setup_streamers_table.php`
Expected: "Done!" message

**Step 3: Commit**

```bash
git add setup_streamers_table.php
git commit -m "feat: add streamers table for dashboard authentication"
```

---

### Task 2: Add filtering columns to channel_settings

**Files:**
- Modify: `c:\Users\Eric\Downloads\clipsystem\hud_position.php` (add column migrations)

**Step 1: Add column creation to hud_position.php**

In `hud_position.php`, after the `top_position` column creation (around line 68), add:

```php
      // Add filtering columns if they don't exist
      try {
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS blocked_words TEXT DEFAULT '[]'");
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS blocked_clippers TEXT DEFAULT '[]'");
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS voting_enabled BOOLEAN DEFAULT TRUE");
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS last_refresh TIMESTAMP");
      } catch (PDOException $e) {
        // Columns might already exist, ignore
      }
```

**Step 2: Test by hitting the endpoint**

Run: `curl "http://localhost/hud_position.php?login=test"`
Expected: JSON response with position data (columns created silently)

**Step 3: Commit**

```bash
git add hud_position.php
git commit -m "feat: add filtering columns to channel_settings"
```

---

### Task 3: Create dashboard authentication helper

**Files:**
- Create: `c:\Users\Eric\Downloads\clipsystem\includes\dashboard_auth.php`

**Step 1: Write the authentication helper**

```php
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
```

**Step 2: Commit**

```bash
git add includes/dashboard_auth.php
git commit -m "feat: add dashboard authentication helper with 3-tier access"
```

---

## Phase 2: Dashboard API

### Task 4: Create dashboard_api.php

**Files:**
- Create: `c:\Users\Eric\Downloads\clipsystem\dashboard_api.php`

**Step 1: Write the dashboard API**

```php
<?php
/**
 * dashboard_api.php - API for streamer dashboard
 *
 * Handles all dashboard actions with proper permission checks.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/dashboard_auth.php';

function json_response($data) {
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error($msg, $code = 400) {
    http_response_code($code);
    json_response(["error" => $msg]);
}

function clean_login($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace("/[^a-z0-9_]/", "", $s);
    return $s ?: "";
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$key = $_GET['key'] ?? $_POST['key'] ?? '';
$login = clean_login($_GET['login'] ?? $_POST['login'] ?? '');
$password = $_GET['password'] ?? $_POST['password'] ?? '';

$auth = new DashboardAuth();
$authenticated = false;

// Try key authentication first
if ($key) {
    $result = $auth->authenticateWithKey($key, $login);
    if ($result) {
        $authenticated = true;
        $login = $result['login'];
    }
}

// Try password authentication if key failed and we have login
if (!$authenticated && $login && $password) {
    $result = $auth->authenticateWithPassword($login, $password);
    if ($result) {
        $authenticated = true;
    }
}

// Special case: login check doesn't require auth
if ($action === 'check_login') {
    // Just verify if the login/password or key is valid
    json_response([
        "authenticated" => $authenticated,
        "role" => $auth->getRoleName(),
        "login" => $auth->getLogin()
    ]);
}

if (!$authenticated) {
    json_error("Unauthorized", 401);
}

$pdo = get_db_connection();
if (!$pdo) {
    json_error("Database unavailable", 500);
}

switch ($action) {
    case 'get_settings':
        if (!$auth->canDo('view')) json_error("Permission denied", 403);

        try {
            $stmt = $pdo->prepare("
                SELECT hud_position, top_position, blocked_words, blocked_clippers, voting_enabled, last_refresh
                FROM channel_settings WHERE login = ?
            ");
            $stmt->execute([$login]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$settings) {
                $settings = [
                    'hud_position' => 'tr',
                    'top_position' => 'br',
                    'blocked_words' => '[]',
                    'blocked_clippers' => '[]',
                    'voting_enabled' => true,
                    'last_refresh' => null
                ];
            }

            // Parse JSON fields
            $settings['blocked_words'] = json_decode($settings['blocked_words'] ?: '[]', true) ?: [];
            $settings['blocked_clippers'] = json_decode($settings['blocked_clippers'] ?: '[]', true) ?: [];

            // Get clip stats
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) as total,
                    COUNT(*) FILTER (WHERE blocked = FALSE) as active,
                    COUNT(*) FILTER (WHERE blocked = TRUE) as blocked
                FROM clips WHERE login = ?
            ");
            $stmt->execute([$login]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            json_response([
                "settings" => $settings,
                "stats" => $stats,
                "role" => $auth->getRoleName()
            ]);
        } catch (PDOException $e) {
            json_error("Database error", 500);
        }
        break;

    case 'save_settings':
        $field = $_GET['field'] ?? $_POST['field'] ?? '';
        $value = $_GET['value'] ?? $_POST['value'] ?? '';

        $allowedFields = [
            'hud_position' => 'change_hud',
            'top_position' => 'change_hud',
            'voting_enabled' => 'toggle_voting',
            'blocked_words' => 'add_blocked_words',
            'blocked_clippers' => 'add_blocked_clippers'
        ];

        if (!isset($allowedFields[$field])) {
            json_error("Invalid field");
        }

        if (!$auth->canDo($allowedFields[$field])) {
            json_error("Permission denied", 403);
        }

        try {
            // Validate and format value based on field
            switch ($field) {
                case 'hud_position':
                case 'top_position':
                    if (!in_array($value, ['tr', 'tl', 'br', 'bl'])) $value = 'tr';
                    break;
                case 'voting_enabled':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'TRUE' : 'FALSE';
                    break;
                case 'blocked_words':
                case 'blocked_clippers':
                    // Expect JSON array
                    $arr = json_decode($value, true);
                    if (!is_array($arr)) $arr = [];
                    $value = json_encode(array_values(array_filter(array_map('trim', $arr))));
                    break;
            }

            $stmt = $pdo->prepare("
                INSERT INTO channel_settings (login, {$field}, updated_at)
                VALUES (?, ?, NOW())
                ON CONFLICT (login) DO UPDATE SET {$field} = ?, updated_at = NOW()
            ");
            $stmt->execute([$login, $value, $value]);

            json_response(["success" => true]);
        } catch (PDOException $e) {
            json_error("Database error: " . $e->getMessage(), 500);
        }
        break;

    case 'set_mod_password':
        if (!$auth->canDo('change_mod_password')) {
            json_error("Permission denied", 403);
        }

        $newPassword = $_GET['new_password'] ?? $_POST['new_password'] ?? '';

        if ($auth->setModPassword($login, $newPassword)) {
            json_response(["success" => true, "message" => $newPassword ? "Mod password set" : "Mod password removed"]);
        } else {
            json_error("Failed to update password");
        }
        break;

    case 'refresh_clips':
        if (!$auth->canDo('refresh_clips')) {
            json_error("Permission denied", 403);
        }

        // Redirect to refresh_clips.php which handles the actual work
        // We'll modify refresh_clips.php to accept streamer keys
        json_response([
            "redirect" => "refresh_clips.php?login=" . urlencode($login) . "&key=" . urlencode($key)
        ]);
        break;

    case 'block_clip':
        if (!$auth->canDo('block_clip')) {
            json_error("Permission denied", 403);
        }

        $seq = (int)($_GET['seq'] ?? $_POST['seq'] ?? 0);
        $blocked = filter_var($_GET['blocked'] ?? $_POST['blocked'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if ($seq <= 0) json_error("Invalid clip seq");

        try {
            $stmt = $pdo->prepare("UPDATE clips SET blocked = ? WHERE login = ? AND seq = ?");
            $stmt->execute([$blocked, $login, $seq]);

            if ($stmt->rowCount() === 0) {
                json_error("Clip not found", 404);
            }

            json_response(["success" => true, "seq" => $seq, "blocked" => $blocked]);
        } catch (PDOException $e) {
            json_error("Database error", 500);
        }
        break;

    case 'block_clipper':
        if (!$auth->canDo('add_blocked_clippers')) {
            json_error("Permission denied", 403);
        }

        $clipper = trim($_GET['clipper'] ?? $_POST['clipper'] ?? '');
        if (!$clipper) json_error("Missing clipper name");

        try {
            // Get current blocked clippers
            $stmt = $pdo->prepare("SELECT blocked_clippers FROM channel_settings WHERE login = ?");
            $stmt->execute([$login]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $clippers = json_decode($row['blocked_clippers'] ?? '[]', true) ?: [];

            if (!in_array($clipper, $clippers)) {
                $clippers[] = $clipper;
            }

            // Save updated list
            $stmt = $pdo->prepare("
                INSERT INTO channel_settings (login, blocked_clippers, updated_at)
                VALUES (?, ?, NOW())
                ON CONFLICT (login) DO UPDATE SET blocked_clippers = ?, updated_at = NOW()
            ");
            $json = json_encode(array_values($clippers));
            $stmt->execute([$login, $json, $json]);

            json_response(["success" => true, "blocked_clippers" => $clippers]);
        } catch (PDOException $e) {
            json_error("Database error", 500);
        }
        break;

    default:
        json_error("Unknown action: " . $action);
}
```

**Step 2: Commit**

```bash
git add dashboard_api.php
git commit -m "feat: add dashboard API with permission-based actions"
```

---

### Task 5: Modify refresh_clips.php to accept streamer keys

**Files:**
- Modify: `c:\Users\Eric\Downloads\clipsystem\refresh_clips.php`

**Step 1: Update authentication to accept streamer keys**

Replace lines 19-24 (the auth check) with:

```php
// Auth - accept either ADMIN_KEY or streamer's own key
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';
$key = $_GET['key'] ?? '';

$isAuthorized = false;
if ($key === $ADMIN_KEY && $ADMIN_KEY !== '') {
    $isAuthorized = true;
} else {
    // Check if it's the streamer's own key
    require_once __DIR__ . '/includes/dashboard_auth.php';
    $auth = new DashboardAuth();
    $result = $auth->authenticateWithKey($key, $login);
    if ($result && $result['login'] === $login) {
        $isAuthorized = true;
    }
}

if (!$isAuthorized) {
    die("Forbidden - invalid key");
}
```

**Step 2: Commit**

```bash
git add refresh_clips.php
git commit -m "feat: allow streamers to refresh their own clips with streamer key"
```

---

## Phase 3: Dashboard Frontend

### Task 6: Create dashboard.php

**Files:**
- Create: `c:\Users\Eric\Downloads\clipsystem\dashboard.php`

**Step 1: Write the dashboard frontend**

```php
<?php
/**
 * dashboard.php - Streamer Dashboard
 *
 * Self-service dashboard for streamers to manage their clip reel.
 * Access: dashboard.php?key=STREAMER_KEY or dashboard.php?login=username (+ mod password)
 */
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

require_once __DIR__ . '/db_config.php';

function clean_login($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace("/[^a-z0-9_]/", "", $s);
    return $s ?: "";
}

$key = $_GET['key'] ?? '';
$login = clean_login($_GET['login'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Streamer Dashboard - Clip Reel System</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0e0e10;
            color: #efeff1;
            min-height: 100vh;
        }

        /* Login Screen */
        .login-screen {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-box {
            background: #18181b;
            border-radius: 8px;
            padding: 32px;
            max-width: 400px;
            width: 100%;
        }
        .login-box h1 {
            margin-bottom: 24px;
            color: #9147ff;
        }
        .login-box input {
            width: 100%;
            padding: 12px;
            margin-bottom: 16px;
            border: 1px solid #3a3a3d;
            border-radius: 4px;
            background: #0e0e10;
            color: #efeff1;
            font-size: 16px;
        }
        .login-box button {
            width: 100%;
            padding: 12px;
            background: #9147ff;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
        }
        .login-box button:hover { background: #772ce8; }
        .error { color: #eb0400; margin-bottom: 16px; display: none; }

        /* Dashboard */
        .dashboard { display: none; }
        .dashboard.active { display: block; }

        .header {
            background: #18181b;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #3a3a3d;
        }
        .header h1 { font-size: 20px; color: #9147ff; }
        .header .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .header .role-badge {
            background: #9147ff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            text-transform: uppercase;
        }
        .header .role-badge.mod { background: #00ad03; }
        .header .role-badge.admin { background: #eb0400; }

        /* Tabs */
        .tabs {
            display: flex;
            background: #18181b;
            border-bottom: 1px solid #3a3a3d;
            padding: 0 24px;
        }
        .tab {
            padding: 16px 24px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            color: #adadb8;
        }
        .tab:hover { color: #efeff1; }
        .tab.active {
            color: #9147ff;
            border-bottom-color: #9147ff;
        }

        /* Tab Content */
        .tab-content {
            display: none;
            padding: 24px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .tab-content.active { display: block; }

        /* Cards */
        .card {
            background: #18181b;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card h3 {
            margin-bottom: 16px;
            color: #efeff1;
            font-size: 16px;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #adadb8;
            font-size: 14px;
        }
        input[type="text"], input[type="password"], select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #3a3a3d;
            border-radius: 4px;
            background: #0e0e10;
            color: #efeff1;
            font-size: 14px;
        }
        textarea { min-height: 80px; resize: vertical; }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            display: inline-block;
        }
        .btn-primary { background: #9147ff; color: white; }
        .btn-primary:hover { background: #772ce8; }
        .btn-secondary { background: #3a3a3d; color: #efeff1; }
        .btn-secondary:hover { background: #464649; }
        .btn-danger { background: #eb0400; color: white; }

        /* Position Picker */
        .position-picker {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            width: 200px;
        }
        .position-btn {
            padding: 20px;
            background: #26262c;
            border: 2px solid transparent;
            border-radius: 4px;
            color: #adadb8;
            cursor: pointer;
            text-align: center;
            font-size: 12px;
        }
        .position-btn:hover { background: #3a3a3d; }
        .position-btn.active {
            border-color: #9147ff;
            background: #9147ff33;
            color: #efeff1;
        }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
        }
        .stat-box {
            background: #26262c;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #9147ff;
        }
        .stat-label {
            color: #adadb8;
            font-size: 14px;
            margin-top: 4px;
        }

        /* Tags */
        .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        .tag {
            background: #3a3a3d;
            padding: 4px 12px;
            border-radius: 16px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tag .remove {
            cursor: pointer;
            color: #eb0400;
            font-weight: bold;
        }

        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #3a3a3d;
            border-radius: 26px;
            transition: 0.3s;
        }
        .toggle-slider:before {
            content: "";
            position: absolute;
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.3s;
        }
        .toggle-switch input:checked + .toggle-slider { background: #9147ff; }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(24px); }

        /* URL Box */
        .url-box {
            background: #0e0e10;
            padding: 12px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
            word-break: break-all;
            cursor: pointer;
        }
        .url-box:hover { background: #1a1a1d; }

        /* Success/Error Messages */
        .message {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        .message.success { background: rgba(0, 173, 3, 0.2); border: 1px solid #00ad03; }
        .message.error { background: rgba(235, 4, 0, 0.2); border: 1px solid #eb0400; }
    </style>
</head>
<body>
    <div class="login-screen" id="loginScreen">
        <div class="login-box">
            <h1>Streamer Dashboard</h1>
            <div class="error" id="loginError"></div>
            <?php if ($login): ?>
                <p style="color: #adadb8; margin-bottom: 16px;">Channel: <strong><?= htmlspecialchars($login) ?></strong></p>
                <input type="password" id="modPassword" placeholder="Mod Password" autofocus>
                <button onclick="loginWithPassword()">Enter</button>
            <?php else: ?>
                <p style="color: #adadb8; margin-bottom: 16px;">Enter your dashboard key or use ?login=username for mod access.</p>
                <input type="text" id="dashboardKey" placeholder="Dashboard Key" autofocus>
                <button onclick="loginWithKey()">Enter</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="dashboard" id="dashboard">
        <div class="header">
            <h1>Streamer Dashboard</h1>
            <div class="user-info">
                <span id="channelName"></span>
                <span class="role-badge" id="roleBadge">MOD</span>
            </div>
        </div>

        <div class="tabs">
            <div class="tab active" data-tab="settings">Settings</div>
            <div class="tab" data-tab="clips">Clip Management</div>
            <div class="tab" data-tab="playlists">Playlists</div>
            <div class="tab" data-tab="stats">Stats</div>
        </div>

        <div class="tab-content active" id="tab-settings">
            <div id="settingsMessage"></div>

            <div class="card">
                <h3>HUD Position</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Position of the clip info overlay on screen.</p>
                <div class="position-picker" id="hudPositionPicker">
                    <button class="position-btn" data-pos="tl">Top Left</button>
                    <button class="position-btn" data-pos="tr">Top Right</button>
                    <button class="position-btn" data-pos="bl">Bottom Left</button>
                    <button class="position-btn" data-pos="br">Bottom Right</button>
                </div>
            </div>

            <div class="card">
                <h3>Top Clips Overlay Position</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Position of the !ctop overlay.</p>
                <div class="position-picker" id="topPositionPicker">
                    <button class="position-btn" data-pos="tl">Top Left</button>
                    <button class="position-btn" data-pos="tr">Top Right</button>
                    <button class="position-btn" data-pos="bl">Bottom Left</button>
                    <button class="position-btn" data-pos="br">Bottom Right</button>
                </div>
            </div>

            <div class="card">
                <h3>Chat Voting</h3>
                <div style="display: flex; align-items: center; gap: 16px;">
                    <label class="toggle-switch">
                        <input type="checkbox" id="votingEnabled" onchange="saveVoting()">
                        <span class="toggle-slider"></span>
                    </label>
                    <span>Enable !like and !dislike commands</span>
                </div>
            </div>

            <div class="card" id="refreshCard">
                <h3>Refresh Clips</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Fetch new clips from Twitch.</p>
                <p style="color: #666; font-size: 13px; margin-bottom: 12px;">Last refresh: <span id="lastRefresh">Never</span></p>
                <button class="btn btn-primary" onclick="refreshClips()">Get New Clips</button>
            </div>

            <div class="card" id="modPasswordCard">
                <h3>Mod Password</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Set a password for mods to access this dashboard with limited permissions.</p>
                <div class="form-group">
                    <input type="password" id="newModPassword" placeholder="New mod password (leave empty to remove)">
                </div>
                <button class="btn btn-secondary" onclick="saveModPassword()">Update Password</button>
            </div>

            <div class="card">
                <h3>Player URL</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Use this URL as a Browser Source in OBS.</p>
                <div class="url-box" id="playerUrl" onclick="copyPlayerUrl()">Loading...</div>
                <p style="color: #666; font-size: 12px; margin-top: 8px;">Click to copy</p>
            </div>
        </div>

        <div class="tab-content" id="tab-clips">
            <div class="card">
                <h3>Content Filtering</h3>

                <div class="form-group" id="blockedWordsGroup">
                    <label>Blocked Words</label>
                    <p style="color: #666; font-size: 12px; margin-bottom: 8px;">Clips with these words in the title will be hidden.</p>
                    <input type="text" id="newBlockedWord" placeholder="Add word and press Enter" onkeypress="if(event.key==='Enter')addBlockedWord()">
                    <div class="tags" id="blockedWordsTags"></div>
                </div>

                <div class="form-group" id="blockedClippersGroup">
                    <label>Blocked Clippers</label>
                    <p style="color: #666; font-size: 12px; margin-bottom: 8px;">All clips from these users will be hidden.</p>
                    <input type="text" id="newBlockedClipper" placeholder="Add clipper and press Enter" onkeypress="if(event.key==='Enter')addBlockedClipper()">
                    <div class="tags" id="blockedClippersTags"></div>
                </div>
            </div>

            <div class="card">
                <h3>Individual Clip Management</h3>
                <p style="color: #adadb8; margin-bottom: 12px;">Use the <a href="clip_search.php" style="color: #9147ff;">Clip Browser</a> to search and manage individual clips.</p>
            </div>
        </div>

        <div class="tab-content" id="tab-playlists">
            <div class="card">
                <h3>Playlists</h3>
                <p style="color: #adadb8;">Use the <a href="mod_dashboard.php" style="color: #9147ff;">Mod Dashboard</a> to create and manage playlists.</p>
            </div>
        </div>

        <div class="tab-content" id="tab-stats">
            <div class="stats-grid" id="statsGrid">
                <div class="stat-box">
                    <div class="stat-value" id="statTotal">-</div>
                    <div class="stat-label">Total Clips</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" id="statActive">-</div>
                    <div class="stat-label">Active Clips</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" id="statBlocked">-</div>
                    <div class="stat-label">Blocked Clips</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '';
        const INITIAL_KEY = <?= json_encode($key) ?>;
        const INITIAL_LOGIN = <?= json_encode($login) ?>;

        let authKey = INITIAL_KEY;
        let authLogin = INITIAL_LOGIN;
        let authRole = '';
        let settings = {};

        // Auto-login if key provided
        if (INITIAL_KEY) {
            checkAuth(INITIAL_KEY, '');
        }

        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
            });
        });

        // Position picker
        document.querySelectorAll('.position-picker').forEach(picker => {
            picker.querySelectorAll('.position-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    picker.querySelectorAll('.position-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    const field = picker.id === 'hudPositionPicker' ? 'hud_position' : 'top_position';
                    saveSetting(field, btn.dataset.pos);
                });
            });
        });

        async function checkAuth(key, password) {
            try {
                let url = `${API_BASE}/dashboard_api.php?action=check_login`;
                if (key) url += `&key=${encodeURIComponent(key)}`;
                if (authLogin) url += `&login=${encodeURIComponent(authLogin)}`;
                if (password) url += `&password=${encodeURIComponent(password)}`;

                const res = await fetch(url);
                const data = await res.json();

                if (data.authenticated) {
                    authKey = key;
                    authLogin = data.login;
                    authRole = data.role;
                    showDashboard();
                    loadSettings();
                } else {
                    showError('Invalid credentials');
                }
            } catch (e) {
                showError('Connection error');
            }
        }

        function loginWithKey() {
            const key = document.getElementById('dashboardKey').value.trim();
            if (key) checkAuth(key, '');
        }

        function loginWithPassword() {
            const password = document.getElementById('modPassword').value;
            if (password) checkAuth('', password);
        }

        document.querySelectorAll('#dashboardKey, #modPassword').forEach(el => {
            if (el) el.addEventListener('keypress', e => {
                if (e.key === 'Enter') {
                    if (el.id === 'dashboardKey') loginWithKey();
                    else loginWithPassword();
                }
            });
        });

        function showError(msg) {
            const el = document.getElementById('loginError');
            el.textContent = msg;
            el.style.display = 'block';
        }

        function showDashboard() {
            document.getElementById('loginScreen').style.display = 'none';
            document.getElementById('dashboard').classList.add('active');
            document.getElementById('channelName').textContent = authLogin;

            const badge = document.getElementById('roleBadge');
            badge.textContent = authRole.toUpperCase();
            badge.className = 'role-badge ' + authRole;

            // Hide elements based on role
            if (authRole === 'mod') {
                document.getElementById('blockedWordsGroup').style.display = 'none';
                document.getElementById('blockedClippersGroup').style.display = 'none';
                document.getElementById('refreshCard').style.display = 'none';
                document.getElementById('modPasswordCard').style.display = 'none';
            }

            // Set player URL
            const playerUrl = `https://gmgnrepeat.com/flop/clipplayer_mp4_reel.html?login=${encodeURIComponent(authLogin)}`;
            document.getElementById('playerUrl').textContent = playerUrl;
        }

        async function loadSettings() {
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=get_settings&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}`);
                const data = await res.json();

                if (data.error) {
                    console.error('Error loading settings:', data.error);
                    return;
                }

                settings = data.settings;

                // HUD positions
                setPositionPicker('hudPositionPicker', settings.hud_position || 'tr');
                setPositionPicker('topPositionPicker', settings.top_position || 'br');

                // Voting
                document.getElementById('votingEnabled').checked = settings.voting_enabled;

                // Last refresh
                if (settings.last_refresh) {
                    document.getElementById('lastRefresh').textContent = new Date(settings.last_refresh).toLocaleString();
                }

                // Blocked words
                renderTags('blockedWordsTags', settings.blocked_words || [], removeBlockedWord);

                // Blocked clippers
                renderTags('blockedClippersTags', settings.blocked_clippers || [], removeBlockedClipper);

                // Stats
                if (data.stats) {
                    document.getElementById('statTotal').textContent = Number(data.stats.total).toLocaleString();
                    document.getElementById('statActive').textContent = Number(data.stats.active).toLocaleString();
                    document.getElementById('statBlocked').textContent = Number(data.stats.blocked).toLocaleString();
                }
            } catch (e) {
                console.error('Error loading settings:', e);
            }
        }

        function setPositionPicker(pickerId, pos) {
            const picker = document.getElementById(pickerId);
            picker.querySelectorAll('.position-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.pos === pos);
            });
        }

        function renderTags(containerId, items, removeCallback) {
            const container = document.getElementById(containerId);
            container.innerHTML = items.map(item => `
                <span class="tag">
                    ${escapeHtml(item)}
                    <span class="remove" onclick="${removeCallback.name}('${escapeHtml(item)}')">&times;</span>
                </span>
            `).join('');
        }

        async function saveSetting(field, value) {
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=save_settings&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}&field=${field}&value=${encodeURIComponent(value)}`);
                const data = await res.json();
                if (!data.success) {
                    console.error('Save failed:', data.error);
                }
            } catch (e) {
                console.error('Save error:', e);
            }
        }

        function saveVoting() {
            saveSetting('voting_enabled', document.getElementById('votingEnabled').checked);
        }

        function addBlockedWord() {
            const input = document.getElementById('newBlockedWord');
            const word = input.value.trim().toLowerCase();
            if (!word) return;

            const words = settings.blocked_words || [];
            if (!words.includes(word)) {
                words.push(word);
                settings.blocked_words = words;
                saveSetting('blocked_words', JSON.stringify(words));
                renderTags('blockedWordsTags', words, removeBlockedWord);
            }
            input.value = '';
        }

        function removeBlockedWord(word) {
            const words = (settings.blocked_words || []).filter(w => w !== word);
            settings.blocked_words = words;
            saveSetting('blocked_words', JSON.stringify(words));
            renderTags('blockedWordsTags', words, removeBlockedWord);
        }

        function addBlockedClipper() {
            const input = document.getElementById('newBlockedClipper');
            const clipper = input.value.trim();
            if (!clipper) return;

            const clippers = settings.blocked_clippers || [];
            if (!clippers.includes(clipper)) {
                clippers.push(clipper);
                settings.blocked_clippers = clippers;
                saveSetting('blocked_clippers', JSON.stringify(clippers));
                renderTags('blockedClippersTags', clippers, removeBlockedClipper);
            }
            input.value = '';
        }

        function removeBlockedClipper(clipper) {
            const clippers = (settings.blocked_clippers || []).filter(c => c !== clipper);
            settings.blocked_clippers = clippers;
            saveSetting('blocked_clippers', JSON.stringify(clippers));
            renderTags('blockedClippersTags', clippers, removeBlockedClipper);
        }

        async function saveModPassword() {
            const password = document.getElementById('newModPassword').value;
            try {
                const res = await fetch(`${API_BASE}/dashboard_api.php?action=set_mod_password&key=${encodeURIComponent(authKey)}&login=${encodeURIComponent(authLogin)}&new_password=${encodeURIComponent(password)}`);
                const data = await res.json();

                const msgEl = document.getElementById('settingsMessage');
                if (data.success) {
                    msgEl.innerHTML = '<div class="message success">' + data.message + '</div>';
                    document.getElementById('newModPassword').value = '';
                } else {
                    msgEl.innerHTML = '<div class="message error">' + (data.error || 'Failed') + '</div>';
                }
                setTimeout(() => msgEl.innerHTML = '', 5000);
            } catch (e) {
                console.error('Error:', e);
            }
        }

        function refreshClips() {
            window.open(`refresh_clips.php?login=${encodeURIComponent(authLogin)}&key=${encodeURIComponent(authKey)}`, '_blank');
        }

        function copyPlayerUrl() {
            const url = document.getElementById('playerUrl').textContent;
            navigator.clipboard.writeText(url).then(() => {
                alert('URL copied to clipboard!');
            });
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    </script>
</body>
</html>
```

**Step 2: Commit**

```bash
git add dashboard.php
git commit -m "feat: add streamer dashboard frontend"
```

---

## Phase 4: Admin Integration

### Task 7: Add dashboard link generation to admin.php

**Files:**
- Modify: `c:\Users\Eric\Downloads\clipsystem\admin.php`

**Step 1: Add the include and action handler**

After line 11 (`require_once __DIR__ . '/db_config.php';`), add:

```php
require_once __DIR__ . '/includes/dashboard_auth.php';
```

After line 74 (after the refresh_user action handler), add:

```php
  // Generate dashboard link for a user
  if (isset($_POST['action']) && $_POST['action'] === 'generate_dashboard') {
    $login = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['login'] ?? '')));

    if ($login) {
      $auth = new DashboardAuth();
      $key = $auth->createStreamer($login);

      if ($key) {
        $dashboardUrl = "https://gmgnrepeat.com/flop/dashboard.php?key=" . urlencode($key);
        $message = "Dashboard link generated for {$login}!";
        $messageType = 'success';
        $generatedDashboardUrl = $dashboardUrl;
        $generatedLogin = $login;
      } else {
        $message = "Failed to generate dashboard link";
        $messageType = 'error';
      }
    }
  }
```

**Step 2: Add the Dashboard Link button to user table**

In the user table row (around line 389), after the "Get New Clips" button form, add:

```php
              <form method="POST" style="display: inline; margin-left: 8px;">
                <input type="hidden" name="action" value="generate_dashboard">
                <input type="hidden" name="login" value="<?= htmlspecialchars($user['login']) ?>">
                <button type="submit" class="btn-secondary" style="padding: 6px 12px; font-size: 14px; background: #9147ff;" title="Generate dashboard access link">Dashboard</button>
              </form>
```

**Step 3: Add display for generated dashboard URL**

After the success message display (around line 335), add:

```php
        <?php if (isset($generatedDashboardUrl)): ?>
          <div style="margin-top: 10px; padding: 10px; background: rgba(0,0,0,0.3); border-radius: 4px;">
            <strong>Dashboard URL for <?= htmlspecialchars($generatedLogin) ?>:</strong><br>
            <input type="text" value="<?= htmlspecialchars($generatedDashboardUrl) ?>" readonly onclick="this.select()" style="width: 100%; margin-top: 5px; cursor: pointer;">
            <div style="margin-top: 8px; font-size: 13px; color: #adadb8;">
              Share this link with the streamer. They can bookmark it for easy access.
            </div>
          </div>
        <?php endif; ?>
```

**Step 4: Commit**

```bash
git add admin.php
git commit -m "feat: add dashboard link generation to admin panel"
```

---

## Phase 5: Content Filtering Integration

### Task 8: Update clip selection to respect filters

**Files:**
- Create: `c:\Users\Eric\Downloads\clipsystem\includes\clip_filter.php`

**Step 1: Create the filter helper**

```php
<?php
/**
 * clip_filter.php - Helper to apply content filters to clip queries
 */

require_once __DIR__ . '/../db_config.php';

class ClipFilter {
    private $pdo;
    private $login;
    private $blockedWords = [];
    private $blockedClippers = [];

    public function __construct($pdo, $login) {
        $this->pdo = $pdo;
        $this->login = $login;
        $this->loadFilters();
    }

    private function loadFilters() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT blocked_words, blocked_clippers
                FROM channel_settings WHERE login = ?
            ");
            $stmt->execute([$this->login]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $this->blockedWords = json_decode($row['blocked_words'] ?: '[]', true) ?: [];
                $this->blockedClippers = json_decode($row['blocked_clippers'] ?: '[]', true) ?: [];
            }
        } catch (PDOException $e) {
            // Filters not available, continue without them
        }
    }

    /**
     * Get additional WHERE clauses for filtering
     * Returns: ['sql' => string, 'params' => array]
     */
    public function getWhereClause() {
        $clauses = [];
        $params = [];

        // Word filtering
        foreach ($this->blockedWords as $word) {
            $clauses[] = "title NOT ILIKE ?";
            $params[] = '%' . $word . '%';
        }

        // Clipper filtering
        if (!empty($this->blockedClippers)) {
            $placeholders = implode(',', array_fill(0, count($this->blockedClippers), '?'));
            $clauses[] = "(creator_name IS NULL OR creator_name NOT IN ({$placeholders}))";
            $params = array_merge($params, $this->blockedClippers);
        }

        return [
            'sql' => $clauses ? ' AND ' . implode(' AND ', $clauses) : '',
            'params' => $params
        ];
    }

    /**
     * Check if a specific clip passes the filters
     */
    public function passesFilter($title, $creatorName) {
        // Check blocked words
        $titleLower = strtolower($title);
        foreach ($this->blockedWords as $word) {
            if (stripos($titleLower, strtolower($word)) !== false) {
                return false;
            }
        }

        // Check blocked clippers
        if ($creatorName && in_array($creatorName, $this->blockedClippers)) {
            return false;
        }

        return true;
    }
}
```

**Step 2: Commit**

```bash
git add includes/clip_filter.php
git commit -m "feat: add clip filter helper for content filtering"
```

---

### Task 9: Update twitch_reel_api.php to use filters

**Files:**
- Modify: `c:\Users\Eric\Downloads\clipsystem\twitch_reel_api.php`

**Step 1: Read the current file to find the clip selection query**

First, let me check the structure of twitch_reel_api.php to understand where to add the filter.

**Step 2: Add filter import and usage**

After the `require_once __DIR__ . '/db_config.php';` line, add:

```php
require_once __DIR__ . '/includes/clip_filter.php';
```

Then, in the query that selects random clips, integrate the filter. Find the main SELECT query for clips and add the filter's WHERE clause.

(Note: The exact modification depends on the current structure of twitch_reel_api.php. The implementation should add the ClipFilter's getWhereClause() output to the existing WHERE clause.)

**Step 3: Commit**

```bash
git add twitch_reel_api.php
git commit -m "feat: apply content filters to clip selection"
```

---

## Phase 6: State Management

### Task 10: Create clear_playback_state.php

**Files:**
- Create: `c:\Users\Eric\Downloads\clipsystem\clear_playback_state.php`

**Step 1: Write the state clearing endpoint**

```php
<?php
/**
 * clear_playback_state.php - Clear all playback state on player init
 *
 * Called by player on browser refresh to ensure clean state.
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

require_once __DIR__ . '/db_config.php';

function clean_login($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace("/[^a-z0-9_]/", "", $s);
    return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode(["error" => "no database"]);
    exit;
}

try {
    // Clear active playlist
    $stmt = $pdo->prepare("DELETE FROM playlist_active WHERE login = ?");
    $stmt->execute([$login]);

    // Clear force play
    $stmt = $pdo->prepare("DELETE FROM force_play WHERE login = ?");
    $stmt->execute([$login]);

    // Clear category filter (if stored in DB)
    $stmt = $pdo->prepare("DELETE FROM category_filter WHERE login = ?");
    $stmt->execute([$login]);

    echo json_encode([
        "ok" => true,
        "login" => $login,
        "message" => "Playback state cleared"
    ]);
} catch (PDOException $e) {
    // Tables might not exist, that's fine
    echo json_encode([
        "ok" => true,
        "login" => $login,
        "message" => "State cleared (some tables may not exist)"
    ]);
}
```

**Step 2: Commit**

```bash
git add clear_playback_state.php
git commit -m "feat: add endpoint to clear playback state on player init"
```

---

### Task 11: Update player to clear state on init

**Files:**
- Modify: `c:\Users\Eric\Downloads\clipsystem\clipplayer_mp4_reel.html`

**Step 1: Add state clearing call in init function**

In the `init()` function (around line 1247), add at the beginning:

```javascript
    // Clear any stale playback state on init
    fetch(`${API_BASE}/clear_playback_state.php?login=${encodeURIComponent(login)}`, { cache: "no-store" })
      .then(() => console.log('[INIT] Playback state cleared'))
      .catch(e => console.error('[INIT] Failed to clear state:', e));
```

**Step 2: Commit**

```bash
git add clipplayer_mp4_reel.html
git commit -m "feat: clear playback state on player init/refresh"
```

---

## Phase 7: Final Integration & Testing

### Task 12: Create docs folder and commit design

**Files:**
- Already created: `c:\Users\Eric\Downloads\clipsystem\docs\plans\2025-12-20-streamer-dashboard-design.md`

**Step 1: Commit the design document**

```bash
git add docs/
git commit -m "docs: add streamer dashboard design document"
```

---

### Task 13: Test the complete flow

**Step 1: Run setup script**

```bash
php setup_streamers_table.php
```

**Step 2: Test dashboard authentication**

1. Access admin.php, click "Dashboard" button for a user
2. Copy the generated URL
3. Open the URL - should see dashboard
4. Test each tab and setting

**Step 3: Test mod access**

1. In dashboard, set a mod password
2. Access dashboard.php?login=username
3. Enter mod password
4. Verify limited permissions

**Step 4: Test content filtering**

1. Add blocked words in dashboard
2. Verify clips with those words are hidden from rotation

**Step 5: Final commit**

```bash
git add -A
git commit -m "feat: complete streamer dashboard implementation"
```

---

## Summary

**Files Created:**
- `setup_streamers_table.php` - Database migration
- `includes/dashboard_auth.php` - Authentication helper
- `includes/clip_filter.php` - Content filter helper
- `dashboard_api.php` - Dashboard API
- `dashboard.php` - Dashboard frontend
- `clear_playback_state.php` - State cleanup endpoint

**Files Modified:**
- `hud_position.php` - Add filtering columns
- `refresh_clips.php` - Accept streamer keys
- `admin.php` - Dashboard link generation
- `twitch_reel_api.php` - Apply content filters
- `clipplayer_mp4_reel.html` - Clear state on init

**Total Tasks:** 13

**Estimated Complexity:** Medium - builds on existing patterns in the codebase
