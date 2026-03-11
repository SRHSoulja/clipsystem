<?php
/**
 * extension_api.php — EBS for ClipTV Twitch Panel Extension
 *
 * All endpoints require a Twitch-signed JWT in the Authorization: Bearer header.
 * JWT is verified with TWITCH_EXT_SECRET (base64-encoded, from Twitch dev console).
 *
 * Actions:
 *   GET  ?action=channel   — resolve broadcaster + fetch settings
 *   GET  ?action=clips     — fetch clips (sort=recent|top|random, limit=5-25)
 *   GET  ?action=search    — search clips (q=query)
 *   POST ?action=settings  — save broadcaster settings (role=broadcaster required)
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_config.php';

// ── Helpers ──────────────────────────────────────────────────────────────────

function json_ok($data) {
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function json_err($msg, $code = 400) {
  http_response_code($code);
  echo json_encode(['error' => $msg]);
  exit;
}

// ── JWT Verification ─────────────────────────────────────────────────────────

/**
 * Verify a Twitch extension JWT and return the decoded payload array.
 * Throws on failure. Never trust unverified data.
 */
function verify_twitch_jwt(string $token): array {
  $secret_b64 = getenv('TWITCH_EXT_SECRET');
  if (!$secret_b64) {
    throw new Exception('TWITCH_EXT_SECRET not configured');
  }
  $secret = base64_decode($secret_b64, true);
  if ($secret === false) {
    throw new Exception('Invalid TWITCH_EXT_SECRET encoding');
  }

  $parts = explode('.', $token);
  if (count($parts) !== 3) {
    throw new Exception('Malformed JWT');
  }

  [$header_b64, $payload_b64, $sig_b64] = $parts;

  // Verify signature
  $expected_sig = hash_hmac('sha256', "$header_b64.$payload_b64", $secret, true);
  $provided_sig = base64_decode(strtr($sig_b64, '-_', '+/') . str_repeat('=', (4 - strlen($sig_b64) % 4) % 4), true);

  if (!hash_equals($expected_sig, $provided_sig)) {
    throw new Exception('Invalid JWT signature');
  }

  // Decode payload
  $payload_json = base64_decode(strtr($payload_b64, '-_', '+/') . str_repeat('=', (4 - strlen($payload_b64) % 4) % 4), true);
  $payload = json_decode($payload_json, true);
  if (!is_array($payload)) {
    throw new Exception('Could not decode JWT payload');
  }

  // Check expiry
  if (!isset($payload['exp']) || $payload['exp'] < time()) {
    throw new Exception('JWT expired');
  }

  return $payload;
}

/**
 * Extract Bearer token from Authorization header and verify it.
 * Returns verified payload array. Calls json_err() and exits on failure.
 */
function require_jwt(): array {
  $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!str_starts_with($auth, 'Bearer ')) {
    json_err('Missing Authorization header', 401);
  }
  $token = substr($auth, 7);
  try {
    return verify_twitch_jwt($token);
  } catch (Exception $e) {
    json_err('JWT error: ' . $e->getMessage(), 401);
  }
}

/**
 * Resolve a Twitch channel_id to a ClipTV login string.
 * Checks channel_settings first, then known_users. Returns null if not found.
 */
function resolve_login(PDO $pdo, string $channel_id): ?string {
  foreach (['channel_settings', 'known_users'] as $table) {
    try {
      $stmt = $pdo->prepare("SELECT login FROM {$table} WHERE twitch_id = ? LIMIT 1");
      $stmt->execute([$channel_id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row) return $row['login'];
    } catch (PDOException $e) {
      error_log("resolve_login {$table} error: " . $e->getMessage());
    }
  }
  return null;
}

// ── Database connection ───────────────────────────────────────────────────────

$pdo = get_db_connection();
if (!$pdo) json_err('Database unavailable', 503);

// ── Schema Migration (idempotent) ────────────────────────────────────────────

try {
  $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS twitch_id VARCHAR(64)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_channel_settings_twitch_id ON channel_settings(twitch_id)");
  $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP");
} catch (PDOException $e) {
  error_log('extension_api migration error: ' . $e->getMessage());
}

// ── Routing ───────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── GET channel ───────────────────────────────────────────────────────────────

if ($action === 'channel' && $method === 'GET') {
  $payload = require_jwt();
  $channel_id = $payload['channel_id'] ?? '';
  if (!$channel_id) json_err('channel_id missing from JWT', 400);

  $preview = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_GET['preview_login'] ?? '')));
  $login = $preview ?: resolve_login($pdo, $channel_id);

  if (!$login) {
    json_ok(['registered' => false]);
  }

  // Verify they actually have clips
  $count = null;
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ? AND blocked = FALSE");
    $stmt->execute([$login]);
    $count = (int)$stmt->fetchColumn();
  } catch (PDOException $e) {
    error_log('extension_api clip count error: ' . $e->getMessage());
    json_err('Database error', 500);
  }
  if ($count === 0) {
    json_ok(['registered' => false, 'reason' => 'no_clips']);
  }

  // Fetch display info
  $display_name = null;
  $profile_image_url = null;
  try {
    $stmt = $pdo->prepare("SELECT display_name, profile_image_url FROM channel_settings WHERE login = ? LIMIT 1");
    $stmt->execute([$login]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $display_name = $row['display_name'];
      $profile_image_url = $row['profile_image_url'];
    }
  } catch (PDOException $e) {
    error_log('extension_api display info error: ' . $e->getMessage());
  }

  json_ok([
    'registered' => true,
    'login' => $login,
    'display_name' => $display_name ?: $login,
    'profile_image_url' => $profile_image_url ?: null,
    'clip_count' => $count
  ]);
}

// ── GET clips ────────────────────────────────────────────────────────────────

