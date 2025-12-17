<?php
/**
 * vote_submit.php - Record a vote for a clip by its permanent seq number
 *
 * Looks up the clip by seq from the full index, so ANY clip can be voted on.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$baseDir = __DIR__ . "/cache";

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

// Look up clip by seq from the full index (not just recent)
$indexFile = $baseDir . "/clips_index_" . $login . ".json";
if (!file_exists($indexFile)) { echo "Clip index not found."; exit; }

$indexRaw = @file_get_contents($indexFile);
$indexData = $indexRaw ? json_decode($indexRaw, true) : null;
if (!is_array($indexData) || !isset($indexData["clips"]) || !is_array($indexData["clips"])) {
  echo "Invalid clip index.";
  exit;
}

$clipId = null;
$clipTitle = "";
foreach ($indexData["clips"] as $c) {
  if (isset($c["seq"]) && (int)$c["seq"] === $seq) {
    $clipId = isset($c["id"]) ? (string)$c["id"] : null;
    $clipTitle = isset($c["title"]) ? $c["title"] : "";
    break;
  }
}

if (!$clipId) {
  $maxSeq = isset($indexData["max_seq"]) ? (int)$indexData["max_seq"] : count($indexData["clips"]);
  echo "Clip #{$seq} not found. Valid range: 1-{$maxSeq}";
  exit;
}

$votesFile  = $baseDir . "/votes_" . $login . ".json";
$ledgerFile = $baseDir . "/votes_ledger_" . $login . ".json";

$votes = file_exists($votesFile) ? json_decode(@file_get_contents($votesFile), true) : [];
if (!is_array($votes)) $votes = [];

$ledger = file_exists($ledgerFile) ? json_decode(@file_get_contents($ledgerFile), true) : [];
if (!is_array($ledger)) $ledger = [];

$ledgerKey = $clipId . "|" . strtolower($user);
if (isset($ledger[$ledgerKey])) {
  $up = (int)($votes[$clipId]["up"] ?? 0);
  $down = (int)($votes[$clipId]["down"] ?? 0);
  echo "Already voted for Clip #{$seq}. 👍{$up} 👎{$down}";
  exit;
}

if (!isset($votes[$clipId]) || !is_array($votes[$clipId])) $votes[$clipId] = ["up" => 0, "down" => 0];

$votes[$clipId][$dir] = (int)$votes[$clipId][$dir] + 1;
$ledger[$ledgerKey] = time();

@file_put_contents($votesFile, json_encode($votes, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
@file_put_contents($ledgerFile, json_encode($ledger, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);

$up = (int)$votes[$clipId]["up"];
$down = (int)$votes[$clipId]["down"];

echo "Voted {$dir} for Clip #{$seq}. 👍{$up} 👎{$down}";
