<?php
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

$currentUser = getCurrentUser();
$login = strtolower(trim(preg_replace('/[^a-z0-9_]/', '', $_GET['login'] ?? '')));

$pdo = get_db_connection();
$clipCount = 0;
$profileImage = '';
$totalStreamers = 0;
$totalClips = 0;
$isArchived = false;

if ($pdo) {
  try {
    // Platform stats
    $stmt = $pdo->query("SELECT COUNT(DISTINCT login) as streamers, COUNT(*) as clips FROM clips WHERE blocked = FALSE");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalStreamers = (int)($stats['streamers'] ?? 0);
    $totalClips = (int)($stats['clips'] ?? 0);

    // Streamer-specific stats
    if ($login) {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ? AND blocked = FALSE");
      $stmt->execute([$login]);
      $clipCount = (int)$stmt->fetchColumn();
      $isArchived = $clipCount > 0;

      $stmt = $pdo->prepare("SELECT profile_image_url FROM channel_settings WHERE login = ?");
      $stmt->execute([$login]);
      $profileImage = $stmt->fetchColumn() ?: '';
    }
  } catch (PDOException $e) { /* ignore */ }
}

// Determine CTA
if ($login && $isArchived) {
  $ctaText = 'Claim Your Dashboard';
  $ctaReturn = '/dashboard/' . urlencode($login);
} elseif ($login) {
  $ctaText = 'Archive My Channel';
  $ctaReturn = '/archive?login=' . urlencode($login);
} else {
  $ctaText = 'Get Started';
  $ctaReturn = '/channels';
}
$ctaUrl = '/auth/login.php?return=' . urlencode($ctaReturn);

// Hero text
if ($login && $isArchived) {
  $heroTitle = htmlspecialchars($login);
  $heroSub = 'You have ' . number_format($clipCount) . ' clips archived and ready to go';
} elseif ($login) {
  $heroTitle = htmlspecialchars($login);
  $heroSub = "Let's save your clips before Twitch deletes them";
} else {
  $heroTitle = 'Your clips deserve a permanent home';
  $heroSub = 'Archive, search, and replay every Twitch clip — forever';
}

// OG meta
$ogTitle = $login ? "ClipArchive — " . htmlspecialchars($login) : "ClipArchive — Twitch Clip Archive";
$ogDesc = $login && $isArchived
  ? number_format($clipCount) . " clips archived for " . htmlspecialchars($login) . ". Search, play, and manage your clips."
  : "Every Twitch clip, permanently archived. Search, play, and manage your clips.";
$ogImage = $profileImage ?: 'https://clips.gmgnrepeat.com/favicon.svg';
$ogUrl = 'https://clips.gmgnrepeat.com/invite' . ($login ? '/' . urlencode($login) : '');

