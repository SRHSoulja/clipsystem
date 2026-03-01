<?php
/**
 * clip_search.php - Display search results for clips
 *
 * Web page that shows all clips matching a search query.
 * Supports category filtering and links directly to Twitch.
 * Falls back to live Twitch API for non-archived streamers.
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_api.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Content-Security-Policy: upgrade-insecure-requests");

// Get current user for voting
$currentUser = getCurrentUser();

// Check if user can manage clips (block/unblock) for this channel
$canManageClips = false;
function checkClipManagePermission($login, $currentUser, $pdo) {
    if (!$currentUser) return false;

    require_once __DIR__ . '/includes/dashboard_auth.php';

    $userLogin = strtolower($currentUser['login']);

    // Super admin can manage any channel
    if (isSuperAdmin()) return true;

    // Streamer can manage their own channel
    if ($userLogin === strtolower($login)) return true;

    // Check if user is a mod with block_clips permission
    if ($pdo) {
        try {
            // Check if user is in channel_mods
            $stmt = $pdo->prepare("SELECT 1 FROM channel_mods WHERE channel_login = ? AND mod_username = ?");
            $stmt->execute([strtolower($login), $userLogin]);
            if ($stmt->fetch()) {
                // Check if they have block_clips permission
                $permStmt = $pdo->prepare("SELECT 1 FROM mod_permissions WHERE channel_login = ? AND mod_username = ? AND permission = 'block_clips'");
                $permStmt->execute([strtolower($login), $userLogin]);
                if ($permStmt->fetch()) {
                    return true;
                }
            }
        } catch (PDOException $e) {
            // Permission check failed, deny access
        }
    }

    return false;
}

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

// Accept both "streamer" and "login" parameters (streamer takes priority)
$login  = clean_login($_GET["streamer"] ?? $_GET["login"] ?? "");
$query  = trim((string)($_GET["q"] ?? ""));
$gameId = trim((string)($_GET["game_id"] ?? ""));
$gameName = trim((string)($_GET["game"] ?? "")); // Search by game name
$clipper = trim((string)($_GET["clipper"] ?? ""));
$sort   = $_GET["sort"] ?? "views"; // views, date, title, trending
$page   = max(1, (int)($_GET["page"] ?? 1));
$perPage = max(25, min(200, (int)($_GET["per_page"] ?? 100))); // 25-200, default 100
$dateRange = $_GET["range"] ?? "year"; // Date range for live mode
$minDuration = $_GET["duration"] ?? ""; // short, medium, long, or empty for all
$minViews = max(0, (int)($_GET["min_views"] ?? 0)); // Minimum view count filter
$exclude = trim((string)($_GET["exclude"] ?? "")); // Exclude clips with these phrases in title

// Parse exclude phrases (comma-separated)
$excludeWords = [];
if ($exclude) {
  $excludeWords = array_filter(array_map('trim', explode(',', $exclude)), function($w) { return strlen($w) >= 2; });
}

// Validate sort option
$validSorts = ['views', 'date', 'oldest', 'title', 'titlez', 'trending'];
if (!in_array($sort, $validSorts)) {
  $sort = 'views';
}

// Validate date range
$validRanges = ['week', 'month', '3months', '6months', 'year', '2years', '3years', 'all'];
if (!in_array($dateRange, $validRanges)) {
  $dateRange = 'year';
}

// Validate duration filter
$validDurations = ['', 'short', 'medium', 'long'];
if (!in_array($minDuration, $validDurations)) {
  $minDuration = '';
}

// Split query into words for multi-word search
$queryWords = [];
if ($query) {
  $queryWords = preg_split('/\s+/', trim($query));
  $queryWords = array_filter($queryWords, function($w) { return strlen($w) >= 2; });
}

// Search for clips
$matches = [];
$totalCount = 0;
$totalPages = 0;
$games = [];
$currentGameName = "";
$isLiveMode = false;  // True if using live Twitch API instead of archive
$liveError = "";

$pdo = get_db_connection();
if ($pdo) init_votes_tables($pdo);

// Check if current user can manage clips for this channel
$canManageClips = checkClipManagePermission($login, $currentUser, $pdo);

// Check if this streamer has archived clips and get their profile image
$hasArchivedClips = false;
$streamerProfileImage = '';
if ($pdo) {
  try {
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ?");
    $checkStmt->execute([$login]);
    $hasArchivedClips = (int)$checkStmt->fetchColumn() > 0;

    // Get streamer's profile image if available
    if ($hasArchivedClips) {
      $imgStmt = $pdo->prepare("SELECT profile_image_url FROM channel_settings WHERE login = ?");
      $imgStmt->execute([$login]);
      $imgResult = $imgStmt->fetch();
      if ($imgResult && !empty($imgResult['profile_image_url'])) {
        $streamerProfileImage = $imgResult['profile_image_url'];
      }
    }

  } catch (PDOException $e) {
    // Ignore - will fall through to live mode
  }

  // If no profile image in DB, fetch from Twitch API and cache it
  // This works for both archived and non-archived streamers
  if (empty($streamerProfileImage)) {
    try {
      $twitchApi = new TwitchAPI();
      if ($twitchApi->isConfigured()) {
        $userInfo = $twitchApi->getUserInfo($login);
        if ($userInfo && !empty($userInfo['profile_image_url'])) {
          $streamerProfileImage = $userInfo['profile_image_url'];
          // Cache it in the database for next time (only for archived streamers)
          if ($pdo && $hasArchivedClips) {
            try {
              $cacheStmt = $pdo->prepare("
                INSERT INTO channel_settings (login, profile_image_url, profile_image_updated_at)
                VALUES (?, ?, NOW())
                ON CONFLICT (login) DO UPDATE SET
                  profile_image_url = EXCLUDED.profile_image_url,
                  profile_image_updated_at = EXCLUDED.profile_image_updated_at
              ");
              $cacheStmt->execute([$login, $streamerProfileImage]);
            } catch (PDOException $e) {
              error_log("Failed to cache profile image for $login: " . $e->getMessage());
            }
          }
        }
      }
    } catch (Exception $e) {
      error_log("Failed to fetch profile image from Twitch for $login: " . $e->getMessage());
    }
  }
}

// Use archived clips if available, otherwise fall back to live Twitch API
if ($hasArchivedClips && $pdo) {
  try {
    // Fetch available games/categories for the dropdown
    $gamesStmt = $pdo->prepare("
      SELECT c.game_id, gc.name, COUNT(*) as count
      FROM clips c
      LEFT JOIN games_cache gc ON c.game_id = gc.game_id
      WHERE c.login = ? AND c.blocked = FALSE AND c.game_id IS NOT NULL AND c.game_id != ''
      GROUP BY c.game_id, gc.name
      ORDER BY count DESC
      LIMIT 100
    ");
    $gamesStmt->execute([$login]);
    $games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Sort games: Just Chatting, IRL, I'm Only Sleeping first, then alphabetical
    usort($games, function($a, $b) {
      $priority = ['Just Chatting' => 0, 'IRL' => 1, "I'm Only Sleeping" => 2];
      $nameA = $a['name'] ?: '';
      $nameB = $b['name'] ?: '';
      $prioA = $priority[$nameA] ?? 999;
      $prioB = $priority[$nameB] ?? 999;
      if ($prioA !== $prioB) return $prioA - $prioB;
      return strcasecmp($nameA, $nameB);
    });

    // Add virtual combined categories (search multiple games at once)
    // These use special "combo:" prefix for game_id
    $comboCats = [
      ['pattern' => 'Super Mario', 'label' => 'Super Mario (All)'],
      ['pattern' => 'Mario', 'label' => 'Mario (All)'],
    ];
    foreach ($comboCats as $combo) {
      $comboCount = 0;
      foreach ($games as $g) {
        if (stripos($g['name'] ?? '', $combo['pattern']) !== false) {
          $comboCount += (int)$g['count'];
        }
      }
      if ($comboCount > 0) {
        $games[] = [
          'game_id' => 'combo:' . $combo['pattern'],
          'name' => $combo['label'],
          'count' => $comboCount,
          'is_combo' => true
        ];
      }
    }

    // Re-sort to put combo categories in alphabetical position
    usort($games, function($a, $b) {
      $priority = ['Just Chatting' => 0, 'IRL' => 1, "I'm Only Sleeping" => 2];
      $nameA = $a['name'] ?: '';
      $nameB = $b['name'] ?: '';
      $prioA = $priority[$nameA] ?? 999;
      $prioB = $priority[$nameB] ?? 999;
      if ($prioA !== $prioB) return $prioA - $prioB;
      return strcasecmp($nameA, $nameB);
    });

    // If a game is selected, get its name
    if ($gameId) {
      foreach ($games as $g) {
        if ($g['game_id'] === $gameId) {
          $currentGameName = $g['name'] ?: "Game $gameId";
          break;
        }
      }
    }

    // Build WHERE clause
    $whereClauses = ["login = ?", "blocked = FALSE"];
    $params = [$login];

    // Game filter by ID (or combo pattern)
    if ($gameId) {
      if (strpos($gameId, 'combo:') === 0) {
        // Combo category - search for games matching the pattern
        $comboPattern = substr($gameId, 6); // Remove "combo:" prefix
        $comboStmt = $pdo->prepare("SELECT game_id FROM games_cache WHERE name ILIKE ?");
        $comboStmt->execute(['%' . $comboPattern . '%']);
        $comboGameIds = $comboStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($comboGameIds)) {
          $placeholders = implode(',', array_fill(0, count($comboGameIds), '?'));
          $whereClauses[] = "game_id IN ($placeholders)";
          $params = array_merge($params, $comboGameIds);
        } else {
          $whereClauses[] = "1 = 0"; // No matches
        }
      } else {
        $whereClauses[] = "game_id = ?";
        $params[] = $gameId;
      }
    }

    // Game filter by name (search games_cache for matching game_ids)
    if ($gameName && !$gameId) {
      // Find game IDs that match the game name
      $gameSearchStmt = $pdo->prepare("SELECT game_id FROM games_cache WHERE name ILIKE ?");
      $gameSearchStmt->execute(['%' . $gameName . '%']);
      $matchingGameIds = $gameSearchStmt->fetchAll(PDO::FETCH_COLUMN);

      if (!empty($matchingGameIds)) {
        $placeholders = implode(',', array_fill(0, count($matchingGameIds), '?'));
        $whereClauses[] = "game_id IN ($placeholders)";
        $params = array_merge($params, $matchingGameIds);
      } else {
        // No games match - force empty results
        $whereClauses[] = "1 = 0";
      }
    }

    // Clipper filter
    if ($clipper) {
      $whereClauses[] = "creator_name ILIKE ?";
      $params[] = '%' . $clipper . '%';
    }

    // Duration filter
    if ($minDuration === 'short') {
      $whereClauses[] = "duration < 30";
    } elseif ($minDuration === 'medium') {
      $whereClauses[] = "duration >= 30 AND duration <= 60";
    } elseif ($minDuration === 'long') {
      $whereClauses[] = "duration > 60";
    }

    // Minimum views filter
    if ($minViews > 0) {
      $whereClauses[] = "view_count >= ?";
      $params[] = $minViews;
    }

    // Check if query is a clip number (all digits)
    $isClipNumber = $query && preg_match('/^\d+$/', $query);

    if ($isClipNumber) {
      // Search by clip seq number OR titles containing the number
      $whereClauses[] = "(seq = ? OR title ILIKE ?)";
      $params[] = (int)$query;
      $params[] = '%' . $query . '%';
    } else {
      // Search filter by title only (clipper filter handles creator_name separately)
      foreach ($queryWords as $word) {
        $whereClauses[] = "title ILIKE ?";
        $params[] = '%' . $word . '%';
      }
    }

    // Exclude phrases from title
    foreach ($excludeWords as $ex) {
      $whereClauses[] = "title NOT ILIKE ?";
      $params[] = '%' . $ex . '%';
    }

    $whereSQL = implode(' AND ', $whereClauses);

    // Get total count first
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE {$whereSQL}");
    $stmt->execute($params);
    $totalCount = (int)$stmt->fetchColumn();
    $totalPages = ceil($totalCount / $perPage);

    // Get paginated results
    $offset = ($page - 1) * $perPage;
    // Determine sort order
    $orderBy = match($sort) {
      'date' => 'created_at DESC',
      'oldest' => 'created_at ASC',
      'title' => 'title ASC',
      'titlez' => 'title DESC',
      'trending' => 'view_count::float / GREATEST(1, EXTRACT(EPOCH FROM (NOW() - created_at)) / 86400) DESC',
      default => 'view_count DESC',
    };

    // If searching by number, prioritize exact seq match first
    if ($isClipNumber) {
      $orderBy = "CASE WHEN seq = " . (int)$query . " THEN 0 ELSE 1 END, " . $orderBy;
    }

    $paginatedParams = array_merge($params, [$perPage, $offset]);
    $stmt = $pdo->prepare("
      SELECT seq, clip_id, title, view_count, created_at, duration, game_id, thumbnail_url, creator_name, blocked, platform
      FROM clips
      WHERE {$whereSQL}
      ORDER BY {$orderBy}
      LIMIT ? OFFSET ?
    ");
    $stmt->execute($paginatedParams);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    error_log("clip_search db error: " . $e->getMessage());
  }
} else {
  // Live mode - fetch from Twitch API with DB cache
  $isLiveMode = true;
  $twitchApi = new TwitchAPI();
  $liveCacheCount = 0;

  // Default to newest-first for live mode (popular clips dominate otherwise)
  if (!isset($_GET['sort'])) {
    $sort = 'date';
  }

  if (!$twitchApi->isConfigured()) {
    $liveError = "Twitch API not configured";
  } elseif (!$pdo) {
    $liveError = "Database not available";
  } else {
    try {
      // Check if we have a fresh cache (< 1 hour old)
      $cacheStmt = $pdo->prepare("
        SELECT COUNT(*) FROM clips_live_cache
        WHERE login = ? AND cached_at > NOW() - INTERVAL '1 hour'
      ");
      $cacheStmt->execute([$login]);
      $cacheHit = (int)$cacheStmt->fetchColumn() > 0;

      if (!$cacheHit) {
        // Cache miss - fetch from Twitch API using two-wave strategy
        $result = $twitchApi->getTwoWaveClips($login);

        if (isset($result['error'])) {
          $liveError = $result['error'];
        } else {
          // Clear old cache for this streamer
          $pdo->prepare("DELETE FROM clips_live_cache WHERE login = ?")->execute([$login]);

          // Batch insert into cache
          if (!empty($result['clips'])) {
            $insertStmt = $pdo->prepare("
              INSERT INTO clips_live_cache (login, clip_id, title, duration, created_at, view_count, game_id, thumbnail_url, creator_name, url, cached_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
              ON CONFLICT (login, clip_id) DO UPDATE SET
                title = EXCLUDED.title,
                view_count = EXCLUDED.view_count,
                thumbnail_url = EXCLUDED.thumbnail_url,
                cached_at = NOW()
            ");
            foreach ($result['clips'] as $clip) {
              $insertStmt->execute([
                $login,
                $clip['clip_id'],
                $clip['title'] ?? '',
                (int)($clip['duration'] ?? 0),
                $clip['created_at'] ?? null,
                (int)($clip['view_count'] ?? 0),
                $clip['game_id'] ?? null,
                $clip['thumbnail_url'] ?? null,
                $clip['creator_name'] ?? '',
                $clip['url'] ?? '',
              ]);
            }
          }
        }
      }

      // Probabilistic stale cache cleanup (1 in 50 requests)
      if (mt_rand(1, 50) === 1) {
        try {
          $pdo->exec("DELETE FROM clips_live_cache WHERE cached_at < NOW() - INTERVAL '24 hours'");
        } catch (PDOException $e) {
          // Non-critical, ignore
        }
      }

      // Query from cache if no error
      if (!$liveError) {
        // Build WHERE conditions
        $where = ["login = ?"];
        $params = [$login];

        // Title search
        if (!empty($queryWords)) {
          foreach ($queryWords as $word) {
            $where[] = "title ILIKE ?";
            $params[] = '%' . $word . '%';
          }
        }

        // Exclude phrases from title
        foreach ($excludeWords as $ex) {
          $where[] = "title NOT ILIKE ?";
          $params[] = '%' . $ex . '%';
        }

        // Clipper filter
        if ($clipper) {
          $where[] = "creator_name ILIKE ?";
          $params[] = '%' . $clipper . '%';
        }

        // Game filter
        if ($gameId) {
          $where[] = "game_id = ?";
          $params[] = $gameId;
        }

        // Duration filter
        if ($minDuration === 'short') {
          $where[] = "duration < 30";
        } elseif ($minDuration === 'medium') {
          $where[] = "duration >= 30 AND duration <= 60";
        } elseif ($minDuration === 'long') {
          $where[] = "duration > 60";
        }

        // Min views filter
        if ($minViews > 0) {
          $where[] = "view_count >= ?";
          $params[] = $minViews;
        }

        $whereClause = implode(' AND ', $where);

        // Sort
        switch ($sort) {
          case 'date':    $orderBy = "created_at DESC NULLS LAST"; break;
          case 'oldest':  $orderBy = "created_at ASC NULLS LAST"; break;
          case 'title':   $orderBy = "title ASC"; break;
          case 'titlez':  $orderBy = "title DESC"; break;
          case 'trending':
            $orderBy = "CASE WHEN created_at IS NOT NULL
              THEN view_count::float / GREATEST(1, EXTRACT(EPOCH FROM (NOW() - created_at)) / 86400)
              ELSE 0 END DESC";
            break;
          default:        $orderBy = "view_count DESC"; break;
        }

        // Get total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM clips_live_cache WHERE $whereClause");
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();
        $totalPages = ceil($totalCount / $perPage);
        $offset = ($page - 1) * $perPage;

        // Get total cached clips count (unfiltered)
        $totalCacheStmt = $pdo->prepare("SELECT COUNT(*) FROM clips_live_cache WHERE login = ?");
        $totalCacheStmt->execute([$login]);
        $liveCacheCount = (int)$totalCacheStmt->fetchColumn();

        // Fetch page
        $dataStmt = $pdo->prepare("
          SELECT clip_id, title, view_count, created_at, duration, game_id, thumbnail_url, creator_name
          FROM clips_live_cache
          WHERE $whereClause
          ORDER BY $orderBy
          LIMIT ? OFFSET ?
        ");
        $dataParams = array_merge($params, [$perPage, $offset]);
        $dataStmt->execute($dataParams);
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
          $matches[] = [
            'seq' => 0,
            'clip_id' => $row['clip_id'],
            'title' => $row['title'],
            'view_count' => (int)$row['view_count'],
            'created_at' => $row['created_at'],
            'duration' => (int)$row['duration'],
            'game_id' => $row['game_id'],
            'thumbnail_url' => $row['thumbnail_url'],
            'creator_name' => $row['creator_name'],
          ];
        }

        // Build games list from cache
        $gamesStmt = $pdo->prepare("
          SELECT game_id, COUNT(*) as cnt FROM clips_live_cache
          WHERE login = ? AND game_id IS NOT NULL AND game_id != ''
          GROUP BY game_id ORDER BY cnt DESC
        ");
        $gamesStmt->execute([$login]);
        $gameRows = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

        $gameIdList = array_column($gameRows, 'game_id');
        $gameNames = [];

        // Resolve game names via cache + API
        if (!empty($gameIdList)) {
          $placeholders = implode(',', array_fill(0, count($gameIdList), '?'));
          $gnStmt = $pdo->prepare("SELECT game_id, name FROM games_cache WHERE game_id IN ($placeholders)");
          $gnStmt->execute($gameIdList);
          $gameNames = $gnStmt->fetchAll(PDO::FETCH_KEY_PAIR);

          $missingIds = array_filter($gameIdList, function($id) use ($gameNames) {
            return !isset($gameNames[$id]);
          });

          if (!empty($missingIds)) {
            $apiGames = $twitchApi->getGamesByIds(array_values($missingIds));
            foreach ($apiGames as $gid => $gameInfo) {
              $gameNames[$gid] = $gameInfo['name'];
              try {
                $pdo->prepare("INSERT INTO games_cache (game_id, name) VALUES (?, ?) ON CONFLICT (game_id) DO UPDATE SET name = EXCLUDED.name")
                 ->execute([$gid, $gameInfo['name']]);
              } catch (PDOException $e) {
                // Ignore
              }
            }
          }
        }

        foreach ($gameRows as $gr) {
          $games[] = [
            'game_id' => $gr['game_id'],
            'name' => $gameNames[$gr['game_id']] ?? 'Unknown Game',
            'count' => (int)$gr['cnt'],
          ];
        }

        // Set currentGameName for active filter display
        if ($gameId) {
          foreach ($games as $g) {
            if ($g['game_id'] === $gameId) {
              $currentGameName = $g['name'] ?: "Game $gameId";
              break;
            }
          }
        }
      }
    } catch (PDOException $e) {
      error_log("clip_search live cache error: " . $e->getMessage());
      $liveError = "Database error loading clips";
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <?php if ($streamerProfileImage): ?>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($streamerProfileImage) ?>">
  <?php else: ?>
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <?php endif; ?>
  <title>Clip Search<?= $query ? ': ' . htmlspecialchars($query) : '' ?> - <?= htmlspecialchars($login) ?></title>
  <style>
    * { box-sizing: border-box; }
    html {
      background: #0e0e10;
      min-height: 100%;
    }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #0e0e10;
      color: #efeff1;
      margin: 0;
      padding: 0;
      min-height: 100vh;
      min-height: -webkit-fill-available;
    }
    .page-body {
      padding: 20px;
    }
    .container {
      max-width: 1400px;
      margin: 0 auto;
    }
    header {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      gap: 15px;
    }
    .header-left {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .streamer-avatar {
      width: 56px;
      height: 56px;
      border-radius: 50%;
      object-fit: cover;
      border: 3px solid #9147ff;
      flex-shrink: 0;
    }
    .streamer-avatar-placeholder {
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #9147ff, #772ce8);
      color: white;
      font-size: 24px;
      font-weight: 700;
    }
    h1 {
      margin: 0;
      font-size: 24px;
    }
    h1 a {
      color: #9147ff;
      text-decoration: none;
      transition: color 0.2s;
    }
    h1 a:hover {
      color: #bf94ff;
    }
    .subtitle {
      color: #adadb8;
      font-size: 14px;
    }
    .nav-links {
      display: flex;
      gap: 15px;
      margin-top: 6px;
    }
    .nav-links a {
      color: #adadb8;
      text-decoration: none;
      font-size: 13px;
      transition: color 0.2s;
    }
    .nav-links a:hover {
      color: #9147ff;
    }
    .header-right {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .total-count {
      background: #1f1f23;
      padding: 8px 14px;
      border-radius: 6px;
      font-size: 14px;
      color: #adadb8;
    }
    .total-count strong {
      color: #9147ff;
    }

    /* Filters */
    .filters {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 20px;
      padding: 15px;
      background: #1f1f23;
      border-radius: 8px;
    }
    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .filter-group label {
      font-size: 11px;
      color: #adadb8;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .filter-group input,
    .filter-group select {
      padding: 10px 14px;
      border: 1px solid #3d3d42;
      border-radius: 6px;
      background: #0e0e10;
      color: #efeff1;
      font-size: 14px;
      min-width: 200px;
    }
    .filter-group input:focus,
    .filter-group select:focus {
      outline: none;
      border-color: #9147ff;
    }
    .filter-group select {
      cursor: pointer;
    }
    .filter-btn {
      align-self: flex-end;
      padding: 10px 20px;
      border: none;
      border-radius: 6px;
      background: #9147ff;
      color: white;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.2s;
    }
    .filter-btn:hover {
      background: #772ce8;
    }
    .clear-btn {
      align-self: flex-end;
      padding: 10px 16px;
      border: 1px solid #3d3d42;
      border-radius: 6px;
      background: transparent;
      color: #adadb8;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s;
      text-decoration: none;
    }
    .clear-btn:hover {
      border-color: #9147ff;
      color: #9147ff;
    }

    /* Active filters display */
    .active-filters {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 20px;
    }
    .filter-tag {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      background: #772ce8;
      border-radius: 20px;
      font-size: 13px;
    }
    .filter-tag a {
      color: white;
      text-decoration: none;
      opacity: 0.8;
    }
    .filter-tag a:hover {
      opacity: 1;
    }

    .info-msg {
      background: #1f1f23;
      border: 1px solid #3d3d42;
      padding: 15px;
      border-radius: 6px;
      margin-bottom: 20px;
      color: #adadb8;
    }

    /* Clip grid */
    .results-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 16px;
    }
    .clip-card {
      background: #1f1f23;
      border-radius: 8px;
      overflow: hidden;
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .clip-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 6px 20px rgba(0,0,0,0.5);
    }
    .clip-thumb {
      position: relative;
      padding-top: 56.25%;
      background: #26262c;
      display: block;
      text-decoration: none;
    }
    .clip-thumb img {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .clip-seq {
      position: absolute;
      top: 8px;
      left: 8px;
      background: rgba(0,0,0,0.85);
      color: #9147ff;
      padding: 3px 8px;
      border-radius: 4px;
      font-weight: bold;
      font-size: 12px;
    }
    .clip-duration {
      position: absolute;
      bottom: 8px;
      right: 8px;
      background: rgba(0,0,0,0.85);
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 12px;
      color: #efeff1;
    }
    .play-overlay {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 60px;
      height: 60px;
      background: rgba(145, 71, 255, 0.9);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0;
      transition: opacity 0.2s;
    }
    .play-overlay::after {
      content: '';
      border-style: solid;
      border-width: 12px 0 12px 20px;
      border-color: transparent transparent transparent white;
      margin-left: 4px;
    }
    .clip-thumb:hover .play-overlay {
      opacity: 1;
    }
    .clip-info {
      padding: 12px;
    }
    .clip-title {
      font-weight: 600;
      margin-bottom: 8px;
      line-height: 1.35;
      display: -webkit-box;
     -webkit-line-clamp: 2;
     -webkit-box-orient: vertical;
      overflow: hidden;
      font-size: 14px;
    }
    .clip-meta {
      display: flex;
      justify-content: space-between;
      align-items: center;
      color: #adadb8;
      font-size: 12px;
    }
    .clip-views {
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .clip-date {
      color: #adadb8;
      font-size: 11px;
    }
    .clip-clipper {
      color: #bf94ff;
      font-size: 12px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 180px;
      text-decoration: none;
    }
    .clip-clipper:hover {
      color: #d4b8ff;
      text-decoration: underline;
    }
    .clip-game-row {
      margin-top: 6px;
    }
    .clip-game {
      background: #26262c;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 11px;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      display: inline-block;
    }

    /* Vote buttons */
    .clip-votes {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 8px;
      padding-top: 8px;
      border-top: 1px solid #3d3d42;
    }
    .vote-btn {
      display: flex;
      align-items: center;
      gap: 4px;
      padding: 4px 10px;
      border: 1px solid #3d3d42;
      border-radius: 4px;
      background: transparent;
      color: #adadb8;
      font-size: 12px;
      cursor: pointer;
      transition: all 0.2s;
    }
    .vote-btn:hover {
      border-color: #9147ff;
      color: #efeff1;
    }
    .vote-btn.active-like {
      background: rgba(0, 200, 83, 0.15);
      border-color: #00c853;
      color: #00c853;
    }
    .vote-btn.active-dislike {
      background: rgba(255, 71, 87, 0.15);
      border-color: #ff4757;
      color: #ff4757;
    }
    .vote-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    .vote-btn svg {
      width: 14px;
      height: 14px;
    }
    .vote-count {
      font-weight: 600;
    }
    .vote-login-prompt {
      font-size: 11px;
      color: #adadb8;
    }
    .vote-login-prompt a {
      color: #9147ff;
    }

    /* Download button */
    .dl-btn {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 10px;
      border: 1px solid #3d3d42;
      border-radius: 4px;
      background: transparent;
      color: #adadb8;
      font-size: 12px;
      cursor: pointer;
      transition: all 0.2s;
      margin-left: auto;
    }
    .dl-btn:hover {
      border-color: #9147ff;
      color: #efeff1;
    }
    .dl-btn.downloading {
      border-color: #00c853;
      color: #00c853;
    }
    .dl-btn svg {
      width: 14px;
      height: 14px;
    }

    /* Clip management buttons */
    .manage-btn {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 8px;
      border: none;
      border-radius: 4px;
      background: #3a3a3d;
      color: #adadb8;
      font-size: 11px;
      cursor: pointer;
      transition: all 0.15s ease;
      margin-left: 8px;
    }
    .manage-btn:hover {
      background: #ff4757;
      color: #fff;
    }
    .manage-btn.is-blocked {
      background: #ff4757;
      color: #fff;
    }
    .manage-btn.is-blocked:hover {
      background: #2ed573;
    }
    .manage-btn svg {
      width: 12px;
      height: 12px;
    }
    .manage-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .no-results {
      text-align: center;
      padding: 60px 20px;
      color: #adadb8;
    }
    .no-results h2 {
      color: #efeff1;
      margin-bottom: 10px;
    }

    /* Pagination */
    .pagination {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 10px;
      margin-top: 30px;
      padding: 20px 0;
      flex-wrap: wrap;
    }
    .pagination a, .pagination span {
      padding: 10px 16px;
      border-radius: 6px;
      text-decoration: none;
      font-weight: 600;
      transition: all 0.2s;
    }
    .pagination a {
      background: #1f1f23;
      color: #9147ff;
      border: 1px solid #3d3d42;
    }
    .pagination a:hover {
      background: #26262c;
      border-color: #9147ff;
    }
    .pagination .current {
      background: #9147ff;
      color: white;
    }
    .pagination .disabled {
      background: #1f1f23;
      color: #3d3d42;
      border: 1px solid #3d3d42;
      cursor: not-allowed;
    }
    .pagination .page-info {
      color: #adadb8;
      background: transparent;
      padding: 10px;
    }
    .page-jump {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-left: 10px;
    }
    .page-jump input {
      width: 60px;
      padding: 8px 10px;
      border: 1px solid #3d3d42;
      border-radius: 6px;
      background: #1f1f23;
      color: #efeff1;
      font-size: 14px;
      text-align: center;
    }
    .page-jump input:focus {
      outline: none;
      border-color: #9147ff;
    }
    .page-jump button {
      padding: 8px 14px;
      border: none;
      border-radius: 6px;
      background: #9147ff;
      color: white;
      font-weight: 600;
      cursor: pointer;
      font-size: 13px;
    }
    .page-jump button:hover {
      background: #772ce8;
    }

    /* Load More button */
    .load-more-container {
      display: flex;
      justify-content: center;
      padding: 30px 20px;
    }
    .load-more-btn {
      padding: 14px 40px;
      border: 2px solid #9147ff;
      border-radius: 8px;
      background: transparent;
      color: #9147ff;
      font-weight: 600;
      font-size: 15px;
      cursor: pointer;
      transition: all 0.2s;
    }
    .load-more-btn:hover {
      background: #9147ff;
      color: white;
    }
    .load-more-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    .load-more-btn.loading {
      background: #1f1f23;
      border-color: #3d3d42;
      color: #adadb8;
    }

    /* Mobile adjustments */
    @media (max-width: 768px) {
      body { padding: 12px; }
      header { flex-direction: column; align-items: flex-start; }
      .filters { flex-direction: column; }
      .filter-group input,
      .filter-group select { min-width: 100%; }
      .results-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/includes/nav.php'; ?>
  <div class="page-body">
  <div class="container">
    <header>
      <div class="header-left">
        <?php if ($streamerProfileImage): ?>
        <img src="<?= htmlspecialchars($streamerProfileImage) ?>" alt="<?= htmlspecialchars($login) ?>" class="streamer-avatar">
        <?php else: ?>
        <div class="streamer-avatar streamer-avatar-placeholder"><?= strtoupper(substr($login, 0, 1)) ?></div>
        <?php endif; ?>
        <div>
          <h1><?= htmlspecialchars($login) ?>'s Clips</h1>
          <p class="subtitle"><?= $hasArchivedClips ? 'Archived' : 'Live from Twitch' ?></p>
          <div class="nav-links">
            <a href="/tv/<?= htmlspecialchars(urlencode($login)) ?>">Watch on ClipTV</a>
            <a href="https://twitch.tv/<?= htmlspecialchars(urlencode($login)) ?>" target="_blank">Twitch Channel</a>
          </div>
        </div>
      </div>
      <div class="header-right">
        <div class="total-count">
          <strong><?= number_format($totalCount) ?></strong> result<?= $totalCount !== 1 ? 's' : '' ?>
          <?php if ($totalPages > 1): ?> &middot; Page <?= $page ?> of <?= $totalPages ?><?php endif; ?>
        </div>
      </div>
    </header>

    <form class="filters" method="get" action="/search/<?= htmlspecialchars(urlencode($login)) ?>">
      <!-- login is captured from URL path by nginx -->

      <div class="filter-group">
        <label>Title Search</label>
        <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Search titles..." autofocus>
      </div>

      <div class="filter-group">
        <label>Clipper</label>
        <input type="text" name="clipper" value="<?= htmlspecialchars($clipper) ?>" placeholder="Filter by clipper...">
      </div>

      <div class="filter-group">
        <label>Exclude</label>
        <input type="text" name="exclude" value="<?= htmlspecialchars($exclude) ?>" placeholder="Hide titles with..." title="Comma-separated phrases to exclude from results">
      </div>

      <div class="filter-group">
        <label>Category</label>
        <select name="game_id">
          <option value="">All Categories</option>
          <?php foreach ($games as $g):
            $gName = $g['name'] ?: "Game {$g['game_id']}";
            $selected = ($g['game_id'] === $gameId) ? 'selected' : '';
          ?>
          <option value="<?= htmlspecialchars($g['game_id']) ?>" <?= $selected ?>><?= htmlspecialchars($gName) ?> (<?= number_format($g['count']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filter-group">
        <label>Sort By</label>
        <select name="sort">
          <option value="views" <?= $sort === 'views' ? 'selected' : '' ?>>Most Viewed</option>
          <option value="trending" <?= $sort === 'trending' ? 'selected' : '' ?>>Trending</option>
          <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>Newest First</option>
          <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
          <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title (A-Z)</option>
          <option value="titlez" <?= $sort === 'titlez' ? 'selected' : '' ?>>Title (Z-A)</option>
        </select>
      </div>

      <div class="filter-group">
        <label>Duration</label>
        <select name="duration">
          <option value="" <?= $minDuration === '' ? 'selected' : '' ?>>Any Length</option>
          <option value="short" <?= $minDuration === 'short' ? 'selected' : '' ?>>Short (&lt;30s)</option>
          <option value="medium" <?= $minDuration === 'medium' ? 'selected' : '' ?>>Medium (30-60s)</option>
          <option value="long" <?= $minDuration === 'long' ? 'selected' : '' ?>>Long (&gt;60s)</option>
        </select>
      </div>

      <div class="filter-group">
        <label>Min Views</label>
        <select name="min_views">
          <option value="0" <?= $minViews === 0 ? 'selected' : '' ?>>Any</option>
          <option value="100" <?= $minViews === 100 ? 'selected' : '' ?>>100+</option>
          <option value="500" <?= $minViews === 500 ? 'selected' : '' ?>>500+</option>
          <option value="1000" <?= $minViews === 1000 ? 'selected' : '' ?>>1K+</option>
          <option value="5000" <?= $minViews === 5000 ? 'selected' : '' ?>>5K+</option>
          <option value="10000" <?= $minViews === 10000 ? 'selected' : '' ?>>10K+</option>
        </select>
      </div>

      <div class="filter-group">
        <label>Per Page</label>
        <select name="per_page">
          <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
          <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
          <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
          <option value="200" <?= $perPage === 200 ? 'selected' : '' ?>>200</option>
        </select>
      </div>

      <?php /* Date range dropdown removed for live mode - two-wave fetch covers recent + popular automatically */ ?>

      <button type="submit" class="filter-btn">Search</button>
      <?php if ($query || $gameId || $gameName || $clipper || $minDuration || $minViews > 0 || $exclude): ?>
      <a href="/search/<?= htmlspecialchars(urlencode($login)) ?>" class="clear-btn">Clear All</a>
      <?php endif; ?>
    </form>

    <?php
      // Build base params for filter tag removal links
      $baseFilterParams = [];
      if ($query) $baseFilterParams['q'] = $query;
      if ($clipper) $baseFilterParams['clipper'] = $clipper;
      if ($gameId) $baseFilterParams['game_id'] = $gameId;
      if ($gameName && !$gameId) $baseFilterParams['game'] = $gameName;
      if ($minDuration) $baseFilterParams['duration'] = $minDuration;
      if ($minViews > 0) $baseFilterParams['min_views'] = $minViews;
      if ($exclude) $baseFilterParams['exclude'] = $exclude;
      if ($sort !== 'views') $baseFilterParams['sort'] = $sort;
      if ($perPage !== 100) $baseFilterParams['per_page'] = $perPage;

      function buildFilterUrl($login, $params, $exclude = []) {
        $filtered = array_diff_key($params, array_flip($exclude));
        $query = http_build_query($filtered);
        return '/search/' . htmlspecialchars(urlencode($login)) . ($query ? '?' . $query : '');
      }

      $durationLabels = ['short' => 'Short (<30s)', 'medium' => 'Medium (30-60s)', 'long' => 'Long (>60s)'];
    ?>
    <?php if ($query || $gameId || $gameName || $clipper || $minDuration || $minViews > 0 || $exclude): ?>
    <div class="active-filters">
      <?php if ($query): ?>
      <span class="filter-tag">
        Search: "<?= htmlspecialchars($query) ?>"
        <a href="<?= buildFilterUrl($login, $baseFilterParams, ['q']) ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($clipper): ?>
      <span class="filter-tag">
        Clipper: <?= htmlspecialchars($clipper) ?>
        <a href="<?= buildFilterUrl($login, $baseFilterParams, ['clipper']) ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($gameId): ?>
      <span class="filter-tag">
        Category: <?= htmlspecialchars($currentGameName) ?>
        <a href="<?= buildFilterUrl($login, $baseFilterParams, ['game_id']) ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($gameName && !$gameId): ?>
      <span class="filter-tag">
        Category: "<?= htmlspecialchars($gameName) ?>"
        <a href="<?= buildFilterUrl($login, $baseFilterParams, ['game']) ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($minDuration): ?>
      <span class="filter-tag">
        Duration: <?= $durationLabels[$minDuration] ?? $minDuration ?>
        <a href="<?= buildFilterUrl($login, $baseFilterParams, ['duration']) ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($minViews > 0): ?>
      <span class="filter-tag">
        Min Views: <?= number_format($minViews) ?>+
        <a href="<?= buildFilterUrl($login, $baseFilterParams, ['min_views']) ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($exclude): ?>
      <span class="filter-tag">
        Excluding: "<?= htmlspecialchars($exclude) ?>"
        <a href="<?= buildFilterUrl($login, $baseFilterParams, ['exclude']) ?>">&times;</a>
      </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($isLiveMode && !$liveError): ?>
    <div class="info-msg" style="background: linear-gradient(90deg, rgba(145,71,255,0.2), rgba(145,71,255,0.1)); border-color: #9147ff;">
      <strong>Live from Twitch</strong> - cached <?= $liveCacheCount ?> clips (refreshes hourly).
      <span style="color: #adadb8; font-size: 12px; display: block; margin-top: 5px;">
        Showing <?= $totalCount ?> clips<?= $totalCount < $liveCacheCount ? ' (filtered)' : '' ?>. Use filters above to narrow results. No voting or clip numbers in live mode.
      </span>
    </div>
    <?php elseif ($liveError): ?>
    <div class="info-msg" style="background: rgba(255,71,87,0.1); border-color: #ff4757;">
      <strong>Error:</strong> <?= htmlspecialchars($liveError) ?>
      <?php if ($liveError === 'Streamer not found'): ?>
      <p style="margin-top: 10px; color: #adadb8;">Make sure you entered the correct Twitch username.</p>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="info-msg">
      Click any clip to watch on Twitch. Search by title or clip number (e.g. "1234").
    </div>
    <?php endif; ?>

    <?php if ($liveError): ?>
    <div class="no-results">
      <h2>Could not load clips</h2>
      <p><a href="/" style="color: #9147ff;">Go back home</a> and try another streamer</p>
    </div>
    <?php elseif (empty($matches) && ($query || $gameId || $gameName || $clipper)): ?>
    <div class="no-results">
      <h2>No clips found</h2>
      <p>Try a different search term or category</p>
    </div>
    <?php elseif (empty($matches)): ?>
    <div class="no-results">
      <h2><?= $isLiveMode ? 'No clips available' : 'Search for clips' ?></h2>
      <p><?= $isLiveMode ? 'This streamer may not have any clips yet.' : 'Enter a search term or select a category to find clips' ?></p>
    </div>
    <?php else: ?>
    <div class="results-grid">
      <?php foreach ($matches as $clip):
        $clipId = $clip['clip_id'];
        $clipPlatform = $clip['platform'] ?? 'twitch';
        $thumbUrl = !empty($clip['thumbnail_url']) && $clip['thumbnail_url'] !== 'NOT_FOUND'
          ? $clip['thumbnail_url']
          : ($clipPlatform === 'kick' ? '' : "https://clips-media-assets2.twitch.tv/{$clipId}-preview-480x272.jpg");
        $twitchUrl = $clipPlatform === 'kick'
          ? "https://kick.com/" . rawurlencode($login) . "?clip=" . rawurlencode($clipId)
          : "https://clips.twitch.tv/" . rawurlencode($clipId);
        $duration = isset($clip['duration']) ? gmdate("i:s", (int)$clip['duration']) : '';
        $title = $clip['title'] ?? '(no title)';
        $seq = (int)$clip['seq'];

        // Find game name
        $gameName = '';
        if (!empty($clip['game_id'])) {
          foreach ($games as $g) {
            if ($g['game_id'] === $clip['game_id']) {
              $gameName = $g['name'] ?: '';
              break;
            }
          }
        }

        // Format date
        $clipDate = '';
        if (!empty($clip['created_at'])) {
          $dateObj = new DateTime($clip['created_at']);
          $clipDate = $dateObj->format('M j, Y');
        }
      ?>
      <div class="clip-card">
        <a href="<?= htmlspecialchars($twitchUrl) ?>" target="_blank" class="clip-thumb">
          <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="" loading="lazy"
               onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 480 272%22><rect fill=%22%2326262c%22 width=%22480%22 height=%22272%22/><text x=%22240%22 y=%22140%22 fill=%22%23666%22 text-anchor=%22middle%22>No Preview</text></svg>'">
          <?php if ($seq > 0): ?><span class="clip-seq">#<?= $seq ?></span><?php endif; ?>
          <?php if ($duration): ?>
          <span class="clip-duration"><?= $duration ?></span>
          <?php endif; ?>
          <span class="play-overlay"></span>
        </a>
        <div class="clip-info">
          <div class="clip-title"><?= htmlspecialchars($title) ?></div>
          <div class="clip-meta">
            <span class="clip-views">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
              <?= number_format((int)($clip['view_count'] ?? 0)) ?>
            </span>
            <?php if ($clipDate): ?>
            <span class="clip-date"><?= $clipDate ?></span>
            <?php endif; ?>
          </div>
          <div class="clip-meta">
            <?php if (!empty($clip['creator_name'])): ?>
            <a href="/search/<?= htmlspecialchars(urlencode($login)) ?>?clipper=<?= urlencode($clip['creator_name']) ?>" class="clip-clipper" title="View all clips by <?= htmlspecialchars($clip['creator_name']) ?>">&#9986; <?= htmlspecialchars($clip['creator_name']) ?></a>
            <?php endif; ?>
          </div>
          <?php if ($gameName): ?>
          <div class="clip-game-row">
            <span class="clip-game" title="<?= htmlspecialchars($gameName) ?>"><?= htmlspecialchars($gameName) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($seq <= 0): // Live clips: download button without votes row ?>
          <div class="clip-votes">
            <button type="button" class="dl-btn" data-clip-id="<?= htmlspecialchars($clipId) ?>" data-title="<?= htmlspecialchars($title) ?>" data-seq="0" title="Download clip">
              <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
            </button>
          </div>
          <?php endif; ?>
          <?php if ($seq > 0): // Only show votes for archived clips ?>
          <div class="clip-votes" data-seq="<?= $seq ?>">
            <button type="button" class="vote-btn like-btn" data-vote="like" title="Like this clip">
              <svg viewBox="0 0 24 24" fill="currentColor"><path d="M2 20h2c.55 0 1-.45 1-1v-9c0-.55-.45-1-1-1H2v11zm19.83-7.12c.11-.25.17-.52.17-.8V11c0-1.1-.9-2-2-2h-5.5l.92-4.65c.05-.22.02-.46-.08-.66-.23-.45-.52-.86-.88-1.22L14 2 7.59 8.41C7.21 8.79 7 9.3 7 9.83v7.84C7 18.95 8.05 20 9.34 20h8.11c.7 0 1.36-.37 1.72-.97l2.66-6.15z"/></svg>
              <span class="vote-count like-count">0</span>
            </button>
            <button type="button" class="vote-btn dislike-btn" data-vote="dislike" title="Dislike this clip">
              <svg viewBox="0 0 24 24" fill="currentColor"><path d="M22 4h-2c-.55 0-1 .45-1 1v9c0 .55.45 1 1 1h2V4zM2.17 11.12c-.11.25-.17.52-.17.8V13c0 1.1.9 2 2 2h5.5l-.92 4.65c-.05.22-.02.46.08.66.23.45.52.86.88 1.22L10 22l6.41-6.41c.38-.38.59-.89.59-1.42V6.34C17 5.05 15.95 4 14.66 4H6.55c-.7 0-1.36.37-1.72.97l-2.66 6.15z"/></svg>
              <span class="vote-count dislike-count">0</span>
            </button>
            <?php if (!$currentUser): ?>
            <span class="vote-login-prompt"><a href="/auth/login.php?return=<?= urlencode($_SERVER['REQUEST_URI']) ?>">Login</a> to vote</span>
            <?php endif; ?>
            <button type="button" class="dl-btn" data-clip-id="<?= htmlspecialchars($clipId) ?>" data-title="<?= htmlspecialchars($title) ?>" data-seq="<?= $seq ?>" title="Download clip">
              <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
            </button>
            <?php if ($canManageClips):
              $isBlocked = !empty($clip['blocked']);
            ?>
            <button type="button" class="manage-btn block-btn<?= $isBlocked ? ' is-blocked' : '' ?>" data-seq="<?= $seq ?>" data-blocked="<?= $isBlocked ? '1' : '0' ?>" onclick="toggleBlockClip(this, <?= $seq ?>)" title="<?= $isBlocked ? 'Unhide this clip (add back to rotation)' : 'Hide this clip from rotation' ?>">
              <?php if ($isBlocked): ?>
              <svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
              <span>Show</span>
              <?php else: ?>
              <svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zM4 12c0-4.42 3.58-8 8-8 1.85 0 3.55.63 4.9 1.69L5.69 16.9C4.63 15.55 4 13.85 4 12zm8 8c-1.85 0-3.55-.63-4.9-1.69L18.31 7.1C19.37 8.45 20 10.15 20 12c0 4.42-3.58 8-8 8z"/></svg>
              <span>Hide</span>
              <?php endif; ?>
            </button>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php /* Load More removed for live mode - all clips are cached and pagination is SQL-based */ ?>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php
        $baseParams = [];
        if ($query) $baseParams['q'] = $query;
        if ($clipper) $baseParams['clipper'] = $clipper;
        if ($gameId) $baseParams['game_id'] = $gameId;
        if ($gameName) $baseParams['game'] = $gameName;
        if ($sort !== 'views') $baseParams['sort'] = $sort;
        if ($minDuration) $baseParams['duration'] = $minDuration;
        if ($minViews > 0) $baseParams['min_views'] = $minViews;
        if ($exclude) $baseParams['exclude'] = $exclude;
        if ($perPage !== 100) $baseParams['per_page'] = $perPage;

        function pageUrl($params, $pageNum) {
          global $login;
          $params['page'] = $pageNum;
          return '/search/' . htmlspecialchars(urlencode($login)) . '?' . http_build_query($params);
        }
      ?>
      <?php if ($page > 1): ?>
        <a href="<?= pageUrl($baseParams, 1) ?>">&laquo; First</a>
        <a href="<?= pageUrl($baseParams, $page - 1) ?>">&lsaquo; Prev</a>
      <?php else: ?>
        <span class="disabled">&laquo; First</span>
        <span class="disabled">&lsaquo; Prev</span>
      <?php endif; ?>

      <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>

      <?php if ($page < $totalPages): ?>
        <a href="<?= pageUrl($baseParams, $page + 1) ?>">Next &rsaquo;</a>
        <a href="<?= pageUrl($baseParams, $totalPages) ?>">Last &raquo;</a>
      <?php else: ?>
        <span class="disabled">Next &rsaquo;</span>
        <span class="disabled">Last &raquo;</span>
      <?php endif; ?>

      <div class="page-jump">
        <input type="number" id="pageInput" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" placeholder="#">
        <button onclick="goToPage()">Go</button>
      </div>
    </div>
    <script>
      function goToPage() {
        const input = document.getElementById('pageInput');
        const page = parseInt(input.value);
        if (page >= 1 && page <= <?= $totalPages ?>) {
          const params = new URLSearchParams(window.location.search);
          params.set('page', page);
          window.location.search = params.toString();
        }
      }
      document.getElementById('pageInput').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') goToPage();
      });
    </script>
    <?php endif; ?>

    <?php endif; ?>
  </div>

  <?php if (!$isLiveMode && !empty($matches)): ?>
  <script>
    const streamer = <?= json_encode($login) ?>;
    const isLoggedIn = <?= $currentUser ? 'true' : 'false' ?>;

    // Fetch vote counts for all visible clips
    async function loadVotes() {
      const voteContainers = document.querySelectorAll('.clip-votes[data-seq]');
      if (!voteContainers.length) return;

      const seqs = Array.from(voteContainers).map(el => el.dataset.seq).join(',');
      if (!seqs) return;

      try {
        const response = await fetch(`/api/votes.php?streamer=${encodeURIComponent(streamer)}&seq=${seqs}`);
        const data = await response.json();

        if (data.votes) {
          for (const [seq, vote] of Object.entries(data.votes)) {
            const container = document.querySelector(`.clip-votes[data-seq="${seq}"]`);
            if (container) {
              container.querySelector('.like-count').textContent = vote.likes || 0;
              container.querySelector('.dislike-count').textContent = vote.dislikes || 0;

              // Mark active vote if user has voted
              if (vote.user_vote === 'like') {
                container.querySelector('.like-btn').classList.add('active-like');
              } else if (vote.user_vote === 'dislike') {
                container.querySelector('.dislike-btn').classList.add('active-dislike');
              }
            }
          }
        }
      } catch (e) {
        console.error('Failed to load votes:', e);
      }
    }

    // Handle vote button clicks
    async function handleVote(button, voteType) {
      if (!isLoggedIn) {
        window.location.href = '/auth/login.php?return=' + encodeURIComponent(window.location.href);
        return;
      }

      const container = button.closest('.clip-votes');
      const seq = container.dataset.seq;

      // Determine if we're toggling off
      const isActive = button.classList.contains('active-like') || button.classList.contains('active-dislike');
      const newVote = isActive ? 'clear' : voteType;

      // Disable buttons during request
      const buttons = container.querySelectorAll('.vote-btn');
      buttons.forEach(btn => btn.disabled = true);

      try {
        const response = await fetch('/api/vote.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ streamer, seq: parseInt(seq), vote: newVote })
        });

        const data = await response.json();

        if (data.success) {
          // Update counts
          container.querySelector('.like-count').textContent = data.likes || 0;
          container.querySelector('.dislike-count').textContent = data.dislikes || 0;

          // Update active state
          container.querySelector('.like-btn').classList.remove('active-like');
          container.querySelector('.dislike-btn').classList.remove('active-dislike');

          if (data.user_vote === 'like') {
            container.querySelector('.like-btn').classList.add('active-like');
          } else if (data.user_vote === 'dislike') {
            container.querySelector('.dislike-btn').classList.add('active-dislike');
          }
        } else {
          console.error('Vote failed:', data.error);
        }
      } catch (e) {
        console.error('Vote request failed:', e);
      } finally {
        buttons.forEach(btn => btn.disabled = false);
      }
    }

    // Attach click handlers
    document.querySelectorAll('.vote-btn').forEach(btn => {
      btn.addEventListener('click', () => handleVote(btn, btn.dataset.vote));
    });

    // Load initial votes
    loadVotes();
  </script>
  <?php endif; ?>

  <?php /* Load More JS removed - live mode now uses DB cache with SQL pagination */ ?>

  <?php if ($canManageClips): ?>
  <script>
    // Clip management (block/unblock)
    const clipManageConfig = {
      streamer: <?= json_encode($login) ?>
    };

    async function toggleBlockClip(btn, seq) {
      if (btn.disabled) return;
      btn.disabled = true;

      const isCurrentlyBlocked = btn.dataset.blocked === '1';
      const newBlockedState = !isCurrentlyBlocked;

      try {
        const params = new URLSearchParams({
          action: 'block_clip',
          login: clipManageConfig.streamer,
          seq: seq,
          blocked: newBlockedState ? '1' : '0'
        });

        const response = await fetch('/dashboard_api.php?' + params.toString());
        const data = await response.json();

        if (data.success) {
          // Update button state
          btn.dataset.blocked = newBlockedState ? '1' : '0';
          btn.classList.toggle('is-blocked', newBlockedState);
          btn.title = newBlockedState ? 'Unhide this clip (add back to rotation)' : 'Hide this clip from rotation';

          // Update icon and text
          const span = btn.querySelector('span');
          const svg = btn.querySelector('svg');
          if (newBlockedState) {
            // Now blocked - show "Show" button
            svg.innerHTML = '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
            span.textContent = 'Show';

            // Fade the clip card to indicate it's hidden
            const card = btn.closest('.clip-card');
            if (card) card.style.opacity = '0.5';
          } else {
            // Now unblocked - show "Hide" button
            svg.innerHTML = '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zM4 12c0-4.42 3.58-8 8-8 1.85 0 3.55.63 4.9 1.69L5.69 16.9C4.63 15.55 4 13.85 4 12zm8 8c-1.85 0-3.55-.63-4.9-1.69L18.31 7.1C19.37 8.45 20 10.15 20 12c0 4.42-3.58 8-8 8z"/>';
            span.textContent = 'Hide';

            // Restore clip card opacity
            const card = btn.closest('.clip-card');
            if (card) card.style.opacity = '1';
          }
        } else {
          console.error('Block/unblock failed:', data.error);
          alert('Failed to update clip: ' + (data.error || 'Unknown error'));
        }
      } catch (e) {
        console.error('Block/unblock request failed:', e);
        alert('Failed to update clip. Please try again.');
      } finally {
        btn.disabled = false;
      }
    }

    // On page load, fade any already-blocked clips
    document.querySelectorAll('.manage-btn.is-blocked').forEach(btn => {
      const card = btn.closest('.clip-card');
      if (card) card.style.opacity = '0.5';
    });
  </script>
  <?php endif; ?>

  <script>
    // Clip download functionality
    const GQL_URL = "https://gql.twitch.tv/gql";
    const GQL_CLIENT_ID = "kimne78kx3ncx6brgo4mv6wki5h1ko";
    const GQL_HASH = "36b89d2507fce29e5ca551df756d27c1cfe079e2609642b4390aa4c35796eb11";

    async function getMp4Url(clipId) {
      try {
        const res = await fetch(GQL_URL, {
          method: "POST",
          headers: { "Client-ID": GQL_CLIENT_ID, "Content-Type": "application/json" },
          body: JSON.stringify({
            operationName: "VideoAccessToken_Clip",
            variables: { slug: clipId },
            extensions: { persistedQuery: { version: 1, sha256Hash: GQL_HASH } }
          })
        });
        if (!res.ok) return null;
        const data = await res.json();
        const clip = data?.data?.clip;
        if (!clip) return null;
        const token = clip.playbackAccessToken?.value;
        const sig = clip.playbackAccessToken?.signature;
        const qualities = clip.videoQualities || [];
        if (!token || !sig || !qualities.length) return null;
        // Highest quality
        const sorted = qualities.sort((a,b) => (parseInt(b.quality)||0) - (parseInt(a.quality)||0));
        const chosen = sorted[0];
        const sep = chosen.sourceURL.includes("?") ? "&" : "?";
        return `${chosen.sourceURL}${sep}sig=${encodeURIComponent(sig)}&token=${encodeURIComponent(token)}`;
      } catch (e) {
        console.error("getMp4Url error:", e);
        return null;
      }
    }

    async function downloadClip(btn) {
      if (btn.classList.contains('downloading')) return;
      const clipId = btn.dataset.clipId;

      btn.classList.add('downloading');
      const origHTML = btn.innerHTML;
      btn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" style="animation:spin 1s linear infinite"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>';

      try {
        const mp4Url = await getMp4Url(clipId);
        if (!mp4Url) throw new Error('Could not get MP4 URL');

        // Open MP4 URL directly - browser will download it
        // (fetch+blob fails due to CORS on Twitch CDN from non-player pages)
        window.open(mp4Url, '_blank');
      } catch (err) {
        console.error("Download failed:", err);
        alert("Download failed. Try again.");
      }

      btn.innerHTML = origHTML;
      btn.classList.remove('downloading');
    }

    // Attach handlers (including dynamically added cards)
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.dl-btn');
      if (btn) {
        e.preventDefault();
        e.stopPropagation();
        downloadClip(btn);
      }
    });
  </script>
  <style>@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>

  </div>
</body>
</html>
