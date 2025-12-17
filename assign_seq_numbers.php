<?php
/**
 * One-time script to assign permanent seq numbers to all clips in the index.
 * Run once: php assign_seq_numbers.php login=floppyjimmie
 *
 * Clips are numbered 1 to N in chronological order (oldest = 1, newest = N).
 * This way new clips get higher numbers and old clips keep their numbers forever.
 */

$login = "floppyjimmie";
foreach ($argv as $arg) {
  if (strpos($arg, "login=") === 0) {
    $login = strtolower(trim(substr($arg, 6)));
  }
}

$login = preg_replace("/[^a-z0-9_]/", "", $login);
if (!$login) { echo "Invalid login\n"; exit(1); }

$indexFile = __DIR__ . "/cache/clips_index_{$login}.json";

if (!file_exists($indexFile)) {
  echo "Index file not found: {$indexFile}\n";
  exit(1);
}

$raw = file_get_contents($indexFile);
$data = json_decode($raw, true);

if (!$data || !isset($data["clips"]) || !is_array($data["clips"])) {
  echo "Invalid index file format\n";
  exit(1);
}

$clips = $data["clips"];
$total = count($clips);

echo "Found {$total} clips in index\n";

// Sort by created_at ascending (oldest first) so oldest clips get lowest numbers
usort($clips, function($a, $b) {
  $ta = strtotime($a["created_at"] ?? "1970-01-01");
  $tb = strtotime($b["created_at"] ?? "1970-01-01");
  return $ta - $tb;
});

// Assign seq numbers 1 to N
$assigned = 0;
foreach ($clips as $i => &$clip) {
  $clip["seq"] = $i + 1;
  $assigned++;
}
unset($clip);

echo "Assigned seq numbers 1 to {$assigned}\n";

// Update the data
$data["clips"] = $clips;
$data["seq_assigned"] = true;
$data["seq_assigned_at"] = gmdate("c");
$data["max_seq"] = $assigned;

// Write back
$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents($indexFile, $json, LOCK_EX);

echo "Index updated successfully!\n";
echo "Oldest clip: seq=1, id={$clips[0]['id']}, created={$clips[0]['created_at']}\n";
echo "Newest clip: seq={$assigned}, id={$clips[$assigned-1]['id']}, created={$clips[$assigned-1]['created_at']}\n";
