<?php
/**
 * metrics.php - Lightweight request instrumentation
 *
 * Include at the top of any endpoint to track request count and latency.
 * Data is stored in cache/metrics/ as hourly JSON bucket files.
 *
 * Usage: require_once __DIR__ . '/includes/metrics.php';
 *   (automatically detects endpoint from SCRIPT_FILENAME)
 *
 * Reading data: visit metrics_report.php or read cache/metrics/*.json
 *
 * Retention: 72 hours (auto-purged on write)
 */

define('METRICS_DIR', __DIR__ . '/../cache/metrics');
define('METRICS_START', microtime(true));
define('METRICS_RETENTION_HOURS', 72);

register_shutdown_function(function () {
    $endpoint = basename($_SERVER['SCRIPT_FILENAME'] ?? 'unknown');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
    $latency_ms = round((microtime(true) - METRICS_START) * 1000, 1);
    $bucket = date('Y-m-d-H');
    $file = METRICS_DIR . '/' . $bucket . '.json';

    if (!is_dir(METRICS_DIR)) {
        @mkdir(METRICS_DIR, 0755, true);
    }

    // Atomic read-modify-write with flock
    $fp = @fopen($file, 'c+');
    if (!$fp) return;

    if (flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        $data = $raw ? json_decode($raw, true) : [];
        if (!is_array($data)) $data = [];

        $key = $endpoint . ':' . $method;
        if (!isset($data[$key])) {
            $data[$key] = ['count' => 0, 'total_ms' => 0, 'max_ms' => 0];
        }

        $data[$key]['count']++;
        $data[$key]['total_ms'] = round($data[$key]['total_ms'] + $latency_ms, 1);
        if ($latency_ms > $data[$key]['max_ms']) {
            $data[$key]['max_ms'] = $latency_ms;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    // Purge old buckets ~1% of requests (low overhead)
    if (mt_rand(1, 100) === 1) {
        $cutoff = date('Y-m-d-H', strtotime('-' . METRICS_RETENTION_HOURS . ' hours'));
        foreach (glob(METRICS_DIR . '/????-??-??-??.json') as $old) {
            if (basename($old, '.json') < $cutoff) {
                @unlink($old);
            }
        }
    }
});
