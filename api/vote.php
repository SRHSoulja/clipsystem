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
 *
 * Anti-bot measures:
 *   - Rate limiting: Max 30 votes per 5 minutes per user
 *   - Suspicious activity tracking: Flags accounts with unusual patterns
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

// Rate limiting constants
const RATE_LIMIT_VOTES = 30;        // Max votes per window
const RATE_LIMIT_WINDOW = 300;      // Window in seconds (5 minutes)
const SUSPICIOUS_DOWNVOTE_RATIO = 0.9;  // Flag if >90% of votes are downvotes
const SUSPICIOUS_VOTES_PER_HOUR = 50;   // Flag if >50 votes in an hour
const NEW_ACCOUNT_VOTE_LIMIT = 10;      // Stricter limit for accounts voting in first hour

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

$username = $user['login'];
$userId = $user['id'] ?? null;

// ============================================
// ANTI-BOT: Rate limiting check (atomic upsert to prevent race conditions)
// ============================================
try {
  // Ensure rate limit table exists
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS vote_rate_limits (
      username VARCHAR(64) PRIMARY KEY,
      vote_count INTEGER DEFAULT 0,
      window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
  ");

  // Atomic upsert with rate limit check
  // This query atomically:
  // 1. Inserts new row if user doesn't exist
  // 2. Resets window if expired
  // 3. Increments counter if within window
  // 4. Returns the resulting state for checking
  $stmt = $pdo->prepare("
    INSERT INTO vote_rate_limits (username, vote_count, window_start)
    VALUES (?, 1, NOW())
    ON CONFLICT (username) DO UPDATE SET
      vote_count = CASE
        WHEN EXTRACT(EPOCH FROM (NOW() - vote_rate_limits.window_start)) >= ?
        THEN 1
        ELSE vote_rate_limits.vote_count + 1
      END,
      window_start = CASE
        WHEN EXTRACT(EPOCH FROM (NOW() - vote_rate_limits.window_start)) >= ?
        THEN NOW()
        ELSE vote_rate_limits.window_start
      END
    RETURNING vote_count, EXTRACT(EPOCH FROM (NOW() - window_start)) as window_age
  ");
  $stmt->execute([$username, RATE_LIMIT_WINDOW, RATE_LIMIT_WINDOW]);
  $rateLimit = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($rateLimit) {
    $voteCount = (int)$rateLimit['vote_count'];
    $windowAge = (float)$rateLimit['window_age'];

    // Check if over limit (vote_count already incremented, so check against limit)
    if ($voteCount > RATE_LIMIT_VOTES) {
      $remaining = ceil(RATE_LIMIT_WINDOW - $windowAge);
      http_response_code(429);
      echo json_encode([
        'error' => 'Rate limit exceeded',
        'message' => "Too many votes. Please wait {$remaining} seconds.",
        'retry_after' => $remaining
      ]);
      exit;
    }
  }
} catch (PDOException $e) {
  // Log but don't fail on rate limit errors
  error_log("Rate limit check error: " . $e->getMessage());
}

// ============================================
// ANTI-BOT: Check if user is flagged as suspicious
// ============================================
try {
  // Check if table exists first to avoid errors on fresh installs
  $tableCheck = $pdo->query("
    SELECT EXISTS (
      SELECT FROM information_schema.tables
      WHERE table_name = 'suspicious_voters'
    )
  ");
  $tableExists = $tableCheck->fetchColumn();

  if ($tableExists) {
    $stmt = $pdo->prepare("SELECT flagged, reviewed FROM suspicious_voters WHERE username = ?");
    $stmt->execute([$username]);
    $suspiciousStatus = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($suspiciousStatus && $suspiciousStatus['flagged'] && !$suspiciousStatus['reviewed']) {
      http_response_code(403);
      echo json_encode([
        'error' => 'Voting suspended',
        'message' => 'Your voting privileges have been temporarily suspended for review.'
      ]);
      exit;
    }
  }
} catch (PDOException $e) {
  // Table might not exist yet on first vote - that's fine, just log and continue
  error_log("Suspicious check error: " . $e->getMessage());
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

  // Debug logging
  error_log("vote.php: Voting - streamer=$streamer, seq=$seq, clipId=$clipId, username=$username, vote=$vote");

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

        // Atomic upsert: create row with vote count OR increment existing
        // This prevents race condition where row exists with 0 count before increment
        if ($voteDir === 'up') {
          $stmt = $pdo->prepare("
            INSERT INTO votes (login, clip_id, seq, title, up_votes, down_votes, created_at, updated_at)
            VALUES (?, ?, ?, ?, 1, 0, NOW(), NOW())
            ON CONFLICT (login, clip_id) DO UPDATE SET
              up_votes = votes.up_votes + 1,
              updated_at = NOW()
          ");
        } else {
          $stmt = $pdo->prepare("
            INSERT INTO votes (login, clip_id, seq, title, up_votes, down_votes, created_at, updated_at)
            VALUES (?, ?, ?, ?, 0, 1, NOW(), NOW())
            ON CONFLICT (login, clip_id) DO UPDATE SET
              down_votes = votes.down_votes + 1,
              updated_at = NOW()
          ");
        }
        $stmt->execute([$streamer, $clipId, $seq, $clipTitle]);

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

  // ============================================
  // ANTI-BOT: Update suspicious activity tracking (async, after response)
  // ============================================
  if ($vote !== 'clear') {
    try {
      // Ensure suspicious_voters table exists
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS suspicious_voters (
          id SERIAL PRIMARY KEY,
          username VARCHAR(64) NOT NULL UNIQUE,
          twitch_user_id VARCHAR(64),
          total_votes INTEGER DEFAULT 0,
          votes_last_hour INTEGER DEFAULT 0,
          votes_last_day INTEGER DEFAULT 0,
          downvote_ratio NUMERIC(5,4) DEFAULT 0,
          first_vote_at TIMESTAMP,
          last_vote_at TIMESTAMP,
          flagged BOOLEAN DEFAULT FALSE,
          flag_reason TEXT,
          flagged_at TIMESTAMP,
          reviewed BOOLEAN DEFAULT FALSE,
          reviewed_by VARCHAR(64),
          reviewed_at TIMESTAMP,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
      ");

      // Get user's vote statistics
      $stmt = $pdo->prepare("
        SELECT
          COUNT(*) as total,
          COUNT(*) FILTER (WHERE vote_dir = 'down') as downvotes,
          COUNT(*) FILTER (WHERE voted_at > NOW() - INTERVAL '1 hour') as last_hour,
          COUNT(*) FILTER (WHERE voted_at > NOW() - INTERVAL '1 day') as last_day,
          MIN(voted_at) as first_vote
        FROM vote_ledger WHERE username = ?
      ");
      $stmt->execute([$username]);
      $stats = $stmt->fetch(PDO::FETCH_ASSOC);

      $totalVotes = (int)$stats['total'];
      $downvotes = (int)$stats['downvotes'];
      $votesLastHour = (int)$stats['last_hour'];
      $votesLastDay = (int)$stats['last_day'];
      $downvoteRatio = $totalVotes > 0 ? $downvotes / $totalVotes : 0;

      // Check for suspicious patterns
      $flagReasons = [];

      // Pattern 1: Very high downvote ratio with significant votes
      if ($totalVotes >= 10 && $downvoteRatio >= SUSPICIOUS_DOWNVOTE_RATIO) {
        $flagReasons[] = "High downvote ratio: " . round($downvoteRatio * 100) . "% downvotes";
      }

      // Pattern 2: Too many votes in a short time
      if ($votesLastHour >= SUSPICIOUS_VOTES_PER_HOUR) {
        $flagReasons[] = "High velocity: {$votesLastHour} votes in last hour";
      }

      // Pattern 3: New account voting rapidly
      $firstVoteTime = $stats['first_vote'] ? strtotime($stats['first_vote']) : time();
      $accountAge = time() - $firstVoteTime;
      if ($accountAge < 3600 && $totalVotes >= NEW_ACCOUNT_VOTE_LIMIT) {
        $flagReasons[] = "New account rapid voting: {$totalVotes} votes in first hour";
      }

      $shouldFlag = !empty($flagReasons);
      $flagReason = implode('; ', $flagReasons);

      // Upsert suspicious voter tracking
      $stmt = $pdo->prepare("
        INSERT INTO suspicious_voters (username, twitch_user_id, total_votes, votes_last_hour, votes_last_day, downvote_ratio, first_vote_at, last_vote_at, flagged, flag_reason, flagged_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, CASE WHEN ? THEN NOW() ELSE NULL END)
        ON CONFLICT (username) DO UPDATE SET
          twitch_user_id = COALESCE(EXCLUDED.twitch_user_id, suspicious_voters.twitch_user_id),
          total_votes = EXCLUDED.total_votes,
          votes_last_hour = EXCLUDED.votes_last_hour,
          votes_last_day = EXCLUDED.votes_last_day,
          downvote_ratio = EXCLUDED.downvote_ratio,
          last_vote_at = NOW(),
          flagged = CASE WHEN suspicious_voters.reviewed THEN suspicious_voters.flagged ELSE EXCLUDED.flagged END,
          flag_reason = CASE WHEN suspicious_voters.reviewed THEN suspicious_voters.flag_reason ELSE EXCLUDED.flag_reason END,
          flagged_at = CASE
            WHEN suspicious_voters.reviewed THEN suspicious_voters.flagged_at
            WHEN EXCLUDED.flagged AND NOT suspicious_voters.flagged THEN NOW()
            ELSE suspicious_voters.flagged_at
          END
      ");
      $stmt->execute([
        $username,
        $userId,
        $totalVotes,
        $votesLastHour,
        $votesLastDay,
        $downvoteRatio,
        $stats['first_vote'],
        $shouldFlag,
        $flagReason ?: null,
        $shouldFlag
      ]);

      if ($shouldFlag) {
        error_log("SUSPICIOUS VOTER FLAGGED: {$username} - {$flagReason}");
      }
    } catch (PDOException $e) {
      error_log("Suspicious tracking error: " . $e->getMessage());
    }
  }

} catch (PDOException $e) {
  error_log("Vote API error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Database error']);
}
