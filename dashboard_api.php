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
require_once __DIR__ . '/includes/clip_weighting.php';

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
$login = clean_login($_GET['login'] ?? $_POST['login'] ?? '');

$auth = new DashboardAuth();
$authenticated = false;
$currentUser = getCurrentUser();

// Debug logging for auth issues
error_log("Dashboard API - Action: $action, Login param: $login");
error_log("Dashboard API - CurrentUser: " . ($currentUser ? $currentUser['login'] : 'NULL'));
error_log("Dashboard API - isSuperAdmin: " . (($currentUser && isSuperAdmin()) ? 'YES' : 'NO'));

// Try OAuth super admin authentication first
if ($currentUser && isSuperAdmin()) {
    // Super admin via OAuth - grant admin role
    $authenticated = true;
    // If login is specified in request, use that; otherwise use super admin's own channel
    if (!$login) {
        $login = strtolower($currentUser['login']);
    }
    $auth->setRole(DashboardAuth::ROLE_ADMIN, $login);
}

// Try OAuth for own channel (non-super-admin)
if (!$authenticated && $currentUser && $login) {
    if (strtolower($currentUser['login']) === strtolower($login)) {
        $authenticated = true;
        $auth->setRole(DashboardAuth::ROLE_STREAMER, $login);
    }
}

// Try OAuth for mod access
if (!$authenticated && $currentUser && $login) {
    $oauthUsername = strtolower($currentUser['login']);
    $pdo = get_db_connection();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM channel_mods WHERE channel_login = ? AND mod_username = ?");
            $stmt->execute([$login, $oauthUsername]);
            if ($stmt->fetch()) {
                $authenticated = true;
                $auth->setRole(DashboardAuth::ROLE_MOD, $login);
            }
        } catch (PDOException $e) {
            // Ignore - table might not exist
        }
    }
}

// Special case: login check doesn't require auth
if ($action === 'check_login') {
    // Debug: log the final auth result
    error_log("Dashboard API - check_login result: authenticated=" . ($authenticated ? 'true' : 'false') . ", role=" . $auth->getRoleName() . ", login=" . $auth->getLogin());

    // Just verify if the login/key is valid (or OAuth session)
    json_response([
        "authenticated" => $authenticated,
        "role" => $auth->getRoleName(),
        "login" => $auth->getLogin()
    ]);
}

