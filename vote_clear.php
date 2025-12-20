<?php
/**
 * vote_clear.php - Clear a user's vote on a clip
 *
 * Removes the user's vote from vote_ledger and decrements the count in votes table.
 * Returns empty response if user has no vote on the clip.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

function clean_login($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");
$user  = strtolower(trim($_GET["user"] ?? ""));
$seq   = (int)($_GET["seq"] ?? 0);

if ($login === "" || $user === "") {
  // Silent fail - no response
  exit;
}

$pdo = get_db_connection();
if (!$pdo) {
  exit;
}

// If no seq provided, get current playing clip
if ($seq <= 0) {
  $runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";
  $nowFile = $runtimeDir . "/now_playing_{$login}.json";
  if (file_exists($nowFile)) {
    $data = json_decode(@file_get_contents($nowFile), true);
    $seq = (int)($data["seq"] ?? 0);
  }
}

if ($seq <= 0) {
  // No clip specified and no current clip - silent fail
  exit;
}

try {
  // Look up the clip
  $stmt = $pdo->prepare("SELECT clip_id, title FROM clips WHERE login = ? AND seq = ?");
  $stmt->execute([$login, $seq]);
  $clip = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$clip) {
    // Clip not found - silent fail
    exit;
  }

  $clipId = $clip['clip_id'];
  $title = $clip['title'] ?: '(untitled)';

  // Check if user has a vote on this clip
  $stmt = $pdo->prepare("SELECT id, vote_dir FROM vote_ledger WHERE login = ? AND clip_id = ? AND username = ?");
  $stmt->execute([$login, $clipId, $user]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$existing) {
    // User has no vote - silent fail (no response)
    exit;
  }

  $voteDir = $existing['vote_dir'];

  // Remove the vote
  $pdo->beginTransaction();

  // Decrement the vote count
  if ($voteDir === 'up') {
    $stmt = $pdo->prepare("UPDATE votes SET up_votes = GREATEST(0, up_votes - 1), updated_at = CURRENT_TIMESTAMP WHERE login = ? AND clip_id = ?");
  } else {
    $stmt = $pdo->prepare("UPDATE votes SET down_votes = GREATEST(0, down_votes - 1), updated_at = CURRENT_TIMESTAMP WHERE login = ? AND clip_id = ?");
  }
  $stmt->execute([$login, $clipId]);

  // Remove from ledger
  $stmt = $pdo->prepare("DELETE FROM vote_ledger WHERE id = ?");
  $stmt->execute([$existing['id']]);

  $pdo->commit();

  // Get updated counts
  $stmt = $pdo->prepare("SELECT up_votes, down_votes FROM votes WHERE login = ? AND clip_id = ?");
  $stmt->execute([$login, $clipId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $up = $row ? (int)$row['up_votes'] : 0;
  $down = $row ? (int)$row['down_votes'] : 0;

  $voteType = $voteDir === 'up' ? 'like' : 'dislike';
  echo "Cleared {$voteType} on Clip #{$seq}. 👍{$up} 👎{$down}";

} catch (PDOException $e) {
  error_log("vote_clear error: " . $e->getMessage());
  // Silent fail
  exit;
}
