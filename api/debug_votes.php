<?php
/**
 * debug_votes.php - Debug endpoint to check vote_ledger contents
 *
 * GET /api/debug_votes.php?streamer=xxx&seq=1
 * Shows all votes for a clip and the current logged-in user info.
 * DELETE THIS FILE after debugging is complete.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/twitch_oauth.php';
require_once __DIR__ . '/../db_config.php';

$streamer = strtolower(trim($_GET['streamer'] ?? ''));
$seq = (int)($_GET['seq'] ?? 0);

$pdo = get_db_connection();
if (!$pdo) {
  echo json_encode(['error' => 'Database error']);
  exit;
}

// Get current user
$user = getCurrentUser();

// If no seq, show summary of all votes for this streamer
if (!$streamer) {
  // Show all streamers with votes
  $stmt = $pdo->query("SELECT login, COUNT(*) as vote_count, SUM(up_votes) as total_up, SUM(down_votes) as total_down FROM votes GROUP BY login ORDER BY vote_count DESC LIMIT 20");
  $streamers = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'debug_info' => 'DELETE THIS ENDPOINT AFTER DEBUGGING',
    'current_user' => $user ? ['login' => $user['login'], 'display_name' => $user['display_name']] : null,
    'streamers_with_votes' => $streamers,
    'usage' => 'Add ?streamer=xxx to see votes for a channel, or ?streamer=xxx&seq=1 for a specific clip',
  ], JSON_PRETTY_PRINT);
  exit;
}

if ($seq <= 0) {
  // Show all votes for this streamer
  $stmt = $pdo->prepare("SELECT v.seq, v.clip_id, v.title, v.up_votes, v.down_votes,
    (SELECT COUNT(*) FROM vote_ledger vl WHERE vl.login = v.login AND vl.clip_id = v.clip_id) as ledger_count
    FROM votes v WHERE v.login = ? ORDER BY v.seq DESC LIMIT 50");
  $stmt->execute([$streamer]);
  $allVotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Also get recent ledger entries
  $stmt = $pdo->prepare("SELECT clip_id, username, vote_dir, voted_at FROM vote_ledger WHERE login = ? ORDER BY voted_at DESC LIMIT 20");
  $stmt->execute([$streamer]);
  $recentLedger = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'debug_info' => 'DELETE THIS ENDPOINT AFTER DEBUGGING',
    'streamer' => $streamer,
    'current_user' => $user ? ['login' => $user['login'], 'display_name' => $user['display_name']] : null,
    'clips_with_votes' => $allVotes,
    'recent_ledger_entries' => $recentLedger,
    'usage' => 'Add &seq=1 to see details for a specific clip',
  ], JSON_PRETTY_PRINT);
  exit;
}

// Get clip_id for this seq
$stmt = $pdo->prepare("SELECT clip_id, title FROM clips WHERE login = ? AND seq = ? AND blocked = FALSE");
$stmt->execute([$streamer, $seq]);
$clip = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$clip) {
  echo json_encode(['error' => 'Clip not found', 'streamer' => $streamer, 'seq' => $seq]);
  exit;
}

$clipId = $clip['clip_id'];

// Get all votes for this clip
$stmt = $pdo->prepare("SELECT username, vote_dir, voted_at FROM vote_ledger WHERE login = ? AND clip_id = ? ORDER BY voted_at DESC");
$stmt->execute([$streamer, $clipId]);
$votes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get aggregate counts
$stmt = $pdo->prepare("SELECT up_votes, down_votes FROM votes WHERE login = ? AND clip_id = ?");
$stmt->execute([$streamer, $clipId]);
$counts = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if current user's vote is in ledger
$userVoteInLedger = null;
if ($user) {
  foreach ($votes as $v) {
    if ($v['username'] === $user['login']) {
      $userVoteInLedger = $v;
      break;
    }
  }
}

echo json_encode([
  'debug_info' => 'DELETE THIS ENDPOINT AFTER DEBUGGING',
  'clip' => [
    'streamer' => $streamer,
    'seq' => $seq,
    'clip_id' => $clipId,
    'title' => $clip['title'],
  ],
  'current_user' => $user ? [
    'login' => $user['login'],
    'display_name' => $user['display_name'],
    'vote_found_in_ledger' => $userVoteInLedger !== null,
    'user_vote_details' => $userVoteInLedger,
  ] : null,
  'aggregate_counts' => $counts ?: ['up_votes' => 0, 'down_votes' => 0],
  'individual_votes' => $votes,
  'vote_count' => count($votes),
  'consistency_check' => [
    'ledger_count' => count($votes),
    'aggregate_up' => (int)($counts['up_votes'] ?? 0),
    'aggregate_down' => (int)($counts['down_votes'] ?? 0),
    'ledger_up_count' => count(array_filter($votes, fn($v) => $v['vote_dir'] === 'up')),
    'ledger_down_count' => count(array_filter($votes, fn($v) => $v['vote_dir'] === 'down')),
  ],
], JSON_PRETTY_PRINT);
