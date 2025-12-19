<?php
/**
 * clip_search.php - Display search results for clips
 *
 * Web page that shows all clips matching a search query.
 * Supports category filtering and links directly to Twitch.
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/db_config.php';

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login  = clean_login($_GET["login"] ?? "");
$query  = trim((string)($_GET["q"] ?? ""));
$gameId = trim((string)($_GET["game_id"] ?? ""));
$page   = max(1, (int)($_GET["page"] ?? 1));
$perPage = 100;

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

$pdo = get_db_connection();

if ($pdo) {
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

    // Game filter
    if ($gameId) {
      $whereClauses[] = "game_id = ?";
      $params[] = $gameId;
    }

    // Check if query is a clip number (all digits)
    $isClipNumber = $query && preg_match('/^\d+$/', $query);

    if ($isClipNumber) {
      // Search by clip seq number
      $whereClauses[] = "seq = ?";
      $params[] = (int)$query;
    } else {
      // Search filter by title words
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
    $paginatedParams = array_merge($params, [$perPage, $offset]);
    $stmt = $pdo->prepare("
      SELECT seq, clip_id, title, view_count, created_at, duration, game_id, thumbnail_url
      FROM clips
      WHERE {$whereSQL}
      ORDER BY view_count DESC
      LIMIT ? OFFSET ?
    ");
    $stmt->execute($paginatedParams);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    error_log("clip_search db error: " . $e->getMessage());
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
      color: #9147ff;
      margin: 0;
      font-size: 24px;
    }
    .subtitle {
      color: #adadb8;
      font-size: 14px;
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
    .clip-game {
      background: #26262c;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 11px;
      max-width: 120px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
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
        <h1>Clip Search</h1>
        <p class="subtitle"><?= htmlspecialchars($login) ?>'s Clips</p>
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
        <label>Search</label>
        <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Search clip titles..." autofocus>
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

      <button type="submit" class="filter-btn">Search</button>
      <?php if ($query || $gameId): ?>
      <a href="?login=<?= htmlspecialchars($login) ?>" class="clear-btn">Clear All</a>
      <?php endif; ?>
    </form>

    <?php if ($query || $gameId): ?>
    <div class="active-filters">
      <?php if ($query): ?>
      <span class="filter-tag">
        Search: "<?= htmlspecialchars($query) ?>"
        <a href="?login=<?= htmlspecialchars($login) ?><?= $gameId ? '&game_id=' . htmlspecialchars($gameId) : '' ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($gameId): ?>
      <span class="filter-tag">
        Category: <?= htmlspecialchars($currentGameName) ?>
        <a href="?login=<?= htmlspecialchars($login) ?><?= $query ? '&q=' . htmlspecialchars($query) : '' ?>">&times;</a>
      </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="info-msg">
      Click any clip to watch on Twitch. Search by title or clip number (e.g. "1234").
    </div>

    <?php if (empty($matches) && ($query || $gameId)): ?>
    <div class="no-results">
      <h2>No clips found</h2>
      <p>Try a different search term or category</p>
    </div>
    <?php elseif (empty($matches)): ?>
    <div class="no-results">
      <h2>Search for clips</h2>
      <p>Enter a search term or select a category to find clips</p>
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
      ?>
      <div class="clip-card">
        <a href="<?= htmlspecialchars($twitchUrl) ?>" target="_blank" class="clip-thumb">
          <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="" loading="lazy"
               onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 480 272%22><rect fill=%22%2326262c%22 width=%22480%22 height=%22272%22/><text x=%22240%22 y=%22140%22 fill=%22%23666%22 text-anchor=%22middle%22>No Preview</text></svg>'">
          <span class="clip-seq">#<?= $seq ?></span>
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
            <?php if ($gameName): ?>
            <span class="clip-game" title="<?= htmlspecialchars($gameName) ?>"><?= htmlspecialchars($gameName) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php
        $baseParams = ['login' => $login];
        if ($query) $baseParams['q'] = $query;
        if ($gameId) $baseParams['game_id'] = $gameId;

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
