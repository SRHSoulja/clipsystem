<?php
/**
 * cron_refresh.php - Automatic clip refresh for all archived channels
 *
 * Triggered by an external cron service (e.g., cron-job.org) every few hours.
 * Loops through all channels and fetches new clips since their last refresh.
 *
 * Auth: requires ?key=ADMIN_KEY query parameter.
 * Usage: GET /cron_refresh.php?key=YOUR_ADMIN_KEY
 *
 * Respects Railway's request timeout by processing channels one at a time
 * and tracking progress. If it times out, the next run picks up where it left off
 * since each channel's last_refresh is updated after completion.
 */

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
set_time_limit(240); // 4 minute max

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_api.php';

// Auth via admin key
$adminKey = getenv('ADMIN_KEY') ?: '';
$providedKey = $_GET['key'] ?? '';

if (!$adminKey || $providedKey !== $adminKey) {
  http_response_code(403);
  echo json_encode(["error" => "Invalid key"]);
  exit;
}

$pdo = get_db_connection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(["error" => "Database not available"]);
  exit;
}
init_votes_tables($pdo);

$twitchApi = new TwitchAPI();
if (!$twitchApi->isConfigured()) {
  http_response_code(500);
  echo json_encode(["error" => "Twitch API not configured"]);
  exit;
}

// Get all channels that have clips, ordered by least recently refreshed first
$stmt = $pdo->query("
  SELECT DISTINCT c.login,
    cs.last_refresh,
    (SELECT COUNT(*) FROM clips WHERE login = c.login) as clip_count
  FROM clips c
  LEFT JOIN channel_settings cs ON cs.login = c.login
  GROUP BY c.login, cs.last_refresh
  ORDER BY cs.last_refresh ASC NULLS FIRST
");
$channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];
$startTime = time();
$maxRuntime = 210; // Stop after 3.5 minutes to leave buffer before timeout

foreach ($channels as $channel) {
  // Check if we're running out of time
  if (time() - $startTime > $maxRuntime) {
    $results[] = ["login" => "TIMEOUT", "message" => "Stopped to avoid timeout, remaining channels will be refreshed next run"];
    break;
  }

  $login = $channel['login'];
  $lastRefresh = $channel['last_refresh'];

  // Skip if refreshed within last 4 hours
  if ($lastRefresh && strtotime($lastRefresh) > time() - (4 * 3600)) {
    continue;
  }

  $channelResult = refresh_channel($pdo, $twitchApi, $login, $lastRefresh);
  $results[] = $channelResult;
}

echo json_encode([
  "status" => "ok",
  "channels_checked" => count($channels),
  "channels_refreshed" => count($results),
  "runtime_seconds" => time() - $startTime,
  "results" => $results,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

/**
 * Refresh one channel's clips. Mirrors refresh_clips.php logic but headless.
 */
function refresh_channel($pdo, $twitchApi, $login, $lastRefresh) {
  $result = ["login" => $login, "new_clips" => 0, "errors" => 0];

  try {
    // Get latest clip date and max seq
    $stmt = $pdo->prepare("SELECT MAX(seq) as max_seq, MAX(created_at) as latest_date FROM clips WHERE login = ?");
    $stmt->execute([$login]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $maxSeq = (int)($row['max_seq'] ?? 0);
    $latestDate = $row['latest_date'] ?? null;

    if (!$latestDate) {
      $result['skipped'] = 'no existing clips';
      return $result;
    }

    // Get broadcaster ID
    $userInfo = $twitchApi->getUserInfo($login);
    if (!$userInfo || empty($userInfo['id'])) {
      $result['error'] = 'broadcaster not found';
      return $result;
    }
    $broadcasterId = $userInfo['id'];

    // Calculate fetch window
    $baseDate = $lastRefresh ? strtotime($lastRefresh) : strtotime($latestDate);
    $fetchStart = $baseDate - 86400; // 24h safety margin
    $now = time();
    $windowSec = 7 * 86400; // 7-day windows
    $totalWindows = (int)ceil(($now - $fetchStart) / $windowSec);

    $result['windows'] = $totalWindows;
    $result['from'] = gmdate('Y-m-d\TH:i:s\Z', $fetchStart);

    $nextSeq = $maxSeq + 1;
    $totalInserted = 0;

    $insertStmt = $pdo->prepare("
      INSERT INTO clips (login, clip_id, seq, title, duration, created_at, view_count, game_id, video_id, vod_offset, creator_name, thumbnail_url)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT (login, clip_id) DO NOTHING
    ");

    // Process each window
    for ($w = 0; $w < $totalWindows; $w++) {
      $windowStart = $fetchStart + ($w * $windowSec);
      $windowEnd = min($now, $windowStart + $windowSec);
      $startedAt = gmdate('Y-m-d\TH:i:s\Z', $windowStart);
      $endedAt = gmdate('Y-m-d\TH:i:s\Z', $windowEnd);

      $cursor = null;
      $pages = 0;

      while ($pages < 30) {
        $pages++;
        $apiResult = $twitchApi->getClips($broadcasterId, 100, null, $cursor, $startedAt, $endedAt);
        $clips = $apiResult['clips'] ?? [];

        if (empty($clips)) break;

        foreach ($clips as $c) {
          $clipId = $c['clip_id'] ?? '';
          if (!$clipId) continue;

          try {
            $insertStmt->execute([
              $login, $clipId, $nextSeq,
              $c['title'] ?? '',
              (int)($c['duration'] ?? 0),
              $c['created_at'] ?? null,
              (int)($c['view_count'] ?? 0),
              $c['game_id'] ?? '',
              $c['video_id'] ?? '',
              $c['vod_offset'] ?? null,
              $c['creator_name'] ?? '',
              $c['thumbnail_url'] ?? '',
            ]);

            if ($insertStmt->rowCount() > 0) {
              $nextSeq++;
              $totalInserted++;
            }
          } catch (PDOException $e) {
            $result['errors']++;
          }
        }

        $cursor = $apiResult['cursor'] ?? null;
        if (!$cursor) break;

        usleep(120000); // 120ms pacing
      }
    }

    $result['new_clips'] = $totalInserted;

    // Resolve missing game names
    $stmt = $pdo->prepare("
      SELECT DISTINCT c.game_id FROM clips c
      LEFT JOIN games_cache g ON c.game_id = g.game_id
      WHERE c.login = ? AND c.game_id IS NOT NULL AND c.game_id != '' AND g.game_id IS NULL
    ");
    $stmt->execute([$login]);
    $missingGameIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($missingGameIds)) {
      $games = $twitchApi->getGamesByIds($missingGameIds);
      foreach ($games as $gid => $info) {
        try {
          $pdo->prepare("INSERT INTO games_cache (game_id, name, box_art_url) VALUES (?, ?, ?) ON CONFLICT (game_id) DO UPDATE SET name = EXCLUDED.name")
            ->execute([$gid, $info['name'], $info['box_art_url'] ?? '']);
        } catch (PDOException $e) { /* ignore */ }
      }
      $result['games_resolved'] = count($games);
    }

    // Update last_refresh
    $pdo->prepare("
      INSERT INTO channel_settings (login, last_refresh) VALUES (?, NOW())
      ON CONFLICT (login) DO UPDATE SET last_refresh = NOW()
    ")->execute([$login]);

  } catch (Exception $e) {
    $result['error'] = substr($e->getMessage(), 0, 200);
  }

  return $result;
}