if ($action === 'clips' && $method === 'GET') {
  $payload = require_jwt();
  $channel_id = $payload['channel_id'] ?? '';
  if (!$channel_id) json_err('channel_id missing from JWT', 400);

  $preview = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_GET['preview_login'] ?? '')));
  $login = $preview ?: resolve_login($pdo, $channel_id);
  if (!$login) json_err('Channel not registered with ClipTV', 404);

  $sort  = in_array($_GET['sort'] ?? '', ['recent', 'top', 'random']) ? $_GET['sort'] : 'recent';
  $limit = max(5, min(25, (int)($_GET['limit'] ?? 10)));

  try {
    $base_sql = "
      SELECT c.clip_id as id, c.seq, c.title, c.duration, c.created_at,
             c.view_count, c.creator_name, c.thumbnail_url, c.mp4_url, c.platform,
             g.name as game_name
      FROM clips c
      LEFT JOIN games_cache g ON c.game_id = g.game_id
      WHERE c.login = ? AND c.blocked = FALSE
    ";

    if ($sort === 'top') {
      $sql = $base_sql . " ORDER BY c.view_count DESC LIMIT ?";
    } elseif ($sort === 'random') {
      $sql = $base_sql . " ORDER BY RANDOM() LIMIT ?";
    } else {
      $sql = $base_sql . " ORDER BY c.created_at DESC LIMIT ?";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$login, $limit]);
    $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clips as &$c) {
      if ($c['created_at'] instanceof DateTime) {
        $c['created_at'] = $c['created_at']->format('c');
      }
      $c['clip_url'] = ($c['platform'] === 'kick' && !empty($c['mp4_url']))
        ? $c['mp4_url']
        : 'https://clips.twitch.tv/' . urlencode($c['id']);
      $c['video_url'] = $c['mp4_url'] ?: null;
      unset($c['platform'], $c['mp4_url']);
    }
    unset($c);

    json_ok(['clips' => $clips, 'sort' => $sort, 'login' => $login]);

  } catch (PDOException $e) {
    error_log('extension_api clips error: ' . $e->getMessage());
    json_err('Database error', 500);
  }
}

// ── GET search ────────────────────────────────────────────────────────────────

if ($action === 'search' && $method === 'GET') {
  $payload = require_jwt();
  $channel_id = $payload['channel_id'] ?? '';
  if (!$channel_id) json_err('channel_id missing from JWT', 400);

  $preview = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_GET['preview_login'] ?? '')));
  $login = $preview ?: resolve_login($pdo, $channel_id);
  if (!$login) json_err('Channel not registered with ClipTV', 404);

  $q = trim($_GET['q'] ?? '');
  if (strlen($q) < 2) json_err('Query too short', 400);

  $limit = 20;

  try {
    $stmt = $pdo->prepare("
      SELECT c.clip_id as id, c.seq, c.title, c.duration, c.created_at,
             c.view_count, c.creator_name, c.thumbnail_url, c.mp4_url, c.platform,
             g.name as game_name
      FROM clips c
      LEFT JOIN games_cache g ON c.game_id = g.game_id
      WHERE c.login = ? AND c.blocked = FALSE
        AND (c.title ILIKE ? OR c.creator_name ILIKE ? OR g.name ILIKE ?)
      ORDER BY c.view_count DESC
      LIMIT ?
    ");
    $like = '%' . $q . '%';
    $stmt->execute([$login, $like, $like, $like, $limit]);
    $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clips as &$c) {
      if ($c['created_at'] instanceof DateTime) {
        $c['created_at'] = $c['created_at']->format('c');
      }
      $c['clip_url'] = ($c['platform'] === 'kick' && !empty($c['mp4_url']))
        ? $c['mp4_url']
        : 'https://clips.twitch.tv/' . urlencode($c['id']);
      $c['video_url'] = $c['mp4_url'] ?: null;
      unset($c['platform'], $c['mp4_url']);
    }
    unset($c);

    json_ok(['clips' => $clips, 'query' => $q]);

  } catch (PDOException $e) {
    error_log('extension_api search error: ' . $e->getMessage());
    json_err('Database error', 500);
  }
}

// ── POST settings ─────────────────────────────────────────────────────────────

if ($action === 'settings' && $method === 'POST') {
  $payload = require_jwt();
  $channel_id = $payload['channel_id'] ?? '';
  $role       = $payload['role'] ?? '';

  if ($role !== 'broadcaster') json_err('Broadcaster role required', 403);
  if (!$channel_id) json_err('channel_id missing from JWT', 400);

  $body = json_decode(file_get_contents('php://input'), true) ?? [];

  $login = trim(strtolower($body['login'] ?? ''));
  $login = preg_replace('/[^a-z0-9_]/', '', $login);

  if (!$login) json_err('login required', 400);

  try {
    // Verify this login actually exists and has clips
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ? AND blocked = FALSE");
    $stmt->execute([$login]);
    if ((int)$stmt->fetchColumn() === 0) {
      json_err('No ClipTV library found for that login. Make sure your clips are archived on ClipTV first.', 422);
    }

    // Link twitch_id to this login in channel_settings
    $stmt = $pdo->prepare("
      INSERT INTO channel_settings (login, twitch_id, updated_at)
      VALUES (?, ?, NOW())
      ON CONFLICT (login) DO UPDATE SET twitch_id = EXCLUDED.twitch_id, updated_at = NOW()
    ");
    $stmt->execute([$login, $channel_id]);

    json_ok(['success' => true, 'login' => $login, 'linked' => true]);

  } catch (PDOException $e) {
    error_log('extension_api settings error: ' . $e->getMessage());
    json_err('Database error', 500);
  }
}

json_err('Unknown action', 400);
