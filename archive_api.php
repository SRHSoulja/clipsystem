<?php
/**
 * archive_api.php - Self-service archive API (background processing)
 *
 * Flow:
 *   1. Frontend POSTs to ?action=start   → creates job, returns immediately
 *   2. Frontend POSTs to ?action=process → responds immediately, processes ALL windows server-side
 *   3. Frontend polls  ?action=status    → reads progress from DB
 *   4. When status=complete → frontend redirects to /search/login
 *
 * The browser can close at any time — processing continues server-side.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_api.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

function json_out($data) {
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}

function json_err($msg, $code = 400) {
  http_response_code($code);
  json_out(["error" => $msg]);
}

function clean_login($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "";
}

function sleep_backoff($attempt) {
  $base = min(8, 1 + $attempt);
  usleep($base * 1000000);
}

/**
 * Send JSON response to client and continue executing in the background.
 * Uses fastcgi_finish_request() on PHP-FPM (Railway) or flush trick otherwise.
 */
function send_and_continue($data) {
  ignore_user_abort(true);
  set_time_limit(0);

  $json = json_encode($data, JSON_UNESCAPED_SLASHES);

  // Clean any existing output buffers
  while (ob_get_level() > 0) { ob_end_clean(); }

  header('Content-Length: ' . strlen($json));
  header('Connection: close');
  echo $json;
  flush();

  if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
  }
}

/**
 * Process all remaining 30-day windows, then auto-finalize.
 */
function process_all_windows($pdo, $login, $job) {
  $broadcasterId = $job['broadcaster_id'];
  $totalWindows = (int)$job['total_windows'];
  $currentWindow = (int)$job['current_window'];
  $windowSec = 30 * 86400;
  $archiveStart = !empty($job['archive_start']) ? strtotime($job['archive_start']) : time() - (5 * 365 * 86400);

  $clientId = getenv('TWITCH_CLIENT_ID') ?: '';
  $twitchApi = new TwitchAPI();
  $token = $twitchApi->getAccessToken();
  if (!$token) {
    $pdo->prepare("UPDATE archive_jobs SET status = 'failed', error_message = 'Failed to get Twitch token', updated_at = NOW() WHERE login = ?")
      ->execute([$login]);
    return;
  }

  $headers = [
    "Authorization: Bearer $token",
    "Client-Id: $clientId"
  ];

  $pdo->prepare("UPDATE archive_jobs SET status = 'running', updated_at = NOW() WHERE login = ?")->execute([$login]);

  for ($w = $currentWindow; $w < $totalWindows; $w++) {
    $windowStart = $archiveStart + ($w * $windowSec);
    $windowEnd = min(time(), $windowStart + $windowSec);
    $startedAt = gmdate('Y-m-d\TH:i:s\Z', $windowStart);
    $endedAt = gmdate('Y-m-d\TH:i:s\Z', $windowEnd);

    // Get current max seq
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(seq), 0) FROM clips WHERE login = ?");
    $stmt->execute([$login]);
    $nextSeq = (int)$stmt->fetchColumn() + 1;

    $windowFound = 0;
    $windowInserted = 0;
    $cursor = '';
    $pages = 0;

    try {
      while ($pages < 30) {
        $pages++;

        $url = 'https://api.twitch.tv/helix/clips?broadcaster_id=' . urlencode($broadcasterId)
          . '&first=100'
          . '&started_at=' . urlencode($startedAt)
          . '&ended_at=' . urlencode($endedAt);
        if ($cursor) $url .= '&after=' . urlencode($cursor);

        // Fetch with retry
        $attempt = 0;
        $httpCode = 0;
        $raw = '';
        while (true) {
          $ch = curl_init($url);
          curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
          ]);
          $raw = curl_exec($ch);
          $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
          curl_close($ch);

          if ($httpCode === 429 || $httpCode >= 500) {
            $attempt++;
            if ($attempt > 6) break;
            sleep_backoff($attempt);
            continue;
          }
          break;
        }

        if ($httpCode < 200 || $httpCode >= 300) break;

        $json = json_decode($raw, true);
        $data = $json['data'] ?? [];
        if (empty($data)) break;

        $insertStmt = $pdo->prepare("
          INSERT INTO clips (login, clip_id, seq, title, duration, created_at, view_count, game_id, video_id, vod_offset, thumbnail_url, creator_name)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
          ON CONFLICT (login, clip_id) DO NOTHING
        ");

        foreach ($data as $c) {
          $clipId = $c['id'] ?? '';
          if (!$clipId) continue;

          $insertStmt->execute([
            $login, $clipId, $nextSeq,
            $c['title'] ?? '',
            (int)($c['duration'] ?? 0),
            $c['created_at'] ?? null,
            (int)($c['view_count'] ?? 0),
            $c['game_id'] ?? '',
            $c['video_id'] ?? '',
            $c['vod_offset'] ?? null,
            $c['thumbnail_url'] ?? '',
            $c['creator_name'] ?? '',
          ]);

          if ($insertStmt->rowCount() > 0) {
            $nextSeq++;
            $windowInserted++;
          }
          $windowFound++;
        }

        $next = $json['pagination']['cursor'] ?? '';
        if (!$next || $next === $cursor) break;
        $cursor = $next;

        usleep(120000); // 120ms between pages
      }

      // Update progress after each window (heartbeat)
      $pdo->prepare("
        UPDATE archive_jobs SET
          current_window = ?,
          clips_found = clips_found + ?,
          clips_inserted = clips_inserted + ?,
          updated_at = NOW()
        WHERE login = ?
      ")->execute([$w + 1, $windowFound, $windowInserted, $login]);

    } catch (Exception $e) {
      error_log("archive process error window $w: " . $e->getMessage());
      $pdo->prepare("UPDATE archive_jobs SET status = 'failed', error_message = ?, updated_at = NOW() WHERE login = ?")
        ->execute(["Error at window $w: " . substr($e->getMessage(), 0, 200), $login]);
      return;
    }

    // Refresh token every 10 windows
    if ($w % 10 === 9) {
      $newToken = $twitchApi->getAccessToken();
      if ($newToken) {
        $token = $newToken;
        $headers = [
          "Authorization: Bearer $token",
          "Client-Id: $clientId"
        ];
      }
    }
  }

  // All windows done — auto-finalize
  do_finalize($pdo, $login);
}

