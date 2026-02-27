<?php
/**
 * cliptv_chat.php - Communal chat for ClipTV
 *
 * Requires Twitch OAuth login to send messages.
 *
 * POST: Send a message
 *   - login: channel login
 *   - message: text (max 200 chars)
 *
 * GET: Fetch messages and chatter count
 *   - login: channel login
 *   - after: (optional) message ID to only fetch newer messages
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

function clean_login($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? $_POST["login"] ?? "");

$pdo = get_db_connection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(["error" => "no database"]);
  exit;
}

// Create table if needed
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS cliptv_chat (
    id SERIAL PRIMARY KEY,
    login VARCHAR(50) NOT NULL,
    user_id VARCHAR(50),
    username VARCHAR(64) NOT NULL,
    display_name VARCHAR(64) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cliptv_chat_login ON cliptv_chat(login, id)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cliptv_chat_cleanup ON cliptv_chat(created_at)");
} catch (PDOException $e) {
  // Table/index exists
}

// Archive and cleanup old messages (older than 24 hours)
try {
  // Fetch expired messages before deleting
  $expired = $pdo->query("
    SELECT login, username, display_name, message, created_at
    FROM cliptv_chat
    WHERE created_at < NOW() - INTERVAL '24 hours'
    ORDER BY created_at ASC
  ")->fetchAll(PDO::FETCH_ASSOC);

  if ($expired) {
    // Archive to file (one JSON line per message, grouped by date)
    $archiveDir = __DIR__ . '/chat_archive';
    if (!is_dir($archiveDir)) {
      mkdir($archiveDir, 0755, true);
    }
    // Group by date and append to daily files
    $byDate = [];
    foreach ($expired as $msg) {
      $date = substr($msg['created_at'], 0, 10); // YYYY-MM-DD
      $byDate[$date][] = $msg;
    }
    foreach ($byDate as $date => $msgs) {
      $file = $archiveDir . '/chat_' . $date . '.jsonl';
      $fp = fopen($file, 'a');
      if ($fp) {
        foreach ($msgs as $msg) {
          fwrite($fp, json_encode($msg, JSON_UNESCAPED_UNICODE) . "\n");
        }
        fclose($fp);
      }
    }

    // Now delete the archived messages
    $pdo->exec("DELETE FROM cliptv_chat WHERE created_at < NOW() - INTERVAL '24 hours'");
  }
} catch (PDOException $e) {
  // ignore
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Require Twitch login
  $currentUser = getCurrentUser();
  if (!$currentUser) {
    http_response_code(401);
    echo json_encode(["error" => "Login required", "logged_in" => false]);
    exit;
  }

  $message = trim($_POST['message'] ?? '');
  if (!$message) {
    http_response_code(400);
    echo json_encode(["error" => "Message required"]);
    exit;
  }

  // Limit message length
  if (mb_strlen($message) > 200) {
    $message = mb_substr($message, 0, 200);
  }

  // Strip HTML tags
  $message = strip_tags($message);

  // Rate limit: max 1 message per 2 seconds per user
  try {
    $stmt = $pdo->prepare("
      SELECT created_at FROM cliptv_chat
      WHERE login = ? AND user_id = ?
      ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$login, $currentUser['id']]);
    $lastMsg = $stmt->fetch();
    if ($lastMsg) {
      $lastTime = strtotime($lastMsg['created_at']);
      if (time() - $lastTime < 2) {
        http_response_code(429);
        echo json_encode(["error" => "Slow down! Wait a moment between messages."]);
        exit;
      }
    }
  } catch (PDOException $e) {
    // ignore rate limit check failure
  }

  // Insert message
  try {
    $stmt = $pdo->prepare("
      INSERT INTO cliptv_chat (login, user_id, username, display_name, message, created_at)
      VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
      $login,
      $currentUser['id'],
      $currentUser['login'],
      $currentUser['display_name'],
      $message
    ]);

    $messageId = $pdo->lastInsertId();

    echo json_encode([
      "ok" => true,
      "id" => intval($messageId),
      "display_name" => $currentUser['display_name']
    ]);
  } catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to send message"]);
  }
  exit;
}

// GET - fetch messages and chatter count
$afterId = intval($_GET['after'] ?? 0);

try {
  // Fetch messages
  if ($afterId > 0) {
    // Only new messages
    $stmt = $pdo->prepare("
      SELECT id, username, display_name, message, created_at
      FROM cliptv_chat
      WHERE login = ? AND id > ?
      ORDER BY id ASC
      LIMIT 50
    ");
    $stmt->execute([$login, $afterId]);
  } else {
    // Last 50 messages
    $stmt = $pdo->prepare("
      SELECT id, username, display_name, message, created_at
      FROM cliptv_chat
      WHERE login = ?
      ORDER BY id DESC
      LIMIT 50
    ");
    $stmt->execute([$login]);
  }

  $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Reverse if we fetched DESC (initial load)
  if ($afterId <= 0) {
    $messages = array_reverse($messages);
  }

  // Format messages
  $formatted = array_map(function($m) {
    return [
      "id" => intval($m['id']),
      "username" => $m['username'],
      "display_name" => $m['display_name'],
      "message" => $m['message'],
      "created_at" => $m['created_at']
    ];
  }, $messages);

  // Count active chatters (sent message in last 5 minutes)
  $stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT username) as chatter_count
    FROM cliptv_chat
    WHERE login = ? AND created_at > NOW() - INTERVAL '5 minutes'
  ");
  $stmt->execute([$login]);
  $chatterCount = intval($stmt->fetchColumn());

  // Check if user is logged in
  $currentUser = getCurrentUser();
  $loggedIn = $currentUser !== null;

  echo json_encode([
    "messages" => $formatted,
    "chatter_count" => $chatterCount,
    "logged_in" => $loggedIn,
    "username" => $loggedIn ? $currentUser['login'] : null,
    "display_name" => $loggedIn ? $currentUser['display_name'] : null
  ]);
} catch (PDOException $e) {
  echo json_encode([
    "messages" => [],
    "chatter_count" => 0,
    "logged_in" => false
  ]);
}
