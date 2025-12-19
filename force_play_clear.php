<?php
/**
 * force_play_clear.php - Clear force play and advance playlist queue
 *
 * When a clip finishes playing:
 * 1. Check if there's a playlist queue
 * 2. If yes, advance to next clip and set new force_play
 * 3. If no more clips or no playlist, just clear force_play
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// Use /tmp for runtime data on Railway, fall back to ./cache locally
$runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";
if (!is_dir($runtimeDir)) @mkdir($runtimeDir, 0777, true);

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");
$forcePath = $runtimeDir . "/force_play_" . $login . ".json";
$queuePath = $runtimeDir . "/playlist_queue_" . $login . ".json";

// Read current force_play to get playlist info
$currentForce = null;
if (file_exists($forcePath)) {
  $raw = @file_get_contents($forcePath);
  $currentForce = $raw ? json_decode($raw, true) : null;
}

// Check if we're in a playlist
$playlistId = $currentForce['playlist_id'] ?? null;
$playlistIndex = $currentForce['playlist_index'] ?? null;

// Try to advance playlist
$nextClip = null;
if ($playlistId && file_exists($queuePath)) {
  $queueRaw = @file_get_contents($queuePath);
  $queue = $queueRaw ? json_decode($queueRaw, true) : null;

  if ($queue && isset($queue['clips']) && is_array($queue['clips'])) {
    $nextIndex = ($playlistIndex !== null ? $playlistIndex : -1) + 1;

    if ($nextIndex < count($queue['clips'])) {
      // There's another clip in the playlist
      $nextClip = $queue['clips'][$nextIndex];

      // Update queue current_index
      $queue['current_index'] = $nextIndex;
      @file_put_contents($queuePath, json_encode($queue, JSON_UNESCAPED_SLASHES), LOCK_EX);

      // Set next clip as force_play
      $forcePayload = [
        "login" => $login,
        "seq" => (int)$nextClip['seq'],
        "clip_id" => $nextClip['clip_id'],
        "title" => $nextClip['title'] ?? "",
        "duration" => (float)($nextClip['duration'] ?? 30),
        "nonce" => (string)(time() . "_" . bin2hex(random_bytes(4))),
        "set_at" => gmdate("c"),
        "playlist_id" => $playlistId,
        "playlist_index" => $nextIndex,
        "playlist_name" => $queue['playlist_name'] ?? '',
        "playlist_total" => count($queue['clips']),
      ];
      @file_put_contents($forcePath, json_encode($forcePayload, JSON_UNESCAPED_SLASHES), LOCK_EX);

      echo json_encode([
        "status" => "playlist_next",
        "index" => $nextIndex,
        "total" => count($queue['clips']),
        "clip" => $nextClip
      ]);
      exit;
    } else {
      // Playlist finished - clear the queue
      @unlink($queuePath);
    }
  }
}

// No playlist or playlist finished - just clear force_play
if (file_exists($forcePath)) {
  @unlink($forcePath);
}

echo json_encode(["status" => "cleared"]);
