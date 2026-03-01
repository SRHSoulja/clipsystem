<?php
/**
 * discord/token.php - Discord OAuth2 Token Exchange for ClipTV Activity
 *
 * Receives an authorization code from the Discord Embedded App SDK,
 * exchanges it for an access token, fetches the user's Discord profile
 * and linked Twitch account, and returns a signed vote token.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$DISCORD_CLIENT_ID = getenv('DISCORD_CLIENT_ID') ?: '';
$DISCORD_CLIENT_SECRET = getenv('DISCORD_CLIENT_SECRET') ?: '';
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

if (!$DISCORD_CLIENT_ID || !$DISCORD_CLIENT_SECRET) {
    http_response_code(500);
    echo json_encode(['error' => 'Discord credentials not configured']);
    exit;
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? '';

if (!$code) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing authorization code']);
    exit;
}

// Step 1: Exchange code for access token
$tokenResponse = curl_discord('https://discord.com/api/oauth2/token', [
    'client_id' => $DISCORD_CLIENT_ID,
    'client_secret' => $DISCORD_CLIENT_SECRET,
    'grant_type' => 'authorization_code',
    'code' => $code,
]);

if (!$tokenResponse || !isset($tokenResponse['access_token'])) {
    http_response_code(400);
    error_log('Discord token exchange failed: ' . json_encode($tokenResponse));
    echo json_encode(['error' => 'Token exchange failed']);
    exit;
}

$accessToken = $tokenResponse['access_token'];

// Step 2: Get Discord user info
$discordUser = curl_discord_get('https://discord.com/api/users/@me', $accessToken);

if (!$discordUser || !isset($discordUser['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Failed to get Discord user info']);
    exit;
}

// Step 3: Get linked accounts (connections) to find Twitch
$connections = curl_discord_get('https://discord.com/api/users/@me/connections', $accessToken);

$twitchUsername = null;
$twitchId = null;

if (is_array($connections)) {
    foreach ($connections as $conn) {
        if (($conn['type'] ?? '') === 'twitch' && ($conn['verified'] ?? false)) {
            $twitchUsername = strtolower($conn['name'] ?? '');
            $twitchId = $conn['id'] ?? null;
            break;
        }
    }
}

// Step 4: Generate signed vote token (if Twitch is linked)
$voteToken = null;
if ($twitchUsername && $ADMIN_KEY) {
    $voteToken = hash_hmac('sha256', $twitchUsername . '|' . $discordUser['id'], $ADMIN_KEY);
}

// Return everything the client needs
echo json_encode([
    'access_token' => $accessToken,
    'discord_user' => [
        'id' => $discordUser['id'],
        'username' => $discordUser['username'],
        'global_name' => $discordUser['global_name'] ?? $discordUser['username'],
        'avatar' => $discordUser['avatar'],
    ],
    'twitch_username' => $twitchUsername,
    'twitch_id' => $twitchId,
    'vote_token' => $voteToken,
]);

// --- Helper functions ---

function curl_discord(string $url, array $postFields): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        error_log("Discord API error ($httpCode): $response");
        return null;
    }
    return json_decode($response, true);
}

function curl_discord_get(string $url, string $token): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        error_log("Discord API error ($httpCode): $response");
        return null;
    }
    return json_decode($response, true);
}
