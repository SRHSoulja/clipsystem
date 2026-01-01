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
        json_error("Database error: " . $e->getMessage(), 500);
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
                SELECT hud_position, top_position, blocked_words, blocked_clippers, voting_enabled, vote_feedback, last_refresh, command_settings
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
                    'last_refresh' => null,
                    'command_settings' => '{}'
                ];
            }

            // Parse JSON fields
            $settings['blocked_words'] = json_decode($settings['blocked_words'] ?: '[]', true) ?: [];
            $settings['blocked_clippers'] = json_decode($settings['blocked_clippers'] ?: '[]', true) ?: [];
            $settings['command_settings'] = json_decode($settings['command_settings'] ?: '{}', true) ?: [];

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
            'blocked_clippers' => 'add_blocked_clippers',
            'command_settings' => 'toggle_commands'
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
                case 'command_settings':
                    // Expect JSON object with command names as keys and boolean values
                    $obj = json_decode($value, true);
                    if (!is_array($obj)) $obj = [];
                    $value = json_encode($obj);
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
        // Mod passwords have been removed - use channel_mods table with OAuth instead
        json_error("Mod passwords are no longer supported. Use the Mod Access tab to add mods via their Twitch username.", 410);
        break;

    case 'get_mods':
        if (!$auth->canDo('change_mod_password')) {
            json_error("Permission denied", 403);
        }

        try {
            $stmt = $pdo->prepare("
                SELECT mod_username, added_by, added_at
                FROM channel_mods
                WHERE channel_login = ?
                ORDER BY added_at DESC
            ");
            $stmt->execute([$login]);
            $mods = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_response(["success" => true, "mods" => $mods]);
        } catch (PDOException $e) {
            json_error("Database error: " . $e->getMessage(), 500);
        }
        break;

    case 'add_mod':
        if (!$auth->canDo('change_mod_password')) {
            json_error("Permission denied", 403);
        }

        $modUsername = strtolower(trim($_GET['mod_username'] ?? $_POST['mod_username'] ?? ''));
        $modUsername = preg_replace("/[^a-z0-9_]/", "", $modUsername);

        if (!$modUsername) {
            json_error("Invalid mod username");
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

            // Return updated list
            $stmt = $pdo->prepare("
                SELECT mod_username, added_by, added_at
                FROM channel_mods
                WHERE channel_login = ?
                ORDER BY added_at DESC
            ");
            $stmt->execute([$login]);
            $mods = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_response(["success" => true, "message" => "Mod added: $modUsername", "mods" => $mods]);
        } catch (PDOException $e) {
            json_error("Database error: " . $e->getMessage(), 500);
        }
        break;

    case 'remove_mod':
        if (!$auth->canDo('change_mod_password')) {
            json_error("Permission denied", 403);
        }

        $modUsername = strtolower(trim($_GET['mod_username'] ?? $_POST['mod_username'] ?? ''));

        if (!$modUsername) {
            json_error("Missing mod username");
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM channel_mods WHERE channel_login = ? AND mod_username = ?");
            $stmt->execute([$login, $modUsername]);

            // Return updated list
            $stmt = $pdo->prepare("
                SELECT mod_username, added_by, added_at
                FROM channel_mods
                WHERE channel_login = ?
                ORDER BY added_at DESC
            ");
            $stmt->execute([$login]);
            $mods = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_response(["success" => true, "message" => "Mod removed: $modUsername", "mods" => $mods]);
        } catch (PDOException $e) {
            json_error("Database error: " . $e->getMessage(), 500);
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
            json_error("Database error: " . $e->getMessage(), 500);
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
            json_error("Error loading weighting config: " . $e->getMessage(), 500);
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
            json_error("Error saving weighting config: " . $e->getMessage(), 500);
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
            json_error("Error resetting weighting config: " . $e->getMessage(), 500);
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
            json_error("Error adding golden clip: " . $e->getMessage(), 500);
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
            json_error("Error removing golden clip: " . $e->getMessage(), 500);
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
