<?php
/**
 * add_thumbnail_column.php - Add thumbnail_url column to clips table
 *
 * Run once to add the column. Safe to run multiple times.
 *
 * Usage:
 *   Via browser: add_thumbnail_column.php?key=YOUR_ADMIN_KEY
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
if ($key !== $ADMIN_KEY) {
    http_response_code(403);
    echo "Forbidden. Use ?key=YOUR_ADMIN_KEY";
    exit;
}

echo "=== Add thumbnail_url column ===\n\n";

$pdo = get_db_connection();
if (!$pdo) {
    echo "ERROR: Could not connect to database.\n";
    exit(1);
}

echo "Connected to PostgreSQL.\n";

try {
    // Check if column exists
    $result = $pdo->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = 'clips' AND column_name = 'thumbnail_url'
    ");

    if ($result->fetch()) {
        echo "Column 'thumbnail_url' already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE clips ADD COLUMN thumbnail_url TEXT");
        echo "Added 'thumbnail_url' column to clips table.\n";
    }

    echo "\nDone!\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
