<?php
/**
 * now_playing_set.php - Track the currently playing clip
 *
 * Looks up the clip's permanent seq number from the index.
 * This ensures voting and pclip always reference the same clip numbers.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function safe_login($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

// Accept both GET and POST (GET may avoid some host rate-limiting)
$input = array_merge($_GET, $_POST);
$login  = isset($input["login"]) ? safe_login($input["login"]) : "";
$url    = isset($input["url"]) ? trim((string)$input["url"]) : "";
$slug   = isset($input["slug"]) ? trim((string)$input["slug"]) : "";
$clipId = isset($input["clip_id"]) ? trim((string)$input["clip_id"]) : "";

if ($login === "") { http_response_code(400); echo "missing login"; exit; }

// Build url if only slug provided (legacy)
if ($url === "" && $slug !== "") {
  $url = "https://www.twitch.tv/{$login}/clip/{$slug}";
}

// If no clip_id provided, fall back to slug as clip_id (legacy-safe)
if ($clipId === "" && $slug !== "") {
  $clipId = $slug;
}

if ($clipId === "") {
  http_response_code(400);
  echo "missing clip_id (or slug)";
  exit;
}

// Build url from clip_id if not provided
if ($url === "" && $clipId !== "") {
  $url = "https://clips.twitch.tv/" . $clipId;
}

require_once __DIR__ . '/db_config.php';

// Static data (clips_index) is in ./cache (read-only on Railway)
$staticDir = __DIR__ . "/cache";
// Runtime data goes to /tmp on Railway
$runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";
if (!is_dir($runtimeDir)) @mkdir($runtimeDir, 0777, true);

$jsonPath   = $runtimeDir . "/now_playing_{$login}.json";
$txtPath    = $runtimeDir . "/now_playing_{$login}.txt";
$recentPath = $runtimeDir . "/recent_played_{$login}.json";

// Look up the clip's permanent seq and title
$seq = 0;
$title = "";

// Try database first (preferred source)
$pdo = get_db_connection();
if ($pdo) {
  try {
    $stmt = $pdo->prepare("SELECT seq, title FROM clips WHERE login = ? AND clip_id = ?");
    $stmt->execute([$login, $clipId]);
    $row = $stmt->fetch();
    if ($row) {
      $seq = (int)$row['seq'];
      $title = $row['title'] ?: '';
    }
  } catch (PDOException $e) {
    error_log("now_playing_set db lookup error: " . $e->getMessage());
  }
}

// Fallback to JSON index if not in database
if ($seq === 0) {
  $indexFile = $staticDir . "/clips_index_{$login}.json";
  if (file_exists($indexFile)) {
    $indexRaw = @file_get_contents($indexFile);
    $indexData = $indexRaw ? json_decode($indexRaw, true) : null;
    if (is_array($indexData) && isset($indexData["clips"]) && is_array($indexData["clips"])) {
      foreach ($indexData["clips"] as $c) {
        if (isset($c["id"]) && $c["id"] === $clipId) {
          $seq = isset($c["seq"]) ? (int)$c["seq"] : 0;
          $title = isset($c["title"]) ? $c["title"] : "";
          break;
        }
      }
    }
  }
}

// Fallback: if clip not in index (maybe new?), use a counter
if ($seq === 0) {
  $seqFile = $runtimeDir . "/seq_{$login}.txt";
  $fp = @fopen($seqFile, "c+");
  if ($fp) {
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $seq = (int)trim($raw);
    $seq = max(0, $seq) + 1;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)$seq);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
  } else {
    $seq = time();
  }
}

$now = time();

$entry = [
  "login"      => $login,
  "seq"        => $seq,
  "clip_id"    => $clipId,
  "url"        => $url,
  "slug"       => $slug,
  "title"      => $title,
  "updated_at" => gmdate("c"),
  "started_at" => $now
];

// Ensure runtime directory exists and is writable
if (!is_dir($runtimeDir)) {
  if (!@mkdir($runtimeDir, 0777, true)) {
    http_response_code(500);
    echo "Failed to create cache directory: $runtimeDir";
    exit;
  }
}

// Write current now playing
$writeResult = file_put_contents($jsonPath, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
if ($writeResult === false) {
  http_response_code(500);
  echo "Failed to write to: $jsonPath - is_writable: " . (is_writable($runtimeDir) ? "yes" : "no");
  exit;
}
@file_put_contents($txtPath, $url, LOCK_EX);

// Append to recent list
$recent = [];
if (file_exists($recentPath)) {
  $d = json_decode(@file_get_contents($recentPath), true);
  if (is_array($d)) $recent = $d;
}

$recent[] = $entry;
$maxKeep = 40;
if (count($recent) > $maxKeep) $recent = array_slice($recent, -$maxKeep);

@file_put_contents($recentPath, json_encode($recent, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);

echo "ok";
