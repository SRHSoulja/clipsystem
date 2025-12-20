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
