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
require_once __DIR__ . '/includes/twitch_oauth.php';

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

// Try OAuth super admin authentication first
$currentUser = getCurrentUser();
if ($currentUser && isSuperAdmin()) {
    // Super admin via OAuth - grant admin role
    $authenticated = true;
    // If login is specified in request, use that; otherwise use super admin's own channel
    if (!$login) {
        $login = strtolower($currentUser['login']);
    }
    // Manually set admin role in auth object for permission checks
    $auth->authenticateWithKey(getenv('ADMIN_KEY') ?: 'oauth-super-admin', $login);
}

// Try key authentication
if (!$authenticated && $key) {
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

// Try OAuth for own channel (non-super-admin)
if (!$authenticated && $currentUser && $login) {
    if (strtolower($currentUser['login']) === strtolower($login)) {
        $authenticated = true;
        // Set streamer role for own channel access
        $auth->authenticateWithKey($auth->getStreamerKey($login) ?: '', $login);
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

// Ensure channel_settings table has all required columns
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS channel_settings (
            login VARCHAR(50) PRIMARY KEY,
            hud_position VARCHAR(10) DEFAULT 'tr',
            top_position VARCHAR(10) DEFAULT 'br',
            blocked_words TEXT DEFAULT '[]',
            blocked_clippers TEXT DEFAULT '[]',
            voting_enabled BOOLEAN DEFAULT TRUE,
            vote_feedback BOOLEAN DEFAULT TRUE,
            last_refresh TIMESTAMP
        )
    ");
    // Add ALL columns if they don't exist (for existing tables)
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS hud_position VARCHAR(10) DEFAULT 'tr'");
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS top_position VARCHAR(10) DEFAULT 'br'");
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS blocked_words TEXT DEFAULT '[]'");
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS blocked_clippers TEXT DEFAULT '[]'");
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS voting_enabled BOOLEAN DEFAULT TRUE");
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS vote_feedback BOOLEAN DEFAULT TRUE");
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS last_refresh TIMESTAMP");
} catch (PDOException $e) {
    // Ignore - table might already be correct
}

switch ($action) {
    case 'get_settings':
        if (!$auth->canDo('view')) json_error("Permission denied", 403);

        try {
            $stmt = $pdo->prepare("
                SELECT hud_position, top_position, blocked_words, blocked_clippers, voting_enabled, vote_feedback, last_refresh
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
                    'vote_feedback' => true,
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

            // Default stats if no clips found
            if (!$stats || $stats['total'] === null) {
                $stats = ['total' => 0, 'active' => 0, 'blocked' => 0];
            }

            // Get streamer's instance for player URL
            $instance = $auth->getStreamerInstance($login);

            json_response([
                "settings" => $settings,
                "stats" => $stats,
                "role" => $auth->getRoleName(),
                "instance" => $instance,
                "debug_login" => $login  // Temporary debug
            ]);
        } catch (PDOException $e) {
            json_error("Database error: " . $e->getMessage(), 500);
        }
        break;

    case 'save_settings':
        $field = $_GET['field'] ?? $_POST['field'] ?? '';
        $value = $_GET['value'] ?? $_POST['value'] ?? '';

        $allowedFields = [
            'hud_position' => 'change_hud',
            'top_position' => 'change_hud',
            'voting_enabled' => 'toggle_voting',
            'vote_feedback' => 'toggle_voting',
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
                case 'vote_feedback':
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
