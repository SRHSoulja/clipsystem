<?php
// Launch bot if not already running
@include_once __DIR__ . '/bot_launcher.php';
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

// Get current user
$currentUser = getCurrentUser();

// Check if this is an API health check request
if (isset($_GET['health']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
  header("Content-Type: application/json");
  header("Access-Control-Allow-Origin: *");
  echo json_encode(["status" => "ok", "service" => "clipsystem"]);
  exit;
}

// Get database connection
$pdo = get_db_connection();

// Get list of archived streamers with profile images
$archivedStreamers = [];
$totalClips = 0;
$totalStreamers = 0;
if ($pdo) {
  try {
    $stmt = $pdo->query("
      SELECT c.login, COUNT(*) as clip_count, cs.profile_image_url
      FROM clips c
      LEFT JOIN channel_settings cs ON cs.login = c.login
      WHERE c.blocked = FALSE
      GROUP BY c.login, cs.profile_image_url
      ORDER BY clip_count DESC
      LIMIT 20
    ");
    $archivedStreamers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT COUNT(DISTINCT login) as streamers, COUNT(*) as clips FROM clips WHERE blocked = FALSE");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalStreamers = (int)($stats['streamers'] ?? 0);
    $totalClips = (int)($stats['clips'] ?? 0);
  } catch (PDOException $e) {
    // Ignore
  }
}

// Otherwise show landing page
header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <title>ClipArchive - Your stream's greatest hits, on demand</title>
  <meta name="description" content="Give your viewers a way to browse, share, and relive your best Twitch clips. BRB overlay, ClipTV, chat bot, and more.">
  <meta property="og:title" content="ClipArchive">
  <meta property="og:description" content="Your stream's greatest hits, on demand. Browse, share, vote, and replay Twitch clips.">
  <meta property="og:image" content="https://clips.gmgnrepeat.com/favicon.svg">
  <meta name="theme-color" content="#9147ff">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #0e0e10;
      color: #efeff1;
      min-height: 100vh;
      overflow-x: hidden;
    }

    /* ── Hero ── */
    .hero {
      position: relative;
      min-height: calc(100vh - 56px);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 60px 20px 40px;
      text-align: center;
      overflow: hidden;
    }

    .hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background:
        radial-gradient(ellipse 80% 60% at 50% 0%, rgba(145,71,255,0.15) 0%, transparent 70%),
        radial-gradient(ellipse 60% 40% at 20% 80%, rgba(145,71,255,0.08) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 80% 80%, rgba(145,71,255,0.08) 0%, transparent 60%);
      pointer-events: none;
    }

    .hero-inner {
      position: relative;
      z-index: 1;
      max-width: 720px;
    }

    .hero h1 {
      font-size: 56px;
      font-weight: 800;
      letter-spacing: -1px;
      line-height: 1.1;
      margin-bottom: 16px;
      background: linear-gradient(135deg, #bf94ff 0%, #9147ff 50%, #bf94ff 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .hero .tagline {
      font-size: 22px;
      color: #adadb8;
      margin-bottom: 40px;
      line-height: 1.5;
    }

    .hero .tagline strong {
      color: #efeff1;
      font-weight: 600;
    }

    /* ── Search ── */
    .search-box {
      display: flex;
      gap: 10px;
      max-width: 540px;
      width: 100%;
      margin: 0 auto 24px;
    }

    .search-box input {
      flex: 1;
      padding: 16px 20px;
      border: 2px solid #3a3a3d;
      border-radius: 12px;
      background: rgba(14,14,16,0.8);
      color: #efeff1;
      font-size: 16px;
      transition: border-color 0.2s, box-shadow 0.2s;
      backdrop-filter: blur(8px);
    }

    .search-box input:focus {
      outline: none;
      border-color: #9147ff;
      box-shadow: 0 0 0 3px rgba(145,71,255,0.2);
    }

    .search-box input::placeholder { color: #53535f; }

    .search-box button {
      padding: 16px 28px;
      border: none;
      border-radius: 12px;
      background: #9147ff;
      color: white;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s;
      white-space: nowrap;
    }

    .search-box button:hover {
      background: #772ce8;
      transform: translateY(-1px);
      box-shadow: 0 4px 16px rgba(145,71,255,0.3);
    }

    .archive-btn {
      background: transparent !important;
      border: 2px solid rgba(145,71,255,0.5) !important;
      color: #bf94ff !important;
    }

    .archive-btn:hover {
      background: rgba(145,71,255,0.15) !important;
      border-color: #9147ff !important;
      color: #fff !important;
    }

    .hero-hint {
      font-size: 14px;
      color: #53535f;
      margin-bottom: 48px;
    }

    .hero-hint a {
      color: #9147ff;
      text-decoration: none;
    }

    .hero-hint a:hover { text-decoration: underline; }

    /* ── Stats Bar ── */
    .stats-bar {
      display: flex;
      gap: 48px;
      justify-content: center;
      margin-bottom: 48px;
    }

    .stat {
      text-align: center;
    }

    .stat .num {
      font-size: 32px;
      font-weight: 700;
      color: #bf94ff;
      line-height: 1;
    }

    .stat .lbl {
      font-size: 13px;
      color: #53535f;
      margin-top: 4px;
    }

    /* ── Features ── */
    .features-section {
      padding: 0 20px 80px;
      max-width: 960px;
      margin: 0 auto;
    }

    .features-section h2 {
      text-align: center;
      font-size: 14px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 2px;
      color: #9147ff;
      margin-bottom: 40px;
    }

    .features-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 20px;
    }

    .feature-card {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      padding: 24px;
      background: rgba(145,71,255,0.06);
      border: 1px solid rgba(145,71,255,0.15);
      border-radius: 16px;
      transition: all 0.25s ease;
    }

    .feature-card:hover {
      background: rgba(145,71,255,0.12);
      border-color: rgba(145,71,255,0.35);
      transform: translateY(-2px);
    }

    .feature-icon {
      font-size: 32px;
      flex-shrink: 0;
      width: 48px;
      height: 48px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(145,71,255,0.12);
      border-radius: 12px;
    }

    .feature-card h3 {
      font-size: 16px;
      font-weight: 600;
      margin-bottom: 4px;
    }

    .feature-card p {
      font-size: 14px;
      color: #adadb8;
      line-height: 1.5;
    }

    /* ── Streamers ── */
    .streamers-section {
      padding: 0 20px 80px;
      max-width: 960px;
      margin: 0 auto;
    }

    .streamers-section h2 {
      text-align: center;
      font-size: 14px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 2px;
      color: #9147ff;
      margin-bottom: 32px;
    }

    .streamer-grid {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 12px;
    }

    .streamer-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px 8px 8px;
      background: rgba(145,71,255,0.08);
      border: 1px solid rgba(145,71,255,0.2);
      border-radius: 100px;
      color: #efeff1;
      text-decoration: none;
      font-size: 14px;
      font-weight: 500;
      transition: all 0.2s;
    }

    .streamer-chip:hover {
      background: rgba(145,71,255,0.2);
      border-color: rgba(145,71,255,0.5);
      transform: translateY(-1px);
    }

    .streamer-chip img {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      object-fit: cover;
      background: #26262c;
    }

    .streamer-chip .avatar-placeholder {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: linear-gradient(135deg, #9147ff, #bf94ff);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 700;
      color: white;
    }

    .streamer-chip .clip-count {
      font-size: 11px;
      color: #adadb8;
      font-weight: 400;
    }

    /* ── Footer links ── */
    .footer-links {
      display: flex;
      gap: 24px;
      justify-content: center;
      padding: 0 20px 40px;
    }

    .footer-links a {
      color: #53535f;
      text-decoration: none;
      font-size: 13px;
      transition: color 0.2s;
    }

    .footer-links a:hover { color: #bf94ff; }

    footer {
      text-align: center;
      padding: 0 20px 40px;
      color: #3a3a3d;
      font-size: 13px;
    }

    footer a {
      color: #53535f;
      text-decoration: none;
    }

    footer a:hover { color: #9147ff; }

    /* ── Responsive ── */
    @media (max-width: 640px) {
      .hero h1 { font-size: 36px; }
      .hero .tagline { font-size: 18px; }
      .search-box { flex-direction: column; }
      .search-box button { width: 100%; }
      .features-grid { grid-template-columns: 1fr; }
      .stats-bar { gap: 32px; }
      .stat .num { font-size: 24px; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/includes/nav.php'; ?>

  <!-- Hero -->
  <div class="hero">
    <div class="hero-inner">
      <h1>Your stream's greatest hits, on demand</h1>
      <p class="tagline">
        Let your viewers <strong>browse</strong>, <strong>share</strong>, and <strong>relive</strong> your best clips.
        BRB overlays, chat bot playback, voting, playlists. All built in.
      </p>

      <form class="search-box" onsubmit="goToSearch(event)">
        <input type="text" id="streamer" placeholder="Enter a streamer name..." autofocus>
        <button type="submit">Browse Clips</button>
        <button type="button" onclick="goToArchive()" class="archive-btn">Archive</button>
      </form>

      <p class="hero-hint">
        New here? <a href="/about.php">See how it works</a> &middot; <a href="/chelp.php">Bot commands</a>
      </p>

      <?php if ($totalStreamers > 0): ?>
      <div class="stats-bar">
        <div class="stat">
          <div class="num"><?= number_format($totalStreamers) ?></div>
          <div class="lbl">Communities</div>
        </div>
        <div class="stat">
          <div class="num"><?= number_format($totalClips) ?></div>
          <div class="lbl">Clips Indexed</div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Features -->
  <div class="features-section">
    <h2>What you get</h2>
    <div class="features-grid">
      <div class="feature-card">
        <div class="feature-icon">🔍</div>
        <div>
          <h3>Browse &amp; Search</h3>
          <p>Your full clip library, searchable by game, title, date, or clipper. Viewers share their favorites.</p>
        </div>
      </div>
      <div class="feature-card">
        <div class="feature-icon">📺</div>
        <div>
          <h3>ClipTV &amp; BRB Overlay</h3>
          <p>Turn dead air into a highlight reel. Auto-play clips as a BRB screen or let viewers binge on their own.</p>
        </div>
      </div>
      <div class="feature-card">
        <div class="feature-icon">🤖</div>
        <div>
          <h3>Chat Bot</h3>
          <p>Viewers type <code style="background:rgba(145,71,255,0.2);padding:2px 6px;border-radius:4px;font-size:13px;">!clip</code> and the community decides what plays next. Requests, voting, playlists.</p>
        </div>
      </div>
      <div class="feature-card">
        <div class="feature-icon">📊</div>
        <div>
          <h3>Community Voting</h3>
          <p>Your viewers pick the best clips. Favorites rise to the top. Engagement that runs itself.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Streamers -->
  <?php if (!empty($archivedStreamers)): ?>
  <div class="streamers-section">
    <h2>Communities using ClipArchive</h2>
    <div class="streamer-grid">
      <?php foreach ($archivedStreamers as $s): ?>
      <a href="/search/<?= htmlspecialchars(urlencode($s['login'])) ?>" class="streamer-chip">
        <?php if (!empty($s['profile_image_url'])): ?>
          <img src="<?= htmlspecialchars($s['profile_image_url']) ?>" alt="" loading="lazy">
        <?php else: ?>
          <span class="avatar-placeholder"><?= strtoupper(substr($s['login'], 0, 1)) ?></span>
        <?php endif; ?>
        <?= htmlspecialchars($s['login']) ?>
        <span class="clip-count"><?= number_format($s['clip_count']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="footer-links">
    <a href="/chelp.php">Bot Commands</a>
    <a href="/about.php">About</a>
    <a href="/archive">Archive a Channel</a>
  </div>

  <footer>
    Powered by <a href="https://gmgnrepeat.com">GMGN Repeat</a>
  </footer>

  <script>
    function goToSearch(e) {
      e.preventDefault();
      const streamer = document.getElementById('streamer').value.trim().toLowerCase();
      if (streamer) {
        window.location.href = '/search/' + encodeURIComponent(streamer);
      }
    }

    function goToArchive() {
      const streamer = document.getElementById('streamer').value.trim().toLowerCase();
      if (streamer) {
        window.location.href = '/archive?login=' + encodeURIComponent(streamer);
      } else {
        window.location.href = '/archive';
      }
    }
  </script>
</body>
</html>
