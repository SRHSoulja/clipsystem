<?php
/**
 * suspicious_voters.php - Admin API for managing suspicious voters
 *
 * Endpoints (require super admin OAuth):
 *   GET ?action=list_flagged  - List all flagged voters
 *   GET ?action=list_all      - List all voters with tracking data
 *   GET ?action=undo_votes&username=X  - Remove all votes from a user
 *   GET ?action=clear_flag&username=X  - Mark as reviewed, clear flag
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../includes/twitch_oauth.php';

// Super admin authentication via OAuth
$currentUser = getCurrentUser();
if (!$currentUser || !isSuperAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - super admin access required']);
    exit;
}

$action = $_GET['action'] ?? '';
$username = strtolower(trim($_GET['username'] ?? ''));

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Ensure tables exist
init_votes_tables($pdo);

try {
    switch ($action) {
        case 'list_flagged':
            // Get all flagged but not reviewed voters
            $stmt = $pdo->query("
                SELECT username, twitch_user_id, total_votes, votes_last_hour, votes_last_day,
                       downvote_ratio, first_vote_at, last_vote_at, flag_reason, flagged_at
                FROM suspicious_voters
                WHERE flagged = TRUE AND reviewed = FALSE
                ORDER BY flagged_at DESC
            ");
            $voters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'voters' => $voters]);
            break;

        case 'list_all':
            // Get all voters with tracking data
            $stmt = $pdo->query("
                SELECT username, twitch_user_id, total_votes, votes_last_hour, votes_last_day,
                       downvote_ratio, first_vote_at, last_vote_at, flagged, reviewed, flag_reason
                FROM suspicious_voters
                ORDER BY last_vote_at DESC
                LIMIT 100
            ");
            $voters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'voters' => $voters]);
            break;

        case 'undo_votes':
            if (!$username) {
                http_response_code(400);
                echo json_encode(['error' => 'Username required']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                // Get all votes by this user
                $stmt = $pdo->prepare("
                    SELECT login, clip_id, vote_dir FROM vote_ledger WHERE username = ?
                ");
                $stmt->execute([$username]);
                $votes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $undoneCount = 0;
                foreach ($votes as $vote) {
                    // Decrement the aggregate count
                    if ($vote['vote_dir'] === 'up') {
                        $stmt = $pdo->prepare("UPDATE votes SET up_votes = GREATEST(0, up_votes - 1), updated_at = NOW() WHERE login = ? AND clip_id = ?");
                    } else {
                        $stmt = $pdo->prepare("UPDATE votes SET down_votes = GREATEST(0, down_votes - 1), updated_at = NOW() WHERE login = ? AND clip_id = ?");
                    }
                    $stmt->execute([$vote['login'], $vote['clip_id']]);
                    $undoneCount++;
                }

                // Delete all votes from ledger
                $stmt = $pdo->prepare("DELETE FROM vote_ledger WHERE username = ?");
                $stmt->execute([$username]);

                // Mark as reviewed, clear flag, and reset vote counts
                $stmt = $pdo->prepare("
                    UPDATE suspicious_voters
                    SET reviewed = TRUE, reviewed_at = NOW(), flagged = FALSE, total_votes = 0, votes_last_hour = 0, votes_last_day = 0, downvote_ratio = 0
                    WHERE username = ?
                ");
                $stmt->execute([$username]);

                // Clear rate limit for this user
                $stmt = $pdo->prepare("DELETE FROM vote_rate_limits WHERE username = ?");
                $stmt->execute([$username]);

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => "Removed {$undoneCount} votes from {$username}",
                    'votes_removed' => $undoneCount
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'clear_flag':
            if (!$username) {
                http_response_code(400);
                echo json_encode(['error' => 'Username required']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE suspicious_voters
                SET reviewed = TRUE, reviewed_at = NOW(), flagged = FALSE
                WHERE username = ?
            ");
            $stmt->execute([$username]);

            if ($stmt->rowCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => "Cleared flag for {$username}"
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => "User not found in tracking"
                ]);
            }
            break;

        case 'get_stats':
            // Get overall statistics
            $stats = [];

            $stmt = $pdo->query("SELECT COUNT(*) FROM suspicious_voters WHERE flagged = TRUE AND reviewed = FALSE");
            $stats['flagged_count'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM suspicious_voters");
            $stats['total_tracked'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(DISTINCT username) FROM vote_ledger");
            $stats['total_voters'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM vote_ledger WHERE voted_at > NOW() - INTERVAL '24 hours'");
            $stats['votes_24h'] = (int)$stmt->fetchColumn();

            echo json_encode(['success' => true, 'stats' => $stats]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Valid actions: list_flagged, list_all, undo_votes, clear_flag, get_stats']);
    }
} catch (PDOException $e) {
    error_log("Suspicious voters API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
