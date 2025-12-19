<?php
/**
 * clip_search.php - Display search results for clips
 *
 * Web page that shows all clips matching a search query.
 * Mods can click to play any clip from the results.
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

require_once __DIR__ . '/db_config.php';

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");
$query = trim((string)($_GET["q"] ?? ""));
$key   = (string)($_GET["key"] ?? "");
$page  = max(1, (int)($_GET["page"] ?? 1));
$perPage = 100;

// Load from environment
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

$isAuthorized = ($key === $ADMIN_KEY && $ADMIN_KEY !== '');

// Search for clips
$matches = [];
$totalCount = 0;
$totalPages = 0;

if (strlen($query) >= 2) {
  $pdo = get_db_connection();

  if ($pdo) {
    try {
      // Get total count first
      $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM clips
        WHERE login = ? AND blocked = FALSE AND title ILIKE ?
      ");
      $stmt->execute([$login, '%' . $query . '%']);
      $totalCount = (int)$stmt->fetchColumn();
      $totalPages = ceil($totalCount / $perPage);

      // Get paginated results
      $offset = ($page - 1) * $perPage;
      $stmt = $pdo->prepare("
        SELECT seq, clip_id, title, view_count, created_at, duration, game_id
        FROM clips
        WHERE login = ? AND blocked = FALSE AND title ILIKE ?
        ORDER BY view_count DESC
        LIMIT ? OFFSET ?
      ");
      $stmt->execute([$login, '%' . $query . '%', $perPage, $offset]);
      $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("clip_search db error: " . $e->getMessage());
    }
  }
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Clip Search: <?= htmlspecialchars($query) ?></title>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #0e0e10;
      color: #efeff1;
      margin: 0;
      padding: 20px;
    }
    .container {
      max-width: 1200px;
      margin: 0 auto;
    }
    h1 {
      color: #9147ff;
      margin-bottom: 5px;
    }
    .subtitle {
      color: #adadb8;
      margin-bottom: 20px;
    }
    .search-form {
      margin-bottom: 20px;
      display: flex;
      gap: 10px;
    }
    .search-form input[type="text"] {
      flex: 1;
      max-width: 400px;
      padding: 10px 15px;
      border: 1px solid #3d3d42;
      border-radius: 6px;
      background: #1f1f23;
      color: #efeff1;
      font-size: 14px;
    }
    .search-form input[type="text"]:focus {
      outline: none;
      border-color: #9147ff;
    }
    .search-form button {
      padding: 10px 20px;
      border: none;
      border-radius: 6px;
      background: #9147ff;
      color: white;
      font-weight: 600;
      cursor: pointer;
    }
    .search-form button:hover {
      background: #772ce8;
    }
    .results-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 15px;
    }
    .clip-card {
      background: #1f1f23;
      border-radius: 8px;
      overflow: hidden;
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .clip-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    }
    .clip-thumb {
      position: relative;
      padding-top: 56.25%;
      background: #26262c;
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
      background: rgba(0,0,0,0.8);
      color: #9147ff;
      padding: 2px 8px;
      border-radius: 4px;
      font-weight: bold;
      font-size: 12px;
    }
    .clip-duration {
      position: absolute;
      bottom: 8px;
      right: 8px;
      background: rgba(0,0,0,0.8);
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 12px;
    }
    .clip-info {
      padding: 12px;
    }
    .clip-title {
      font-weight: 600;
      margin-bottom: 8px;
      line-height: 1.3;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
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
    .clip-thumb {
      display: block;
      text-decoration: none;
      color: inherit;
    }
    .play-overlay {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      font-size: 48px;
      color: white;
      opacity: 0;
      transition: opacity 0.2s;
      text-shadow: 0 2px 8px rgba(0,0,0,0.8);
    }
    .clip-thumb:hover .play-overlay {
      opacity: 1;
    }
    .clip-actions {
      display: flex;
      gap: 8px;
    }
    .watch-btn {
      padding: 6px 12px;
      border: 1px solid #9147ff;
      border-radius: 4px;
      background: transparent;
      color: #9147ff;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.2s;
      text-decoration: none;
    }
    .watch-btn:hover {
      background: rgba(145, 71, 255, 0.1);
    }
    .queue-btn {
      padding: 6px 12px;
      border: none;
      border-radius: 4px;
      background: #9147ff;
      color: white;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.2s;
    }
    .queue-btn:hover {
      background: #772ce8;
    }
    .queue-btn:disabled {
      background: #3d3d42;
      cursor: not-allowed;
    }
    .no-results {
      text-align: center;
      padding: 60px 20px;
      color: #adadb8;
    }
    .no-results h2 {
      color: #efeff1;
    }
    .error-msg {
      background: #5c1616;
      border: 1px solid #9c2626;
      padding: 15px;
      border-radius: 6px;
      margin-bottom: 20px;
    }
    .info-msg {
      background: #1f1f23;
      border: 1px solid #3d3d42;
      padding: 15px;
      border-radius: 6px;
      margin-bottom: 20px;
      color: #adadb8;
    }
    .success-msg {
      position: fixed;
      bottom: 20px;
      right: 20px;
      background: #1a5c1a;
      border: 1px solid #2d8f2d;
      padding: 15px 20px;
      border-radius: 6px;
      animation: fadeIn 0.3s;
      z-index: 1000;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .pagination {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 10px;
      margin-top: 30px;
      padding: 20px 0;
    }
    .pagination a, .pagination span {
      padding: 10px 16px;
      border-radius: 6px;
      text-decoration: none;
      font-weight: 600;
      transition: background 0.2s;
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
  </style>
</head>
<body>
  <div class="container">
    <h1>Clip Search</h1>
    <p class="subtitle"><?= htmlspecialchars($login) ?> - <?= number_format($totalCount) ?> result<?= $totalCount !== 1 ? 's' : '' ?> for "<?= htmlspecialchars($query) ?>"<?php if ($totalPages > 1): ?> (Page <?= $page ?> of <?= $totalPages ?>)<?php endif; ?></p>

    <form class="search-form" method="get">
      <input type="hidden" name="login" value="<?= htmlspecialchars($login) ?>">
      <?php if ($isAuthorized): ?>
      <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
      <?php endif; ?>
      <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Search clips..." autofocus>
      <button type="submit">Search</button>
    </form>

    <?php if (!$isAuthorized): ?>
    <div class="info-msg">
      Click any clip to watch on Twitch. Mods can queue clips to the stream player.
    </div>
    <?php endif; ?>

    <?php if (empty($matches) && strlen($query) >= 2): ?>
    <div class="no-results">
      <h2>No clips found</h2>
      <p>Try a different search term</p>
    </div>
    <?php elseif (strlen($query) < 2): ?>
    <div class="no-results">
      <h2>Enter a search term</h2>
      <p>Search for clips by title (minimum 2 characters)</p>
    </div>
    <?php else: ?>
    <div class="results-grid">
      <?php foreach ($matches as $clip):
        $thumbUrl = "https://clips-media-assets2.twitch.tv/" . htmlspecialchars($clip['clip_id']) . "-preview-480x272.jpg";
        $twitchUrl = "https://clips.twitch.tv/" . htmlspecialchars($clip['clip_id']);
        $duration = isset($clip['duration']) ? gmdate("i:s", (int)$clip['duration']) : '';
      ?>
      <div class="clip-card">
        <a href="<?= $twitchUrl ?>" target="_blank" class="clip-thumb">
          <img src="<?= $thumbUrl ?>" alt="" loading="lazy" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 480 272%22><rect fill=%22%2326262c%22 width=%22480%22 height=%22272%22/><text x=%22240%22 y=%22140%22 fill=%22%23666%22 text-anchor=%22middle%22>No Preview</text></svg>'">
          <span class="clip-seq">#<?= (int)$clip['seq'] ?></span>
          <?php if ($duration): ?>
          <span class="clip-duration"><?= $duration ?></span>
          <?php endif; ?>
          <span class="play-overlay">&#9658;</span>
        </a>
        <div class="clip-info">
          <div class="clip-title"><?= htmlspecialchars($clip['title'] ?? '(no title)') ?></div>
          <div class="clip-meta">
            <span class="clip-views">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
              <?= number_format((int)($clip['view_count'] ?? 0)) ?>
            </span>
            <div class="clip-actions">
              <a href="<?= $twitchUrl ?>" target="_blank" class="watch-btn">Watch</a>
              <?php if ($isAuthorized): ?>
              <button class="queue-btn" onclick="queueClip(<?= (int)$clip['seq'] ?>, this)">Queue</button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php
        $baseParams = ['login' => $login, 'q' => $query];
        if ($isAuthorized) $baseParams['key'] = $key;

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
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>

  <div id="toast" class="success-msg" style="display: none;"></div>

  <?php if ($isAuthorized): ?>
  <script>
    const login = <?= json_encode($login) ?>;
    const key = <?= json_encode($key) ?>;
    const baseUrl = <?= json_encode($baseUrl) ?>;

    async function queueClip(seq, btn) {
      btn.disabled = true;
      btn.textContent = '...';

      try {
        const url = `${baseUrl}/pclip.php?login=${login}&key=${key}&seq=${seq}`;
        const res = await fetch(url);
        const text = await res.text();

        showToast(text);
      } catch (err) {
        showToast('Error: ' + err.message);
      } finally {
        btn.disabled = false;
        btn.textContent = 'Queue';
      }
    }

    function showToast(msg) {
      const toast = document.getElementById('toast');
      toast.textContent = msg;
      toast.style.display = 'block';
      setTimeout(() => { toast.style.display = 'none'; }, 3000);
    }
  </script>
  <?php endif; ?>
</body>
</html>