/**
 * Finalize: reorder seq numbers, resolve game names, setup streamer.
 */
function do_finalize($pdo, $login) {
  $pdo->prepare("UPDATE archive_jobs SET status = 'resolving_games', updated_at = NOW() WHERE login = ?")->execute([$login]);

  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ?");
    $stmt->execute([$login]);
    $clipCount = (int)$stmt->fetchColumn();

    if ($clipCount > 0) {
      // Reorder seq chronologically (two-phase to avoid unique constraint)
      $pdo->beginTransaction();
      $pdo->prepare("UPDATE clips SET seq = -id WHERE login = ?")->execute([$login]);
      $pdo->exec("
        WITH ordered AS (
          SELECT id, ROW_NUMBER() OVER (ORDER BY created_at ASC NULLS LAST, id ASC) as new_seq
          FROM clips WHERE login = " . $pdo->quote($login) . "
        )
        UPDATE clips SET seq = ordered.new_seq FROM ordered WHERE clips.id = ordered.id
      ");
      $pdo->commit();

      // Resolve game names
      $stmt = $pdo->prepare("
        SELECT DISTINCT c.game_id FROM clips c
        LEFT JOIN games_cache g ON c.game_id = g.game_id
        WHERE c.login = ? AND c.game_id IS NOT NULL AND c.game_id != '' AND g.game_id IS NULL
      ");
      $stmt->execute([$login]);
      $missingGameIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

      if (!empty($missingGameIds)) {
        $twitchApi = new TwitchAPI();
        foreach (array_chunk($missingGameIds, 100) as $batch) {
          $games = $twitchApi->getGamesByIds($batch);
          foreach ($games as $gid => $info) {
            try {
              $pdo->prepare("INSERT INTO games_cache (game_id, name, box_art_url) VALUES (?, ?, ?) ON CONFLICT (game_id) DO UPDATE SET name = EXCLUDED.name")
                ->execute([$gid, $info['name'], $info['box_art_url'] ?? '']);
            } catch (PDOException $e) { /* ignore */ }
          }
        }
      }

      // Setup streamer entry
      require_once __DIR__ . '/includes/dashboard_auth.php';
      $auth = new DashboardAuth();
      $auth->createStreamer($login);

      // Register bot channel (inactive by default)
      try {
        $pdo->prepare("
          INSERT INTO bot_channels (channel_login, added_by, active) VALUES (?, 'archive', FALSE)
          ON CONFLICT (channel_login) DO NOTHING
        ")->execute([$login]);
      } catch (PDOException $e) { /* ignore */ }

      // Init channel settings
      try {
        $pdo->prepare("
          INSERT INTO channel_settings (login, last_refresh) VALUES (?, NOW())
          ON CONFLICT (login) DO UPDATE SET last_refresh = NOW()
        ")->execute([$login]);
      } catch (PDOException $e) { /* ignore */ }
    }

    $pdo->prepare("UPDATE archive_jobs SET status = 'complete', completed_at = NOW(), updated_at = NOW() WHERE login = ?")->execute([$login]);

  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("archive finalize error: " . $e->getMessage());
    $pdo->prepare("UPDATE archive_jobs SET status = 'failed', error_message = ?, updated_at = NOW() WHERE login = ?")
      ->execute(["Finalize error: " . substr($e->getMessage(), 0, 200), $login]);
  }
}

// ── Main router ──────────────────────────────────────

$pdo = get_db_connection();
if (!$pdo) { json_err("Database not available", 500); }
init_votes_tables($pdo);

$action = $_GET['action'] ?? '';
$login = clean_login($_REQUEST['login'] ?? '');

switch ($action) {

// ─────────────────────────────────────────────
// START - Create or resume an archive job
// ─────────────────────────────────────────────
case 'start':
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_err("POST only", 405); }

  $user = getCurrentUser();
  if (!$user) { json_out(["error" => "login_required", "login_url" => "/auth/login.php?return=" . urlencode("/archive?login=$login")]); }
  if (!$login) { json_err("Missing login parameter"); }

  // Already archived?
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ?");
  $stmt->execute([$login]);
  $existingClips = (int)$stmt->fetchColumn();

  // Check for existing job
  $stmt = $pdo->prepare("SELECT * FROM archive_jobs WHERE login = ?");
  $stmt->execute([$login]);
  $job = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($job) {
    if ($job['status'] === 'complete') {
      json_out(["status" => "already_archived", "clip_count" => $existingClips, "redirect" => "/search/$login"]);
    }
    if (in_array($job['status'], ['running', 'resolving_games']) && $job['updated_at'] && strtotime($job['updated_at']) > time() - 300) {
      // Active job — observer mode (just poll status, don't call process)
      $job['progress_pct'] = $job['total_windows'] > 0 ? round($job['current_window'] / $job['total_windows'] * 100) : 0;
      json_out(["status" => "in_progress", "job" => $job]);
    }
    // Stale or failed — will resume from current_window
  } elseif ($existingClips > 0) {
    json_out(["status" => "already_archived", "clip_count" => $existingClips, "redirect" => "/search/$login"]);
  }

  // Rate limit: max 2 concurrent running jobs
  $stmt = $pdo->query("SELECT COUNT(*) FROM archive_jobs WHERE status IN ('running','resolving_games') AND updated_at > NOW() - INTERVAL '5 minutes'");
  $running = (int)$stmt->fetchColumn();
  if ($running >= 2 && (!$job || !in_array($job['status'], ['running', 'resolving_games']))) {
    json_out(["error" => "rate_limited", "message" => "Archive queue is full. Try again in a few minutes."]);
  }

  // Resolve broadcaster info (ID + account created date)
  $twitchApi = new TwitchAPI();
  if (!$twitchApi->isConfigured()) { json_err("Twitch API not configured", 500); }
  $userInfo = $twitchApi->getUserInfo($login);
  if (!$userInfo || empty($userInfo['id'])) { json_out(["error" => "streamer_not_found", "message" => "Streamer '$login' not found on Twitch."]); }
  $broadcasterId = $userInfo['id'];

  // Calculate windows from account creation date (not a hardcoded 5 years)
  $createdAt = strtotime($userInfo['created_at'] ?? '2020-01-01');
  $archiveStart = max($createdAt, strtotime('2016-05-26')); // Clips API launched May 2016
  $daysSinceCreation = max(1, (int)ceil((time() - $archiveStart) / 86400));
  $totalWindows = (int)ceil($daysSinceCreation / 30);

  $archiveStartDate = gmdate('Y-m-d\TH:i:s\Z', $archiveStart);

  // Upsert job (keep current_window if resuming)
  if ($job) {
    $stmt = $pdo->prepare("
      UPDATE archive_jobs SET broadcaster_id = ?, status = 'pending', started_by = ?,
        total_windows = ?, archive_start = ?, updated_at = NOW(), error_message = NULL
      WHERE login = ?
    ");
    $stmt->execute([$broadcasterId, $user['login'], $totalWindows, $archiveStartDate, $login]);
  } else {
    $stmt = $pdo->prepare("
      INSERT INTO archive_jobs (login, broadcaster_id, status, started_by, total_windows, archive_start, current_window, clips_found, clips_inserted)
      VALUES (?, ?, 'pending', ?, ?, ?, 0, 0, 0)
      ON CONFLICT (login) DO UPDATE SET
        broadcaster_id = EXCLUDED.broadcaster_id, status = 'pending', started_by = EXCLUDED.started_by,
        total_windows = EXCLUDED.total_windows, archive_start = EXCLUDED.archive_start, updated_at = NOW(), error_message = NULL
    ");
    $stmt->execute([$login, $broadcasterId, $user['login'], $totalWindows, $archiveStartDate]);
  }

  // Reload job
  $stmt = $pdo->prepare("SELECT * FROM archive_jobs WHERE login = ?");
  $stmt->execute([$login]);
  $job = $stmt->fetch(PDO::FETCH_ASSOC);
  $job['progress_pct'] = 0;

  json_out(["status" => "started", "job" => $job]);
  break;

// ─────────────────────────────────────────────
// STATUS - Poll archive progress (no auth needed)
// ─────────────────────────────────────────────
case 'status':
  if (!$login) { json_err("Missing login parameter"); }

  $stmt = $pdo->prepare("SELECT * FROM archive_jobs WHERE login = ?");
  $stmt->execute([$login]);
  $job = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$job) { json_out(["status" => "not_found"]); }

  $job['progress_pct'] = $job['total_windows'] > 0 ? round($job['current_window'] / $job['total_windows'] * 100) : 0;

  if ($job['status'] === 'complete') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ?");
    $stmt->execute([$login]);
    $job['total_clips'] = (int)$stmt->fetchColumn();
    $job['redirect'] = "/search/$login";
  }

  json_out(["status" => $job['status'], "job" => $job]);
  break;

// ─────────────────────────────────────────────
// PROCESS - Kick off background processing (fire-and-forget)
// ─────────────────────────────────────────────
case 'process':
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_err("POST only", 405); }

  $user = getCurrentUser();
  if (!$user) { json_out(["error" => "login_required"]); }
  if (!$login) { json_err("Missing login parameter"); }

  // Load job
  $stmt = $pdo->prepare("SELECT * FROM archive_jobs WHERE login = ? AND status IN ('pending','running','failed')");
  $stmt->execute([$login]);
  $job = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$job) { json_err("No active archive job for this streamer"); }

  // Already done?
  if ($job['current_window'] >= $job['total_windows']) {
    json_out(["status" => "windows_complete", "needs_finalize" => true]);
  }

  // Send response immediately, then process in background
  send_and_continue(["status" => "processing", "message" => "Archive started. You can close this page — processing continues in the background."]);

  // ── Everything below runs server-side after the response is sent ──
  process_all_windows($pdo, $login, $job);
  exit;

// ─────────────────────────────────────────────
// FINALIZE - Manual fallback (auto-called by process)
// ─────────────────────────────────────────────
case 'finalize':
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_err("POST only", 405); }

  $user = getCurrentUser();
  if (!$user) { json_out(["error" => "login_required"]); }
  if (!$login) { json_err("Missing login parameter"); }

  $stmt = $pdo->prepare("SELECT * FROM archive_jobs WHERE login = ?");
  $stmt->execute([$login]);
  $job = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$job) { json_err("No archive job found"); }

  do_finalize($pdo, $login);

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ?");
  $stmt->execute([$login]);
  $clipCount = (int)$stmt->fetchColumn();

  json_out([
    "status" => "complete",
    "clips_total" => $clipCount,
    "redirect" => "/search/$login",
  ]);
  break;

default:
  json_err("Unknown action: $action");
}
