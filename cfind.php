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
header("Pragma: no-cache");
header("Expires: 0");
header("Vary: *");

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

// If no query provided, just return the search page link
$baseUrl = getenv('API_BASE_URL') ?: 'https://clipsystem-production.up.railway.app';
if (strlen($query) < 2) {
  echo "Search clips: {$baseUrl}/clip_search.php?login=" . urlencode($login);
  exit;
}

// Split query into words for multi-word search
$queryWords = preg_split('/\s+/', trim($query));
$queryWords = array_filter($queryWords, function($w) { return strlen($w) >= 2; });
$queryWords = array_values($queryWords); // Re-index to ensure sequential keys

// Search in PostgreSQL - separate counts for title vs clipper
$pdo = get_db_connection();
$titleCount = 0;
$clipperCount = 0;

// Log request details for debugging
$serverInfo = gethostname() . ':' . getmypid();
error_log("cfind [$serverInfo] request: login=$login, query='$query', words=" . json_encode($queryWords));

if (!$pdo) {
  error_log("cfind [$serverInfo] ERROR: No database connection");
}

if ($pdo && !empty($queryWords)) {
  try {
    // Count clips matching in TITLE
    $titleWhere = ["login = ?", "blocked = FALSE"];
    $titleParams = [$login];
    foreach ($queryWords as $word) {
      $titleWhere[] = "title ILIKE ?";
      $titleParams[] = '%' . $word . '%';
    }
    $titleSQL = implode(' AND ', $titleWhere);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE {$titleSQL}");
    $stmt->execute($titleParams);
    $titleCount = (int)$stmt->fetchColumn();

    // Count clips matching in CREATOR_NAME (clipper)
    $clipperWhere = ["login = ?", "blocked = FALSE"];
    $clipperParams = [$login];
    foreach ($queryWords as $word) {
      $clipperWhere[] = "creator_name ILIKE ?";
      $clipperParams[] = '%' . $word . '%';
    }
    $clipperSQL = implode(' AND ', $clipperWhere);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE {$clipperSQL}");
    $stmt->execute($clipperParams);
    $clipperCount = (int)$stmt->fetchColumn();

    error_log("cfind [$serverInfo] title=$titleCount, clipper=$clipperCount");
  } catch (PDOException $e) {
    error_log("cfind [$serverInfo] DB ERROR: " . $e->getMessage());
  }
} else {
  error_log("cfind [$serverInfo] skip: pdo=" . ($pdo ? "yes" : "no") . ", words=" . count($queryWords));
}

// If no results at all
if ($titleCount === 0 && $clipperCount === 0) {
  echo "No clips found matching \"{$query}\"";
  exit;
}

// Build search URLs
$baseUrl = getenv('API_BASE_URL') ?: 'https://clipsystem-production.up.railway.app';
$titleUrl = $baseUrl . '/clip_search.php?login=' . urlencode($login) . '&q=' . urlencode($query);
$clipperUrl = $baseUrl . '/clip_search.php?login=' . urlencode($login) . '&clipper=' . urlencode($query);

// Build response - show both title and clipper results if applicable
$parts = [];

if ($titleCount > 0) {
  $parts[] = "{$titleCount} in titles: {$titleUrl}";
}

if ($clipperCount > 0) {
  $parts[] = "{$clipperCount} by clipper: {$clipperUrl}";
}

echo implode(' | ', $parts);
