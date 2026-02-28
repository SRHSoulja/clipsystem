<?php
/**
 * seq_export.php - Export canonical clip_id → seq mapping
 *
 * Run this ONCE after your initial fresh import to create a backup of seq assignments.
 * If you ever need to rebuild, use seq_import.php to restore these exact seq numbers.
 *
 * Usage:
 *   seq_export.php?login=floppyjimmie&key=YOUR_ADMIN_KEY
 *
 * Output:
 *   Downloads a JSON file: seq_map_floppyjimmie_YYYYMMDD.json
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

$pdo = get_db_connection();
if (!$pdo) {
    echo "ERROR: Could not connect to database.";
    exit(1);
}

// Export all clip_id → seq mappings
$stmt = $pdo->prepare("SELECT clip_id, seq, title, created_at FROM clips WHERE login = ? ORDER BY seq ASC");
$stmt->execute([$login]);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo "No clips found for login: $login";
    exit(1);
}

$export = [
    "login" => $login,
    "exported_at" => gmdate('c'),
    "count" => count($rows),
    "max_seq" => 0,
    "mappings" => []
];

foreach ($rows as $row) {
    $seq = (int)$row['seq'];
    $export['mappings'][$row['clip_id']] = [
        "seq" => $seq,
        "title" => $row['title'],
        "created_at" => $row['created_at']
    ];
    if ($seq > $export['max_seq']) {
        $export['max_seq'] = $seq;
    }
}

// Output as downloadable JSON
$filename = "seq_map_{$login}_" . date('Ymd') . ".json";
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo json_encode($export, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
