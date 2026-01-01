<?php
/**
 * clip_manage.php - Clip management for streamers
 *
 * Like clip_search.php but with management tools:
 * - Block/unblock clips
 * - Play clips (!pclip)
 * - View blocked clips
 * - Block clippers
 *
 * Requires OAuth login (own channel, super admin, or mod).
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Content-Security-Policy: upgrade-insecure-requests");

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/dashboard_auth.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login  = clean_login($_GET["login"] ?? "");
$query  = trim((string)($_GET["q"] ?? ""));
$gameId = trim((string)($_GET["game_id"] ?? ""));
$gameName = trim((string)($_GET["game"] ?? ""));
$clipper = trim((string)($_GET["clipper"] ?? ""));
$sort   = $_GET["sort"] ?? "views";
$page   = max(1, (int)($_GET["page"] ?? 1));
$perPage = 50; // Fewer per page for management view
$showBlocked = isset($_GET["blocked"]) && $_GET["blocked"] === "1";

// Authenticate via OAuth (own channel, super admin, or mod)
$auth = new DashboardAuth();
$isAuthed = false;
$currentUser = getCurrentUser();

if ($currentUser) {
  $oauthUsername = strtolower($currentUser['login']);
  // Own channel access
  if ($oauthUsername === $login) {
    $isAuthed = true;
  }
  // Super admin access
  elseif (isSuperAdmin()) {
    $isAuthed = true;
  }
  // Check if user is in channel's mod list
  else {
    $pdoCheck = get_db_connection();
    if ($pdoCheck) {
      try {
        $stmt = $pdoCheck->prepare("SELECT 1 FROM channel_mods WHERE channel_login = ? AND mod_username = ?");
        $stmt->execute([$login, $oauthUsername]);
        if ($stmt->fetch()) {
          $isAuthed = true;
        }
      } catch (PDOException $e) {
        // Ignore - table might not exist
      }
    }
  }
}

if (!$isAuthed) {
  http_response_code(403);
  $oauth = new TwitchOAuth();
  $authUrl = $oauth->getAuthUrl($_SERVER['REQUEST_URI']);
  echo '<!DOCTYPE html><html><head><title>Access Denied</title><style>body{font-family:system-ui;background:#0e0e10;color:#efeff1;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;}.box{background:#1f1f23;padding:40px;border-radius:8px;text-align:center;max-width:400px;}.box h1{color:#f87171;margin-top:0;}.box a{color:#9147ff;}.btn{display:inline-block;background:#9147ff;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;margin-top:15px;}</style></head><body><div class="box"><h1>Access Denied</h1><p>Please log in with Twitch to access clip management.</p><a href="' . htmlspecialchars($authUrl) . '" class="btn">Login with Twitch</a></div></body></html>';
  exit;
}

// Validate sort option
$validSorts = ['views', 'date', 'oldest', 'title', 'titlez', 'seq', 'seqz'];
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
$blockedCount = 0;
$activeCount = 0;

$pdo = get_db_connection();

if ($pdo) {
  try {
    // Get blocked/active counts
    $countStmt = $pdo->prepare("
      SELECT
        COUNT(*) FILTER (WHERE blocked = FALSE) as active,
        COUNT(*) FILTER (WHERE blocked = TRUE) as blocked_count
      FROM clips WHERE login = ?
    ");
    $countStmt->execute([$login]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);
    $activeCount = (int)($counts['active'] ?? 0);
    $blockedCount = (int)($counts['blocked_count'] ?? 0);

    // Fetch available games/categories for the dropdown
    $blockedFilter = $showBlocked ? "blocked = TRUE" : "blocked = FALSE";
    $gamesStmt = $pdo->prepare("
      SELECT c.game_id, gc.name, COUNT(*) as count
      FROM clips c
      LEFT JOIN games_cache gc ON c.game_id = gc.game_id
      WHERE c.login = ? AND c.{$blockedFilter} AND c.game_id IS NOT NULL AND c.game_id != ''
      GROUP BY c.game_id, gc.name
      ORDER BY count DESC
      LIMIT 100
    ");
    $gamesStmt->execute([$login]);
    $games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Sort games alphabetically
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
    $whereClauses = ["login = ?"];
    $params = [$login];

    // Blocked filter
    if ($showBlocked) {
      $whereClauses[] = "blocked = TRUE";
    } else {
      $whereClauses[] = "blocked = FALSE";
    }

    // Game filter
    if ($gameId) {
      $whereClauses[] = "game_id = ?";
      $params[] = $gameId;
    }

    // Game name filter
    if ($gameName && !$gameId) {
      $gameSearchStmt = $pdo->prepare("SELECT game_id FROM games_cache WHERE name ILIKE ?");
      $gameSearchStmt->execute(['%' . $gameName . '%']);
      $matchingGameIds = $gameSearchStmt->fetchAll(PDO::FETCH_COLUMN);

      if (!empty($matchingGameIds)) {
        $placeholders = implode(',', array_fill(0, count($matchingGameIds), '?'));
        $whereClauses[] = "game_id IN ($placeholders)";
        $params = array_merge($params, $matchingGameIds);
      } else {
        $whereClauses[] = "1 = 0";
      }
    }

    // Clipper filter
    if ($clipper) {
      $whereClauses[] = "creator_name ILIKE ?";
      $params[] = '%' . $clipper . '%';
    }

    // Query filter
    $isClipNumber = $query && preg_match('/^\d+$/', $query);
    if ($isClipNumber) {
      $whereClauses[] = "(seq = ? OR title ILIKE ?)";
      $params[] = (int)$query;
      $params[] = '%' . $query . '%';
    } else {
      foreach ($queryWords as $word) {
        $whereClauses[] = "title ILIKE ?";
        $params[] = '%' . $word . '%';
      }
    }

    $whereSQL = implode(' AND ', $whereClauses);

    // Get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE {$whereSQL}");
    $stmt->execute($params);
    $totalCount = (int)$stmt->fetchColumn();
    $totalPages = ceil($totalCount / $perPage);

    // Get paginated results
    $offset = ($page - 1) * $perPage;
    $orderBy = match($sort) {
      'date' => 'created_at DESC',
      'oldest' => 'created_at ASC',
      'title' => 'title ASC',
      'titlez' => 'title DESC',
      'seq' => 'seq ASC',
      'seqz' => 'seq DESC',
      default => 'view_count DESC',
    };

    if ($isClipNumber) {
      $orderBy = "CASE WHEN seq = " . (int)$query . " THEN 0 ELSE 1 END, " . $orderBy;
    }

    $paginatedParams = array_merge($params, [$perPage, $offset]);
    $stmt = $pdo->prepare("
      SELECT seq, clip_id, title, view_count, created_at, duration, game_id, thumbnail_url, creator_name, blocked
      FROM clips
      WHERE {$whereSQL}
      ORDER BY {$orderBy}
      LIMIT ? OFFSET ?
    ");
    $stmt->execute($paginatedParams);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    error_log("clip_manage db error: " . $e->getMessage());
  }
}

// Build base URL with key preserved
$baseUrl = "clip_manage.php?login=" . urlencode($login) . "&key=" . urlencode($key);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Clip Manager - <?= htmlspecialchars($login) ?></title>
  <style>
    * { box-sizing: border-box; }
    html { background: #0e0e10; min-height: 100%; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #0e0e10;
      color: #efeff1;
      margin: 0;
      padding: 20px;
      min-height: 100vh;
    }
    .container { max-width: 1400px; margin: 0 auto; }

    header {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 20px;
      gap: 15px;
    }
    h1 { margin: 0; font-size: 24px; color: #9147ff; }
    .subtitle { color: #adadb8; font-size: 14px; margin-top: 4px; }
    .nav-links { display: flex; gap: 15px; margin-top: 8px; }
    .nav-links a { color: #9147ff; text-decoration: none; font-size: 13px; }
    .nav-links a:hover { text-decoration: underline; }

    .stats-bar {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }
    .stat-box {
      background: #1f1f23;
      padding: 10px 16px;
      border-radius: 6px;
      font-size: 14px;
    }
    .stat-box strong { color: #9147ff; }
    .stat-box.blocked strong { color: #f87171; }
    .stat-box.active strong { color: #4ade80; }

    /* Tab toggle */
    .tab-toggle {
      display: flex;
      gap: 0;
      margin-bottom: 20px;
    }
    .tab-toggle a {
      padding: 12px 24px;
      background: #1f1f23;
      color: #adadb8;
      text-decoration: none;
      font-weight: 600;
      border: 1px solid #3d3d42;
      transition: all 0.2s;
    }
    .tab-toggle a:first-child { border-radius: 6px 0 0 6px; }
    .tab-toggle a:last-child { border-radius: 0 6px 6px 0; border-left: none; }
    .tab-toggle a.active {
      background: #9147ff;
      color: white;
      border-color: #9147ff;
    }
    .tab-toggle a.active.blocked-tab {
      background: #dc2626;
      border-color: #dc2626;
    }
    .tab-toggle a:hover:not(.active) {
      background: #26262c;
      color: #efeff1;
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
    .filter-group { display: flex; flex-direction: column; gap: 4px; }
    .filter-group label { font-size: 11px; color: #adadb8; text-transform: uppercase; }
    .filter-group input, .filter-group select {
      padding: 10px 14px;
      border: 1px solid #3d3d42;
      border-radius: 6px;
      background: #0e0e10;
      color: #efeff1;
      font-size: 14px;
      min-width: 180px;
    }
    .filter-group input:focus, .filter-group select:focus {
      outline: none;
      border-color: #9147ff;
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
    }
    .filter-btn:hover { background: #772ce8; }
    .clear-btn {
      align-self: flex-end;
      padding: 10px 16px;
      border: 1px solid #3d3d42;
      border-radius: 6px;
      background: transparent;
      color: #adadb8;
      text-decoration: none;
    }
    .clear-btn:hover { border-color: #9147ff; color: #9147ff; }

    /* Active filters */
    .active-filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
    .filter-tag {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      background: #772ce8;
      border-radius: 20px;
      font-size: 13px;
    }
    .filter-tag a { color: white; text-decoration: none; opacity: 0.8; }
    .filter-tag a:hover { opacity: 1; }

    /* Results grid */
    .results-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 16px;
    }
    .clip-card {
      background: #1f1f23;
      border-radius: 8px;
      overflow: hidden;
      transition: transform 0.2s, box-shadow 0.2s;
      position: relative;
    }
    .clip-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.4); }
    .clip-card.is-blocked { border: 2px solid #f87171; opacity: 0.7; }
    .clip-card.is-blocked:hover { opacity: 1; }

    .clip-thumb {
      position: relative;
      padding-top: 56.25%;
      background: #26262c;
      display: block;
    }
    .clip-thumb img {
      position: absolute;
      top: 0; left: 0;
      width: 100%; height: 100%;
      object-fit: cover;
    }
    .clip-seq {
      position: absolute;
      top: 8px; left: 8px;
      background: rgba(0,0,0,0.85);
      color: #9147ff;
      padding: 3px 8px;
      border-radius: 4px;
      font-weight: bold;
      font-size: 13px;
    }
    .clip-duration {
      position: absolute;
      bottom: 8px; right: 8px;
      background: rgba(0,0,0,0.85);
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 12px;
    }
    .blocked-badge {
      position: absolute;
      top: 8px; right: 8px;
      background: #dc2626;
      color: white;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: bold;
    }

    .clip-info { padding: 12px; }
    .clip-title {
      font-weight: 600;
      font-size: 14px;
      line-height: 1.35;
      margin-bottom: 8px;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .clip-meta {
      display: flex;
      justify-content: space-between;
      color: #adadb8;
      font-size: 12px;
      margin-bottom: 6px;
    }
    .clip-clipper { color: #bf94ff; font-size: 12px; }
    .clip-game { background: #26262c; padding: 2px 6px; border-radius: 4px; font-size: 11px; display: inline-block; margin-top: 6px; }

    /* Action buttons */
    .clip-actions {
      display: flex;
      gap: 8px;
      padding: 12px;
      padding-top: 0;
      flex-wrap: wrap;
    }
    .action-btn {
      flex: 1;
      padding: 8px 12px;
      border: none;
      border-radius: 6px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 4px;
      transition: all 0.2s;
      min-width: 80px;
    }
    .action-btn.play { background: #9147ff; color: white; }
    .action-btn.play:hover { background: #772ce8; }
    .action-btn.block { background: #dc2626; color: white; }
    .action-btn.block:hover { background: #b91c1c; }
    .action-btn.unblock { background: #16a34a; color: white; }
    .action-btn.unblock:hover { background: #15803d; }
    .action-btn.twitch { background: #1f1f23; color: #9147ff; border: 1px solid #3d3d42; }
    .action-btn.twitch:hover { background: #26262c; }
    .action-btn:disabled { opacity: 0.5; cursor: not-allowed; }

    .no-results { text-align: center; padding: 60px 20px; color: #adadb8; }
    .no-results h2 { color: #efeff1; margin-bottom: 10px; }

    /* Pagination */
    .pagination {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 10px;
      margin-top: 30px;
      flex-wrap: wrap;
    }
    .pagination a, .pagination span {
      padding: 10px 16px;
      border-radius: 6px;
      text-decoration: none;
      font-weight: 600;
    }
    .pagination a { background: #1f1f23; color: #9147ff; border: 1px solid #3d3d42; }
    .pagination a:hover { background: #26262c; border-color: #9147ff; }
    .pagination .current { background: #9147ff; color: white; }
    .pagination .disabled { background: #1f1f23; color: #3d3d42; border: 1px solid #3d3d42; }
    .pagination .page-info { color: #adadb8; background: transparent; }

    /* Toast notifications */
    .toast {
      position: fixed;
      bottom: 20px;
      right: 20px;
      padding: 12px 20px;
      border-radius: 8px;
      font-weight: 600;
      z-index: 9999;
      animation: slideIn 0.3s ease;
    }
    .toast.success { background: #16a34a; color: white; }
    .toast.error { background: #dc2626; color: white; }
    @keyframes slideIn {
      from { transform: translateX(100%); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }

    @media (max-width: 768px) {
      body { padding: 12px; }
      header { flex-direction: column; }
      .filters { flex-direction: column; }
      .filter-group input, .filter-group select { min-width: 100%; }
      .results-grid { grid-template-columns: 1fr; }
      .clip-actions { flex-direction: column; }
      .action-btn { min-width: auto; }
    }
  </style>
</head>
<body>
  <div class="container">
    <header>
      <div>
        <h1>Clip Manager</h1>
        <p class="subtitle"><?= htmlspecialchars($login) ?>'s Clips</p>
        <div class="nav-links">
          <a href="mod_dashboard.php?login=<?= htmlspecialchars($login) ?>">Back to Dashboard</a>
          <a href="clip_search.php?login=<?= htmlspecialchars($login) ?>">Public Search</a>
        </div>
      </div>
      <div class="stats-bar">
        <div class="stat-box active">
          <strong><?= number_format($activeCount) ?></strong> Active
        </div>
        <div class="stat-box blocked">
          <strong><?= number_format($blockedCount) ?></strong> Blocked
        </div>
        <div class="stat-box">
          <strong><?= number_format($totalCount) ?></strong> shown
          <?php if ($totalPages > 1): ?> &middot; Page <?= $page ?>/<?= $totalPages ?><?php endif; ?>
        </div>
      </div>
    </header>

    <!-- Tab toggle -->
    <div class="tab-toggle">
      <a href="<?= $baseUrl ?>" class="<?= !$showBlocked ? 'active' : '' ?>">Active Clips</a>
      <a href="<?= $baseUrl ?>&blocked=1" class="<?= $showBlocked ? 'active blocked-tab' : '' ?>">Blocked Clips (<?= $blockedCount ?>)</a>
    </div>

    <form class="filters" method="get">
      <input type="hidden" name="login" value="<?= htmlspecialchars($login) ?>">
      <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
      <?php if ($showBlocked): ?><input type="hidden" name="blocked" value="1"><?php endif; ?>

      <div class="filter-group">
        <label>Title / Clip #</label>
        <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Search...">
      </div>

      <div class="filter-group">
        <label>Clipper</label>
        <input type="text" name="clipper" value="<?= htmlspecialchars($clipper) ?>" placeholder="Clipper name...">
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
        <label>Sort</label>
        <select name="sort">
          <option value="views" <?= $sort === 'views' ? 'selected' : '' ?>>Most Viewed</option>
          <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>Newest</option>
          <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest</option>
          <option value="seq" <?= $sort === 'seq' ? 'selected' : '' ?>>Clip # (Low-High)</option>
          <option value="seqz" <?= $sort === 'seqz' ? 'selected' : '' ?>>Clip # (High-Low)</option>
          <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title (A-Z)</option>
        </select>
      </div>

      <button type="submit" class="filter-btn">Search</button>
      <?php if ($query || $gameId || $clipper): ?>
      <a href="<?= $baseUrl ?><?= $showBlocked ? '&blocked=1' : '' ?>" class="clear-btn">Clear</a>
      <?php endif; ?>
    </form>

    <?php if ($query || $gameId || $clipper): ?>
    <div class="active-filters">
      <?php if ($query): ?>
      <span class="filter-tag">
        "<?= htmlspecialchars($query) ?>"
        <a href="<?= $baseUrl ?><?= $gameId ? '&game_id=' . htmlspecialchars($gameId) : '' ?><?= $clipper ? '&clipper=' . htmlspecialchars($clipper) : '' ?><?= $showBlocked ? '&blocked=1' : '' ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($clipper): ?>
      <span class="filter-tag">
        Clipper: <?= htmlspecialchars($clipper) ?>
        <a href="<?= $baseUrl ?><?= $query ? '&q=' . htmlspecialchars($query) : '' ?><?= $gameId ? '&game_id=' . htmlspecialchars($gameId) : '' ?><?= $showBlocked ? '&blocked=1' : '' ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($gameId): ?>
      <span class="filter-tag">
        <?= htmlspecialchars($currentGameName) ?>
        <a href="<?= $baseUrl ?><?= $query ? '&q=' . htmlspecialchars($query) : '' ?><?= $clipper ? '&clipper=' . htmlspecialchars($clipper) : '' ?><?= $showBlocked ? '&blocked=1' : '' ?>">&times;</a>
      </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($matches)): ?>
    <div class="no-results">
      <h2><?= $showBlocked ? 'No blocked clips' : 'No clips found' ?></h2>
      <p><?= $query || $gameId || $clipper ? 'Try different filters' : ($showBlocked ? 'No clips have been blocked yet' : 'Search or browse clips above') ?></p>
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
        $isBlocked = (bool)$clip['blocked'];

        $clipGameName = '';
        if (!empty($clip['game_id'])) {
          foreach ($games as $g) {
            if ($g['game_id'] === $clip['game_id']) {
              $clipGameName = $g['name'] ?: '';
              break;
            }
          }
        }

        $clipDate = '';
        if (!empty($clip['created_at'])) {
          $dateObj = new DateTime($clip['created_at']);
          $clipDate = $dateObj->format('M j, Y');
        }
      ?>
      <div class="clip-card <?= $isBlocked ? 'is-blocked' : '' ?>" data-seq="<?= $seq ?>">
        <div class="clip-thumb">
          <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="" loading="lazy"
               onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 480 272%22><rect fill=%22%2326262c%22 width=%22480%22 height=%22272%22/><text x=%22240%22 y=%22140%22 fill=%22%23666%22 text-anchor=%22middle%22>No Preview</text></svg>'">
          <span class="clip-seq">#<?= $seq ?></span>
          <?php if ($duration): ?>
          <span class="clip-duration"><?= $duration ?></span>
          <?php endif; ?>
          <?php if ($isBlocked): ?>
          <span class="blocked-badge">BLOCKED</span>
          <?php endif; ?>
        </div>
        <div class="clip-info">
          <div class="clip-title"><?= htmlspecialchars($title) ?></div>
          <div class="clip-meta">
            <span><?= number_format((int)($clip['view_count'] ?? 0)) ?> views</span>
            <?php if ($clipDate): ?><span><?= $clipDate ?></span><?php endif; ?>
          </div>
          <?php if (!empty($clip['creator_name'])): ?>
          <div class="clip-clipper">&#9986; <?= htmlspecialchars($clip['creator_name']) ?></div>
          <?php endif; ?>
          <?php if ($clipGameName): ?>
          <span class="clip-game"><?= htmlspecialchars($clipGameName) ?></span>
          <?php endif; ?>
        </div>
        <div class="clip-actions">
          <button class="action-btn play" onclick="playClip(<?= $seq ?>)" title="Force play this clip">
            &#9654; Play
          </button>
          <?php if ($isBlocked): ?>
          <button class="action-btn unblock" onclick="toggleBlock(<?= $seq ?>, false)" title="Unblock this clip">
            &#10003; Unblock
          </button>
          <?php else: ?>
          <button class="action-btn block" onclick="toggleBlock(<?= $seq ?>, true)" title="Block this clip from playing">
            &#10005; Block
          </button>
          <?php endif; ?>
          <a href="<?= htmlspecialchars($twitchUrl) ?>" target="_blank" class="action-btn twitch">
            Twitch
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php
        $pageParams = ['login' => $login, 'key' => $key];
        if ($query) $pageParams['q'] = $query;
        if ($clipper) $pageParams['clipper'] = $clipper;
        if ($gameId) $pageParams['game_id'] = $gameId;
        if ($sort !== 'views') $pageParams['sort'] = $sort;
        if ($showBlocked) $pageParams['blocked'] = '1';

        function pageUrl($params, $pageNum) {
          $params['page'] = $pageNum;
          return '?' . http_build_query($params);
        }
      ?>
      <?php if ($page > 1): ?>
        <a href="<?= pageUrl($pageParams, 1) ?>">&laquo; First</a>
        <a href="<?= pageUrl($pageParams, $page - 1) ?>">&lsaquo; Prev</a>
      <?php else: ?>
        <span class="disabled">&laquo; First</span>
        <span class="disabled">&lsaquo; Prev</span>
      <?php endif; ?>

      <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>

      <?php if ($page < $totalPages): ?>
        <a href="<?= pageUrl($pageParams, $page + 1) ?>">Next &rsaquo;</a>
        <a href="<?= pageUrl($pageParams, $totalPages) ?>">Last &raquo;</a>
      <?php else: ?>
        <span class="disabled">Next &rsaquo;</span>
        <span class="disabled">Last &raquo;</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>

  <script>
    const login = <?= json_encode($login) ?>;
    const key = <?= json_encode($key) ?>;
    const apiBase = 'dashboard_api.php';

    function showToast(message, type = 'success') {
      const toast = document.createElement('div');
      toast.className = 'toast ' + type;
      toast.textContent = message;
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 3000);
    }

    async function playClip(seq) {
      try {
        const url = `pclip.php?login=${encodeURIComponent(login)}&seq=${seq}&key=${encodeURIComponent(key)}`;
        const res = await fetch(url);
        const text = await res.text();
        if (res.ok) {
          showToast(`Playing clip #${seq}`);
        } else {
          showToast(text || 'Failed to play clip', 'error');
        }
      } catch (e) {
        showToast('Error: ' + e.message, 'error');
      }
    }

    async function toggleBlock(seq, blocked) {
      try {
        const url = `${apiBase}?login=${encodeURIComponent(login)}&key=${encodeURIComponent(key)}&action=block_clip&seq=${seq}&blocked=${blocked}`;
        const res = await fetch(url);
        const data = await res.json();

        if (data.success) {
          showToast(blocked ? `Clip #${seq} blocked` : `Clip #${seq} unblocked`);
          // Refresh the page to update the view
          setTimeout(() => location.reload(), 500);
        } else {
          showToast(data.error || 'Failed to update clip', 'error');
        }
      } catch (e) {
        showToast('Error: ' + e.message, 'error');
      }
    }
  </script>
</body>
</html>
