<?php
/**
 * clear_all_votes.php - TEMPORARY: Clear all votes for a login
 * DELETE THIS FILE AFTER USE
 */
header("Content-Type: text/plain; charset=utf-8");

require_once __DIR__ . '/db_config.php';

$login = strtolower(trim($_GET["login"] ?? ""));
$key = $_GET["key"] ?? "";

$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

if ($key !== $ADMIN_KEY) {
  echo "forbidden";
  exit;
}

if ($login === "") {
  echo "missing login";
  exit;
}

$pdo = get_db_connection();
if (!$pdo) {
  echo "db error";
  exit;
}

try {
  // Clear vote_ledger
  $stmt = $pdo->prepare("DELETE FROM vote_ledger WHERE login = ?");
  $stmt->execute([$login]);
  $ledgerCount = $stmt->rowCount();

  // Clear votes
  $stmt = $pdo->prepare("DELETE FROM votes WHERE login = ?");
  $stmt->execute([$login]);
  $votesCount = $stmt->rowCount();

  echo "Cleared {$ledgerCount} ledger entries and {$votesCount} vote records for {$login}";
} catch (PDOException $e) {
  echo "Error: " . $e->getMessage();
}