// Special case: get channels where user is a mod (requires OAuth login but not channel auth)
if ($action === 'my_channels') {
    if (!$currentUser) {
        json_error("Must be logged in with Twitch", 401);
    }

    $username = strtolower($currentUser['login']);
    $pdo = get_db_connection();
    if (!$pdo) {
        json_error("Database unavailable", 500);
    }

    try {
        $stmt = $pdo->prepare("
            SELECT cm.channel_login, cm.added_at, s.instance
            FROM channel_mods cm
            LEFT JOIN streamers s ON s.login = cm.channel_login
            WHERE cm.mod_username = ?
            ORDER BY cm.channel_login ASC
        ");
        $stmt->execute([$username]);
        $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_response([
            "success" => true,
            "username" => $username,
            "is_super_admin" => isSuperAdmin(),
            "channels" => $channels
        ]);
    } catch (PDOException $e) {
        error_log("Dashboard API DB error: " . $e->getMessage());
        json_error("Database error occurred", 500);
    }
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
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS command_settings TEXT DEFAULT '{}'");
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS weighting_config TEXT DEFAULT '{}'");
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS silent_prefix BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS profile_image_url TEXT");
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS profile_image_updated_at TIMESTAMP");
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS banner_config TEXT DEFAULT '{}'");
    $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS discord_hud_position VARCHAR(10) DEFAULT 'tr'");

    // Ensure channel_mods table exists
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
} catch (PDOException $e) {
    // Ignore - table might already be correct
}

switch ($action) {
    case 'get_settings':
        if (!$auth->canDo('view')) json_error("Permission denied", 403);

        try {
            $stmt = $pdo->prepare("
                SELECT hud_position, discord_hud_position, top_position, blocked_words, blocked_clippers, voting_enabled, vote_feedback, silent_prefix, last_refresh, command_settings, banner_config
                FROM channel_settings WHERE login = ?
            ");
            $stmt->execute([$login]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$settings) {
                $settings = [
                    'hud_position' => 'tr',
                    'discord_hud_position' => 'tr',
                    'top_position' => 'br',
                    'blocked_words' => '[]',
                    'blocked_clippers' => '[]',
                    'vote_feedback' => true,
                    'voting_enabled' => true,
                    'silent_prefix' => false,
                    'last_refresh' => null,
                    'command_settings' => '{}',
                    'banner_config' => '{}'
                ];
            }

            // Parse JSON fields
            $settings['blocked_words'] = json_decode($settings['blocked_words'] ?: '[]', true) ?: [];
            $settings['blocked_clippers'] = json_decode($settings['blocked_clippers'] ?: '[]', true) ?: [];
            $settings['command_settings'] = json_decode($settings['command_settings'] ?: '{}', true) ?: [];
            $settings['banner_config'] = json_decode($settings['banner_config'] ?: '{}', true) ?: [];

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
                "instance" => $instance
            ]);
        } catch (PDOException $e) {
            error_log("Dashboard API DB error: " . $e->getMessage());
            json_error("Database error occurred", 500);
        }
        break;

    case 'save_settings':
        $field = $_GET['field'] ?? $_POST['field'] ?? '';
        $value = $_GET['value'] ?? $_POST['value'] ?? '';

        $allowedFields = [
            'hud_position' => 'change_hud',
            'discord_hud_position' => 'change_hud',
            'top_position' => 'change_hud',
            'voting_enabled' => 'toggle_voting',
            'vote_feedback' => 'toggle_voting',
            'silent_prefix' => 'toggle_voting',
            'blocked_words' => 'add_blocked_words',
            'blocked_clippers' => 'add_blocked_clippers',
            'command_settings' => 'toggle_commands',
            'banner_config' => 'change_hud'
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
                case 'discord_hud_position':
                    if (!in_array($value, ['tr', 'tl', 'tc', 'br', 'bl'])) $value = 'tr';
                    break;
                case 'top_position':
                    if (!in_array($value, ['tr', 'tl', 'br', 'bl'])) $value = 'tr';
                    break;
                case 'voting_enabled':
                case 'vote_feedback':
                case 'silent_prefix':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'TRUE' : 'FALSE';
                    break;
                case 'blocked_words':
                case 'blocked_clippers':
                    // Expect JSON array
                    $arr = json_decode($value, true);
                    if (!is_array($arr)) $arr = [];
                    $value = json_encode(array_values(array_filter(array_map('trim', $arr))));
                    break;
                case 'command_settings':
                    // Expect JSON object with command names as keys and boolean values
                    $obj = json_decode($value, true);
                    if (!is_array($obj)) $obj = [];
                    $value = json_encode($obj);
                    break;
                case 'banner_config':
                    error_log("Banner save - raw value length: " . strlen($value));
                    $config = json_decode($value, true);
                    if (!is_array($config)) {
                        error_log("Banner save - json_decode failed: " . json_last_error_msg());
                        $config = [];
                    }
                    error_log("Banner save - decoded keys: " . implode(',', array_keys($config)));
                    $validated = [];
                    $validated['enabled'] = !empty($config['enabled']);
                    $validated['text'] = mb_substr(trim($config['text'] ?? ''), 0, 200);
                    $validated['text_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $config['text_color'] ?? '') ? $config['text_color'] : '#ffffff';
                    $validated['bg_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $config['bg_color'] ?? '') ? $config['bg_color'] : '#9147ff';
                    $validated['bg_opacity'] = max(0, min(1, floatval($config['bg_opacity'] ?? 0.85)));
                    $validated['font_size'] = max(12, min(72, intval($config['font_size'] ?? 32)));
                    $validFonts = ['Inter', 'Roboto', 'Poppins', 'Montserrat', 'Press Start 2P', 'Permanent Marker', 'Bangers', 'Oswald'];
                    $validated['font_family'] = in_array($config['font_family'] ?? '', $validFonts) ? $config['font_family'] : 'Inter';
                    $validPositions = ['top', 'center', 'bottom'];
                    $validated['position'] = in_array($config['position'] ?? '', $validPositions) ? $config['position'] : 'top';
                    $validBorders = ['none', 'solid', 'glow'];
                    $validated['border_style'] = in_array($config['border_style'] ?? '', $validBorders) ? $config['border_style'] : 'none';
                    $validAnimations = ['none', 'pulse', 'scroll'];
                    $validated['animation'] = in_array($config['animation'] ?? '', $validAnimations) ? $config['animation'] : 'none';
                    $validated['scroll_speed'] = max(3, min(20, intval($config['scroll_speed'] ?? 8)));
                    $validShapes = ['rectangle', 'rounded', 'pill'];
                    $validated['shape'] = in_array($config['shape'] ?? '', $validShapes) ? $config['shape'] : 'rectangle';
                    $validated['timed_enabled'] = !empty($config['timed_enabled']);
                    $validated['show_duration'] = max(5, min(120, intval($config['show_duration'] ?? 15)));
                    $validated['interval'] = max(1, min(30, intval($config['interval'] ?? 5)));
                    $value = json_encode($validated);
                    error_log("Banner save - final value: " . $value);
                    break;
            }

            $stmt = $pdo->prepare("
                INSERT INTO channel_settings (login, {$field}, updated_at)
                VALUES (?, ?, NOW())
                ON CONFLICT (login) DO UPDATE SET {$field} = ?, updated_at = NOW()
            ");
            $stmt->execute([$login, $value, $value]);

            error_log("Settings save - login: {$login}, field: {$field}, rowCount: " . $stmt->rowCount());

            // Verify the save by reading it back
            if ($field === 'banner_config') {
                $verify = $pdo->prepare("SELECT banner_config FROM channel_settings WHERE login = ?");
                $verify->execute([$login]);
                $verifyRow = $verify->fetch(PDO::FETCH_ASSOC);
                error_log("Banner verify - stored value: " . ($verifyRow ? $verifyRow['banner_config'] : 'NO ROW'));
            }

            json_response(["success" => true]);
        } catch (PDOException $e) {
            error_log("Dashboard API DB error: " . $e->getMessage());
            json_error("Database error occurred", 500);
        }
        break;

    case 'set_mod_password':
        // Mod passwords have been removed - use channel_mods table with OAuth instead
        json_error("Mod passwords are no longer supported. Use the Mod Access tab to add mods via their Twitch username.", 410);
        break;

    case 'get_mods':
        if (!$auth->canDo('manage_mods')) {
            json_error("Permission denied", 403);
        }

        try {
            // Get mods with their permissions
            $mods = $auth->getChannelModsWithPermissions($login);

            json_response([
                "success" => true,
                "mods" => $mods,
                "all_permissions" => DashboardAuth::ALL_PERMISSIONS,
                "default_permissions" => DashboardAuth::DEFAULT_MOD_PERMISSIONS
            ]);
        } catch (PDOException $e) {
            error_log("Dashboard API DB error: " . $e->getMessage());
            json_error("Database error occurred", 500);
        }
        break;

    case 'add_mod':
        if (!$auth->canDo('manage_mods')) {
            json_error("Permission denied", 403);
        }

        $modUsername = strtolower(trim($_GET['mod_username'] ?? $_POST['mod_username'] ?? ''));
        $modUsername = preg_replace("/[^a-z0-9_]/", "", $modUsername);

        // Validate username format (Twitch usernames are 4-25 chars)
        if (!$modUsername || strlen($modUsername) < 3 || strlen($modUsername) > 25) {
            json_error("Invalid mod username - must be 3-25 characters");
        }

        // Can't add yourself as a mod
        if ($modUsername === $login) {
            json_error("Cannot add yourself as a mod");
        }

        try {
            $addedBy = $currentUser ? $currentUser['login'] : $auth->getLogin();

            $stmt = $pdo->prepare("
                INSERT INTO channel_mods (channel_login, mod_username, added_by)
                VALUES (?, ?, ?)
                ON CONFLICT (channel_login, mod_username) DO NOTHING
            ");
            $stmt->execute([$login, $modUsername, $addedBy]);

            // Grant default permissions to the new mod
            $auth->grantDefaultModPermissions($login, $modUsername);

            // Return updated list with permissions
            $mods = $auth->getChannelModsWithPermissions($login);

            json_response([
                "success" => true,
                "message" => "Mod added: $modUsername",
                "mods" => $mods,
                "all_permissions" => DashboardAuth::ALL_PERMISSIONS
            ]);
        } catch (PDOException $e) {
            error_log("Dashboard API DB error: " . $e->getMessage());
            json_error("Database error occurred", 500);
        }
        break;

    case 'remove_mod':
        if (!$auth->canDo('manage_mods')) {
            json_error("Permission denied", 403);
        }

        $modUsername = strtolower(trim($_GET['mod_username'] ?? $_POST['mod_username'] ?? ''));

        if (!$modUsername) {
            json_error("Missing mod username");
        }

        try {
            // Remove mod from channel_mods
            $stmt = $pdo->prepare("DELETE FROM channel_mods WHERE channel_login = ? AND mod_username = ?");
            $stmt->execute([$login, $modUsername]);

            // Also remove all their permissions
            $auth->revokeAllModPermissions($login, $modUsername);

            // Return updated list
            $mods = $auth->getChannelModsWithPermissions($login);

            json_response([
                "success" => true,
                "message" => "Mod removed: $modUsername",
                "mods" => $mods
            ]);
        } catch (PDOException $e) {
            error_log("Dashboard API DB error: " . $e->getMessage());
            json_error("Database error occurred", 500);
        }
        break;

    case 'set_mod_permission':
        if (!$auth->canDo('manage_mods')) {
            json_error("Permission denied", 403);
        }

        $modUsername = strtolower(trim($_GET['mod_username'] ?? $_POST['mod_username'] ?? ''));
        $permission = trim($_GET['permission'] ?? $_POST['permission'] ?? '');
        $granted = filter_var($_GET['granted'] ?? $_POST['granted'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if (!$modUsername) {
            json_error("Missing mod username");
        }

        if (!isset(DashboardAuth::ALL_PERMISSIONS[$permission])) {
            json_error("Invalid permission: $permission");
        }

        try {
            if ($granted) {
                $auth->grantModPermission($login, $modUsername, $permission);
            } else {
                $auth->revokeModPermission($login, $modUsername, $permission);
            }

            // Return updated mod info
            $permissions = $auth->getModPermissions($login, $modUsername);

            json_response([
                "success" => true,
                "mod_username" => $modUsername,
                "permission" => $permission,
                "granted" => $granted,
                "permissions" => $permissions
            ]);
        } catch (PDOException $e) {
            error_log("Dashboard API DB error: " . $e->getMessage());
            json_error("Database error occurred", 500);
        }
        break;

    case 'get_my_permissions':
        // Get current user's permissions for a channel (no special auth needed, just OAuth)
        if (!$currentUser) {
            json_error("Must be logged in", 401);
        }

        if (!$login) {
            json_error("Missing channel login");
        }

        try {
            $myUsername = strtolower($currentUser['login']);

            // Check if user is the channel owner
            if ($myUsername === strtolower($login)) {
                json_response([
                    "success" => true,
                    "role" => "streamer",
                    "permissions" => array_keys(DashboardAuth::ALL_PERMISSIONS)
                ]);
            }

            // Check if super admin
            if (isSuperAdmin()) {
                json_response([
                    "success" => true,
                    "role" => "admin",
                    "permissions" => array_keys(DashboardAuth::ALL_PERMISSIONS)
                ]);
            }

            // Check if mod
            $stmt = $pdo->prepare("SELECT 1 FROM channel_mods WHERE channel_login = ? AND mod_username = ?");
            $stmt->execute([$login, $myUsername]);
            if (!$stmt->fetch()) {
                json_response([
                    "success" => true,
                    "role" => "none",
                    "permissions" => []
                ]);
            }

            // Get mod permissions
            $permissions = $auth->getModPermissions($login, $myUsername);

            json_response([
                "success" => true,
                "role" => "mod",
                "permissions" => $permissions
            ]);
        } catch (PDOException $e) {
            error_log("Dashboard API DB error: " . $e->getMessage());
            json_error("Database error occurred", 500);
        }
        break;

    case 'get_accessible_channels':
        // Get all channels the current user can access (for channel switcher dropdown)
        if (!$currentUser) {
            json_error("Must be logged in", 401);
        }

        try {
            $myUsername = strtolower($currentUser['login']);
            $channels = [];

            // Check if user is an archived streamer (has own channel)
            $stmt = $pdo->prepare("SELECT COUNT(*) as clip_count FROM clips WHERE login = ?");
            $stmt->execute([$myUsername]);
            $ownClips = $stmt->fetch();
            if ($ownClips && $ownClips['clip_count'] > 0) {
                $channels[] = [
                    'login' => $myUsername,
                    'role' => 'streamer',
                    'clip_count' => (int)$ownClips['clip_count']
                ];
            }

            // Get channels where user is a mod
            $stmt = $pdo->prepare("
                SELECT cm.channel_login,
                       (SELECT COUNT(*) FROM clips WHERE login = cm.channel_login) as clip_count
                FROM channel_mods cm
                WHERE cm.mod_username = ?
                ORDER BY cm.channel_login
            ");
            $stmt->execute([$myUsername]);
            while ($row = $stmt->fetch()) {
                // Don't duplicate if it's their own channel
                if ($row['channel_login'] !== $myUsername) {
                    $channels[] = [
                        'login' => $row['channel_login'],
                        'role' => 'mod',
                        'clip_count' => (int)$row['clip_count']
                    ];
                }
            }

            json_response([
                "success" => true,
                "is_super_admin" => isSuperAdmin(),
                "channels" => $channels
            ]);
        } catch (PDOException $e) {
            error_log("Dashboard API DB error: " . $e->getMessage());
            json_error("Database error occurred", 500);
        }
        break;

    case 'refresh_clips':
        if (!$auth->canDo('refresh_clips')) {
            json_error("Permission denied", 403);
        }

        // Redirect to refresh_clips.php which handles the actual work
        // Uses OAuth session for authentication
        json_response([
            "redirect" => "refresh_clips.php?login=" . urlencode($login)
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

    case 'get_vote_stats':
        if (!$auth->canDo('view')) json_error("Permission denied", 403);

        try {
            // Total votes for this channel
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(up_votes), 0) as total_up,
                    COALESCE(SUM(down_votes), 0) as total_down
                FROM votes WHERE login = ?
            ");
            $stmt->execute([$login]);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);

            // Top 10 liked clips
            $stmt = $pdo->prepare("
                SELECT v.seq, v.title, v.up_votes, v.down_votes,
                       (v.up_votes - v.down_votes) as score
                FROM votes v
                WHERE v.login = ? AND v.up_votes > 0
                ORDER BY v.up_votes DESC, v.down_votes ASC
                LIMIT 10
            ");
            $stmt->execute([$login]);
            $topLiked = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Top 10 disliked clips
            $stmt = $pdo->prepare("
                SELECT v.seq, v.title, v.up_votes, v.down_votes,
                       (v.up_votes - v.down_votes) as score
                FROM votes v
                WHERE v.login = ? AND v.down_votes > 0
                ORDER BY v.down_votes DESC, v.up_votes ASC
                LIMIT 10
            ");
            $stmt->execute([$login]);
            $topDisliked = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Recent votes (last 20)
            $stmt = $pdo->prepare("
                SELECT vl.username, vl.vote_dir, vl.voted_at, v.seq, v.title
                FROM vote_ledger vl
                JOIN votes v ON vl.login = v.login AND vl.clip_id = v.clip_id
                WHERE vl.login = ?
                ORDER BY vl.voted_at DESC
                LIMIT 20
            ");
            $stmt->execute([$login]);
            $recentVotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_response([
                "success" => true,
                "totals" => [
                    "up" => (int)$totals['total_up'],
                    "down" => (int)$totals['total_down'],
                    "total" => (int)$totals['total_up'] + (int)$totals['total_down']
                ],
                "top_liked" => $topLiked,
                "top_disliked" => $topDisliked,
                "recent_votes" => $recentVotes
            ]);
        } catch (PDOException $e) {
            error_log("Dashboard API DB error: " . $e->getMessage());
            json_error("Database error occurred", 500);
        }
        break;

    // ===== CLIP WEIGHTING ENDPOINTS =====

    case 'get_weighting':
        if (!$auth->canDo('view')) json_error("Permission denied", 403);

        try {
            $weighting = new ClipWeighting($pdo, $login);
            $config = $weighting->getConfig();
            $categories = $weighting->getCategories();
            $clippers = $weighting->getClippers();

            json_response([
                "success" => true,
                "config" => $config,
                "available_categories" => $categories,
                "available_clippers" => $clippers
            ]);
        } catch (Exception $e) {
            json_error("Error loading weighting config", 500);
        }
        break;

    case 'save_weighting':
        // Only streamer/admin can change weighting (not mods)
        if ($auth->getRole() < DashboardAuth::ROLE_STREAMER) {
            json_error("Permission denied - streamer access required", 403);
        }

        // Get JSON body if POST
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input || !isset($input['config'])) {
            json_error("Missing config in request body");
        }

        try {
            $weighting = new ClipWeighting($pdo, $login);
            $success = $weighting->saveConfig($input['config']);

            if ($success) {
                json_response([
                    "success" => true,
                    "config" => $weighting->getConfig()
                ]);
            } else {
                json_error("Failed to save weighting config");
            }
        } catch (Exception $e) {
            json_error("Error saving weighting config", 500);
        }
        break;

    case 'reset_weighting':
        // Only streamer/admin can reset weighting
        if ($auth->getRole() < DashboardAuth::ROLE_STREAMER) {
            json_error("Permission denied - streamer access required", 403);
        }

        try {
            $weighting = new ClipWeighting($pdo, $login);
            $success = $weighting->saveConfig(ClipWeighting::DEFAULT_CONFIG);

            if ($success) {
                json_response([
                    "success" => true,
                    "message" => "Weighting config reset to defaults",
                    "config" => $weighting->getConfig()
                ]);
            } else {
                json_error("Failed to reset weighting config");
            }
        } catch (Exception $e) {
            json_error("Error resetting weighting config", 500);
        }
        break;

    case 'add_golden_clip':
        if ($auth->getRole() < DashboardAuth::ROLE_STREAMER) {
            json_error("Permission denied - streamer access required", 403);
        }

        // Read from JSON body or GET/POST params
        $rawInput = file_get_contents('php://input');
        $jsonInput = json_decode($rawInput, true) ?: [];

        $seq = (int)($jsonInput['seq'] ?? $_GET['seq'] ?? $_POST['seq'] ?? 0);
        $boost = (float)($jsonInput['boost'] ?? $_GET['boost'] ?? $_POST['boost'] ?? 2.0);

        if ($seq <= 0) json_error("Invalid clip seq");

        try {
            // Get clip title for display and verify it exists
            $stmt = $pdo->prepare("SELECT title FROM clips WHERE login = ? AND seq = ?");
            $stmt->execute([$login, $seq]);
            $clip = $stmt->fetch();

            if (!$clip) {
                json_error("Clip #{$seq} not found");
            }

            $title = $clip['title'] ?? '';

            $weighting = new ClipWeighting($pdo, $login);
            $success = $weighting->addGoldenClip($seq, $boost, $title);

            if ($success) {
                json_response([
                    "success" => true,
                    "message" => "Added clip #{$seq} as golden clip",
                    "title" => $title,
                    "config" => $weighting->getConfig()
                ]);
            } else {
                json_error("Clip #{$seq} is already a golden clip");
            }
        } catch (Exception $e) {
            json_error("Error adding golden clip", 500);
        }
        break;

    case 'remove_golden_clip':
        if ($auth->getRole() < DashboardAuth::ROLE_STREAMER) {
            json_error("Permission denied - streamer access required", 403);
        }

        // Read from JSON body or GET/POST params
        $rawInput = file_get_contents('php://input');
        $jsonInput = json_decode($rawInput, true) ?: [];

        $seq = (int)($jsonInput['seq'] ?? $_GET['seq'] ?? $_POST['seq'] ?? 0);
        if ($seq <= 0) json_error("Invalid clip seq");

        try {
            $weighting = new ClipWeighting($pdo, $login);
            $success = $weighting->removeGoldenClip($seq);

            json_response([
                "success" => true,
                "message" => "Removed clip #{$seq} from golden clips",
                "config" => $weighting->getConfig()
            ]);
        } catch (Exception $e) {
            json_error("Error removing golden clip", 500);
        }
        break;

    case 'get_weighting_for_player':
        // This endpoint is public (no auth needed) for the player to fetch weights
        // It only returns the weighting config, no sensitive data
        try {
            $weighting = new ClipWeighting($pdo, $login);
            json_response([
                "success" => true,
                "config" => $weighting->getConfigForPlayer()
            ]);
        } catch (Exception $e) {
            // Return defaults on error
            json_response([
                "success" => true,
                "config" => (new ClipWeighting(null, ''))->getConfigForPlayer()
            ]);
        }
        break;

    default:
        json_error("Unknown action: " . $action);
}
