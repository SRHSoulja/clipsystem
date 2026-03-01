<?php
/**
 * discord/discord_vote.php - Voting endpoint for Discord Activity users
 *
 * Accepts votes authenticated via HMAC token (issued by token.php)
 * instead of PHP session cookies. Same DB logic as api/vote.php.
 *
 * POST Parameters (JSON body):
 *   - streamer: Channel name
 *   - seq: Clip sequence number
 *   - vote: 'like', 'dislike', or 'clear'
 *   - twitch_username: Voter's Twitch username (from Discord connection)
 *   - discord_user_id: Discord user ID
 *   - vote_token: HMAC-SHA256 signed token from token.php
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../db_config.php';

$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$streamer = strtolower(trim($input['streamer'] ?? ''));
$seq = (int)($input['seq'] ?? 0);
$vote = strtolower(trim($input['vote'] ?? ''));
$twitchUsername = strtolower(trim($input['twitch_username'] ?? ''));
$discordUserId = trim($input['discord_user_id'] ?? '');
$voteToken = $input['vote_token'] ?? '';

// Validate required fields
if (!$streamer || $seq <= 0 || !$vote || !$twitchUsername || !$discordUserId || !$voteToken) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

// Verify HMAC token
$expectedToken = hash_hmac('sha256', $twitchUsername . '|' . $discordUserId, $ADMIN_KEY);
if (!hash_equals($expectedToken, $voteToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid vote token']);
    exit;
}

// Map vote types
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

$username = $twitchUsername;

// Rate limiting (same logic as api/vote.php)
const RATE_LIMIT_VOTES = 30;
const RATE_LIMIT_WINDOW = 300;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vote_rate_limits (
        username VARCHAR(64) PRIMARY KEY,
        vote_count INTEGER DEFAULT 0,
        window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

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

    if ($rateLimit && (int)$rateLimit['vote_count'] > RATE_LIMIT_VOTES) {
        $remaining = ceil(RATE_LIMIT_WINDOW - (float)$rateLimit['window_age']);
        http_response_code(429);
        echo json_encode([
            'error' => 'Rate limit exceeded',
            'message' => "Too many votes. Please wait {$remaining} seconds.",
            'retry_after' => $remaining
        ]);
        exit;
    }
} catch (PDOException $e) {
    error_log("Discord vote rate limit error: " . $e->getMessage());
}

// Check if user is flagged as suspicious
try {
    $tableCheck = $pdo->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'suspicious_voters')");
    if ($tableCheck->fetchColumn()) {
        $stmt = $pdo->prepare("SELECT flagged, reviewed FROM suspicious_voters WHERE username = ?");
        $stmt->execute([$username]);
        $sus = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($sus && $sus['flagged'] && !$sus['reviewed']) {
            http_response_code(403);
            echo json_encode(['error' => 'Voting suspended', 'message' => 'Your voting privileges have been temporarily suspended for review.']);
            exit;
        }
    }
} catch (PDOException $e) {
    error_log("Discord vote suspicious check error: " . $e->getMessage());
}

// Process the vote (same logic as api/vote.php)
try {
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
    $response = ['success' => true];

    // Check existing vote
    $stmt = $pdo->prepare("SELECT vote_dir FROM vote_ledger WHERE login = ? AND clip_id = ? AND username = ?");
    $stmt->execute([$streamer, $clipId, $username]);
    $existingVote = $stmt->fetchColumn();

    if ($vote === 'clear') {
        if ($existingVote) {
            $stmt = $pdo->prepare("DELETE FROM vote_ledger WHERE login = ? AND clip_id = ? AND username = ?");
            $stmt->execute([$streamer, $clipId, $username]);
            if ($existingVote === 'up') {
                $pdo->prepare("UPDATE votes SET up_votes = GREATEST(0, up_votes - 1), updated_at = NOW() WHERE login = ? AND clip_id = ?")->execute([$streamer, $clipId]);
            } else {
                $pdo->prepare("UPDATE votes SET down_votes = GREATEST(0, down_votes - 1), updated_at = NOW() WHERE login = ? AND clip_id = ?")->execute([$streamer, $clipId]);
            }
        }
        $response['action'] = 'cleared';
        $response['message'] = "Vote cleared on #{$seq}";
    } else {
        $existingVoteType = $existingVote === 'up' ? 'like' : ($existingVote === 'down' ? 'dislike' : null);

        if ($existingVoteType === $vote) {
            $response['action'] = 'unchanged';
            $response['message'] = "You already {$vote}d #{$seq}";
        } else {
            $pdo->beginTransaction();
            try {
                if ($existingVote) {
                    if ($existingVote === 'up') {
                        $pdo->prepare("UPDATE votes SET up_votes = GREATEST(0, up_votes - 1) WHERE login = ? AND clip_id = ?")->execute([$streamer, $clipId]);
                    } else {
                        $pdo->prepare("UPDATE votes SET down_votes = GREATEST(0, down_votes - 1) WHERE login = ? AND clip_id = ?")->execute([$streamer, $clipId]);
                    }
                }

                $stmt = $pdo->prepare("
                    INSERT INTO vote_ledger (login, clip_id, username, vote_dir, voted_at)
                    VALUES (?, ?, ?, ?, NOW())
                    ON CONFLICT (login, clip_id, username)
                    DO UPDATE SET vote_dir = EXCLUDED.vote_dir, voted_at = NOW()
                ");
                $stmt->execute([$streamer, $clipId, $username, $voteDir]);

                if ($voteDir === 'up') {
                    $stmt = $pdo->prepare("
                        INSERT INTO votes (login, clip_id, seq, title, up_votes, down_votes, created_at, updated_at)
                        VALUES (?, ?, ?, ?, 1, 0, NOW(), NOW())
                        ON CONFLICT (login, clip_id) DO UPDATE SET up_votes = votes.up_votes + 1, updated_at = NOW()
                    ");
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO votes (login, clip_id, seq, title, up_votes, down_votes, created_at, updated_at)
                        VALUES (?, ?, ?, ?, 0, 1, NOW(), NOW())
                        ON CONFLICT (login, clip_id) DO UPDATE SET down_votes = votes.down_votes + 1, updated_at = NOW()
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

    // Get updated counts
    $stmt = $pdo->prepare("SELECT up_votes, down_votes FROM votes WHERE login = ? AND clip_id = ?");
    $stmt->execute([$streamer, $clipId]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT vote_dir FROM vote_ledger WHERE login = ? AND clip_id = ? AND username = ?");
    $stmt->execute([$streamer, $clipId, $username]);
    $currentVote = $stmt->fetchColumn();

    $response['likes'] = (int)($counts['up_votes'] ?? 0);
    $response['dislikes'] = (int)($counts['down_votes'] ?? 0);
    $response['user_vote'] = $currentVote === 'up' ? 'like' : ($currentVote === 'down' ? 'dislike' : null);

    echo json_encode($response);

    // Anti-bot tracking (same as api/vote.php)
    if ($vote !== 'clear') {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS suspicious_voters (
                id SERIAL PRIMARY KEY, username VARCHAR(64) NOT NULL UNIQUE, twitch_user_id VARCHAR(64),
                total_votes INTEGER DEFAULT 0, votes_last_hour INTEGER DEFAULT 0, votes_last_day INTEGER DEFAULT 0,
                downvote_ratio NUMERIC(5,4) DEFAULT 0, first_vote_at TIMESTAMP, last_vote_at TIMESTAMP,
                flagged BOOLEAN DEFAULT FALSE, flag_reason TEXT, flagged_at TIMESTAMP,
                reviewed BOOLEAN DEFAULT FALSE, reviewed_by VARCHAR(64), reviewed_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total, COUNT(*) FILTER (WHERE vote_dir = 'down') as downvotes,
                COUNT(*) FILTER (WHERE voted_at > NOW() - INTERVAL '1 hour') as last_hour,
                COUNT(*) FILTER (WHERE voted_at > NOW() - INTERVAL '1 day') as last_day,
                MIN(voted_at) as first_vote FROM vote_ledger WHERE username = ?
            ");
            $stmt->execute([$username]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            $totalVotes = (int)$stats['total'];
            $downvotes = (int)$stats['downvotes'];
            $votesLastHour = (int)$stats['last_hour'];
            $votesLastDay = (int)$stats['last_day'];
            $downvoteRatio = $totalVotes > 0 ? $downvotes / $totalVotes : 0;

            $flagReasons = [];
            if ($totalVotes >= 10 && $downvoteRatio >= 0.9) $flagReasons[] = "High downvote ratio: " . round($downvoteRatio * 100) . "%";
            if ($votesLastHour >= 50) $flagReasons[] = "High velocity: {$votesLastHour} votes/hour";
            $firstVoteTime = $stats['first_vote'] ? strtotime($stats['first_vote']) : time();
            if ((time() - $firstVoteTime) < 3600 && $totalVotes >= 10) $flagReasons[] = "New account rapid voting: {$totalVotes} votes in first hour";

            $shouldFlag = !empty($flagReasons);
            $flagReason = implode('; ', $flagReasons);

            $stmt = $pdo->prepare("
                INSERT INTO suspicious_voters (username, twitch_user_id, total_votes, votes_last_hour, votes_last_day, downvote_ratio, first_vote_at, last_vote_at, flagged, flag_reason, flagged_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, CASE WHEN ? THEN NOW() ELSE NULL END)
                ON CONFLICT (username) DO UPDATE SET
                  total_votes = EXCLUDED.total_votes, votes_last_hour = EXCLUDED.votes_last_hour,
                  votes_last_day = EXCLUDED.votes_last_day, downvote_ratio = EXCLUDED.downvote_ratio, last_vote_at = NOW(),
                  flagged = CASE WHEN suspicious_voters.reviewed THEN suspicious_voters.flagged ELSE EXCLUDED.flagged END,
                  flag_reason = CASE WHEN suspicious_voters.reviewed THEN suspicious_voters.flag_reason ELSE EXCLUDED.flag_reason END,
                  flagged_at = CASE WHEN suspicious_voters.reviewed THEN suspicious_voters.flagged_at WHEN EXCLUDED.flagged AND NOT suspicious_voters.flagged THEN NOW() ELSE suspicious_voters.flagged_at END
            ");
            $stmt->execute([$username, null, $totalVotes, $votesLastHour, $votesLastDay, $downvoteRatio, $stats['first_vote'], $shouldFlag, $flagReason ?: null, $shouldFlag]);

            if ($shouldFlag) error_log("SUSPICIOUS VOTER FLAGGED (Discord): {$username} - {$flagReason}");
        } catch (PDOException $e) {
            error_log("Discord vote suspicious tracking error: " . $e->getMessage());
        }
    }
} catch (PDOException $e) {
    error_log("Discord vote error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
