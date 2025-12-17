<?php
// floppyjimmie_media.php

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
  foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    if (!str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v));
  }
}

// -------------------------
// CONFIG YOU MUST SET
// -------------------------
$TWITCH_CLIENT_ID = getenv('TWITCH_CLIENT_ID') ?: '';
$TWITCH_CLIENT_SECRET = getenv('TWITCH_CLIENT_SECRET') ?: '';
$PARENT_DOMAIN = 'GMGNREPEAT.COM'; // example: seindicate.com   (domain only, no https, no path)

// Twitch username to show media for
$BROADCASTER_LOGIN = 'floppyjimmie';

// How many items to show
$MAX_VODS = 12;
$MAX_CLIPS = 18;

// -------------------------
// BASIC VALIDATION
// -------------------------
if (!$TWITCH_CLIENT_ID || !$TWITCH_CLIENT_SECRET) {
  http_response_code(500);
  echo "Missing TWITCH_CLIENT_ID or TWITCH_CLIENT_SECRET in environment.";
  exit;
}
if (!$PARENT_DOMAIN || $PARENT_DOMAIN === 'YOURDOMAIN.COM') {
  http_response_code(500);
  echo "Set \$PARENT_DOMAIN to your site domain (domain only, no scheme, no path).";
  exit;
}

// -------------------------
// TWITCH HELPERS
// -------------------------
function twitch_post($url, $data) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($data),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 20,
  ]);
  $out = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) return [null, $code, $out];
  return [json_decode($out, true), $code, $out];
}

function twitch_get($url, $headers) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 20,
  ]);
  $out = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) return [null, $code, $out];
  return [json_decode($out, true), $code, $out];
}

// -------------------------
// GET APP ACCESS TOKEN
// -------------------------
list($tokenJson, $tokenCode, $tokenRaw) = twitch_post(
  'https://id.twitch.tv/oauth2/token',
  [
    'client_id' => $TWITCH_CLIENT_ID,
    'client_secret' => $TWITCH_CLIENT_SECRET,
    'grant_type' => 'client_credentials',
  ]
);

if (!$tokenJson || empty($tokenJson['access_token'])) {
  http_response_code(500);
  echo "Failed to get Twitch app token. HTTP $tokenCode\n$tokenRaw";
  exit;
}
$ACCESS_TOKEN = $tokenJson['access_token'];

$headers = [
  'Authorization: Bearer ' . $ACCESS_TOKEN,
  'Client-Id: ' . $TWITCH_CLIENT_ID,
];

// -------------------------
// LOOKUP USER ID
// -------------------------
$userUrl = 'https://api.twitch.tv/helix/users?login=' . urlencode($BROADCASTER_LOGIN);
list($userJson, $userCode, $userRaw) = twitch_get($userUrl, $headers);

if (!$userJson || empty($userJson['data'][0]['id'])) {
  http_response_code(500);
  echo "Failed to lookup user. HTTP $userCode\n$userRaw";
  exit;
}
$broadcasterId = $userJson['data'][0]['id'];

// -------------------------
// GET VODS
// -------------------------
$vodUrl = 'https://api.twitch.tv/helix/videos?user_id=' . urlencode($broadcasterId)
  . '&first=' . intval($MAX_VODS)
  . '&type=archive';

list($vodJson, $vodCode, $vodRaw) = twitch_get($vodUrl, $headers);
$vods = $vodJson && isset($vodJson['data']) ? $vodJson['data'] : [];

// -------------------------
// GET CLIPS (last 30 days)
 // You can adjust the time window. Clips endpoint needs started_at/ended_at for predictable results.
$endedAt = gmdate('c');
$startedAt = gmdate('c', time() - (30 * 24 * 60 * 60));

$clipsUrl = 'https://api.twitch.tv/helix/clips?broadcaster_id=' . urlencode($broadcasterId)
  . '&first=' . intval($MAX_CLIPS)
  . '&started_at=' . urlencode($startedAt)
  . '&ended_at=' . urlencode($endedAt);

list($clipsJson, $clipsCode, $clipsRaw) = twitch_get($clipsUrl, $headers);
$clips = $clipsJson && isset($clipsJson['data']) ? $clipsJson['data'] : [];

// -------------------------
// WHICH ITEM TO PLAY
// -------------------------
$mode = isset($_GET['mode']) ? $_GET['mode'] : '';
$id = isset($_GET['id']) ? $_GET['id'] : '';

$embedSrc = '';
$title = '';

if ($mode === 'vod' && $id) {
  $embedSrc = 'https://player.twitch.tv/?video=' . rawurlencode($id) . '&parent=' . rawurlencode($PARENT_DOMAIN);
  $title = 'VOD ' . htmlspecialchars($id, ENT_QUOTES);
}
if ($mode === 'clip' && $id) {
  // For clips, use the clip "id" value from Helix clips response
  $embedSrc = 'https://clips.twitch.tv/embed?clip=' . rawurlencode($id) . '&parent=' . rawurlencode($PARENT_DOMAIN);
  $title = 'Clip ' . htmlspecialchars($id, ENT_QUOTES);
}

