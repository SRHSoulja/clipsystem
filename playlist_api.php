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
require_once __DIR__ . '/includes/dashboard_auth.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

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
$action = (string)($_GET["action"] ?? "");

// Auth: OAuth only (own channel, super admin, or mod)
$isAuthorized = false;
$currentUser = getCurrentUser();

if ($currentUser) {
  $oauthUsername = strtolower($currentUser['login']);
  // Own channel access
  if ($oauthUsername === $login) {
    $isAuthorized = true;
  }
  // Super admin access
  elseif (isSuperAdmin()) {
    $isAuthorized = true;
  }
  // Check if user is in channel's mod list
  else {
    $pdoCheck = get_db_connection();
    if ($pdoCheck) {
      try {
        $stmt = $pdoCheck->prepare("SELECT 1 FROM channel_mods WHERE channel_login = ? AND mod_username = ?");
        $stmt->execute([$login, $oauthUsername]);
        if ($stmt->fetch()) {
          $isAuthorized = true;
        }
      } catch (PDOException $e) {
        // Ignore - table might not exist
      }
    }
  }
}

if (!$isAuthorized) {
  json_error("Forbidden - OAuth login required", 403);
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
        SELECT pc.clip_seq as seq, pc.position, c.title, c.clip_id, c.duration
        FROM playlist_clips pc
        LEFT JOIN clips c ON c.login = ? AND c.seq = pc.clip_seq
        WHERE pc.playlist_id = ?
        ORDER BY pc.position
      ");
      $stmt->execute([$login, $id]);
      $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Calculate total duration
      $totalDuration = 0;
      foreach ($clips as $c) {
        $totalDuration += (float)($c['duration'] ?? 0);
      }
      $playlist['clips'] = $clips;
      $playlist['total_duration'] = $totalDuration;
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
    // Queue a playlist for playback using database
    $id = (int)($_GET["id"] ?? 0);
    if ($id <= 0) json_error("Missing playlist id");

    try {
      // Get playlist name
      $stmt = $pdo->prepare("SELECT name FROM playlists WHERE id = ? AND login = ?");
      $stmt->execute([$id, $login]);
      $playlist = $stmt->fetch();
      if (!$playlist) json_error("Playlist not found", 404);

      // Get clips in order to verify playlist has valid clips
      $stmt = $pdo->prepare("
        SELECT pc.clip_seq as seq, c.clip_id, c.title, c.duration
        FROM playlist_clips pc
        JOIN clips c ON c.login = ? AND c.seq = pc.clip_seq
        WHERE pc.playlist_id = ?
        ORDER BY pc.position
      ");
      $stmt->execute([$login, $id]);
      $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($clips)) {
        json_response(["success" => false, "message" => "Playlist is empty or clips were deleted"]);
      }

      // Set this playlist as active in the database
      // This replaces any previous active playlist for this login
      // Set current_index to 1 since the first clip (index 0) is force-played immediately
      $stmt = $pdo->prepare("
        INSERT INTO playlist_active (login, playlist_id, current_index, started_at, updated_at)
        VALUES (?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON CONFLICT (login) DO UPDATE SET
          playlist_id = EXCLUDED.playlist_id,
          current_index = 1,
          started_at = CURRENT_TIMESTAMP,
          updated_at = CURRENT_TIMESTAMP
      ");
      $stmt->execute([$login, $id]);

      $first = $clips[0];
      json_response([
        "success" => true,
        "message" => "Playing playlist: " . $playlist['name'] . " (" . count($clips) . " clips)",
        "playlist_id" => $id,
        "first_clip" => [
          "seq" => $first['seq'],
          "clip_id" => $first['clip_id'],
          "title" => $first['title'] ?? "",
          "duration" => $first['duration'] ?? 30
        ]
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

  case 'stop':
    // Stop the currently playing playlist
    try {
      $stmt = $pdo->prepare("DELETE FROM playlist_active WHERE login = ?");
      $stmt->execute([$login]);
      json_response(["success" => true, "message" => "Playlist stopped"]);
    } catch (PDOException $e) {
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  case 'status':
    // Get current playlist status
    try {
      $stmt = $pdo->prepare("
        SELECT pa.playlist_id, pa.current_index, p.name as playlist_name
        FROM playlist_active pa
        JOIN playlists p ON p.id = pa.playlist_id
        WHERE pa.login = ?
      ");
      $stmt->execute([$login]);
      $active = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($active) {
        // Get total clips in playlist
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM playlist_clips WHERE playlist_id = ?");
        $stmt->execute([$active['playlist_id']]);
        $totalClips = (int)$stmt->fetchColumn();

        json_response([
          "active" => true,
          "playlist_id" => $active['playlist_id'],
          "playlist_name" => $active['playlist_name'],
          "current_index" => $active['current_index'],
          "total_clips" => $totalClips
        ]);
      } else {
        json_response(["active" => false]);
      }
    } catch (PDOException $e) {
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  case 'reorder':
    // Reorder clips in a playlist (move from one position to another)
    $id = (int)($_GET["id"] ?? 0);
    $from = (int)($_GET["from"] ?? -1);
    $to = (int)($_GET["to"] ?? -1);

    if ($id <= 0) json_error("Missing playlist id");
    if ($from < 0 || $to < 0) json_error("Missing from/to indices");

    try {
      // Verify playlist ownership
      $stmt = $pdo->prepare("SELECT id FROM playlists WHERE id = ? AND login = ?");
      $stmt->execute([$id, $login]);
      if (!$stmt->fetch()) json_error("Playlist not found", 404);

      // Get all clips in current order
      $stmt = $pdo->prepare("SELECT id, clip_seq, position FROM playlist_clips WHERE playlist_id = ? ORDER BY position");
      $stmt->execute([$id]);
      $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if ($from >= count($clips) || $to >= count($clips)) {
        json_error("Index out of range");
      }

      // Reorder the array
      $moved = array_splice($clips, $from, 1);
      array_splice($clips, $to, 0, $moved);

      // Update positions in database
      $pdo->beginTransaction();
      foreach ($clips as $i => $clip) {
        $stmt = $pdo->prepare("UPDATE playlist_clips SET position = ? WHERE id = ?");
        $stmt->execute([$i, $clip['id']]);
      }
      $pdo->commit();

      json_response(["success" => true]);
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  case 'shuffle':
    // Randomly shuffle all clips in a playlist
    $id = (int)($_GET["id"] ?? 0);
    if ($id <= 0) json_error("Missing playlist id");

    try {
      // Verify playlist ownership
      $stmt = $pdo->prepare("SELECT id FROM playlists WHERE id = ? AND login = ?");
      $stmt->execute([$id, $login]);
      if (!$stmt->fetch()) json_error("Playlist not found", 404);

      // Get all clips
      $stmt = $pdo->prepare("SELECT id FROM playlist_clips WHERE playlist_id = ?");
      $stmt->execute([$id]);
      $clips = $stmt->fetchAll(PDO::FETCH_COLUMN);

      if (count($clips) < 2) {
        json_response(["success" => true, "message" => "Nothing to shuffle"]);
      }

      // Shuffle the array
      shuffle($clips);

      // Update positions in database
      $pdo->beginTransaction();
      foreach ($clips as $i => $clipId) {
        $stmt = $pdo->prepare("UPDATE playlist_clips SET position = ? WHERE id = ?");
        $stmt->execute([$i, $clipId]);
      }
      $pdo->commit();

      json_response(["success" => true]);
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      json_error("Database error: " . $e->getMessage(), 500);
    }
    break;

  default:
    json_error("Unknown action: " . $action);
}
