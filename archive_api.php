<?php
/**
 * archive_api.php - Self-service archive API
 *
 * Flow:
 *   1. Frontend POSTs ?action=start    → creates job
 *   2. Frontend POSTs ?action=process  → processes ONE 30-day window, returns progress
 *   3. Frontend loops process calls until all windows done
 *   4. Frontend POSTs ?action=finalize → reorders clips, resolves games, sets up streamer
 *   5. Frontend redirects to /search/login
 *
 * If the user closes the tab, the current window finishes. When they return,
 * the job resumes from where it left off.
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
 * Process ONE 30-day window. Returns clips found/inserted for this window.
 * Uses TwitchAPI::getClips() which handles auth internally.
 */
function process_one_window($pdo, $login, $job) {
  $broadcasterId = $job['broadcaster_id'];
  $totalWindows = (int)$job['total_windows'];
  $w = (int)$job['current_window'];
  $windowSec = 30 * 86400;
  $archiveStart = !empty($job['archive_start']) ? strtotime($job['archive_start']) : time() - (5 * 365 * 86400);

  $windowStart = $archiveStart + ($w * $windowSec);
  $windowEnd = min(time(), $windowStart + $windowSec);
  $startedAt = gmdate('Y-m-d\TH:i:s\Z', $windowStart);
  $endedAt = gmdate('Y-m-d\TH:i:s\Z', $windowEnd);

  $twitchApi = new TwitchAPI();
  if (!$twitchApi->isConfigured()) {
    $pdo->prepare("UPDATE archive_jobs SET status = 'failed', error_message = 'Twitch API not configured', updated_at = NOW() WHERE login = ?")
      ->execute([$login]);
    return ['error' => 'Twitch API not configured'];
  }

  $pdo->prepare("UPDATE archive_jobs SET status = 'running', updated_at = NOW() WHERE login = ?")->execute([$login]);

  // Get current max seq
  $stmt = $pdo->prepare("SELECT COALESCE(MAX(seq), 0) FROM clips WHERE login = ?");
  $stmt->execute([$login]);
  $nextSeq = (int)$stmt->fetchColumn() + 1;

  $windowFound = 0;
  $windowInserted = 0;
  $cursor = null;
  $pages = 0;

  $insertStmt = $pdo->prepare("
    INSERT INTO clips (login, clip_id, seq, title, duration, created_at, view_count, game_id, video_id, vod_offset, thumbnail_url, creator_name)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON CONFLICT (login, clip_id) DO NOTHING
  ");

  while ($pages < 30) {
    $pages++;

    $result = $twitchApi->getClips($broadcasterId, 100, null, $cursor, $startedAt, $endedAt);
    $clips = $result['clips'] ?? [];

    if (empty($clips)) break;

    foreach ($clips as $c) {
      $clipId = $c['clip_id'] ?? '';
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

    $cursor = $result['cursor'] ?? null;
    if (!$cursor) break;

    usleep(120000); // 120ms between pages
  }

  // Update progress
  $newWindow = $w + 1;
  $pdo->prepare("
    UPDATE archive_jobs SET
      current_window = ?,
      clips_found = clips_found + ?,
      clips_inserted = clips_inserted + ?,
      updated_at = NOW()
    WHERE login = ?
  ")->execute([$newWindow, $windowFound, $windowInserted, $login]);

  // Reload job for response
  $stmt = $pdo->prepare("SELECT * FROM archive_jobs WHERE login = ?");
  $stmt->execute([$login]);
  $updatedJob = $stmt->fetch(PDO::FETCH_ASSOC);
  $updatedJob['progress_pct'] = $totalWindows > 0 ? round($newWindow / $totalWindows * 100) : 0;

  return [
    'window_found' => $windowFound,
    'window_inserted' => $windowInserted,
    'done' => $newWindow >= $totalWindows,
    'job' => $updatedJob,
  ];
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
    return $clipCount;

  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("archive finalize error: " . $e->getMessage());
    $pdo->prepare("UPDATE archive_jobs SET status = 'failed', error_message = ?, updated_at = NOW() WHERE login = ?")
      ->execute(["Finalize error: " . substr($e->getMessage(), 0, 200), $login]);
    return -1;
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
      // Active job — observer mode
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
// PROCESS - Process ONE window, return progress
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

  // Already done with windows?
  if ((int)$job['current_window'] >= (int)$job['total_windows']) {
    json_out(["status" => "windows_complete", "done" => true, "job" => $job]);
  }

  // Process one window
  set_time_limit(120);
  $result = process_one_window($pdo, $login, $job);

  if (isset($result['error'])) {
    json_out(["status" => "failed", "error" => $result['error']]);
  }

  json_out([
    "status" => $result['done'] ? "windows_complete" : "processing",
    "done" => $result['done'],
    "window_found" => $result['window_found'],
    "window_inserted" => $result['window_inserted'],
    "job" => $result['job'],
  ]);
  break;

// ─────────────────────────────────────────────
// FINALIZE - Reorder clips, resolve games, setup streamer
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

  set_time_limit(120);
  $clipCount = do_finalize($pdo, $login);

  if ($clipCount < 0) {
    json_out(["status" => "failed", "error" => "Finalize failed"]);
  }

  json_out([
    "status" => "complete",
    "clips_total" => $clipCount,
    "redirect" => "/search/$login",
  ]);
  break;

default:
  json_err("Unknown action: $action");
}
