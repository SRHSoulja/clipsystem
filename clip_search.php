<?php
/**
 * clip_search.php - Display search results for clips
 *
 * Web page that shows all clips matching a search query.
 * Supports category filtering and links directly to Twitch.
 * Falls back to live Twitch API for non-archived streamers.
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_api.php';

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
$sort   = $_GET["sort"] ?? "views"; // views, date, title
$page   = max(1, (int)($_GET["page"] ?? 1));
$perPage = 100;

// Validate sort option
$validSorts = ['views', 'date', 'oldest', 'title', 'titlez'];
if (!in_array($sort, $validSorts)) {
  $sort = 'views';
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

// Check if this streamer has archived clips
$hasArchivedClips = false;
if ($pdo) {
  try {
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ?");
    $checkStmt->execute([$login]);
    $hasArchivedClips = (int)$checkStmt->fetchColumn() > 0;
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

  if (!$twitchApi->isConfigured()) {
    $liveError = "Twitch API not configured";
  } else {
    // Fetch clips from Twitch API
    $result = $twitchApi->getClipsForStreamer($login, 500, $gameName ?: null);

    if (isset($result['error'])) {
      $liveError = $result['error'];
    } else {
      $allLiveClips = $result['clips'];

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

      // Build games list from clips (for category dropdown)
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

      // Try to get game names from Twitch API or cache
      if ($pdo && !empty($games)) {
        try {
          $gameIdList = array_column($games, 'game_id');
          $placeholders = implode(',', array_fill(0, count($gameIdList), '?'));
          $stmt = $pdo->prepare("SELECT game_id, name FROM games_cache WHERE game_id IN ($placeholders)");
          $stmt->execute($gameIdList);
          $gameNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

          foreach ($games as &$g) {
            if (isset($gameNames[$g['game_id']])) {
              $g['name'] = $gameNames[$g['game_id']];
            } else {
              $g['name'] = "Game " . $g['game_id'];
            }
          }
          unset($g);
        } catch (PDOException $e) {
          // Ignore - just use game IDs
        }
      }

      // Sort games by count
      usort($games, function($a, $b) {
        return $b['count'] - $a['count'];
      });
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
      padding: 20px;
      min-height: 100vh;
      min-height: -webkit-fill-available;
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
  <div class="container">
    <header>
      <div>
        <h1><a href="?login=<?= htmlspecialchars($login) ?>">Clip Search</a></h1>
        <p class="subtitle"><?= htmlspecialchars($login) ?>'s Clips</p>
        <div class="nav-links">
          <a href="chelp.php">Bot Commands</a>
          <a href="about.php">About</a>
        </div>
      </div>
      <div class="header-right">
        <div class="total-count">
          <strong><?= number_format($totalCount) ?></strong> result<?= $totalCount !== 1 ? 's' : '' ?>
          <?php if ($totalPages > 1): ?> &middot; Page <?= $page ?> of <?= $totalPages ?><?php endif; ?>
        </div>
      </div>
    </header>

    <form class="filters" method="get">
      <input type="hidden" name="login" value="<?= htmlspecialchars($login) ?>">

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
          <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>Newest First</option>
          <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
          <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title (A-Z)</option>
          <option value="titlez" <?= $sort === 'titlez' ? 'selected' : '' ?>>Title (Z-A)</option>
        </select>
      </div>

      <button type="submit" class="filter-btn">Search</button>
      <?php if ($query || $gameId || $gameName || $clipper): ?>
      <a href="?login=<?= htmlspecialchars($login) ?>" class="clear-btn">Clear All</a>
      <?php endif; ?>
    </form>

    <?php
      $sortParam = ($sort !== 'views') ? '&sort=' . htmlspecialchars($sort) : '';
    ?>
    <?php if ($query || $gameId || $gameName || $clipper): ?>
    <div class="active-filters">
      <?php if ($query): ?>
      <span class="filter-tag">
        Search: "<?= htmlspecialchars($query) ?>"
        <a href="?login=<?= htmlspecialchars($login) ?><?= $gameId ? '&game_id=' . htmlspecialchars($gameId) : '' ?><?= $gameName ? '&game=' . htmlspecialchars($gameName) : '' ?><?= $clipper ? '&clipper=' . htmlspecialchars($clipper) : '' ?><?= $sortParam ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($clipper): ?>
      <span class="filter-tag">
        Clipper: <?= htmlspecialchars($clipper) ?>
        <a href="?login=<?= htmlspecialchars($login) ?><?= $query ? '&q=' . htmlspecialchars($query) : '' ?><?= $gameId ? '&game_id=' . htmlspecialchars($gameId) : '' ?><?= $gameName ? '&game=' . htmlspecialchars($gameName) : '' ?><?= $sortParam ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($gameId): ?>
      <span class="filter-tag">
        Category: <?= htmlspecialchars($currentGameName) ?>
        <a href="?login=<?= htmlspecialchars($login) ?><?= $query ? '&q=' . htmlspecialchars($query) : '' ?><?= $clipper ? '&clipper=' . htmlspecialchars($clipper) : '' ?><?= $sortParam ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($gameName && !$gameId): ?>
      <span class="filter-tag">
        Category: "<?= htmlspecialchars($gameName) ?>"
        <a href="?login=<?= htmlspecialchars($login) ?><?= $query ? '&q=' . htmlspecialchars($query) : '' ?><?= $clipper ? '&clipper=' . htmlspecialchars($clipper) : '' ?><?= $sortParam ?>">&times;</a>
      </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($isLiveMode && !$liveError): ?>
    <div class="info-msg" style="background: linear-gradient(90deg, rgba(145,71,255,0.2), rgba(145,71,255,0.1)); border-color: #9147ff;">
      <strong>Live from Twitch</strong> - Showing top clips fetched directly from Twitch API.
      <span style="color: #adadb8; font-size: 12px; display: block; margin-top: 5px;">
        Note: Limited to ~500 clips. Clipper search works but may miss some results. No voting or clip numbers in live mode.
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
            <a href="?login=<?= htmlspecialchars($login) ?>&clipper=<?= urlencode($clip['creator_name']) ?>" class="clip-clipper" title="View all clips by <?= htmlspecialchars($clip['creator_name']) ?>">&#9986; <?= htmlspecialchars($clip['creator_name']) ?></a>
            <?php endif; ?>
          </div>
          <?php if ($gameName): ?>
          <div class="clip-game-row">
            <span class="clip-game" title="<?= htmlspecialchars($gameName) ?>"><?= htmlspecialchars($gameName) ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php
        $baseParams = ['login' => $login];
        if ($query) $baseParams['q'] = $query;
        if ($clipper) $baseParams['clipper'] = $clipper;
        if ($gameId) $baseParams['game_id'] = $gameId;
        if ($gameName) $baseParams['game'] = $gameName;
        if ($sort !== 'views') $baseParams['sort'] = $sort;

        function pageUrl($params, $pageNum) {
          $params['page'] = $pageNum;
          return '?' . http_build_query($params);
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
</body>
</html>
