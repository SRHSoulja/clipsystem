<?php
/**
 * cvote.php - Clear votes for a clip (mod command)
 *
 * Resets up_votes and down_votes to 0 for a specific clip.
 * If no seq provided, clears votes for currently playing clip.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/db_config.php';

set_cors_headers();
handle_options_request();
header("Content-Type: text/plain; charset=utf-8");

$login = clean_login($_GET["login"] ?? "");
$seq = isset($_GET["seq"]) ? intval($_GET["seq"]) : 0;
require_admin_auth();

$pdo = get_db_connection();
if (!$pdo) {
  echo "Database unavailable";
  exit;
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

  // Clear votes (set to 0)
  $stmt = $pdo->prepare("
    UPDATE votes
    SET up_votes = 0, down_votes = 0
    WHERE login = ? AND clip_id = ?
  ");
  $stmt->execute([$login, $clipId]);

  if ($stmt->rowCount() > 0) {
    echo "Cleared votes for clip #{$seq}.";
  } else {
    // No votes existed, that's fine
    echo "Clip #{$seq} had no votes.";
  }

} catch (PDOException $e) {
  error_log("cvote error: " . $e->getMessage());
  echo "Error clearing votes.";
}
