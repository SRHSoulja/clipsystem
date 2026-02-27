<?php
/**
 * db_session_handler.php - PostgreSQL-backed PHP session handler
 *
 * Stores sessions in the database so they survive Railway deployments.
 * Replaces PHP's default file-based session storage.
 */

class DbSessionHandler implements SessionHandlerInterface {
  private ?PDO $pdo = null;

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
    $this->ensureTable();
  }

  private function ensureTable(): void {
    try {
      $this->pdo->exec("CREATE TABLE IF NOT EXISTS php_sessions (
        id VARCHAR(128) PRIMARY KEY,
        data TEXT NOT NULL DEFAULT '',
        last_access TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
      )");
      $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_php_sessions_access ON php_sessions(last_access)");
    } catch (PDOException $e) {
      error_log("Session table creation failed: " . $e->getMessage());
    }
  }

  public function open(string $path, string $name): bool {
    return true;
  }

  public function close(): bool {
    return true;
  }

  public function read(string $id): string|false {
    try {
      $stmt = $this->pdo->prepare("SELECT data FROM php_sessions WHERE id = ?");
      $stmt->execute([$id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      return $row ? $row['data'] : '';
    } catch (PDOException $e) {
      error_log("Session read failed: " . $e->getMessage());
      return '';
    }
  }

  public function write(string $id, string $data): bool {
    try {
      $stmt = $this->pdo->prepare("
        INSERT INTO php_sessions (id, data, last_access)
        VALUES (?, ?, NOW())
        ON CONFLICT (id) DO UPDATE SET
          data = EXCLUDED.data,
          last_access = NOW()
      ");
      $stmt->execute([$id, $data]);
      return true;
    } catch (PDOException $e) {
      error_log("Session write failed: " . $e->getMessage());
      return false;
    }
  }

  public function destroy(string $id): bool {
    try {
      $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE id = ?");
      $stmt->execute([$id]);
      return true;
    } catch (PDOException $e) {
      error_log("Session destroy failed: " . $e->getMessage());
      return false;
    }
  }

  public function gc(int $max_lifetime): int|false {
    try {
      $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE last_access < NOW() - INTERVAL '1 second' * ?");
      $stmt->execute([$max_lifetime]);
      return $stmt->rowCount();
    } catch (PDOException $e) {
      error_log("Session GC failed: " . $e->getMessage());
      return false;
    }
  }
}

/**
 * Initialize database session handler.
 * Call BEFORE session_start().
 * Returns true if DB sessions are active, false if falling back to files.
 */
function initDbSessions(): bool {
  static $initialized = false;
  if ($initialized) return true;

  $pdo = get_db_connection();
  if (!$pdo) {
    return false; // Fall back to file-based sessions
  }

  $handler = new DbSessionHandler($pdo);
  session_set_save_handler($handler, true);
  $initialized = true;
  return true;
}
