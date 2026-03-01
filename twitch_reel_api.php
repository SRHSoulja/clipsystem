<?php
// twitch_reel_api.php (PHP 7 compatible, serves from local catalog index)
// Requires: cache/clips_index_<login>.json produced by your backfill script.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function load_env($path) {
  if (!file_exists($path)) return;
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    if ($line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    list($k, $v) = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v));
  }
}

function safe_login($s) {
  $s = strtolower(trim($s));
  return preg_replace('/[^a-z0-9_]/', '_', $s);
}

function read_json_file($path) {
  $raw = @file_get_contents($path);
  if ($raw === false) return null;
  $j = json_decode($raw, true);
  return is_array($j) ? $j : null;
}

function write_json_file($path, $arr) {
  @file_put_contents($path, json_encode($arr, JSON_UNESCAPED_SLASHES));
}

function parse_iso_time($iso) {
  if (!$iso) return 0;
  $t = strtotime($iso);
  return $t ? $t : 0;
}

// ---- env (optional, kept for consistency) ----
load_env(__DIR__ . '/.env');

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/clip_filter.php';

// ---- query params ----
$login = isset($_GET['login']) ? strtolower(trim($_GET['login'])) : 'floppyjimmie';

// days is now used as "recency window" for weighting; set days=0 to ignore recency weighting
$days  = isset($_GET['days']) ? intval($_GET['days']) : 180;
if ($days < 0) $days = 0;
if ($days > 3650) $days = 3650;

$pool  = isset($_GET['pool']) ? intval($_GET['pool']) : 400;
if ($pool < 50) $pool = 50;
if ($pool > 15000) $pool = 15000; // allow full catalog export

// Check if advance=1 is passed (player finished a clip and wants to advance playlist)
$advance = isset($_GET['advance']) && $_GET['advance'] === '1';

// Check if rotate=1 is passed (enable smart rotation mode)
$rotate = isset($_GET['rotate']) && $_GET['rotate'] === '1';

// ---- Check for active playlist first ----
$pdo = get_db_connection();
if ($pdo) {
  // Ensure platform/mp4_url columns exist (added for Kick support)
  try {
    $pdo->exec("ALTER TABLE clips ADD COLUMN IF NOT EXISTS platform VARCHAR(16) DEFAULT 'twitch'");
    $pdo->exec("ALTER TABLE clips ADD COLUMN IF NOT EXISTS mp4_url TEXT");
  } catch (PDOException $e) {
    // columns may already exist
  }
  try {
    // Check if there's an active playlist for this login
    $stmt = $pdo->prepare("SELECT playlist_id, current_index FROM playlist_active WHERE login = ?");
    $stmt->execute([$login]);
    $active = $stmt->fetch();

    if ($active) {
      $playlistId = (int)$active['playlist_id'];
      $currentIndex = (int)$active['current_index'];

      // If advance=1, increment the current index
      if ($advance) {
        $currentIndex++;
        $stmt = $pdo->prepare("UPDATE playlist_active SET current_index = ?, updated_at = CURRENT_TIMESTAMP WHERE login = ?");
        $stmt->execute([$currentIndex, $login]);
      }

      // Get playlist clips in order with game names
      $stmt = $pdo->prepare("
        SELECT c.clip_id as id, c.seq, c.title, c.duration, c.created_at, c.view_count, c.game_id,
               c.creator_name, c.mp4_url, c.platform, g.name as game_name
        FROM playlist_clips pc
        JOIN clips c ON c.login = ? AND c.seq = pc.clip_seq
        LEFT JOIN games_cache g ON c.game_id = g.game_id
        WHERE pc.playlist_id = ?
        ORDER BY pc.position
      ");
      $stmt->execute([$login, $playlistId]);
      $playlistClips = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Get playlist name
      $stmt = $pdo->prepare("SELECT name FROM playlists WHERE id = ?");
      $stmt->execute([$playlistId]);
      $playlistInfo = $stmt->fetch();
      $playlistName = $playlistInfo ? $playlistInfo['name'] : 'Unknown';

      // Check if we've finished the playlist
      if ($currentIndex >= count($playlistClips)) {
        // Playlist finished - clear it and fall through to normal clips
        $stmt = $pdo->prepare("DELETE FROM playlist_active WHERE login = ?");
        $stmt->execute([$login]);
        // Don't return - fall through to normal weighted clips
      } else {
        // Return playlist clips starting from current index
        // The player will play them in order
        $remainingClips = array_slice($playlistClips, $currentIndex);

        $out = [
          "login" => $login,
          "source" => "playlist",
          "playlist_mode" => true,
          "playlist_id" => $playlistId,
          "playlist_name" => $playlistName,
          "playlist_index" => $currentIndex,
          "playlist_total" => count($playlistClips),
          "count" => count($remainingClips),
          "clips" => $remainingClips,
          "fetched_at" => gmdate('c'),
        ];

        echo json_encode($out, JSON_UNESCAPED_SLASHES);
        exit;
      }
    }
  } catch (PDOException $e) {
    error_log("playlist check error: " . $e->getMessage());
    // Fall through to normal clips
  }
}

// ---- small output cache (prevents OBS rapid refresh reshuffling constantly) ----
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

$safe = safe_login($login);
$rotateStr = $rotate ? '_rotate' : '';
$cacheKey = "reel_{$safe}_days{$days}_pool{$pool}{$rotateStr}.json";
$cacheFile = $cacheDir . '/' . $cacheKey;

// Cache TTL - 5 minutes balances freshness with reduced DB load
// With player pooling every 5s, this gives ~99% cache hit rate vs ~5.5% at 90s
$cacheTtlSeconds = 300;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtlSeconds)) {
  readfile($cacheFile);
  exit;
}

