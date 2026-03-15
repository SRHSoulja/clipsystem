<?php
/**
 * analytics.php - First-party funnel analytics for ClipTV
 *
 * Lightweight event tracking. No PII, no cookies, no third-party services.
 * Stores events to PostgreSQL if available, falls back to append-only log file.
 *
 * Usage:
 *   require_once __DIR__ . '/includes/analytics.php';
 *   track_event('page_load', ['page' => 'archive']);
 *
 * Events are:
 *   page_load       — any page view (with page name)
 *   clip_play       — a clip starts playing (with clip_id, channel)
 *   dashboard_visit — streamer dashboard accessed (with channel)
 *   discord_launch  — Discord activity app opened
 *   archive_browse  — archive page loaded (with channel if specified)
 *
 * Privacy: no user identification, no cookies, no IP storage.
 * Only event type, metadata, and timestamp are recorded.
 */

function track_event($event_type, $metadata = []) {
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $meta_json = json_encode($metadata, JSON_UNESCAPED_SLASHES);

    // Try PostgreSQL first
    try {
        require_once __DIR__ . '/../db_config.php';
        $pdo = get_db_connection();
        if ($pdo) {
            // Create table if not exists (idempotent)
            static $table_ensured = false;
            if (!$table_ensured) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_events (
                    id SERIAL PRIMARY KEY,
                    event_type VARCHAR(50) NOT NULL,
                    metadata JSONB DEFAULT '{}',
                    created_at TIMESTAMPTZ DEFAULT NOW()
                )");
                // Index for querying by type and time
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_analytics_type_time ON analytics_events (event_type, created_at DESC)");
                $table_ensured = true;
            }

            $stmt = $pdo->prepare("INSERT INTO analytics_events (event_type, metadata) VALUES (:type, :meta)");
            $stmt->execute([
                ':type' => $event_type,
                ':meta' => $meta_json,
            ]);
            return true;
        }
    } catch (Exception $e) {
        // Fall through to file-based logging
    }

    // Fallback: append to log file
    $log_dir = __DIR__ . '/../cache';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    $log_file = $log_dir . '/analytics.log';
    $line = "$timestamp\t$event_type\t$meta_json\n";
    @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
    return true;
}

/**
 * Get event counts for a given time range.
 * Returns array of [event_type => count].
 */
function get_event_counts($since_hours = 24) {
    try {
        require_once __DIR__ . '/../db_config.php';
        $pdo = get_db_connection();
        if ($pdo) {
            $stmt = $pdo->prepare("
                SELECT event_type, COUNT(*) as cnt
                FROM analytics_events
                WHERE created_at > NOW() - INTERVAL :hours HOUR
                GROUP BY event_type
                ORDER BY cnt DESC
            ");
            // PostgreSQL interval syntax
            $stmt = $pdo->prepare("
                SELECT event_type, COUNT(*) as cnt
                FROM analytics_events
                WHERE created_at > NOW() - (:hours || ' hours')::INTERVAL
                GROUP BY event_type
                ORDER BY cnt DESC
            ");
            $stmt->execute([':hours' => (int)$since_hours]);
            $counts = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $counts[$row['event_type']] = (int)$row['cnt'];
            }
            return $counts;
        }
    } catch (Exception $e) {
        // Fall through
    }

    // Fallback: parse log file
    $log_file = __DIR__ . '/../cache/analytics.log';
    if (!file_exists($log_file)) return [];

    $cutoff = time() - ($since_hours * 3600);
    $counts = [];
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = explode("\t", $line, 3);
        if (count($parts) < 2) continue;
        $ts = strtotime($parts[0]);
        if ($ts && $ts > $cutoff) {
            $type = $parts[1];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
    }
    arsort($counts);
    return $counts;
}
