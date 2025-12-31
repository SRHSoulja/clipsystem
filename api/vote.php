<?php
/**
 * vote.php - Web voting API endpoint
 *
 * Allows authenticated users to vote on clips via the website.
 * Uses Twitch OAuth for authentication.
 * Uses existing vote_ledger and votes tables schema.
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

// Parse JSON body if content type is application/json
$jsonInput = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
  $rawInput = file_get_contents('php://input');
  $jsonInput = json_decode($rawInput, true) ?: [];
}

// Get parameters (from JSON body, POST, or GET)
$streamer = strtolower(trim($jsonInput['streamer'] ?? $_POST['streamer'] ?? $_GET['streamer'] ?? ''));
$seq = (int)($jsonInput['seq'] ?? $_POST['seq'] ?? $_GET['seq'] ?? 0);
$vote = strtolower(trim($jsonInput['vote'] ?? $_POST['vote'] ?? $_GET['vote'] ?? ''));

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

// Map vote types: like -> up, dislike -> down
$voteDir = null;
if ($vote === 'like') {
  $voteDir = 'up';
} elseif ($vote === 'dislike') {
  $voteDir = 'down';
} elseif ($vote !== 'clear') {
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
  $stmt = $pdo->prepare("SELECT clip_id, title FROM clips WHERE login = ? AND seq = ? AND blocked = FALSE");
  $stmt->execute([$streamer, $seq]);
  $clip = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$clip) {
    http_response_code(404);
    echo json_encode(['error' => 'Clip not found']);
    exit;
  }

  $clipId = $clip['clip_id'];
  $clipTitle = $clip['title'];
  $username = $user['login'];

  $response = ['success' => true];

  // Check for existing vote in ledger
  $stmt = $pdo->prepare("SELECT vote_dir FROM vote_ledger WHERE login = ? AND clip_id = ? AND username = ?");
  $stmt->execute([$streamer, $clipId, $username]);
  $existingVote = $stmt->fetchColumn();

  if ($vote === 'clear') {
    if ($existingVote) {
      // Remove from ledger
      $stmt = $pdo->prepare("DELETE FROM vote_ledger WHERE login = ? AND clip_id = ? AND username = ?");
      $stmt->execute([$streamer, $clipId, $username]);

      // Update aggregate counts
      if ($existingVote === 'up') {
        $pdo->prepare("UPDATE votes SET up_votes = GREATEST(0, up_votes - 1), updated_at = NOW() WHERE login = ? AND clip_id = ?")->execute([$streamer, $clipId]);
      } else {
        $pdo->prepare("UPDATE votes SET down_votes = GREATEST(0, down_votes - 1), updated_at = NOW() WHERE login = ? AND clip_id = ?")->execute([$streamer, $clipId]);
      }
    }
    $response['action'] = 'cleared';
    $response['message'] = "Vote cleared on #{$seq}";
  } else {
    // Map back for comparison
    $existingVoteType = $existingVote === 'up' ? 'like' : ($existingVote === 'down' ? 'dislike' : null);

    if ($existingVoteType === $vote) {
      // Same vote - inform user
      $response['action'] = 'unchanged';
      $response['message'] = "You already {$vote}d #{$seq}";
    } else {
      // Begin transaction for atomic update
      $pdo->beginTransaction();

      try {
        // If changing vote, adjust old count
        if ($existingVote) {
          if ($existingVote === 'up') {
            $pdo->prepare("UPDATE votes SET up_votes = GREATEST(0, up_votes - 1) WHERE login = ? AND clip_id = ?")->execute([$streamer, $clipId]);
          } else {
            $pdo->prepare("UPDATE votes SET down_votes = GREATEST(0, down_votes - 1) WHERE login = ? AND clip_id = ?")->execute([$streamer, $clipId]);
          }
        }

        // Upsert ledger entry
        $stmt = $pdo->prepare("
          INSERT INTO vote_ledger (login, clip_id, username, vote_dir, voted_at)
          VALUES (?, ?, ?, ?, NOW())
          ON CONFLICT (login, clip_id, username)
          DO UPDATE SET vote_dir = EXCLUDED.vote_dir, voted_at = NOW()
        ");
        $stmt->execute([$streamer, $clipId, $username, $voteDir]);

        // Ensure votes aggregate row exists and update
        $stmt = $pdo->prepare("
          INSERT INTO votes (login, clip_id, seq, title, up_votes, down_votes, created_at, updated_at)
          VALUES (?, ?, ?, ?, 0, 0, NOW(), NOW())
          ON CONFLICT (login, clip_id) DO NOTHING
        ");
        $stmt->execute([$streamer, $clipId, $seq, $clipTitle]);

        // Update the new vote count
        if ($voteDir === 'up') {
          $pdo->prepare("UPDATE votes SET up_votes = up_votes + 1, updated_at = NOW() WHERE login = ? AND clip_id = ?")->execute([$streamer, $clipId]);
        } else {
          $pdo->prepare("UPDATE votes SET down_votes = down_votes + 1, updated_at = NOW() WHERE login = ? AND clip_id = ?")->execute([$streamer, $clipId]);
        }

        $pdo->commit();

        $response['action'] = $existingVote ? 'changed' : 'recorded';
        $response['vote'] = $vote;
        $response['message'] = ucfirst($vote) . "d #{$seq}!";
      } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
      }
    }
  }

  // Get updated vote counts
  $stmt = $pdo->prepare("SELECT up_votes, down_votes FROM votes WHERE login = ? AND clip_id = ?");
  $stmt->execute([$streamer, $clipId]);
  $counts = $stmt->fetch(PDO::FETCH_ASSOC);

  // Get user's current vote
  $stmt = $pdo->prepare("SELECT vote_dir FROM vote_ledger WHERE login = ? AND clip_id = ? AND username = ?");
  $stmt->execute([$streamer, $clipId, $username]);
  $currentVote = $stmt->fetchColumn();

  // Add counts to response (map back to like/dislike terminology)
  $response['likes'] = (int)($counts['up_votes'] ?? 0);
  $response['dislikes'] = (int)($counts['down_votes'] ?? 0);
  $response['user_vote'] = $currentVote === 'up' ? 'like' : ($currentVote === 'down' ? 'dislike' : null);

  echo json_encode($response);

} catch (PDOException $e) {
  error_log("Vote API error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
