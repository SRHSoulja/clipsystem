<?php
/**
 * channel_info.php - Public channel info endpoint
 *
 * Returns basic channel info (profile image, display name) from the database.
 * Used by the clip player to set the favicon dynamically.
 *
 * GET ?login=streamer_name
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: public, max-age=3600"); // Cache for 1 hour

require_once __DIR__ . '/db_config.php';

$login = strtolower(trim(preg_replace('/[^a-z0-9_]/', '', $_GET['login'] ?? '')));

if (!$login) {
    echo json_encode(["error" => "Missing login"]);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode(["error" => "no database"]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT profile_image_url FROM channel_settings WHERE login = ?");
    $stmt->execute([$login]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "login" => $login,
        "profile_image_url" => $row['profile_image_url'] ?? null
    ]);
} catch (PDOException $e) {
    echo json_encode(["login" => $login, "profile_image_url" => null]);
}
