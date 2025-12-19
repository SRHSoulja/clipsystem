<?php
/**
 * cfind.php - Search clips by title
 *
 * Mod command to find and play clips matching a search query.
 * Returns best match and plays it, shows count of total matches.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$runtimeDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";
if (!is_dir($runtimeDir)) @mkdir($runtimeDir, 0777, true);

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");
$query = trim((string)($_GET["q"] ?? ""));
$key   = (string)($_GET["key"] ?? "");

// Load from environment
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

if ($key !== $ADMIN_KEY) { http_response_code(403); echo "forbidden"; exit; }
if (strlen($query) < 2) { echo "Usage: !cfind <search term>"; exit; }

// Search in PostgreSQL
$pdo = get_db_connection();
$matches = [];

if ($pdo) {
  try {
    // Case-insensitive search using ILIKE
    $stmt = $pdo->prepare("
      SELECT seq, clip_id, title, view_count
      FROM clips
      WHERE login = ? AND blocked = FALSE AND title ILIKE ?
      ORDER BY view_count DESC
      LIMIT 20
    ");
    $stmt->execute([$login, '%' . $query . '%']);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    error_log("cfind db error: " . $e->getMessage());
  }
}

// Fallback to JSON if database empty
if (empty($matches)) {
  $indexFile = __DIR__ . "/cache/clips_index_{$login}.json";
  if (file_exists($indexFile)) {
    $raw = @file_get_contents($indexFile);
    $data = $raw ? json_decode($raw, true) : null;
    if (is_array($data) && isset($data['clips'])) {
      $queryLower = strtolower($query);
      foreach ($data['clips'] as $c) {
        $title = $c['title'] ?? '';
        if (stripos($title, $query) !== false) {
          $matches[] = [
            'seq' => $c['seq'] ?? 0,
            'clip_id' => $c['id'] ?? '',
            'title' => $title,
            'view_count' => $c['view_count'] ?? 0
          ];
        }
      }
      // Sort by view count desc
      usort($matches, function($a, $b) {
        return ($b['view_count'] ?? 0) - ($a['view_count'] ?? 0);
      });
      $matches = array_slice($matches, 0, 20);
    }
  }
}

if (empty($matches)) {
  echo "No clips found matching \"{$query}\"";
  exit;
}

// Score matches - prefer exact word matches and title start matches
$queryLower = strtolower($query);
$queryWords = preg_split('/\s+/', $queryLower);

foreach ($matches as &$m) {
  $titleLower = strtolower($m['title'] ?? '');
  $score = 0;

  // Exact match bonus
  if ($titleLower === $queryLower) {
    $score += 1000;
  }

  // Starts with query bonus
  if (strpos($titleLower, $queryLower) === 0) {
    $score += 500;
  }

  // Word boundary match bonus (query appears as whole word)
  if (preg_match('/\b' . preg_quote($queryLower, '/') . '\b/i', $titleLower)) {
    $score += 200;
  }

  // Multiple query words matching
  foreach ($queryWords as $word) {
    if (strlen($word) >= 2 && stripos($titleLower, $word) !== false) {
      $score += 50;
    }
  }

  // View count tiebreaker
  $score += min(100, ($m['view_count'] ?? 0) / 1000);

  $m['score'] = $score;
}
unset($m);

// Sort by score descending
usort($matches, function($a, $b) {
  return $b['score'] - $a['score'];
});

$best = $matches[0];
$count = count($matches);

// Force play the best match
$forcePath = $runtimeDir . "/force_play_" . $login . ".json";
$payload = [
  "login"    => $login,
  "seq"      => (int)$best['seq'],
  "clip_id"  => $best['clip_id'],
  "title"    => $best['title'] ?? "",
  "nonce"    => (string)(time() . "_" . bin2hex(random_bytes(4))),
  "set_at"   => gmdate("c"),
];
@file_put_contents($forcePath, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);

// Format response based on match count
$title = $best['title'] ?? "(no title)";
$seq = (int)$best['seq'];

if ($count === 1) {
  echo "Playing #{$seq}: {$title}";
} elseif ($count <= 3) {
  // Show all seq numbers
  $seqs = array_map(function($m) { return '#' . $m['seq']; }, $matches);
  echo "Found " . implode(', ', $seqs) . " - Playing #{$seq}: {$title}";
} else {
  echo "Found {$count} clips - Playing #{$seq}: {$title}";
}