// ---- Try PostgreSQL first for clips ----
$all = [];
$totalAll = 0;
$blockedCount = 0;
$source = "database";
$pdo = get_db_connection();

if ($pdo) {
  try {
    // Get total count and blocked count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ?");
    $stmt->execute([$login]);
    $totalAll = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ? AND blocked = TRUE");
    $stmt->execute([$login]);
    $blockedCount = (int)$stmt->fetchColumn();

    if ($totalAll > 0) {
      // Check if mp4_only mode requested (for OBS native video playback)
      $mp4Only = isset($_GET['mp4']) && $_GET['mp4'] === '1';

      // Get content filters for this channel
      $clipFilter = new ClipFilter($pdo, $login);
      $filterData = $clipFilter->getWhereClause();

      // Fetch all non-blocked clips from database with game names
      // If mp4_only, filter to clips with valid thumbnail_url (not NULL, not empty, not NOT_FOUND)
      $sql = "
        SELECT c.clip_id as id, c.seq, c.title, c.duration, c.created_at, c.view_count, c.game_id, c.video_id, c.vod_offset,
               c.creator_name, c.thumbnail_url, c.mp4_url, c.platform, g.name as game_name
        FROM clips c
        LEFT JOIN games_cache g ON c.game_id = g.game_id
        WHERE c.login = ? AND c.blocked = FALSE
      ";
      if ($mp4Only) {
        $sql .= " AND c.thumbnail_url IS NOT NULL AND c.thumbnail_url != '' AND c.thumbnail_url != 'NOT_FOUND'";
      }
      // Add content filter clauses (blocked words, blocked clippers)
      $sql .= $filterData['sql'];
      $sql .= " ORDER BY c.created_at DESC";

      $stmt = $pdo->prepare($sql);
      $params = array_merge([$login], $filterData['params']);
      $stmt->execute($params);
      $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Convert created_at to ISO format string if needed
      foreach ($all as &$c) {
        if ($c['created_at'] instanceof DateTime) {
          $c['created_at'] = $c['created_at']->format('c');
        }
      }
      unset($c);
    }
  } catch (PDOException $e) {
    error_log("reel_api db error: " . $e->getMessage());
    $all = []; // Fall through to JSON
  }
}

// ---- Fallback to JSON if database empty or unavailable ----
if (empty($all)) {
  $source = "local_index";
  $indexFile = $cacheDir . "/clips_index_{$safe}.json";
  $index = read_json_file($indexFile);

  if (!$index || !isset($index['clips']) || !is_array($index['clips']) || !count($index['clips'])) {
    http_response_code(500);
    $out = [
      "error" => "Missing or empty catalog index. Expected: {$indexFile}",
      "login" => $login,
    ];
    echo json_encode($out, JSON_UNESCAPED_SLASHES);
    exit;
  }

  $all = $index['clips'];
  $totalAll = count($all);

  // Filter out blocked clips from JSON (check database blocklist + file)
  $blockedIds = [];

  if ($pdo) {
    try {
      $stmt = $pdo->prepare("SELECT clip_id FROM blocklist WHERE login = ?");
      $stmt->execute([$login]);
      while ($row = $stmt->fetch()) {
        $blockedIds[$row['clip_id']] = true;
      }
    } catch (PDOException $e) {
      error_log("blocklist db error: " . $e->getMessage());
    }
  }

  // Also check file-based blocklist (for backwards compatibility)
  $runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : $cacheDir;
  $blocklistFile = $runtimeDir . "/blocklist_{$safe}.json";
  if (file_exists($blocklistFile)) {
    $blockRaw = @file_get_contents($blocklistFile);
    $blocklist = $blockRaw ? json_decode($blockRaw, true) : [];
    if (is_array($blocklist)) {
      foreach ($blocklist as $b) {
        if (isset($b["clip_id"])) {
          $blockedIds[$b["clip_id"]] = true;
        }
      }
    }
  }

  if (count($blockedIds) > 0) {
    $all = array_filter($all, function($c) use ($blockedIds) {
      $id = isset($c['id']) ? $c['id'] : '';
      return !isset($blockedIds[$id]);
    });
    $all = array_values($all); // reindex
  }

  $blockedCount = count($blockedIds);
}

