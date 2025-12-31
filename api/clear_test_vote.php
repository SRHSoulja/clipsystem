<?php
/**
 * clear_test_vote.php - One-time cleanup script to remove test votes
 * DELETE THIS FILE AFTER USE
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../db_config.php';

$pdo = get_db_connection();
if (!$pdo) {
  echo json_encode(['error' => 'No database connection']);
  exit;
}

// Remove testuser123's vote from clip 3768
$streamer = 'floppyjimmie';
$username = 'testuser123';

try {
  // Find the clip_id for seq 3768
  $stmt = $pdo->prepare("SELECT clip_id FROM clips WHERE login = ? AND seq = 3768");
  $stmt->execute([$streamer]);
  $clipId = $stmt->fetchColumn();

  if (!$clipId) {
    echo json_encode(['error' => 'Clip not found']);
    exit;
  }

  $pdo->beginTransaction();

  // Get the vote direction before deleting
  $stmt = $pdo->prepare("SELECT vote_dir FROM vote_ledger WHERE login = ? AND clip_id = ? AND username = ?");
  $stmt->execute([$streamer, $clipId, $username]);
  $voteDir = $stmt->fetchColumn();

  if (!$voteDir) {
    echo json_encode(['message' => 'No vote found for testuser123']);
    exit;
  }

  // Delete from ledger
  $stmt = $pdo->prepare("DELETE FROM vote_ledger WHERE login = ? AND clip_id = ? AND username = ?");
  $stmt->execute([$streamer, $clipId, $username]);
  $ledgerDeleted = $stmt->rowCount();

  // Decrement the aggregate count
  if ($voteDir === 'up') {
    $stmt = $pdo->prepare("UPDATE votes SET up_votes = GREATEST(0, up_votes - 1), updated_at = CURRENT_TIMESTAMP WHERE login = ? AND clip_id = ?");
  } else {
    $stmt = $pdo->prepare("UPDATE votes SET down_votes = GREATEST(0, down_votes - 1), updated_at = CURRENT_TIMESTAMP WHERE login = ? AND clip_id = ?");
  }
  $stmt->execute([$streamer, $clipId]);

  $pdo->commit();

  echo json_encode([
    'success' => true,
    'message' => "Removed testuser123's $voteDir vote from clip 3768",
    'ledger_rows_deleted' => $ledgerDeleted,
    'reminder' => 'DELETE THIS FILE NOW'
  ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['error' => $e->getMessage()]);
}
