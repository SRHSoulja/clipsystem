<?php
/**
 * analytics.php - Simple analytics dashboard for ClipTV
 *
 * Shows event counts over the last 24h, 7d, and 30d.
 * Protected: only accessible to admin users.
 */
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/dashboard_auth.php';
require_once __DIR__ . '/includes/analytics.php';

track_event('page_load', ['page' => 'analytics']);

$counts_24h = get_event_counts(24);
$counts_7d = get_event_counts(168);
$counts_30d = get_event_counts(720);

header("Content-Type: text/html; charset=utf-8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Analytics - ClipTV</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #0a0a0f; color: #c8c8d0; font-family: 'Segoe UI', system-ui, sans-serif; padding: 20px; }
    h1 { color: #00ff88; font-size: 24px; margin-bottom: 20px; }
    h2 { color: #aaa; font-size: 14px; text-transform: uppercase; letter-spacing: 2px; margin: 20px 0 10px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
    .card { background: #12121a; border: 1px solid #222; border-radius: 6px; padding: 16px; }
    .card .label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 1px; }
    .card .value { font-size: 28px; font-weight: 700; color: #00ff88; margin-top: 4px; }
    .card .value.zero { color: #444; }
    .back { display: inline-block; margin-bottom: 16px; color: #00ff88; text-decoration: none; font-size: 13px; }
    .back:hover { text-decoration: underline; }
    .section { margin-bottom: 30px; }
  </style>
</head>
<body>
  <a class="back" href="/admin.php">&larr; Back to Admin</a>
  <h1>ClipTV Analytics</h1>

  <?php
  $periods = [
    '24h' => $counts_24h,
    '7 days' => $counts_7d,
    '30 days' => $counts_30d,
  ];

  $event_labels = [
    'page_load' => 'Page Loads',
    'clip_play' => 'Clip Plays',
    'dashboard_visit' => 'Dashboard Visits',
    'discord_launch' => 'Discord Launches',
    'archive_browse' => 'Archive Browsing',
  ];

  foreach ($periods as $label => $counts): ?>
    <div class="section">
      <h2>Last <?= htmlspecialchars($label) ?></h2>
      <div class="grid">
        <?php foreach ($event_labels as $key => $name):
          $count = $counts[$key] ?? 0;
        ?>
          <div class="card">
            <div class="label"><?= htmlspecialchars($name) ?></div>
            <div class="value<?= $count === 0 ? ' zero' : '' ?>"><?= number_format($count) ?></div>
          </div>
        <?php endforeach; ?>
        <?php
        // Show any unlabeled event types
        foreach ($counts as $key => $count):
          if (!isset($event_labels[$key])):
        ?>
          <div class="card">
            <div class="label"><?= htmlspecialchars($key) ?></div>
            <div class="value"><?= number_format($count) ?></div>
          </div>
        <?php endif; endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</body>
</html>
