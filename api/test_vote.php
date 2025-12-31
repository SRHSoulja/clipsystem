<?php
/**
 * test_vote.php - Test voting directly and return debug info
 *
 * GET /api/test_vote.php?streamer=xxx&seq=123&user=testuser&dir=up
 * DELETE THIS FILE AFTER DEBUGGING
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../db_config.php';

$streamer = strtolower(trim($_GET['streamer'] ?? ''));
$seq = (int)($_GET['seq'] ?? 0);
$user = strtolower(trim($_GET['user'] ?? 'testuser'));
$dir = strtolower(trim($_GET['dir'] ?? 'up'));

if (!$streamer || $seq <= 0) {
  echo json_encode(['error' => 'Missing streamer or seq', 'usage' => '/api/test_vote.php?streamer=xxx&seq=123&user=testuser&dir=up']);
  exit;
}

if ($dir !== 'up' && $dir !== 'down') {
  echo json_encode(['error' => 'dir must be up or down']);
  exit;
}

$pdo = get_db_connection();
if (!$pdo) {
  echo json_encode(['error' => 'No database connection']);
  exit;
}

$result = ['debug' => 'DELETE THIS ENDPOINT AFTER DEBUGGING'];
$result['input'] = compact('streamer', 'seq', 'user', 'dir');

// Find clip
try {
  $stmt = $pdo->prepare("SELECT clip_id, title FROM clips WHERE login = ? AND seq = ? AND blocked = FALSE");
  $stmt->execute([$streamer, $seq]);
  $clip = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$clip) {
    echo json_encode(['error' => 'Clip not found', 'input' => $result['input']]);
    exit;
  }

  $clipId = $clip['clip_id'];
  $clipTitle = $clip['title'];
  $result['clip'] = ['clip_id' => $clipId, 'title' => $clipTitle];

} catch (PDOException $e) {
  echo json_encode(['error' => 'Clip lookup failed: ' . $e->getMessage()]);
  exit;
}

// Check existing vote
try {
  $stmt = $pdo->prepare("SELECT id, vote_dir FROM vote_ledger WHERE login = ? AND clip_id = ? AND username = ?");
  $stmt->execute([$streamer, $clipId, $user]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC);
  $result['existing_vote'] = $existing ?: null;

  if ($existing && $existing['vote_dir'] === $dir) {
    echo json_encode(['status' => 'already_voted', 'message' => "User already voted $dir", 'result' => $result]);
    exit;
  }
} catch (PDOException $e) {
  echo json_encode(['error' => 'Existing vote check failed: ' . $e->getMessage()]);
  exit;
}

// Try to record vote
try {
  $pdo->beginTransaction();
  $result['steps'] = [];

  // Step 1: Insert/update votes aggregate
  $stmt = $pdo->prepare("
    INSERT INTO votes (login, clip_id, seq, title, up_votes, down_votes, updated_at)
    VALUES (?, ?, ?, ?, CASE WHEN ? = 'up' THEN 1 ELSE 0 END, CASE WHEN ? = 'down' THEN 1 ELSE 0 END, CURRENT_TIMESTAMP)
    ON CONFLICT (login, clip_id) DO UPDATE SET
      up_votes = CASE WHEN ? = 'up' THEN votes.up_votes + 1 ELSE votes.up_votes END,
      down_votes = CASE WHEN ? = 'down' THEN votes.down_votes + 1 ELSE votes.down_votes END,
      updated_at = CURRENT_TIMESTAMP
  ");
  $stmt->execute([$streamer, $clipId, $seq, $clipTitle, $dir, $dir, $dir, $dir]);
  $result['steps'][] = ['action' => 'votes_upsert', 'rows' => $stmt->rowCount(), 'status' => 'ok'];

  // Step 2: Insert ledger
  $stmt = $pdo->prepare("INSERT INTO vote_ledger (login, clip_id, username, vote_dir) VALUES (?, ?, ?, ?)");
  $stmt->execute([$streamer, $clipId, $user, $dir]);
  $result['steps'][] = ['action' => 'ledger_insert', 'rows' => $stmt->rowCount(), 'status' => 'ok'];

  $pdo->commit();
  $result['steps'][] = ['action' => 'commit', 'status' => 'ok'];

  // Get final counts
  $stmt = $pdo->prepare("SELECT up_votes, down_votes FROM votes WHERE login = ? AND clip_id = ?");
  $stmt->execute([$streamer, $clipId]);
  $counts = $stmt->fetch(PDO::FETCH_ASSOC);
  $result['final_counts'] = $counts;

  echo json_encode(['status' => 'success', 'message' => "Vote recorded: $dir for #$seq", 'result' => $result], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode([
    'status' => 'error',
    'error' => $e->getMessage(),
    'error_code' => $e->getCode(),
    'result' => $result
  ], JSON_PRETTY_PRINT);
}
