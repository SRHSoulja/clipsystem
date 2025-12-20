<?php
/**
 * pclip.php - Force play a clip by its permanent seq number
 *
 * Looks up the clip by seq from PostgreSQL (fast indexed lookup).
 * Falls back to JSON file if database unavailable.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/dashboard_auth.php';
require_once __DIR__ . '/db_config.php';

set_cors_headers();
handle_options_request();
set_nocache_headers();
header("Content-Type: text/plain; charset=utf-8");

// Static data (clips_index) is in ./cache (read-only on Railway)
$staticDir = __DIR__ . "/cache";
// Runtime data (force_play) goes to /tmp on Railway
$runtimeDir = get_runtime_dir();

$login = clean_login($_GET["login"] ?? "");
$seq   = (int)($_GET["seq"] ?? 0);

require_admin_auth();

// Get streamer's instance for command isolation
$auth = new DashboardAuth();
$instance = $auth->getStreamerInstance($login) ?: "";
if ($seq <= 0) { echo "Usage: !pclip <clip#>"; exit; }

// Try PostgreSQL first (fast indexed lookup)
$clip = null;
$maxSeq = 0;
$pdo = get_db_connection();

if ($pdo) {
  try {
    $stmt = $pdo->prepare("SELECT clip_id, title, duration, blocked, creator_name FROM clips WHERE login = ? AND seq = ?");
    $stmt->execute([$login, $seq]);
    $row = $stmt->fetch();

    if ($row) {
      if ($row['blocked']) {
        echo "Clip #{$seq} has been removed.";
        exit;
      }
      $clip = [
        'id' => $row['clip_id'],
        'title' => $row['title'],
        'duration' => $row['duration'] ? (float)$row['duration'] : 30,
        'creator_name' => $row['creator_name'] ?? ''
      ];
    } else {
      // Get max seq for error message
      $stmt = $pdo->prepare("SELECT MAX(seq) FROM clips WHERE login = ?");
      $stmt->execute([$login]);
      $maxSeq = (int)$stmt->fetchColumn();
    }
  } catch (PDOException $e) {
    error_log("pclip db error: " . $e->getMessage());
    // Fall through to JSON
  }
}

// Fallback to JSON if database didn't find it
if (!$clip && !$maxSeq) {
  $indexFile = $staticDir . "/clips_index_" . $login . ".json";
  if (!file_exists($indexFile)) {
    echo "Clip index not found.";
    exit;
  }

  $raw = @file_get_contents($indexFile);
  if (!$raw) { echo "Could not read clip index."; exit; }

  $data = json_decode($raw, true);
  if (!is_array($data) || !isset($data["clips"]) || !is_array($data["clips"])) {
    echo "Invalid clip index format.";
    exit;
  }

  // Find clip by seq number
  foreach ($data["clips"] as $c) {
    if (isset($c["seq"]) && (int)$c["seq"] === $seq) {
      $clip = $c;
      break;
    }
  }

  $maxSeq = isset($data["max_seq"]) ? (int)$data["max_seq"] : count($data["clips"]);
}

if (!$clip) {
  echo "Clip #{$seq} not found. Valid range: 1-{$maxSeq}";
  exit;
}

$clipId = (string)($clip["id"] ?? $clip["clip_id"] ?? "");
if ($clipId === "") { echo "Clip #{$seq} missing id."; exit; }

// Build a full clip object for the player (in case clip isn't in current pool)
$clipObject = [
  "id" => $clipId,
  "seq" => $seq,
  "title" => $clip["title"] ?? "",
  "duration" => $clip["duration"] ?? 30,
  "creator_name" => $clip["creator_name"] ?? "",
];

$payload = json_encode([
  "login"    => $login,
  "seq"      => $seq,
  "clip_id"  => $clipId,
  "title"    => $clip["title"] ?? "",
  "duration" => $clip["duration"] ?? 30,
  "creator_name" => $clip["creator_name"] ?? "",
  "clip"     => $clipObject,  // Include full clip object for player fallback
  "nonce"    => (string)(time() . "_" . bin2hex(random_bytes(4))),
  "set_at"   => gmdate("c"),
], JSON_UNESCAPED_SLASHES);

// Write to BOTH generic and instance-specific paths
// Always write generic file (for basic sources)
$genericPath = $runtimeDir . "/force_play_" . $login . ".json";
@file_put_contents($genericPath, $payload, LOCK_EX);

// Also write instance-specific file if streamer has instance
if ($instance) {
  $instancePath = $runtimeDir . "/force_play_" . $login . "_" . $instance . ".json";
  @file_put_contents($instancePath, $payload, LOCK_EX);
}

$title = $clip["title"] ?? "(no title)";
echo "Playing Clip #{$seq}: {$title}";
