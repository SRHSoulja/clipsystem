<?php
/**
 * discord/channel_favorites.php - Per-user favorites for Discord Activity
 *
 * GET  ?discord_user_id=X&discord_token=Y  - List user's favorites
 * POST {discord_user_id, discord_token, channel_login}  - Toggle favorite
 *
 * Auth: HMAC discord_token = hash_hmac('sha256', 'discord|' . discordUserId, ADMIN_KEY)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../db_config.php';

$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

// Parse input based on method
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $discordUserId = trim($input['discord_user_id'] ?? '');
    $discordToken = $input['discord_token'] ?? '';
    $channelLogin = strtolower(trim($input['channel_login'] ?? ''));
} else {
    $discordUserId = trim($_GET['discord_user_id'] ?? '');
    $discordToken = $_GET['discord_token'] ?? '';
    $channelLogin = '';
}

// Validate auth
if (!$discordUserId || !$discordToken || !$ADMIN_KEY) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing auth parameters']);
    exit;
}

$expected = hash_hmac('sha256', 'discord|' . $discordUserId, $ADMIN_KEY);
if (!hash_equals($expected, $discordToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid discord token']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Auto-migrate table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS discord_favorites (
        id SERIAL PRIMARY KEY,
        discord_user_id VARCHAR(64) NOT NULL,
        channel_login VARCHAR(64) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(discord_user_id, channel_login)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_discord_favorites_user ON discord_favorites(discord_user_id)");
} catch (PDOException $e) {
    // Table likely already exists
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Return user's favorites with channel details
    try {
        $stmt = $pdo->prepare("
            SELECT df.channel_login as login,
                   cs.display_name,
                   cs.profile_image_url,
                   COALESCE(cc.clip_count, 0) as clip_count
            FROM discord_favorites df
            LEFT JOIN channel_settings cs ON cs.login = df.channel_login
            LEFT JOIN (
                SELECT login, COUNT(*) as clip_count
                FROM clips WHERE blocked = FALSE
                GROUP BY login
            ) cc ON cc.login = df.channel_login
            WHERE df.discord_user_id = ?
            ORDER BY df.created_at DESC
        ");
        $stmt->execute([$discordUserId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        error_log("Favorites GET error: " . $e->getMessage());
        echo json_encode([]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$channelLogin) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing channel_login']);
        exit;
    }

    try {
        // Check if already favorited
        $stmt = $pdo->prepare("SELECT id FROM discord_favorites WHERE discord_user_id = ? AND channel_login = ?");
        $stmt->execute([$discordUserId, $channelLogin]);

        if ($stmt->fetch()) {
            // Remove
            $pdo->prepare("DELETE FROM discord_favorites WHERE discord_user_id = ? AND channel_login = ?")
                ->execute([$discordUserId, $channelLogin]);
            echo json_encode(['favorited' => false, 'channel_login' => $channelLogin]);
        } else {
            // Add
            $pdo->prepare("INSERT INTO discord_favorites (discord_user_id, channel_login) VALUES (?, ?)")
                ->execute([$discordUserId, $channelLogin]);
            echo json_encode(['favorited' => true, 'channel_login' => $channelLogin]);
        }
    } catch (PDOException $e) {
        error_log("Favorites POST error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
}
