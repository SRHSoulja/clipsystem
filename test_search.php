<?php
/**
 * test_search.php - Direct test of database search
 *
 * Usage: test_search.php?login=floppyjimmie&q=11+mario
 */
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store");

require_once __DIR__ . '/db_config.php';

$login = strtolower(preg_replace('/[^a-z0-9_]/', '', $_GET['login'] ?? 'floppyjimmie'));
$query = trim($_GET['q'] ?? '');

echo "=== Test Search ===\n";
echo "Server: " . gethostname() . ":" . getmypid() . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "Login: $login\n";
echo "Query: $query\n\n";

$pdo = get_db_connection();
if (!$pdo) {
    echo "ERROR: No database connection\n";
    exit;
}

echo "Database: Connected\n\n";

// Parse query words
$queryWords = preg_split('/\s+/', trim($query));
$queryWords = array_filter($queryWords, function($w) { return strlen($w) >= 2; });
$queryWords = array_values($queryWords);

echo "Query words: " . json_encode($queryWords) . "\n\n";

if (empty($queryWords)) {
    echo "ERROR: No valid query words\n";
    exit;
}

// Build query
$whereClauses = ["login = ?", "blocked = FALSE"];
$params = [$login];
foreach ($queryWords as $word) {
    $whereClauses[] = "title ILIKE ?";
    $params[] = '%' . $word . '%';
}
$whereSQL = implode(' AND ', $whereClauses);

echo "SQL WHERE: $whereSQL\n";
echo "Params: " . json_encode($params) . "\n\n";

// Execute
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE {$whereSQL}");
    $stmt->execute($params);
    $count = (int)$stmt->fetchColumn();
    echo "Result count: $count\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
