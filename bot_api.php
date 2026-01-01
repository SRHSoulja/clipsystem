<?php
/**
 * bot_api.php - API for managing bot channels
 *
 * Allows super admins to add/remove channels for the bot to join dynamically.
 * Bot polls this endpoint to get the current channel list.
 * Admin actions require OAuth authentication as super admin.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

function json_response($data) {
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error($msg, $code = 400) {
    http_response_code($code);
    json_response(["error" => $msg]);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$pdo = get_db_connection();
if (!$pdo) {
    json_error("Database unavailable", 500);
}

// Ensure bot_channels table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_channels (
            id SERIAL PRIMARY KEY,
            channel_login VARCHAR(64) NOT NULL UNIQUE,
            added_by VARCHAR(64),
            added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            active BOOLEAN DEFAULT TRUE
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bot_channels_active ON bot_channels(active)");
} catch (PDOException $e) {
    // Table might already exist
}

// List channels - no auth required (bot needs to poll this)
if ($action === 'list' || $action === '') {
    try {
        $stmt = $pdo->prepare("
            SELECT channel_login, added_by, added_at, active
            FROM bot_channels
            WHERE active = TRUE
            ORDER BY channel_login ASC
        ");
        $stmt->execute();
        $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Return just the channel names for the bot
        $channelList = array_map(function($ch) {
            return strtolower($ch['channel_login']);
        }, $channels);

        json_response([
            "success" => true,
            "channels" => $channelList,
            "details" => $channels
        ]);
    } catch (PDOException $e) {
        json_error("Database error: " . $e->getMessage(), 500);
    }
}

// All other actions require super admin OAuth
$currentUser = getCurrentUser();
if (!$currentUser || !isSuperAdmin()) {
    json_error("Unauthorized - super admin access required", 401);
}

switch ($action) {
    case 'add':
        $channel = strtolower(trim($_GET['channel'] ?? $_POST['channel'] ?? ''));
        $channel = preg_replace('/[^a-z0-9_]/', '', $channel);

        if (!$channel) {
            json_error("Missing channel name");
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO bot_channels (channel_login, added_by, active)
                VALUES (?, 'admin', TRUE)
                ON CONFLICT (channel_login) DO UPDATE SET active = TRUE, added_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$channel]);

            json_response([
                "success" => true,
                "message" => "Bot will join #{$channel}",
                "channel" => $channel
            ]);
        } catch (PDOException $e) {
            json_error("Database error: " . $e->getMessage(), 500);
        }
        break;

    case 'remove':
        $channel = strtolower(trim($_GET['channel'] ?? $_POST['channel'] ?? ''));
        $channel = preg_replace('/[^a-z0-9_]/', '', $channel);

        if (!$channel) {
            json_error("Missing channel name");
        }

        try {
            // Soft delete - set active to false
            $stmt = $pdo->prepare("UPDATE bot_channels SET active = FALSE WHERE channel_login = ?");
            $stmt->execute([$channel]);

            if ($stmt->rowCount() === 0) {
                json_error("Channel not found", 404);
            }

            json_response([
                "success" => true,
                "message" => "Bot will leave #{$channel}",
                "channel" => $channel
            ]);
        } catch (PDOException $e) {
            json_error("Database error: " . $e->getMessage(), 500);
        }
        break;

    case 'list_all':
        // List all channels including inactive ones (admin only)
        try {
            $stmt = $pdo->prepare("
                SELECT channel_login, added_by, added_at, active
                FROM bot_channels
                ORDER BY active DESC, channel_login ASC
            ");
            $stmt->execute();
            $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_response([
                "success" => true,
                "channels" => $channels
            ]);
        } catch (PDOException $e) {
            json_error("Database error: " . $e->getMessage(), 500);
        }
        break;

    default:
        json_error("Unknown action: " . $action);
}
