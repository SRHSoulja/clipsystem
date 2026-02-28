<?php
/**
 * seq_import.php - Restore canonical seq numbers from exported mapping
 *
 * Use this after a fresh rebuild to restore the original seq numbers.
 * This ensures votes and references stay valid even after rebuilding.
 *
 * Usage:
 *   1. Upload your seq_map_*.json file to /tmp/clipsystem_cache/seq_map.json on Railway
 *   2. Run: seq_import.php?login=floppyjimmie&key=YOUR_ADMIN_KEY
 *
 * Or provide the mapping inline (for smaller datasets):
 *   seq_import.php?login=floppyjimmie&key=YOUR_ADMIN_KEY&source=uploaded
 *
 * What it does:
 *   - Reads the seq mapping from the JSON file
 *   - Updates each clip's seq number to match the original
 *   - New clips (not in mapping) keep their assigned seq numbers
 */

header("Content-Type: text/plain; charset=utf-8");

// Load env
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
  foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    if (strpos($line, '=') === false) continue;
    list($k, $v) = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v));
  }
}

require_once __DIR__ . '/db_config.php';

// Auth check
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';
$key = $_GET['key'] ?? '';
if ($ADMIN_KEY === '' || !hash_equals($ADMIN_KEY, (string)$key)) {
    http_response_code(403);
    echo "Forbidden. Use ?key=YOUR_ADMIN_KEY";
    exit;
}

$login = strtolower(trim($_GET['login'] ?? 'floppyjimmie'));
$login = preg_replace('/[^a-z0-9_]/', '', $login);

echo "=== Seq Number Import ===\n";
echo "Login: $login\n\n";

// Look for the mapping file
$mapFile = "/tmp/clipsystem_cache/seq_map_{$login}.json";
if (!file_exists($mapFile)) {
    $mapFile = "/tmp/clipsystem_cache/seq_map.json";
}
if (!file_exists($mapFile)) {
    $mapFile = __DIR__ . "/cache/seq_map_{$login}.json";
}

if (!file_exists($mapFile)) {
    echo "ERROR: Seq mapping file not found.\n";
    echo "Upload your seq_map_*.json to /tmp/clipsystem_cache/seq_map.json\n";
    exit(1);
}

echo "Loading mapping from: $mapFile\n";
$mapData = json_decode(file_get_contents($mapFile), true);

if (!$mapData || !isset($mapData['mappings']) || !is_array($mapData['mappings'])) {
    echo "ERROR: Invalid mapping file format.\n";
    exit(1);
}

$mappings = $mapData['mappings'];
echo "Loaded " . count($mappings) . " seq mappings.\n";
echo "Original max_seq: " . ($mapData['max_seq'] ?? 'unknown') . "\n\n";

$pdo = get_db_connection();
if (!$pdo) {
    echo "ERROR: Could not connect to database.";
    exit(1);
}

// Get current clips
$stmt = $pdo->prepare("SELECT id, clip_id, seq FROM clips WHERE login = ?");
$stmt->execute([$login]);
$clips = $stmt->fetchAll();

echo "Found " . count($clips) . " clips in database.\n\n";

// Prepare update statement
$updateStmt = $pdo->prepare("UPDATE clips SET seq = ? WHERE id = ?");

$updated = 0;
$unchanged = 0;
$notInMap = 0;
$errors = 0;

// First pass: apply mappings
$pdo->beginTransaction();

// Temporarily disable the unique constraint by setting seq to negative
// This avoids conflicts during the update
$tempStmt = $pdo->prepare("UPDATE clips SET seq = -seq WHERE login = ? AND seq > 0");
$tempStmt->execute([$login]);

foreach ($clips as $clip) {
    $clipId = $clip['clip_id'];
    $currentSeq = abs((int)$clip['seq']);

    if (isset($mappings[$clipId])) {
        $targetSeq = (int)$mappings[$clipId]['seq'];

        if ($currentSeq !== $targetSeq) {
            try {
                $updateStmt->execute([$targetSeq, $clip['id']]);
                $updated++;
            } catch (PDOException $e) {
                $errors++;
                echo "Error updating $clipId: " . $e->getMessage() . "\n";
            }
        } else {
            // Restore positive seq
            $updateStmt->execute([$targetSeq, $clip['id']]);
            $unchanged++;
        }
    } else {
        // Clip not in mapping - it's new, restore its original seq
        $updateStmt->execute([$currentSeq, $clip['id']]);
        $notInMap++;
    }
}

$pdo->commit();

echo "=== Import Complete ===\n";
echo "Updated: $updated\n";
echo "Unchanged: $unchanged\n";
echo "New clips (not in map): $notInMap\n";
echo "Errors: $errors\n";

// Verify
$maxSeq = $pdo->query("SELECT MAX(seq) FROM clips WHERE login = " . $pdo->quote($login))->fetchColumn();
echo "\nCurrent max_seq in database: $maxSeq\n";

// Check for any negative seq (shouldn't happen)
$negCount = $pdo->query("SELECT COUNT(*) FROM clips WHERE login = " . $pdo->quote($login) . " AND seq < 0")->fetchColumn();
if ($negCount > 0) {
    echo "WARNING: $negCount clips still have negative seq numbers!\n";
}

echo "\n✅ Seq numbers restored from backup.\n";
