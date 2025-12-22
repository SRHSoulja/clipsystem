<?php
/**
 * cvote.php - Clear votes for a user
 *
 * Usage:
 *   ?login=X&user=USERNAME           - Clear user's vote on currently playing clip
 *   ?login=X&user=USERNAME&seq=3     - Clear user's vote on clip #3
 *   ?login=X&user=USERNAME&clear=all - Clear ALL votes the user has ever made
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/db_config.php';

set_cors_headers();
handle_options_request();
header("Content-Type: text/plain; charset=utf-8");

$login = clean_login($_GET["login"] ?? "");
$user = isset($_GET["user"]) ? strtolower(preg_replace("/[^a-zA-Z0-9_]/", "", trim($_GET["user"]))) : "";
$seq = isset($_GET["seq"]) ? intval($_GET["seq"]) : 0;
$clearAll = isset($_GET["clear"]) && $_GET["clear"] === "all";

if (empty($user)) {
  echo "Missing username.";
  exit;
}

$pdo = get_db_connection();
if (!$pdo) {
  echo "Database unavailable";
  exit;
}

// Clear ALL votes for this user
if ($clearAll) {
  try {
    // Get all votes by this user
    $stmt = $pdo->prepare("SELECT clip_id, vote_dir FROM vote_ledger WHERE login = ? AND username = ?");
    $stmt->execute([$login, $user]);
    $votes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($votes)) {
      echo "You have no votes to clear.";
      exit;
    }

    $pdo->beginTransaction();

    // Decrement each vote from the totals
    foreach ($votes as $vote) {
      if ($vote['vote_dir'] === 'up') {
        $stmt = $pdo->prepare("UPDATE votes SET up_votes = GREATEST(0, up_votes - 1) WHERE login = ? AND clip_id = ?");
      } else {
        $stmt = $pdo->prepare("UPDATE votes SET down_votes = GREATEST(0, down_votes - 1) WHERE login = ? AND clip_id = ?");
      }
      $stmt->execute([$login, $vote['clip_id']]);
    }

    // Delete all ledger entries for this user
    $stmt = $pdo->prepare("DELETE FROM vote_ledger WHERE login = ? AND username = ?");
    $stmt->execute([$login, $user]);

    $pdo->commit();

    $count = count($votes);
    echo "Cleared all {$count} of your votes.";
    exit;

  } catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("cvote clear all error: " . $e->getMessage());
    echo "Error clearing votes.";
    exit;
  }
}

// If no seq provided, get currently playing clip
if ($seq <= 0) {
  $runtimeDir = get_runtime_dir();
  $npPath = $runtimeDir . "/now_playing_" . $login . ".json";

  if (file_exists($npPath)) {
    $npData = json_decode(file_get_contents($npPath), true);
    if ($npData && isset($npData["seq"])) {
      $seq = (int)$npData["seq"];
    }
  }

  if ($seq <= 0) {
    echo "No clip currently playing. Use !cvote <clip#>";
    exit;
  }
}

// Get clip_id from seq
try {
  $stmt = $pdo->prepare("SELECT clip_id FROM clips WHERE login = ? AND seq = ?");
  $stmt->execute([$login, $seq]);
  $clip = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$clip) {
    echo "Clip #{$seq} not found.";
    exit;
  }

  $clipId = $clip['clip_id'];

  // Check if user has a vote on this clip
  $stmt = $pdo->prepare("SELECT id, vote_dir FROM vote_ledger WHERE login = ? AND clip_id = ? AND username = ?");
  $stmt->execute([$login, $clipId, $user]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$existing) {
    echo "You haven't voted on clip #{$seq}.";
    exit;
  }

  $voteDir = $existing['vote_dir'];

  // Start transaction
  $pdo->beginTransaction();

  // Decrement the vote count
  if ($voteDir === 'up') {
    $stmt = $pdo->prepare("UPDATE votes SET up_votes = GREATEST(0, up_votes - 1) WHERE login = ? AND clip_id = ?");
  } else {
    $stmt = $pdo->prepare("UPDATE votes SET down_votes = GREATEST(0, down_votes - 1) WHERE login = ? AND clip_id = ?");
  }
  $stmt->execute([$login, $clipId]);

  // Remove from ledger
  $stmt = $pdo->prepare("DELETE FROM vote_ledger WHERE id = ?");
  $stmt->execute([$existing['id']]);

  $pdo->commit();

  // Get updated counts
  $stmt = $pdo->prepare("SELECT up_votes, down_votes FROM votes WHERE login = ? AND clip_id = ?");
  $stmt->execute([$login, $clipId]);
  $row = $stmt->fetch();
  $up = $row ? (int)$row['up_votes'] : 0;
  $down = $row ? (int)$row['down_votes'] : 0;

  echo "Cleared your vote on clip #{$seq}. 👍{$up} 👎{$down}";

} catch (PDOException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("cvote error: " . $e->getMessage());
  echo "Error clearing vote.";
}