// Default selection
if (!$embedSrc) {
  if (!empty($clips[0]['id'])) {
    $mode = 'clip';
    $id = $clips[0]['id'];
    $embedSrc = 'https://clips.twitch.tv/embed?clip=' . rawurlencode($id) . '&parent=' . rawurlencode($PARENT_DOMAIN);
    $title = 'Latest Clip';
  } elseif (!empty($vods[0]['id'])) {
    $mode = 'vod';
    $id = $vods[0]['id'];
    $embedSrc = 'https://player.twitch.tv/?video=' . rawurlencode($id) . '&parent=' . rawurlencode($PARENT_DOMAIN);
    $title = 'Latest VOD';
  } else {
    $title = 'No clips or VODs found';
  }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>FloppyJimmie Clips + VODs</title>
  <style>
    body { font-family: system-ui, Arial, sans-serif; margin: 0; background: #0b0b10; color: #e9e9ef; }
    .wrap { display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 16px; padding: 16px; }
    @media (max-width: 980px) { .wrap { grid-template-columns: 1fr; } }
    .player { background: #12121a; border: 1px solid #1f1f2b; border-radius: 12px; overflow: hidden; }
    .playerHeader { padding: 12px 14px; border-bottom: 1px solid #1f1f2b; display: flex; justify-content: space-between; align-items: center; }
    .playerFrame { width: 100%; aspect-ratio: 16 / 9; min-height: 300px; }
    iframe { width: 100%; height: 100%; border: 0; }
    .side { display: grid; gap: 16px; }
    .panel { background: #12121a; border: 1px solid #1f1f2b; border-radius: 12px; overflow: hidden; }
    .panel h2 { margin: 0; padding: 12px 14px; font-size: 14px; letter-spacing: 0.02em; border-bottom: 1px solid #1f1f2b; }
    .list { max-height: 420px; overflow: auto; }
    .item { display: block; padding: 10px 14px; text-decoration: none; color: inherit; border-bottom: 1px solid #1a1a24; }
    .item:hover { background: #171722; }
    .meta { font-size: 12px; opacity: 0.8; margin-top: 4px; }
    .pill { font-size: 12px; padding: 3px 8px; border: 1px solid #2a2a3a; border-radius: 999px; opacity: 0.9; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="player">
      <div class="playerHeader">
        <div><?php echo $title; ?></div>
        <div class="pill"><?php echo htmlspecialchars($BROADCASTER_LOGIN, ENT_QUOTES); ?></div>
      </div>
      <div class="playerFrame">
        <?php if ($embedSrc): ?>
          <iframe
            src="<?php echo htmlspecialchars($embedSrc, ENT_QUOTES); ?>"
            allowfullscreen="true"
            allow="autoplay; fullscreen">
          </iframe>
        <?php else: ?>
          <div style="padding:14px;">No media to embed.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="side">
      <div class="panel">
        <h2>Clips (last 30 days)</h2>
        <div class="list">
          <?php if (empty($clips)): ?>
            <div class="item">No clips found.</div>
          <?php else: ?>
            <?php foreach ($clips as $c): ?>
              <?php
                $clipId = $c['id'];
                $clipTitle = $c['title'] ?: 'Untitled clip';
                $creator = $c['creator_name'] ?? '';
                $views = $c['view_count'] ?? 0;
              ?>
              <a class="item" href="?mode=clip&id=<?php echo urlencode($clipId); ?>">
                <div><?php echo htmlspecialchars($clipTitle, ENT_QUOTES); ?></div>
                <div class="meta">by <?php echo htmlspecialchars($creator, ENT_QUOTES); ?> • <?php echo intval($views); ?> views</div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <h2>VODs (recent past broadcasts)</h2>
        <div class="list">
          <?php if (empty($vods)): ?>
            <div class="item">No VODs found.</div>
          <?php else: ?>
            <?php foreach ($vods as $v): ?>
              <?php
                $vodId = $v['id'];
                $vodTitle = $v['title'] ?: 'Untitled VOD';
                $created = $v['created_at'] ?? '';
                $duration = $v['duration'] ?? '';
              ?>
              <a class="item" href="?mode=vod&id=<?php echo urlencode($vodId); ?>">
                <div><?php echo htmlspecialchars($vodTitle, ENT_QUOTES); ?></div>
                <div class="meta"><?php echo htmlspecialchars($created, ENT_QUOTES); ?> • <?php echo htmlspecialchars($duration, ENT_QUOTES); ?></div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
