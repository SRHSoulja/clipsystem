<?php
/**
 * playlist_api.php - API for playlist management
 *
 * Actions:
 *   list     - List all playlists for a login
 *   get      - Get a playlist with its clips
 *   create   - Create a new playlist
 *   delete   - Delete a playlist
 *   add_clips    - Add clips to a playlist
 *   remove_clip  - Remove a clip from a playlist
 *   play     - Queue a playlist for playback
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

function json_response($data) {
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}

function json_error($msg, $code = 400) {
  http_response_code($code);
  json_response(["error" => $msg]);
}

$login  = clean_login($_GET["login"] ?? "");
$key    = (string)($_GET["key"] ?? "");
$action = (string)($_GET["action"] ?? "");

$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

if ($key !== $ADMIN_KEY) {
  json_error("forbidden", 403);
}

$pdo = get_db_connection();
if (!$pdo) {
  json_error("Database unavailable", 500);
}

// Create tables if needed
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS playlists (
      id SERIAL PRIMARY KEY,
      login VARCHAR(64) NOT NULL,
      name VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE(login, name)
    )
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS playlist_clips (
      id SERIAL PRIMARY KEY,
      playlist_id INTEGER NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
      clip_seq INTEGER NOT NULL,
      position INTEGER NOT NULL,
      added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE(playlist_id, clip_seq)
    )
  ");

  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_playlists_login ON playlists(login)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_playlist_clips_playlist ON playlist_clips(playlist_id, position)");
} catch (PDOException $e) {
  error_log("playlist_api table creation error: " . $e->getMessage());
}

switch ($action) {
  case 'list':
    // List all playlists
    try {
      $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.created_at, COUNT(pc.id) as clip_count
        FROM playlists p
        LEFT JOIN playlist_clips pc ON p.id = pc.playlist_id
        WHERE p.login = ?
        GROUP BY p.id
        ORDER BY p.created_at DESC
      ");
      $stmt->execute([$login]);
      $playlists = $stmt->fetchAll(PDO::FETCH_ASSOC);
      json_response(["playlists" => $playlists]);
    } catch (PDOException $e) {
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  case 'get':
    // Get a playlist with its clips
    $id = (int)($_GET["id"] ?? 0);
    if ($id <= 0) json_error("Missing playlist id");

    try {
      // Get playlist
      $stmt = $pdo->prepare("SELECT id, name, created_at FROM playlists WHERE id = ? AND login = ?");
      $stmt->execute([$id, $login]);
      $playlist = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$playlist) json_error("Playlist not found", 404);

      // Get clips
      $stmt = $pdo->prepare("
        SELECT pc.clip_seq as seq, pc.position, c.title, c.clip_id
        FROM playlist_clips pc
        LEFT JOIN clips c ON c.login = ? AND c.seq = pc.clip_seq
        WHERE pc.playlist_id = ?
        ORDER BY pc.position
      ");
      $stmt->execute([$login, $id]);
      $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

      $playlist['clips'] = $clips;
      json_response(["playlist" => $playlist]);
    } catch (PDOException $e) {
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  case 'create':
    // Create a new playlist
    $name = trim((string)($_GET["name"] ?? ""));
    if (strlen($name) < 1) json_error("Playlist name required");
    if (strlen($name) > 100) json_error("Playlist name too long");

    try {
      $stmt = $pdo->prepare("INSERT INTO playlists (login, name) VALUES (?, ?) RETURNING id");
      $stmt->execute([$login, $name]);
      $id = $stmt->fetchColumn();
      json_response(["success" => true, "id" => $id, "name" => $name]);
    } catch (PDOException $e) {
      if (strpos($e->getMessage(), 'duplicate') !== false || strpos($e->getMessage(), 'unique') !== false) {
        json_error("Playlist with that name already exists");
      }
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  case 'delete':
    // Delete a playlist
    $id = (int)($_GET["id"] ?? 0);
    if ($id <= 0) json_error("Missing playlist id");

    try {
      $stmt = $pdo->prepare("DELETE FROM playlists WHERE id = ? AND login = ?");
      $stmt->execute([$id, $login]);
      if ($stmt->rowCount() === 0) {
        json_error("Playlist not found", 404);
      }
      json_response(["success" => true]);
    } catch (PDOException $e) {
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  case 'add_clips':
    // Add clips to a playlist
    $id = (int)($_GET["id"] ?? 0);
    $seqs = (string)($_GET["seqs"] ?? "");
    if ($id <= 0) json_error("Missing playlist id");
    if (!$seqs) json_error("Missing clip seqs");

    $seqList = array_filter(array_map('intval', explode(',', $seqs)));
    if (empty($seqList)) json_error("Invalid clip seqs");

    try {
      // Verify playlist exists
      $stmt = $pdo->prepare("SELECT id FROM playlists WHERE id = ? AND login = ?");
      $stmt->execute([$id, $login]);
      if (!$stmt->fetch()) json_error("Playlist not found", 404);

      // Get current max position
      $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) FROM playlist_clips WHERE playlist_id = ?");
      $stmt->execute([$id]);
      $maxPos = (int)$stmt->fetchColumn();

      // Add clips
      $stmt = $pdo->prepare("INSERT INTO playlist_clips (playlist_id, clip_seq, position) VALUES (?, ?, ?) ON CONFLICT (playlist_id, clip_seq) DO NOTHING");
      $added = 0;
      foreach ($seqList as $seq) {
        $maxPos++;
        $stmt->execute([$id, $seq, $maxPos]);
        if ($stmt->rowCount() > 0) $added++;
      }

      json_response(["success" => true, "added" => $added]);
    } catch (PDOException $e) {
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  case 'remove_clip':
    // Remove a clip from a playlist
    $id = (int)($_GET["id"] ?? 0);
    $seq = (int)($_GET["seq"] ?? 0);
    if ($id <= 0) json_error("Missing playlist id");
    if ($seq <= 0) json_error("Missing clip seq");

    try {
      // Verify playlist belongs to login
      $stmt = $pdo->prepare("SELECT id FROM playlists WHERE id = ? AND login = ?");
      $stmt->execute([$id, $login]);
      if (!$stmt->fetch()) json_error("Playlist not found", 404);

      $stmt = $pdo->prepare("DELETE FROM playlist_clips WHERE playlist_id = ? AND clip_seq = ?");
      $stmt->execute([$id, $seq]);
      json_response(["success" => true]);
    } catch (PDOException $e) {
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  case 'play':
    // Queue a playlist for playback
    $id = (int)($_GET["id"] ?? 0);
    if ($id <= 0) json_error("Missing playlist id");

    try {
      // Get playlist name
      $stmt = $pdo->prepare("SELECT name FROM playlists WHERE id = ? AND login = ?");
      $stmt->execute([$id, $login]);
      $playlist = $stmt->fetch();
      if (!$playlist) json_error("Playlist not found", 404);

      // Get clips in order
      $stmt = $pdo->prepare("
        SELECT pc.clip_seq as seq, c.clip_id, c.title, c.duration
        FROM playlist_clips pc
        LEFT JOIN clips c ON c.login = ? AND c.seq = pc.clip_seq
        WHERE pc.playlist_id = ?
        ORDER BY pc.position
      ");
      $stmt->execute([$login, $id]);
      $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($clips)) {
        json_response(["success" => false, "message" => "Playlist is empty"]);
      }

      // Write playlist queue file
      $runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";
      if (!is_dir($runtimeDir)) @mkdir($runtimeDir, 0777, true);

      $queuePath = $runtimeDir . "/playlist_queue_" . $login . ".json";
      $payload = [
        "login" => $login,
        "playlist_id" => $id,
        "playlist_name" => $playlist['name'],
        "clips" => $clips,
        "current_index" => 0,
        "set_at" => gmdate("c"),
      ];
      @file_put_contents($queuePath, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);

      // Also set first clip as force_play
      $first = $clips[0];
      $forcePath = $runtimeDir . "/force_play_" . $login . ".json";
      $forcePayload = [
        "login" => $login,
        "seq" => (int)$first['seq'],
        "clip_id" => $first['clip_id'],
        "title" => $first['title'] ?? "",
        "duration" => (float)($first['duration'] ?? 30),
        "nonce" => (string)(time() . "_" . bin2hex(random_bytes(4))),
        "set_at" => gmdate("c"),
        "playlist_id" => $id,
        "playlist_index" => 0,
        "playlist_name" => $playlist['name'],
        "playlist_total" => count($clips),
      ];
      @file_put_contents($forcePath, json_encode($forcePayload, JSON_UNESCAPED_SLASHES), LOCK_EX);

      json_response([
        "success" => true,
        "message" => "Playing playlist: " . $playlist['name'] . " (" . count($clips) . " clips)"
      ]);
    } catch (PDOException $e) {
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  case 'rename':
    // Rename a playlist
    $id = (int)($_GET["id"] ?? 0);
    $name = trim((string)($_GET["name"] ?? ""));
    if ($id <= 0) json_error("Missing playlist id");
    if (strlen($name) < 1) json_error("Playlist name required");
    if (strlen($name) > 100) json_error("Playlist name too long");

    try {
      $stmt = $pdo->prepare("UPDATE playlists SET name = ? WHERE id = ? AND login = ?");
      $stmt->execute([$name, $id, $login]);
      if ($stmt->rowCount() === 0) {
        json_error("Playlist not found", 404);
      }
      json_response(["success" => true, "name" => $name]);
    } catch (PDOException $e) {
      if (strpos($e->getMessage(), 'duplicate') !== false || strpos($e->getMessage(), 'unique') !== false) {
        json_error("Playlist with that name already exists");
      }
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  case 'get_by_name':
    // Get playlist by name (for bot command)
    $name = trim((string)($_GET["name"] ?? ""));
    if (!$name) json_error("Missing playlist name");

    try {
      $stmt = $pdo->prepare("SELECT id, name FROM playlists WHERE login = ? AND LOWER(name) = LOWER(?)");
      $stmt->execute([$login, $name]);
      $playlist = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$playlist) json_error("Playlist not found", 404);

      json_response(["playlist" => $playlist]);
    } catch (PDOException $e) {
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  default:
    json_error("Unknown action: " . $action);
}