header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <title><?= $ogTitle ?></title>

  <!-- Open Graph -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= $ogTitle ?>">
  <meta property="og:description" content="<?= $ogDesc ?>">
  <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
  <meta property="og:url" content="<?= htmlspecialchars($ogUrl) ?>">
  <meta name="theme-color" content="#9147ff">

  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="<?= $ogTitle ?>">
  <meta name="twitter:description" content="<?= $ogDesc ?>">
  <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">

  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: linear-gradient(135deg, #0e0e10 0%, #18181b 50%, #1f1f23 100%);
      color: #efeff1;
      min-height: 100vh;
    }

    .page-content {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 60px 20px 40px;
      min-height: calc(100vh - 56px);
    }

    .invite-container {
      max-width: 580px;
      width: 100%;
      text-align: center;
    }

    /* ── Hero ── */
    .hero {
      margin-bottom: 48px;
    }

    .hero-avatar {
      width: 96px;
      height: 96px;
      border-radius: 50%;
      border: 3px solid #9147ff;
      margin-bottom: 20px;
      object-fit: cover;
    }

    .hero-icon {
      font-size: 72px;
      margin-bottom: 20px;
    }

    .hero h1 {
      font-size: 40px;
      font-weight: 700;
      margin-bottom: 12px;
      background: linear-gradient(90deg, #9147ff, #bf94ff);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .hero p {
      font-size: 18px;
      color: #adadb8;
      line-height: 1.5;
    }

    /* ── Features ── */
    .features {
      display: flex;
      flex-direction: column;
      gap: 16px;
      margin-bottom: 40px;
      text-align: left;
    }

    .feature-card {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      background: rgba(145, 71, 255, 0.08);
      border: 1px solid rgba(145, 71, 255, 0.2);
      border-radius: 12px;
      padding: 20px;
      transition: all 0.2s;
    }

    .feature-card:hover {
      background: rgba(145, 71, 255, 0.14);
      border-color: rgba(145, 71, 255, 0.4);
    }

    .feature-emoji {
      font-size: 28px;
      flex-shrink: 0;
      margin-top: 2px;
    }

    .feature-text h3 {
      font-size: 16px;
      font-weight: 600;
      margin-bottom: 4px;
    }

    .feature-text p {
      font-size: 14px;
      color: #adadb8;
      line-height: 1.4;
    }

    .feature-text a {
      color: #bf94ff;
      text-decoration: none;
    }

    .feature-text a:hover {
      text-decoration: underline;
    }

    /* ── Social proof ── */
    .social-proof {
      display: flex;
      justify-content: center;
      gap: 40px;
      margin-bottom: 40px;
    }

    .proof-stat {
      text-align: center;
    }

    .proof-stat .number {
      font-size: 28px;
      font-weight: 700;
      color: #bf94ff;
    }

    .proof-stat .label {
      font-size: 13px;
      color: #53535f;
      margin-top: 2px;
    }

    /* ── CTA ── */
    .cta-section {
      margin-bottom: 32px;
    }

    .cta-btn {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 16px 40px;
      background: #9147ff;
      color: white;
      text-decoration: none;
      border-radius: 10px;
      font-size: 18px;
      font-weight: 600;
      transition: all 0.2s;
    }

    .cta-btn:hover {
      background: #772ce8;
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(145, 71, 255, 0.3);
    }

    .cta-btn svg {
      width: 20px;
      height: 20px;
    }

    .cta-hint {
      margin-top: 12px;
      font-size: 13px;
      color: #53535f;
    }

    /* ── Footer ── */
    .invite-footer {
      margin-top: 48px;
      color: #53535f;
      font-size: 13px;
    }

    .invite-footer a {
      color: #9147ff;
      text-decoration: none;
    }

    @media (max-width: 480px) {
      .hero h1 { font-size: 28px; }
      .hero p { font-size: 16px; }
      .social-proof { gap: 24px; }
      .proof-stat .number { font-size: 22px; }
      .cta-btn { padding: 14px 32px; font-size: 16px; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/includes/nav.php'; ?>

  <div class="page-content">
  <div class="invite-container">

    <!-- Hero -->
    <div class="hero">
      <?php if ($profileImage): ?>
        <img src="<?= htmlspecialchars($profileImage) ?>" alt="" class="hero-avatar">
      <?php elseif (!$login): ?>
        <div class="hero-icon">📺</div>
      <?php endif; ?>

      <h1><?= $heroTitle ?></h1>
      <p><?= $heroSub ?></p>
    </div>

    <!-- Features -->
    <div class="features">
      <div class="feature-card">
        <div class="feature-emoji">🗄️</div>
        <div class="feature-text">
          <h3>Permanent Archive</h3>
          <p>Every clip saved forever — searchable by title, game, clipper, or date. Never lose a clip to Twitch again.</p>
        </div>
      </div>

      <div class="feature-card">
        <div class="feature-emoji">📺</div>
        <div class="feature-text">
          <h3>ClipTV</h3>
          <p>Auto-playing channel reel your viewers can binge anytime.<?php if ($isArchived): ?> <a href="/tv/<?= htmlspecialchars($login) ?>">Watch now</a><?php endif; ?></p>
        </div>
      </div>

      <div class="feature-card">
        <div class="feature-emoji">🤖</div>
        <div class="feature-text">
          <h3>Chat Bot</h3>
          <p>Viewers use <code style="background:rgba(145,71,255,0.2);padding:2px 6px;border-radius:4px;">!clip</code> to play clips on stream. Voting, playlists, and more — all from chat.</p>
        </div>
      </div>
    </div>

    <!-- Social proof -->
    <?php if ($totalStreamers > 0): ?>
    <div class="social-proof">
      <div class="proof-stat">
        <div class="number"><?= number_format($totalStreamers) ?></div>
        <div class="label">Streamers Archived</div>
      </div>
      <div class="proof-stat">
        <div class="number"><?= number_format($totalClips) ?></div>
        <div class="label">Clips Saved</div>
      </div>
      <?php if ($isArchived): ?>
      <div class="proof-stat">
        <div class="number"><?= number_format($clipCount) ?></div>
        <div class="label">Your Clips</div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- CTA -->
    <div class="cta-section">
      <a href="<?= htmlspecialchars($ctaUrl) ?>" class="cta-btn">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714Z"/></svg>
        <?= $ctaText ?>
      </a>
      <p class="cta-hint">Sign in with Twitch — takes 5 seconds</p>
    </div>

    <div class="invite-footer">
      Powered by <a href="https://gmgnrepeat.com">GMGN Repeat</a>
    </div>

  </div>
  </div>
</body>
</html>
