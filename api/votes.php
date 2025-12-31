<?php
/**
 * votes.php - Get vote counts for clips
 *
 * GET /api/votes.php?streamer=xxx&seq=1,2,3
 * Returns vote counts and current user's vote for each clip.
 * Uses existing votes and vote_ledger tables schema.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/twitch_oauth.php';
require_once __DIR__ . '/../db_config.php';

$streamer = strtolower(trim($_GET['streamer'] ?? ''));
$seqList = $_GET['seq'] ?? '';

if (!$streamer) {
  echo json_encode(['error' => 'Missing streamer parameter']);
  exit;
}

// Parse seq list (comma-separated)
$seqs = array_filter(array_map('intval', explode(',', $seqList)));
if (empty($seqs)) {
  echo json_encode(['error' => 'No valid clip numbers']);
  exit;
}

$pdo = get_db_connection();
if (!$pdo) {
  echo json_encode(['error' => 'Database error']);
  exit;
}

// Get current user (if logged in)
$user = getCurrentUser();
$username = $user ? $user['login'] : null;

try {
  // Get clip_ids for the given seq numbers
  $placeholders = implode(',', array_fill(0, count($seqs), '?'));
  $stmt = $pdo->prepare("SELECT seq, clip_id FROM clips WHERE login = ? AND seq IN ($placeholders) AND blocked = FALSE");
  $stmt->execute(array_merge([$streamer], $seqs));
  $clips = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // seq => clip_id

  if (empty($clips)) {
    echo json_encode(['votes' => []]);
    exit;
  }

  $clipIds = array_values($clips);
  $clipPlaceholders = implode(',', array_fill(0, count($clipIds), '?'));

  // Get vote counts from the votes table (uses up_votes/down_votes columns)
  $stmt = $pdo->prepare("
    SELECT clip_id, up_votes, down_votes
    FROM votes
    WHERE login = ? AND clip_id IN ($clipPlaceholders)
  ");
  $stmt->execute(array_merge([$streamer], $clipIds));
  $voteCounts = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $voteCounts[$row['clip_id']] = [
      'likes' => (int)$row['up_votes'],
      'dislikes' => (int)$row['down_votes'],
    ];
  }

  // Get user's votes from vote_ledger (if logged in)
  $userVotes = [];
  if ($username) {
    // Debug: log what we're looking for
    error_log("votes.php: Looking for user votes - login=$streamer, username=$username, clipIds=" . implode(',', $clipIds));

    // Debug: see what's actually in the ledger for these clips
    $debugStmt = $pdo->prepare("SELECT clip_id, username, vote_dir FROM vote_ledger WHERE login = ? AND clip_id IN ($clipPlaceholders)");
    $debugStmt->execute(array_merge([$streamer], $clipIds));
    $allVotes = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("votes.php: All votes in ledger for these clips: " . json_encode($allVotes));

    $stmt = $pdo->prepare("SELECT clip_id, vote_dir FROM vote_ledger WHERE login = ? AND clip_id IN ($clipPlaceholders) AND username = ?");
    $stmt->execute(array_merge([$streamer], $clipIds, [$username]));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      // Map up/down to like/dislike
      $userVotes[$row['clip_id']] = $row['vote_dir'] === 'up' ? 'like' : 'dislike';
      error_log("votes.php: Found vote for current user - clip_id={$row['clip_id']}, vote_dir={$row['vote_dir']}");
    }

    if (empty($userVotes)) {
      error_log("votes.php: No votes found for user $username (looking for exact match)");
    }
  }

  // Build response keyed by seq
  $response = [];
  foreach ($clips as $seq => $clipId) {
    $response[$seq] = [
      'likes' => $voteCounts[$clipId]['likes'] ?? 0,
      'dislikes' => $voteCounts[$clipId]['dislikes'] ?? 0,
      'user_vote' => $userVotes[$clipId] ?? null,
    ];
  }

  echo json_encode([
    'votes' => $response,
    'logged_in' => $user !== null,
    'username' => $username,
  ]);

} catch (PDOException $e) {
  error_log("Votes API error: " . $e->getMessage());
  echo json_encode(['error' => 'Database error']);
}
