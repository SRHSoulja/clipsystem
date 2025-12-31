<?php
/**
 * vote.php - Web voting API endpoint
 *
 * Allows authenticated users to vote on clips via the website.
 * Uses Twitch OAuth for authentication.
 *
 * POST /api/vote.php
 * Parameters:
 *   - streamer: Channel/streamer name
 *   - seq: Clip sequence number
 *   - vote: 'like', 'dislike', or 'clear'
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  exit;
}

require_once __DIR__ . '/../includes/twitch_oauth.php';
require_once __DIR__ . '/../db_config.php';

// Check authentication
$user = getCurrentUser();
if (!$user) {
  http_response_code(401);
  echo json_encode(['error' => 'Not authenticated', 'login_url' => '/auth/login.php']);
  exit;
}

// Get parameters
$streamer = strtolower(trim($_POST['streamer'] ?? $_GET['streamer'] ?? ''));
$seq = (int)($_POST['seq'] ?? $_GET['seq'] ?? 0);
$vote = strtolower(trim($_POST['vote'] ?? $_GET['vote'] ?? ''));

// Validate
if (!$streamer) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing streamer parameter']);
  exit;
}

if ($seq <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid clip number']);
  exit;
}

if (!in_array($vote, ['like', 'dislike', 'clear'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid vote type. Use: like, dislike, or clear']);
  exit;
}

// Connect to database
$pdo = get_db_connection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(['error' => 'Database connection failed']);
  exit;
}

try {
  // Find the clip
  $stmt = $pdo->prepare("SELECT id, clip_id, title FROM clips WHERE login = ? AND seq = ? AND blocked = FALSE");
  $stmt->execute([$streamer, $seq]);
  $clip = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$clip) {
    http_response_code(404);
    echo json_encode(['error' => 'Clip not found']);
    exit;
  }

  $clipId = $clip['id'];
  $username = $user['login'];

  if ($vote === 'clear') {
    // Remove vote
    $stmt = $pdo->prepare("DELETE FROM votes WHERE clip_id = ? AND username = ?");
    $stmt->execute([$clipId, $username]);

    echo json_encode([
      'success' => true,
      'action' => 'cleared',
      'message' => "Vote cleared on #{$seq}"
    ]);
  } else {
    // Check for existing vote
    $stmt = $pdo->prepare("SELECT vote_type FROM votes WHERE clip_id = ? AND username = ?");
    $stmt->execute([$clipId, $username]);
    $existingVote = $stmt->fetchColumn();

    if ($existingVote === $vote) {
      // Same vote - inform user
      echo json_encode([
        'success' => true,
        'action' => 'unchanged',
        'message' => "You already {$vote}d #{$seq}"
      ]);
    } else {
      // Upsert vote
      $stmt = $pdo->prepare("
        INSERT INTO votes (clip_id, username, vote_type, voted_at)
        VALUES (?, ?, ?, NOW())
        ON CONFLICT (clip_id, username)
        DO UPDATE SET vote_type = EXCLUDED.vote_type, voted_at = NOW()
      ");
      $stmt->execute([$clipId, $username, $vote]);

      $action = $existingVote ? 'changed' : 'recorded';
      echo json_encode([
        'success' => true,
        'action' => $action,
        'vote' => $vote,
        'message' => ucfirst($vote) . "d #{$seq}!"
      ]);
    }
  }

  // Get updated vote counts
  $stmt = $pdo->prepare("
    SELECT
      SUM(CASE WHEN vote_type = 'like' THEN 1 ELSE 0 END) as likes,
      SUM(CASE WHEN vote_type = 'dislike' THEN 1 ELSE 0 END) as dislikes
    FROM votes
    WHERE clip_id = ?
  ");
  $stmt->execute([$clipId]);
  $counts = $stmt->fetch(PDO::FETCH_ASSOC);

  // Get user's current vote
  $stmt = $pdo->prepare("SELECT vote_type FROM votes WHERE clip_id = ? AND username = ?");
  $stmt->execute([$clipId, $username]);
  $userVote = $stmt->fetchColumn() ?: null;

  // Add counts to response
  $response = json_decode(ob_get_contents() ?: '{}', true);
  ob_clean();

  $response['counts'] = [
    'likes' => (int)($counts['likes'] ?? 0),
    'dislikes' => (int)($counts['dislikes'] ?? 0),
  ];
  $response['user_vote'] = $userVote;

  echo json_encode($response);

} catch (PDOException $e) {
  error_log("Vote API error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Database error']);
}
