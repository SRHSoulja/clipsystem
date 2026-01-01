<?php
/**
 * command_settings.php - API for bot to check command settings
 *
 * Returns enabled/disabled status for commands per channel.
 * Used by the bot to determine if a command should be executed.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$login = strtolower(trim($_GET['login'] ?? ''));
$login = preg_replace('/[^a-z0-9_]/', '', $login);

if (!$login) {
    echo json_encode(["error" => "Missing login"]);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode(["error" => "Database unavailable"]);
    exit;
}

// Default commands - all enabled by default
$defaultCommands = [
    'cclip' => true,      // Show clip info
    'cplay' => true,      // Play specific clip (mod)
    'like' => true,       // Upvote clip
    'dislike' => true,    // Downvote clip
    'cremove' => true,    // Remove clip from pool (mod)
    'cadd' => true,       // Restore removed clip (mod)
    'cfind' => true,      // Search clips
    'cskip' => true,      // Skip current clip (mod)
    'cprev' => true,      // Previous clip (mod)
    'ccat' => true,       // Category filter (mod)
    'ctop' => true,       // Show top clips overlay (mod)
    'cvote' => true,      // Clear own votes
    'chelp' => true,      // Show help
    'chud' => true,       // Move HUD (mod)
    'clikeon' => true,    // Enable voting (mod)
    'clikeoff' => true,   // Disable voting (mod)
    'cswitch' => true,    // Switch channel control (admin)
];

try {
    $stmt = $pdo->prepare("SELECT command_settings FROM channel_settings WHERE login = ?");
    $stmt->execute([$login]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $customSettings = [];
    if ($row && $row['command_settings']) {
        $customSettings = json_decode($row['command_settings'], true) ?: [];
    }

    // Merge defaults with custom settings
    $commands = array_merge($defaultCommands, $customSettings);

    echo json_encode([
        "success" => true,
        "login" => $login,
        "commands" => $commands
    ]);
} catch (PDOException $e) {
    echo json_encode(["error" => "Database error"]);
}
