<?php
/**
 * votes.php - Get vote counts for clips
 *
 * GET /api/votes.php?streamer=xxx&seq=1,2,3
 * Returns vote counts and current user's vote for each clip.
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
  // Get clip IDs for the given seq numbers
  $placeholders = implode(',', array_fill(0, count($seqs), '?'));
  $stmt = $pdo->prepare("SELECT id, seq FROM clips WHERE login = ? AND seq IN ($placeholders) AND blocked = FALSE");
  $stmt->execute(array_merge([$streamer], $seqs));
  $clips = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // seq => id

  if (empty($clips)) {
    echo json_encode(['votes' => []]);
    exit;
  }

  $clipIds = array_values($clips);
  $placeholders = implode(',', array_fill(0, count($clipIds), '?'));

  // Get vote counts
  $stmt = $pdo->prepare("
    SELECT
      clip_id,
      SUM(CASE WHEN vote_type = 'like' THEN 1 ELSE 0 END) as likes,
      SUM(CASE WHEN vote_type = 'dislike' THEN 1 ELSE 0 END) as dislikes
    FROM votes
    WHERE clip_id IN ($placeholders)
    GROUP BY clip_id
  ");
  $stmt->execute($clipIds);
  $voteCounts = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $voteCounts[$row['clip_id']] = [
      'likes' => (int)$row['likes'],
      'dislikes' => (int)$row['dislikes'],
    ];
  }

  // Get user's votes (if logged in)
  $userVotes = [];
  if ($username) {
    $stmt = $pdo->prepare("SELECT clip_id, vote_type FROM votes WHERE clip_id IN ($placeholders) AND username = ?");
    $stmt->execute(array_merge($clipIds, [$username]));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $userVotes[$row['clip_id']] = $row['vote_type'];
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