function shuffle_in_place(&$arr) {
  // stronger shuffle than default in some environments by reseeding
  for ($i = count($arr) - 1; $i > 0; $i--) {
    $j = random_int(0, $i);
    $tmp = $arr[$i];
    $arr[$i] = $arr[$j];
    $arr[$j] = $tmp;
  }
}

$take = function($src, $n) {
  if ($n <= 0) return [];
  return array_slice($src, 0, min($n, count($src)));
};

// ---- Smart Rotation Mode ----
if ($rotate && $pdo) {
  try {
    // Get all clip IDs from our collection
    $allClipIds = array_map(function($c) { return $c['id']; }, $all);
    $clipIdMap = [];
    foreach ($all as $c) {
      $clipIdMap[$c['id']] = $c;
    }

    // Get clip play history for this login
    $playedClips = [];
    if (count($allClipIds) > 0) {
      $placeholders = str_repeat('?,', count($allClipIds) - 1) . '?';
      $stmt = $pdo->prepare("
        SELECT clip_id, last_played_at, play_count
        FROM clip_plays
        WHERE login = ? AND clip_id IN ($placeholders)
        ORDER BY last_played_at ASC NULLS FIRST
      ");
      $params = array_merge([$login], $allClipIds);
      $stmt->execute($params);
      while ($row = $stmt->fetch()) {
        $playedClips[$row['clip_id']] = $row;
      }
    }

    // Get top-voted clips (net score > 0)
    $topVoted = [];
    $stmt = $pdo->prepare("
      SELECT clip_id, (up_votes - down_votes) as net_score
      FROM votes
      WHERE login = ? AND (up_votes - down_votes) > 0
      ORDER BY net_score DESC
      LIMIT 1000
    ");
    $stmt->execute([$login]);
    while ($row = $stmt->fetch()) {
      if (isset($clipIdMap[$row['clip_id']])) {
        $topVoted[$row['clip_id']] = $row['net_score'];
      }
    }

    // Categorize clips
    $neverPlayed = [];
    $leastRecentlyPlayed = [];

    foreach ($all as $c) {
      $id = $c['id'];
      if (!isset($playedClips[$id])) {
        // Never played
        $neverPlayed[] = $c;
      } else {
        // Has been played - add to least recently played list
        $leastRecentlyPlayed[] = $c;
      }
    }

    // Sort least recently played by last_played_at ascending (oldest first)
    usort($leastRecentlyPlayed, function($a, $b) use ($playedClips) {
      $timeA = strtotime($playedClips[$a['id']]['last_played_at']);
      $timeB = strtotime($playedClips[$b['id']]['last_played_at']);
      return $timeA - $timeB;
    });

    // Shuffle within categories for variety
    shuffle_in_place($neverPlayed);

    // Build rotation pool with target distribution:
    // 70% fresh/unplayed, 20% least-recently-played, 10% top-voted
    $wantFresh = (int)floor($pool * 0.70);
    $wantLRP = (int)floor($pool * 0.20);
    $wantTopVoted = $pool - $wantFresh - $wantLRP;

    $pick = [];

    // 1. Fresh/unplayed clips (70%)
    $pickFresh = $take($neverPlayed, $wantFresh);
    $pick = array_merge($pick, $pickFresh);

    // 2. Least-recently-played (20%)
    $pickLRP = $take($leastRecentlyPlayed, $wantLRP);
    $pick = array_merge($pick, $pickLRP);

    // 3. Top-voted favorites (10%)
    // Filter to clips we have and sort by score
    $topVotedClips = [];
    foreach ($all as $c) {
      if (isset($topVoted[$c['id']])) {
        $topVotedClips[] = $c;
      }
    }
    usort($topVotedClips, function($a, $b) use ($topVoted) {
      return $topVoted[$b['id']] - $topVoted[$a['id']];
    });
    $pickTopVoted = $take($topVotedClips, $wantTopVoted);
    $pick = array_merge($pick, $pickTopVoted);

    // Fill remainder from remaining pool if we're short
    $remain = $pool - count($pick);
    if ($remain > 0) {
      $pickFreshRem = $take(array_slice($neverPlayed, count($pickFresh)), $remain);
      $pick = array_merge($pick, $pickFreshRem);
    }
    $remain = $pool - count($pick);
    if ($remain > 0) {
      $pickLRPRem = $take(array_slice($leastRecentlyPlayed, count($pickLRP)), $remain);
      $pick = array_merge($pick, $pickLRPRem);
    }
    $remain = $pool - count($pick);
    if ($remain > 0) {
      shuffle_in_place($all);
      $pick = array_merge($pick, $take($all, $remain));
    }

    // Final dedupe by ID
    $seen = [];
    $clips = [];
    foreach ($pick as $c) {
      $id = isset($c['id']) ? $c['id'] : '';
      if (!$id) continue;
      if (isset($seen[$id])) continue;
      $seen[$id] = true;
      $clips[] = $c;
      if (count($clips) >= $pool) break;
    }

  } catch (PDOException $e) {
    error_log("smart rotation error: " . $e->getMessage());
    // Fall back to regular mode
    $rotate = false;
  }
}

// ---- Standard Time-Based Rotation Mode (default) ----
if (!$rotate) {
  $now = time();
  $recentCut = ($days > 0) ? ($now - ($days * 86400)) : 0;

  // Buckets to keep variety:
  // - 60% recent (within days window)
  // - 30% mid (older than days, within 2 years)
  // - 10% old (older than 2 years)
  $recent = [];
  $mid = [];
  $old = [];

  $twoYearsCut = $now - (730 * 86400);

  foreach ($all as $c) {
    $ts = parse_iso_time(isset($c['created_at']) ? $c['created_at'] : '');
    if ($ts <= 0) {
      $old[] = $c;
      continue;
    }

    if ($days > 0 && $ts >= $recentCut) {
      $recent[] = $c;
    } elseif ($ts >= $twoYearsCut) {
      $mid[] = $c;
    } else {
      $old[] = $c;
    }
  }

  // If days=0, treat everything as "mid" for a balanced shuffle
  if ($days === 0) {
    $mid = $all;
    $recent = [];
    $old = [];
  }

  shuffle_in_place($recent);
  shuffle_in_place($mid);
  shuffle_in_place($old);

  // Determine target counts
  $wantRecent = (int)floor($pool * 0.60);
  $wantMid    = (int)floor($pool * 0.30);
  $wantOld    = $pool - $wantRecent - $wantMid;

  // If a bucket is short, spill into others
  $pick = [];

  $pickRecent = $take($recent, $wantRecent);
  $pickMid    = $take($mid, $wantMid);
  $pickOld    = $take($old, $wantOld);

  $pick = array_merge($pickRecent, $pickMid, $pickOld);

  // Fill remainder from anywhere (recent -> mid -> old -> all)
  $remain = $pool - count($pick);
  if ($remain > 0) $pick = array_merge($pick, $take(array_slice($recent, count($pickRecent)), $remain));
  $remain = $pool - count($pick);
  if ($remain > 0) $pick = array_merge($pick, $take(array_slice($mid, count($pickMid)), $remain));
  $remain = $pool - count($pick);
  if ($remain > 0) $pick = array_merge($pick, $take(array_slice($old, count($pickOld)), $remain));
  $remain = $pool - count($pick);
  if ($remain > 0) {
    shuffle_in_place($all);
    $pick = array_merge($pick, $take($all, $remain));
  }

  // Final dedupe by ID (paranoia-safe)
  $seen = [];
  $clips = [];
  foreach ($pick as $c) {
    $id = isset($c['id']) ? $c['id'] : '';
    if (!$id) continue;
    if (isset($seen[$id])) continue;
    $seen[$id] = true;
    $clips[] = $c;
    if (count($clips) >= $pool) break;
  }
}

$out = [
  "login" => $login,
  "source" => $source,
  "index_total" => $totalAll,
  "blocked_count" => $blockedCount,
  "days_window" => $days,
  "rotation_mode" => $rotate ? "smart" : "time-based",
  "count" => count($clips),
  "clips" => $clips,
  "fetched_at" => gmdate('c'),
];

write_json_file($cacheFile, $out);
echo json_encode($out, JSON_UNESCAPED_SLASHES);
