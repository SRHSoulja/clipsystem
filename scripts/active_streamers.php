<?php
/**
 * active_streamers.php - Derived metric: 7-day active streamer count
 *
 * Counts distinct streamers with at least one clip play in the last 7 days.
 * Uses the clip_plays table (last_played_at indexed per login).
 *
 * Usage:
 *   php scripts/active_streamers.php              # human-readable
 *   php scripts/active_streamers.php --json        # JSON output (cron-friendly)
 *   php scripts/active_streamers.php --list        # include streamer names
 *   php scripts/active_streamers.php --days 14     # custom window
 */

require_once __DIR__ . '/../db_config.php';

$args = getopt('', ['json', 'list', 'days:']);
$json_mode = isset($args['json']);
$show_list = isset($args['list']);
$days = isset($args['days']) ? max(1, (int)$args['days']) : 7;

$pdo = get_db_connection();
if (!$pdo) {
    if ($json_mode) {
        echo json_encode(['error' => 'No database connection', 'date' => gmdate('Y-m-d')]);
    } else {
        fwrite(STDERR, "Error: No database connection (DATABASE_URL not set)\n");
    }
    exit(1);
}

try {
    // Count distinct streamers with clip plays in the window
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT login) as active_count
        FROM clip_plays
        WHERE last_played_at > NOW() - (:days || ' days')::INTERVAL
    ");
    $stmt->execute([':days' => $days]);
    $count = (int)$stmt->fetchColumn();

    // Optionally fetch the list
    $streamers = [];
    if ($show_list) {
        $stmt = $pdo->prepare("
            SELECT login, MAX(last_played_at) as last_active, SUM(play_count) as total_plays
            FROM clip_plays
            WHERE last_played_at > NOW() - (:days || ' days')::INTERVAL
            GROUP BY login
            ORDER BY last_active DESC
        ");
        $stmt->execute([':days' => $days]);
        $streamers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $result = [
        'metric' => 'active_streamers',
        'count' => $count,
        'window_days' => $days,
        'date' => gmdate('Y-m-d'),
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if ($show_list) {
        $result['streamers'] = $streamers;
    }

    if ($json_mode) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "Active Streamers ({$days}-day window)\n";
        echo "  Count: {$count}\n";
        echo "  Date:  {$result['date']}\n";
        if ($show_list && $streamers) {
            echo "\n  Streamers:\n";
            foreach ($streamers as $s) {
                $plays = $s['total_plays'];
                $last = substr($s['last_active'], 0, 16);
                echo "    {$s['login']} — {$plays} plays, last active {$last}\n";
            }
        }
    }
} catch (PDOException $e) {
    if ($json_mode) {
        echo json_encode(['error' => $e->getMessage(), 'date' => gmdate('Y-m-d')]);
    } else {
        fwrite(STDERR, "Database error: " . $e->getMessage() . "\n");
    }
    exit(1);
}
