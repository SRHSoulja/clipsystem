<?php
/**
 * vote_submit.php - Record a vote for a clip by its permanent seq number
 *
 * Uses PostgreSQL for persistent storage when DATABASE_URL is set.
 * Falls back to file storage (ephemeral on Railway) otherwise.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

// Static data (clips_index) is in ./cache (read-only on Railway)
$staticDir = __DIR__ . "/cache";
// Runtime data goes to /tmp on Railway (fallback only)
$runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";
if (!is_dir($runtimeDir)) @mkdir($runtimeDir, 0777, true);

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}
function clean_user($s){
  $s = trim((string)$s);
  $s = preg_replace("/[^a-zA-Z0-9_]/", "", $s);
  return $s ?: "anon";
}
function clean_seq($s){
  $s = trim((string)$s);
  $s = ltrim($s, "#");
  $s = preg_replace("/[^0-9]/", "", $s);
  return (int)$s;
}

$login = clean_login($_REQUEST["login"] ?? "");
$user  = clean_user($_REQUEST["user"] ?? "");

// Require a number (no generic !like)
$seq = clean_seq($_REQUEST["seq"] ?? "");
if ($seq <= 0) {
  echo "Invalid vote. Use: !like <clip#> or !dislike <clip#>";
  exit;
}

// Accept either dir=up/down or vote=like/dislike
$dir  = strtolower(trim((string)($_REQUEST["dir"] ?? "")));
$vote = strtolower(trim((string)($_REQUEST["vote"] ?? "")));

if ($dir === "" && $vote !== "") {
  if ($vote === "like") $dir = "up";
  if ($vote === "dislike") $dir = "down";
}

if ($dir !== "up" && $dir !== "down") {
  echo "Invalid vote. Use: !like <clip#> or !dislike <clip#>";
  exit;
}

// Check if vote feedback is enabled for this channel
$showFeedback = true; // Default to showing feedback
$pdo = get_db_connection();
if ($pdo) {
  try {
    $stmt = $pdo->prepare("SELECT vote_feedback FROM channel_settings WHERE login = ?");
    $stmt->execute([$login]);
    $row = $stmt->fetch();
    if ($row && isset($row['vote_feedback'])) {
      $showFeedback = (bool)$row['vote_feedback'];
    }
  } catch (PDOException $e) {
    // Ignore - default to showing feedback
  }
}

// Look up clip by seq - try database first, fall back to JSON
$clipId = null;
$clipTitle = "";
$maxSeq = 0;
$pdo = get_db_connection();

if ($pdo) {
  try {
    $stmt = $pdo->prepare("SELECT clip_id, title, blocked FROM clips WHERE login = ? AND seq = ?");
    $stmt->execute([$login, $seq]);
    $row = $stmt->fetch();

    if ($row) {
      if ($row['blocked']) {
        echo "Clip #{$seq} has been removed.";
        exit;
      }
      $clipId = $row['clip_id'];
      $clipTitle = $row['title'] ?? "";
    } else {
      $stmt = $pdo->prepare("SELECT MAX(seq) FROM clips WHERE login = ?");
      $stmt->execute([$login]);
      $maxSeq = (int)$stmt->fetchColumn();
    }
  } catch (PDOException $e) {
    error_log("vote_submit db lookup error: " . $e->getMessage());
    // Fall through to JSON
  }
}

// Fallback to JSON if database didn't find it
if (!$clipId && !$maxSeq) {
  $indexFile = $staticDir . "/clips_index_" . $login . ".json";
  if (!file_exists($indexFile)) { echo "Clip index not found."; exit; }

  $indexRaw = @file_get_contents($indexFile);
  $indexData = $indexRaw ? json_decode($indexRaw, true) : null;
  if (!is_array($indexData) || !isset($indexData["clips"]) || !is_array($indexData["clips"])) {
    echo "Invalid clip index.";
    exit;
  }

  foreach ($indexData["clips"] as $c) {
    if (isset($c["seq"]) && (int)$c["seq"] === $seq) {
      $clipId = isset($c["id"]) ? (string)$c["id"] : null;
      $clipTitle = isset($c["title"]) ? $c["title"] : "";
      break;
    }
  }

  $maxSeq = isset($indexData["max_seq"]) ? (int)$indexData["max_seq"] : count($indexData["clips"]);
}

if (!$clipId) {
  echo "Clip #{$seq} not found. Valid range: 1-{$maxSeq}";
  exit;
}

// Try database first, fall back to file storage
// $pdo already set above from clip lookup

if ($pdo) {
  // Use PostgreSQL for persistent storage
  init_votes_tables($pdo);

  try {
    // Check if user already voted
    $stmt = $pdo->prepare("SELECT id, vote_dir FROM vote_ledger WHERE login = ? AND clip_id = ? AND username = ?");
    $stmt->execute([$login, $clipId, strtolower($user)]);
    $existing = $stmt->fetch();

    if ($existing) {
      $oldDir = $existing['vote_dir'];

      // Same vote direction - already voted
      if ($oldDir === $dir) {
        $stmt = $pdo->prepare("SELECT up_votes, down_votes FROM votes WHERE login = ? AND clip_id = ?");
        $stmt->execute([$login, $clipId]);
        $row = $stmt->fetch();
        $up = $row ? (int)$row['up_votes'] : 0;
        $down = $row ? (int)$row['down_votes'] : 0;
        if ($showFeedback) echo "Already voted {$dir} for Clip #{$seq}. 👍{$up} 👎{$down}";
        exit;
      }

      // Different direction - change the vote
      $pdo->beginTransaction();

      // Use CASE statements to avoid dynamic column names (SQL injection safe)
      $stmt = $pdo->prepare("
        UPDATE votes SET
          up_votes = CASE
            WHEN ? = 'up' THEN GREATEST(0, up_votes - 1)
            WHEN ? = 'up' THEN up_votes + 1
            ELSE up_votes
          END,
          down_votes = CASE
            WHEN ? = 'down' THEN GREATEST(0, down_votes - 1)
            WHEN ? = 'down' THEN down_votes + 1
            ELSE down_votes
          END,
          updated_at = CURRENT_TIMESTAMP
        WHERE login = ? AND clip_id = ?
      ");
      $stmt->execute([$oldDir, $dir, $oldDir, $dir, $login, $clipId]);

      // Update ledger with new vote direction
      $stmt = $pdo->prepare("UPDATE vote_ledger SET vote_dir = ?, voted_at = CURRENT_TIMESTAMP WHERE id = ?");
      $stmt->execute([$dir, $existing['id']]);

      $pdo->commit();

      // Get final counts
      $stmt = $pdo->prepare("SELECT up_votes, down_votes FROM votes WHERE login = ? AND clip_id = ?");
      $stmt->execute([$login, $clipId]);
      $row = $stmt->fetch();
      $up = $row ? (int)$row['up_votes'] : 0;
      $down = $row ? (int)$row['down_votes'] : 0;

      if ($showFeedback) echo "Changed vote to {$dir} for Clip #{$seq}. 👍{$up} 👎{$down}";
      exit;
    }

    // Record the vote
    $pdo->beginTransaction();

    // Insert or update votes aggregate (use CASE to avoid dynamic column names)
    $stmt = $pdo->prepare("
      INSERT INTO votes (login, clip_id, seq, title, up_votes, down_votes, updated_at)
      VALUES (?, ?, ?, ?, CASE WHEN ? = 'up' THEN 1 ELSE 0 END, CASE WHEN ? = 'down' THEN 1 ELSE 0 END, CURRENT_TIMESTAMP)
      ON CONFLICT (login, clip_id) DO UPDATE SET
        up_votes = CASE WHEN ? = 'up' THEN votes.up_votes + 1 ELSE votes.up_votes END,
        down_votes = CASE WHEN ? = 'down' THEN votes.down_votes + 1 ELSE votes.down_votes END,
        updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$login, $clipId, $seq, $clipTitle, $dir, $dir, $dir, $dir]);

    // Record in ledger to prevent duplicate votes
    $stmt = $pdo->prepare("INSERT INTO vote_ledger (login, clip_id, username, vote_dir) VALUES (?, ?, ?, ?)");
    $stmt->execute([$login, $clipId, strtolower($user), $dir]);

    $pdo->commit();

    // Get final counts
    $stmt = $pdo->prepare("SELECT up_votes, down_votes FROM votes WHERE login = ? AND clip_id = ?");
    $stmt->execute([$login, $clipId]);
    $row = $stmt->fetch();
    $up = $row ? (int)$row['up_votes'] : 0;
    $down = $row ? (int)$row['down_votes'] : 0;

    if ($showFeedback) echo "Voted {$dir} for Clip #{$seq}. 👍{$up} 👎{$down}";
    exit;

  } catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Vote database error: " . $e->getMessage());
    // Fall through to file storage
  }
}

// Fallback: File-based storage (ephemeral on Railway)
$votesFile  = $runtimeDir . "/votes_" . $login . ".json";
$ledgerFile = $runtimeDir . "/votes_ledger_" . $login . ".json";

$votes = file_exists($votesFile) ? json_decode(@file_get_contents($votesFile), true) : [];
if (!is_array($votes)) $votes = [];

$ledger = file_exists($ledgerFile) ? json_decode(@file_get_contents($ledgerFile), true) : [];
if (!is_array($ledger)) $ledger = [];

$ledgerKey = $clipId . "|" . strtolower($user);
if (isset($ledger[$ledgerKey])) {
  $up = (int)($votes[$clipId]["up"] ?? 0);
  $down = (int)($votes[$clipId]["down"] ?? 0);
  if ($showFeedback) echo "Already voted for Clip #{$seq}. 👍{$up} 👎{$down}";
  exit;
}

if (!isset($votes[$clipId]) || !is_array($votes[$clipId])) $votes[$clipId] = ["up" => 0, "down" => 0];

$votes[$clipId][$dir] = (int)$votes[$clipId][$dir] + 1;
$ledger[$ledgerKey] = time();

@file_put_contents($votesFile, json_encode($votes, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
@file_put_contents($ledgerFile, json_encode($ledger, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);

$up = (int)$votes[$clipId]["up"];
$down = (int)$votes[$clipId]["down"];

if ($showFeedback) echo "Voted {$dir} for Clip #{$seq}. 👍{$up} 👎{$down}";
