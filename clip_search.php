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
      SELECT seq, clip_id, title, view_count, created_at, duration, game_id, thumbnail_url, creator_name
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
  // Live mode - fetch from Twitch API
  $isLiveMode = true;
  $twitchApi = new TwitchAPI();
  $liveCursor = null; // For progressive loading
  $hasMoreClips = false;

  if (!$twitchApi->isConfigured()) {
    $liveError = "Twitch API not configured";
  } else {
    // Fetch clips from Twitch API with date range
    // Start with 500 clips, user can load more via AJAX
    $result = $twitchApi->getClipsForStreamer($login, 500, $gameName ?: null, $dateRange);

    if (isset($result['error'])) {
      $liveError = $result['error'];
    } else {
      $allLiveClips = $result['clips'];

      // Build games list from ALL clips BEFORE filtering (for category dropdown)
      $gameIds = [];
      foreach ($allLiveClips as $clip) {
        $gid = $clip['game_id'] ?? '';
        if ($gid && !isset($gameIds[$gid])) {
          $gameIds[$gid] = ['game_id' => $gid, 'name' => '', 'count' => 0];
        }
        if ($gid) {
          $gameIds[$gid]['count']++;
        }
      }
      $games = array_values($gameIds);

      // Try to get game names from cache first, then Twitch API for missing ones
      if (!empty($games)) {
        $gameIdList = array_column($games, 'game_id');
        $gameNames = [];

        // Try database cache first
        if ($pdo) {
          try {
            $placeholders = implode(',', array_fill(0, count($gameIdList), '?'));
            $stmt = $pdo->prepare("SELECT game_id, name FROM games_cache WHERE game_id IN ($placeholders)");
            $stmt->execute($gameIdList);
            $gameNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
          } catch (PDOException $e) {
            // Ignore - will try API
          }
        }

        // Find which game IDs are missing from cache
        $missingIds = array_filter($gameIdList, function($id) use ($gameNames) {
          return !isset($gameNames[$id]);
        });

        // Fetch missing game names from Twitch API
        if (!empty($missingIds)) {
          $apiGames = $twitchApi->getGamesByIds($missingIds);
          foreach ($apiGames as $gid => $gameInfo) {
            $gameNames[$gid] = $gameInfo['name'];

            // Cache to database for future use
            if ($pdo) {
              try {
                $insertStmt = $pdo->prepare("INSERT INTO games_cache (game_id, name) VALUES (?, ?) ON CONFLICT (game_id) DO UPDATE SET name = EXCLUDED.name");
                $insertStmt->execute([$gid, $gameInfo['name']]);
              } catch (PDOException $e) {
                // Ignore cache write errors
              }
            }
          }
        }

        // Apply names to games list
        foreach ($games as &$g) {
          if (isset($gameNames[$g['game_id']])) {
            $g['name'] = $gameNames[$g['game_id']];
          } else {
            $g['name'] = "Unknown Game";
          }
        }
        unset($g);
      }

      // Sort games by count
      usort($games, function($a, $b) {
        return $b['count'] - $a['count'];
      });

      // Set currentGameName for active filter display
      if ($gameId) {
        foreach ($games as $g) {
          if ($g['game_id'] === $gameId) {
            $currentGameName = $g['name'] ?: "Game $gameId";
            break;
          }
        }
      }

      // Apply title filter if query provided
      if (!empty($queryWords)) {
        $allLiveClips = array_filter($allLiveClips, function($clip) use ($queryWords) {
          $title = strtolower($clip['title'] ?? '');
          foreach ($queryWords as $word) {
            if (stripos($title, $word) === false) {
              return false;
            }
          }
          return true;
        });
        $allLiveClips = array_values($allLiveClips);
      }

      // Apply clipper filter if provided (partial match)
      if ($clipper) {
        $allLiveClips = array_filter($allLiveClips, function($clip) use ($clipper) {
          return stripos($clip['creator_name'] ?? '', $clipper) !== false;
        });
        $allLiveClips = array_values($allLiveClips);
      }

      // Apply game_id filter if provided (for live mode category filtering)
      if ($gameId) {
        $allLiveClips = array_filter($allLiveClips, function($clip) use ($gameId) {
          return ($clip['game_id'] ?? '') === $gameId;
        });
        $allLiveClips = array_values($allLiveClips);
      }

      // Apply duration filter
      if ($minDuration) {
        $allLiveClips = array_filter($allLiveClips, function($clip) use ($minDuration) {
          $dur = (float)($clip['duration'] ?? 0);
          if ($minDuration === 'short') return $dur < 30;
          if ($minDuration === 'medium') return $dur >= 30 && $dur <= 60;
          if ($minDuration === 'long') return $dur > 60;
          return true;
        });
        $allLiveClips = array_values($allLiveClips);
      }

      // Apply minimum views filter
      if ($minViews > 0) {
        $allLiveClips = array_filter($allLiveClips, function($clip) use ($minViews) {
          return ($clip['view_count'] ?? 0) >= $minViews;
        });
        $allLiveClips = array_values($allLiveClips);
      }

      // Sort clips
      usort($allLiveClips, function($a, $b) use ($sort) {
        switch ($sort) {
          case 'date':
            return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
          case 'oldest':
            return strtotime($a['created_at'] ?? 0) - strtotime($b['created_at'] ?? 0);
          case 'title':
            return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
          case 'titlez':
            return strcasecmp($b['title'] ?? '', $a['title'] ?? '');
          case 'trending':
            // Trending = views per day (higher = more trending)
            $nowTs = time();
            $ageA = max(1, ($nowTs - strtotime($a['created_at'] ?? 'now')) / 86400);
            $ageB = max(1, ($nowTs - strtotime($b['created_at'] ?? 'now')) / 86400);
            $trendA = ($a['view_count'] ?? 0) / $ageA;
            $trendB = ($b['view_count'] ?? 0) / $ageB;
            return $trendB <=> $trendA;
          default: // views
            return ($b['view_count'] ?? 0) - ($a['view_count'] ?? 0);
        }
      });

      // Paginate
      $totalCount = count($allLiveClips);
      $totalPages = ceil($totalCount / $perPage);
      $offset = ($page - 1) * $perPage;
      $pagedClips = array_slice($allLiveClips, $offset, $perPage);

      // Convert to matches format (similar to DB format)
      foreach ($pagedClips as $clip) {
        $matches[] = [
          'seq' => 0, // No seq for live clips
          'clip_id' => $clip['clip_id'],
          'title' => $clip['title'],
          'view_count' => $clip['view_count'],
          'created_at' => $clip['created_at'],
          'duration' => $clip['duration'],
          'game_id' => $clip['game_id'],
          'thumbnail_url' => $clip['thumbnail_url'],
          'creator_name' => $clip['creator_name'],
        ];
      }
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

      <?php if ($isLiveMode): ?>
      <div class="filter-group">
        <label>Date Range</label>
        <select name="range">
          <option value="week" <?= $dateRange === 'week' ? 'selected' : '' ?>>Last Week</option>
          <option value="month" <?= $dateRange === 'month' ? 'selected' : '' ?>>Last Month</option>
          <option value="3months" <?= $dateRange === '3months' ? 'selected' : '' ?>>Last 3 Months</option>
          <option value="6months" <?= $dateRange === '6months' ? 'selected' : '' ?>>Last 6 Months</option>
          <option value="year" <?= $dateRange === 'year' ? 'selected' : '' ?>>Last Year</option>
          <option value="2years" <?= $dateRange === '2years' ? 'selected' : '' ?>>Last 2 Years</option>
          <option value="3years" <?= $dateRange === '3years' ? 'selected' : '' ?>>Last 3 Years</option>
          <option value="all" <?= $dateRange === 'all' ? 'selected' : '' ?>>All Time</option>
        </select>
      </div>
      <?php endif; ?>

      <button type="submit" class="filter-btn">Search</button>
      <?php if ($query || $gameId || $gameName || $clipper || $minDuration || $minViews > 0): ?>
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
      if ($sort !== 'views') $baseFilterParams['sort'] = $sort;
      if ($perPage !== 100) $baseFilterParams['per_page'] = $perPage;

      function buildFilterUrl($login, $params, $exclude = []) {
        $filtered = array_diff_key($params, array_flip($exclude));
        $query = http_build_query($filtered);
        return '/search/' . htmlspecialchars(urlencode($login)) . ($query ? '?' . $query : '');
      }

      $durationLabels = ['short' => 'Short (<30s)', 'medium' => 'Medium (30-60s)', 'long' => 'Long (>60s)'];
    ?>
    <?php if ($query || $gameId || $gameName || $clipper || $minDuration || $minViews > 0): ?>
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
    </div>
    <?php endif; ?>

    <?php if ($isLiveMode && !$liveError): ?>
    <div class="info-msg" style="background: linear-gradient(90deg, rgba(145,71,255,0.2), rgba(145,71,255,0.1)); border-color: #9147ff;">
      <strong>Live from Twitch</strong> - Showing clips from <?= $dateRange === 'all' ? 'all time' : 'the last ' . str_replace(['week', 'month', '3months', '6months', 'year', '2years', '3years'], ['week', 'month', '3 months', '6 months', 'year', '2 years', '3 years'], $dateRange) ?>.
      <span style="color: #adadb8; font-size: 12px; display: block; margin-top: 5px;">
        Showing <span id="liveClipCount"><?= $totalCount ?></span> clips. Use filters above to narrow results. No voting or clip numbers in live mode.
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
        $thumbUrl = !empty($clip['thumbnail_url']) && $clip['thumbnail_url'] !== 'NOT_FOUND'
          ? $clip['thumbnail_url']
          : "https://clips-media-assets2.twitch.tv/{$clipId}-preview-480x272.jpg";
        $twitchUrl = "https://clips.twitch.tv/" . rawurlencode($clipId);
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
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($isLiveMode && $totalCount >= 500): ?>
    <!-- Load More for live mode (Twitch API returns more clips) -->
    <div class="load-more-container">
      <button type="button" class="load-more-btn" id="loadMoreBtn" onclick="loadMoreClips()">
        Load More Clips
      </button>
    </div>
    <?php endif; ?>

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
        if ($perPage !== 100) $baseParams['per_page'] = $perPage;
        if ($isLiveMode && $dateRange !== 'year') $baseParams['range'] = $dateRange;

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

  <?php if ($isLiveMode && !$liveError && !empty($matches)): ?>
  <script>
    // Live mode: Load More functionality
    const liveConfig = {
      streamer: <?= json_encode($login) ?>,
      dateRange: <?= json_encode($dateRange) ?>,
      query: <?= json_encode($query) ?>,
      clipper: <?= json_encode($clipper) ?>,
      gameId: <?= json_encode($gameId) ?>,
      minDuration: <?= json_encode($minDuration) ?>,
      minViews: <?= (int)$minViews ?>,
      sort: <?= json_encode($sort) ?>,
      cursor: null, // Will be set when we start loading more
      loadedClips: new Set(), // Track clip IDs to avoid duplicates
      totalDisplayed: <?= $totalCount ?>
    };

    // Track already-loaded clip IDs
    document.querySelectorAll('.clip-card .clip-thumb').forEach(el => {
      const url = el.href;
      const clipId = url.split('/').pop();
      if (clipId) liveConfig.loadedClips.add(clipId);
    });

    function formatDuration(seconds) {
      const mins = Math.floor(seconds / 60);
      const secs = Math.floor(seconds % 60);
      return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }

    function formatDate(dateStr) {
      const date = new Date(dateStr);
      return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function formatViews(num) {
      return num.toLocaleString();
    }

    function createClipCard(clip) {
      const thumbUrl = clip.thumbnail_url || `https://clips-media-assets2.twitch.tv/${clip.clip_id}-preview-480x272.jpg`;
      const twitchUrl = `https://clips.twitch.tv/${encodeURIComponent(clip.clip_id)}`;
      const duration = clip.duration ? formatDuration(clip.duration) : '';

      return `
        <div class="clip-card">
          <a href="${twitchUrl}" target="_blank" class="clip-thumb">
            <img src="${thumbUrl}" alt="" loading="lazy"
                 onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 480 272%22><rect fill=%22%2326262c%22 width=%22480%22 height=%22272%22/><text x=%22240%22 y=%22140%22 fill=%22%23666%22 text-anchor=%22middle%22>No Preview</text></svg>'">
            ${duration ? `<span class="clip-duration">${duration}</span>` : ''}
            <span class="play-overlay"></span>
          </a>
          <div class="clip-info">
            <div class="clip-title">${clip.title ? clip.title.replace(/</g, '&lt;').replace(/>/g, '&gt;') : '(no title)'}</div>
            <div class="clip-meta">
              <span class="clip-views">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                ${formatViews(clip.view_count || 0)}
              </span>
              ${clip.created_at ? `<span class="clip-date">${formatDate(clip.created_at)}</span>` : ''}
            </div>
            <div class="clip-meta">
              ${clip.creator_name ? `<a href="/search/${encodeURIComponent(liveConfig.streamer)}?clipper=${encodeURIComponent(clip.creator_name)}" class="clip-clipper" title="View all clips by ${clip.creator_name.replace(/"/g, '&quot;')}">&#9986; ${clip.creator_name.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</a>` : ''}
            </div>
            ${clip.game_name ? `<div class="clip-game-row"><span class="clip-game" title="${clip.game_name.replace(/"/g, '&quot;')}">${clip.game_name.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</span></div>` : ''}
          </div>
        </div>
      `;
    }

    function filterClip(clip) {
      // Skip duplicates
      if (liveConfig.loadedClips.has(clip.clip_id)) return false;

      // Apply title search filter
      if (liveConfig.query) {
        const words = liveConfig.query.toLowerCase().split(/\s+/).filter(w => w.length >= 2);
        const title = (clip.title || '').toLowerCase();
        for (const word of words) {
          if (!title.includes(word)) return false;
        }
      }

      // Apply clipper filter
      if (liveConfig.clipper) {
        if (!(clip.creator_name || '').toLowerCase().includes(liveConfig.clipper.toLowerCase())) return false;
      }

      // Apply game filter
      if (liveConfig.gameId && clip.game_id !== liveConfig.gameId) return false;

      // Apply duration filter
      if (liveConfig.minDuration) {
        const dur = clip.duration || 0;
        if (liveConfig.minDuration === 'short' && dur >= 30) return false;
        if (liveConfig.minDuration === 'medium' && (dur < 30 || dur > 60)) return false;
        if (liveConfig.minDuration === 'long' && dur <= 60) return false;
      }

      // Apply min views filter
      if (liveConfig.minViews > 0 && (clip.view_count || 0) < liveConfig.minViews) return false;

      return true;
    }

    function sortClips(clips) {
      const sort = liveConfig.sort;
      return clips.sort((a, b) => {
        switch (sort) {
          case 'date':
            return new Date(b.created_at || 0) - new Date(a.created_at || 0);
          case 'oldest':
            return new Date(a.created_at || 0) - new Date(b.created_at || 0);
          case 'title':
            return (a.title || '').localeCompare(b.title || '');
          case 'titlez':
            return (b.title || '').localeCompare(a.title || '');
          case 'trending':
            const now = Date.now();
            const ageA = Math.max(1, (now - new Date(a.created_at || now)) / 86400000);
            const ageB = Math.max(1, (now - new Date(b.created_at || now)) / 86400000);
            return ((b.view_count || 0) / ageB) - ((a.view_count || 0) / ageA);
          default: // views
            return (b.view_count || 0) - (a.view_count || 0);
        }
      });
    }

    async function loadMoreClips() {
      const btn = document.getElementById('loadMoreBtn');
      if (!btn) return;

      btn.disabled = true;
      btn.classList.add('loading');
      btn.textContent = 'Loading...';

      try {
        const params = new URLSearchParams({
          login: liveConfig.streamer,
          range: liveConfig.dateRange,
          batch: 100
        });
        if (liveConfig.cursor) params.set('cursor', liveConfig.cursor);

        const response = await fetch(`/api/live_clips.php?${params}`);
        const data = await response.json();

        if (!data.success) {
          throw new Error(data.error || 'Failed to load clips');
        }

        // Filter and sort new clips
        const newClips = data.clips.filter(filterClip);
        sortClips(newClips);

        // Add to grid
        const grid = document.querySelector('.results-grid');
        if (grid && newClips.length > 0) {
          for (const clip of newClips) {
            liveConfig.loadedClips.add(clip.clip_id);
            grid.insertAdjacentHTML('beforeend', createClipCard(clip));
            liveConfig.totalDisplayed++;
          }

          // Update counter
          const counter = document.getElementById('liveClipCount');
          if (counter) counter.textContent = liveConfig.totalDisplayed;
        }

        // Update cursor for next batch
        liveConfig.cursor = data.cursor;

        // Hide button if no more clips
        if (!data.has_more) {
          btn.textContent = 'All Clips Loaded';
          btn.disabled = true;
          btn.classList.remove('loading');
        } else {
          btn.disabled = false;
          btn.classList.remove('loading');
          btn.textContent = 'Load More Clips';
        }

      } catch (error) {
        console.error('Load more failed:', error);
        btn.disabled = false;
        btn.classList.remove('loading');
        btn.textContent = 'Error - Try Again';
      }
    }
  </script>
  <?php endif; ?>
  </div>
</body>
</html>
