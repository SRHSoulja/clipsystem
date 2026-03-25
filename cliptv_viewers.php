<?php
require_once __DIR__ . '/includes/metrics.php';
/**
 * cliptv_viewers.php - Track ClipTV viewers and handle skip voting
 *
 * POST: Register viewer heartbeat and optionally vote to skip
 *   - login: channel login
 *   - viewer_id: unique viewer identifier (from localStorage)
 *   - skip: (optional) 1 to vote to skip, 0 to cancel skip vote
 *
 * GET: Get current viewer count and skip vote status
 *   - login: channel login
 *
 * Returns (POST):
 *   - viewer_count: number of active viewers
 *   - skip_votes: number of viewers who want to skip
 *   - skip_needed: votes needed for majority
 *   - should_skip: true if majority reached
 *   - my_skip_vote: true if this viewer voted to skip
 *   - clip_request: active clip request data (piggybacked from cliptv_requests table), or null
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

function clean_login($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? $_POST["login"] ?? "");

$pdo = get_db_connection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(["error" => "no database"]);
  exit;
}

// Schema is handled by db_bootstrap (runs once per deploy)
db_ensure_schema($pdo);

// Viewer timeout - 18 seconds of no heartbeat = gone (matches 5s heartbeat interval, 3 missed = safe)
$VIEWER_TIMEOUT = 18;

// Clean up stale viewers
try {
  $cutoff = date('Y-m-d H:i:s', time() - $VIEWER_TIMEOUT);
  $pdo->prepare("DELETE FROM cliptv_viewers WHERE last_seen < ?")->execute([$cutoff]);
} catch (PDOException $e) {
  // ignore cleanup errors
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Register heartbeat
  $viewerId = $_POST['viewer_id'] ?? '';
  $wantsSkip = isset($_POST['skip']) ? (bool)(int)$_POST['skip'] : null;
  $clipId = $_POST['clip_id'] ?? null;

  if (!$viewerId) {
    http_response_code(400);
    echo json_encode(["error" => "viewer_id required"]);
    exit;
  }

  try {
    // Upsert viewer
    if ($wantsSkip !== null) {
      // Update heartbeat and skip vote
      $stmt = $pdo->prepare("
        INSERT INTO cliptv_viewers (login, viewer_id, last_seen, wants_skip, clip_id)
        VALUES (?, ?, NOW(), ?, ?)
        ON CONFLICT (login, viewer_id) DO UPDATE SET
          last_seen = NOW(),
          wants_skip = ?,
          clip_id = COALESCE(?, cliptv_viewers.clip_id)
      ");
      $stmt->execute([$login, $viewerId, $wantsSkip, $clipId, $wantsSkip, $clipId]);
    } else {
      // Just heartbeat, keep existing skip vote
      $stmt = $pdo->prepare("
        INSERT INTO cliptv_viewers (login, viewer_id, last_seen, clip_id)
        VALUES (?, ?, NOW(), ?)
        ON CONFLICT (login, viewer_id) DO UPDATE SET
          last_seen = NOW(),
          clip_id = COALESCE(?, cliptv_viewers.clip_id)
      ");
      $stmt->execute([$login, $viewerId, $clipId, $clipId]);
    }

    // Get current state
    $stmt = $pdo->prepare("
      SELECT
        COUNT(*) as viewer_count,
        SUM(CASE WHEN wants_skip THEN 1 ELSE 0 END) as skip_votes
      FROM cliptv_viewers
      WHERE login = ?
    ");
    $stmt->execute([$login]);
    $row = $stmt->fetch();

    $viewerCount = (int)($row['viewer_count'] ?? 0);
    $skipVotes = (int)($row['skip_votes'] ?? 0);
    // Majority = more than half. 1->1, 2->2 (both), 3->2, 4->3, etc.
    $skipNeeded = $viewerCount === 1 ? 1 : (int)floor($viewerCount / 2) + 1;
    $shouldSkip = $skipVotes >= $skipNeeded && $skipVotes > 0;

    // Update peak viewer count if current exceeds stored peak
    if ($viewerCount > 0) {
      try {
        $pdo->prepare("
          INSERT INTO viewer_peaks (login, peak_viewers, peak_at, updated_at)
          VALUES (?, ?, NOW(), NOW())
          ON CONFLICT (login) DO UPDATE SET
            peak_viewers = GREATEST(viewer_peaks.peak_viewers, EXCLUDED.peak_viewers),
            peak_at = CASE WHEN EXCLUDED.peak_viewers > viewer_peaks.peak_viewers THEN NOW() ELSE viewer_peaks.peak_at END,
            updated_at = NOW()
        ")->execute([$login, $viewerCount]);
      } catch (PDOException $e) {
        // Non-critical, ignore - table may not exist yet
      }
    }

    // Get this viewer's skip vote status
    $stmt = $pdo->prepare("SELECT wants_skip FROM cliptv_viewers WHERE login = ? AND viewer_id = ?");
    $stmt->execute([$login, $viewerId]);
    $myVote = $stmt->fetch();
    $mySkipVote = $myVote ? (bool)$myVote['wants_skip'] : false;

    // Piggyback active clip request data (saves a separate GET to cliptv_request.php)
    $clipRequest = null;
    try {
      $reqStmt = $pdo->prepare("
        SELECT clip_id, clip_seq, clip_title, clip_game, clip_creator, clip_duration, requester_id,
               EXTRACT(EPOCH FROM (NOW() - created_at)) as age_seconds
        FROM cliptv_requests
        WHERE login = ? AND played = FALSE
          AND created_at > NOW() - INTERVAL '15 seconds'
      ");
      $reqStmt->execute([$login]);
      $reqRow = $reqStmt->fetch(PDO::FETCH_ASSOC);
      if ($reqRow) {
        $clipRequest = [
          "has_request" => true,
          "clip_id" => $reqRow['clip_id'],
          "clip_seq" => intval($reqRow['clip_seq']),
          "clip_title" => $reqRow['clip_title'],
          "clip_game" => $reqRow['clip_game'],
          "clip_creator" => $reqRow['clip_creator'],
          "clip_duration" => floatval($reqRow['clip_duration']),
          "requester_id" => $reqRow['requester_id'],
          "age_seconds" => floatval($reqRow['age_seconds'])
        ];
      }
    } catch (PDOException $e) {
      // Non-critical — frontend treats null as no active request
    }

    echo json_encode([
      "ok" => true,
      "login" => $login,
      "viewer_count" => $viewerCount,
      "skip_votes" => $skipVotes,
      "skip_needed" => $skipNeeded,
      "should_skip" => $shouldSkip,
      "my_skip_vote" => $mySkipVote,
      "clip_request" => $clipRequest
    ]);

  } catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database error"]);
  }
  exit;
}

// GET - return current state
try {
  $stmt = $pdo->prepare("
    SELECT
      COUNT(*) as viewer_count,
      SUM(CASE WHEN wants_skip THEN 1 ELSE 0 END) as skip_votes
    FROM cliptv_viewers
    WHERE login = ?
  ");
  $stmt->execute([$login]);
  $row = $stmt->fetch();

  $viewerCount = (int)($row['viewer_count'] ?? 0);
  $skipVotes = (int)($row['skip_votes'] ?? 0);
  // Majority = more than half. 1->1, 2->2 (both), 3->2, 4->3, etc.
  $skipNeeded = $viewerCount === 1 ? 1 : (int)floor($viewerCount / 2) + 1;
  $shouldSkip = $skipVotes >= $skipNeeded && $skipVotes > 0;

  echo json_encode([
    "login" => $login,
    "viewer_count" => $viewerCount,
    "skip_votes" => $skipVotes,
    "skip_needed" => $skipNeeded,
    "should_skip" => $shouldSkip
  ]);
} catch (PDOException $e) {
  echo json_encode([
    "login" => $login,
    "viewer_count" => 0,
    "skip_votes" => 0,
    "skip_needed" => 1,
    "should_skip" => false
  ]);
}
