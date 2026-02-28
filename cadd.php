<?php
/**
 * cadd.php - Restore a previously removed clip by its seq number
 *
 * Mod-only command to un-block clips that were accidentally removed.
 * Uses PostgreSQL for persistent storage.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");
$seq   = (int)($_GET["seq"] ?? 0);
$key   = (string)($_GET["key"] ?? "");

// Load from environment (set ADMIN_KEY in Railway)
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

if ($ADMIN_KEY === '' || !hash_equals($ADMIN_KEY, (string)$key)) { http_response_code(403); echo "forbidden"; exit; }
if ($seq <= 0) { echo "Usage: !cadd <clip#>"; exit; }

// Try PostgreSQL
$pdo = get_db_connection();

if ($pdo) {
  try {
    // Look up clip by seq
    $stmt = $pdo->prepare("SELECT clip_id, title, blocked FROM clips WHERE login = ? AND seq = ?");
    $stmt->execute([$login, $seq]);
    $row = $stmt->fetch();

    if ($row) {
      if (!$row['blocked']) {
        echo "Clip #{$seq} is not blocked: " . ($row['title'] ?? "(no title)");
        exit;
      }

      // Unblock in clips table
      $stmt = $pdo->prepare("UPDATE clips SET blocked = FALSE WHERE login = ? AND seq = ?");
      $stmt->execute([$login, $seq]);

      // Remove from blocklist table
      $stmt = $pdo->prepare("DELETE FROM blocklist WHERE login = ? AND clip_id = ?");
      $stmt->execute([$login, $row['clip_id']]);

      // Get remaining blocked count
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ? AND blocked = TRUE");
      $stmt->execute([$login]);
      $count = (int)$stmt->fetchColumn();

      echo "Restored Clip #{$seq}: " . ($row['title'] ?? "(no title)") . " ({$count} still blocked)";
      exit;

    } else {
      // Get max seq for error message
      $stmt = $pdo->prepare("SELECT MAX(seq) FROM clips WHERE login = ?");
      $stmt->execute([$login]);
      $maxSeq = (int)$stmt->fetchColumn();
      if ($maxSeq > 0) {
        echo "Clip #{$seq} not found. Valid range: 1-{$maxSeq}";
        exit;
      }
      // Fall through to JSON if no clips in database
    }
  } catch (PDOException $e) {
    error_log("cadd db error: " . $e->getMessage());
    // Fall through to JSON
  }
}

// Fallback: JSON/file-based (limited functionality)
$staticDir = __DIR__ . "/cache";
$runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";

$blocklistFile = $runtimeDir . "/blocklist_" . $login . ".json";
if (!file_exists($blocklistFile)) {
  echo "No blocklist found for {$login}.";
  exit;
}

$blocklist = json_decode(@file_get_contents($blocklistFile), true);
if (!is_array($blocklist)) {
  echo "Invalid blocklist format.";
  exit;
}

// Find and remove by seq
$found = null;
$newBlocklist = [];
foreach ($blocklist as $b) {
  if (isset($b["seq"]) && (int)$b["seq"] === $seq) {
    $found = $b;
  } else {
    $newBlocklist[] = $b;
  }
}

if (!$found) {
  echo "Clip #{$seq} is not in the blocklist.";
  exit;
}

// Save updated blocklist
@file_put_contents($blocklistFile, json_encode($newBlocklist, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);

$count = count($newBlocklist);
$title = $found["title"] ?? "(no title)";
echo "Restored Clip #{$seq}: {$title} ({$count} still blocked)";
