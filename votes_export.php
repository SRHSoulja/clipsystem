<?php
/**
 * votes_export.php - Export all vote data for weighting calculations
 *
 * Returns all clips with their vote counts, seq numbers, and titles.
 * Used for calculating weighted playback or analytics.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$baseDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/cache";

function clean_login($s){
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? "");

$result = [
  "login" => $login,
  "exported_at" => gmdate("c"),
  "votes" => []
];

// Try database first
$pdo = get_db_connection();

if ($pdo) {
  try {
    $stmt = $pdo->prepare("
      SELECT clip_id, seq, title, up_votes, down_votes,
             (up_votes - down_votes) as net_score,
             created_at, updated_at
      FROM votes
      WHERE login = ?
      ORDER BY seq ASC
    ");
    $stmt->execute([$login]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
      $result["votes"][] = [
        "clip_id" => $row["clip_id"],
        "seq" => (int)$row["seq"],
        "title" => $row["title"],
        "up" => (int)$row["up_votes"],
        "down" => (int)$row["down_votes"],
        "net_score" => (int)$row["net_score"],
        "first_vote" => $row["created_at"],
        "last_vote" => $row["updated_at"]
      ];
    }

    $result["source"] = "database";
    $result["total_clips_voted"] = count($rows);

  } catch (PDOException $e) {
    error_log("votes_export db error: " . $e->getMessage());
    // Fall through to file storage
  }
} else {
  // Fallback: File-based storage
  $votesFile = $baseDir . "/votes_" . $login . ".json";
  $indexFile = __DIR__ . "/cache/clips_index_" . $login . ".json";

  $votes = [];
  if (file_exists($votesFile)) {
    $votes = json_decode(@file_get_contents($votesFile), true);
    if (!is_array($votes)) $votes = [];
  }

  // Load index to get seq numbers and titles
  $index = [];
  if (file_exists($indexFile)) {
    $indexData = json_decode(@file_get_contents($indexFile), true);
    if (is_array($indexData) && isset($indexData["clips"])) {
      foreach ($indexData["clips"] as $c) {
        if (isset($c["id"])) {
          $index[$c["id"]] = [
            "seq" => (int)($c["seq"] ?? 0),
            "title" => $c["title"] ?? ""
          ];
        }
      }
    }
  }

  foreach ($votes as $clipId => $v) {
    $up = (int)($v["up"] ?? 0);
    $down = (int)($v["down"] ?? 0);
    $seq = isset($index[$clipId]) ? $index[$clipId]["seq"] : 0;
    $title = isset($index[$clipId]) ? $index[$clipId]["title"] : "";

    $result["votes"][] = [
      "clip_id" => $clipId,
      "seq" => $seq,
      "title" => $title,
      "up" => $up,
      "down" => $down,
      "net_score" => $up - $down
    ];
  }

  // Sort by seq
  usort($result["votes"], function($a, $b) {
    return $a["seq"] - $b["seq"];
  });

  $result["source"] = "file";
  $result["total_clips_voted"] = count($votes);
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
