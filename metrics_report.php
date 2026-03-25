<?php
/**
 * metrics_report.php - View endpoint instrumentation data
 *
 * Query params:
 *   hours=N   — show last N hours (default: 24, max: 72)
 *   format=json — return raw JSON instead of text table
 */
require_once __DIR__ . '/includes/helpers.php';

$hours = min(max((int)($_GET['hours'] ?? 24), 1), 72);
$format = $_GET['format'] ?? 'text';

$metrics_dir = '/tmp/cliptv/metrics';
if (!is_dir($metrics_dir)) {
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No metrics data yet']);
    } else {
        echo "No metrics data yet.\n";
    }
    exit;
}

// Collect buckets within the requested window
$cutoff = date('Y-m-d-H', strtotime("-{$hours} hours"));
$totals = [];    // endpoint:method => {count, total_ms, max_ms}
$hourly = [];    // bucket => endpoint:method => {count, total_ms, max_ms}

foreach (glob($metrics_dir . '/????-??-??-??.json') as $file) {
    $bucket = basename($file, '.json');
    if ($bucket < $cutoff) continue;

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) continue;

    foreach ($data as $key => $stats) {
        if (!isset($totals[$key])) {
            $totals[$key] = ['count' => 0, 'total_ms' => 0, 'max_ms' => 0];
        }
        $totals[$key]['count'] += $stats['count'];
        $totals[$key]['total_ms'] += $stats['total_ms'];
        if ($stats['max_ms'] > $totals[$key]['max_ms']) {
            $totals[$key]['max_ms'] = $stats['max_ms'];
        }

        $hourly[$bucket][$key] = $stats;
    }
}

// Sort by request count descending
arsort($totals);
uasort($totals, fn($a, $b) => $b['count'] - $a['count']);
ksort($hourly);

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'window_hours' => $hours,
        'generated_at' => date('c'),
        'totals' => $totals,
        'hourly' => $hourly,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Text table output
header('Content-Type: text/plain');
echo "=== ClipTV Endpoint Metrics (last {$hours}h) ===\n";
echo "Generated: " . date('Y-m-d H:i:s T') . "\n\n";

if (empty($totals)) {
    echo "No data in this window.\n";
    exit;
}

printf("%-35s %8s %10s %8s\n", "Endpoint", "Requests", "Avg (ms)", "Max (ms)");
printf("%-35s %8s %10s %8s\n", str_repeat('-', 35), str_repeat('-', 8), str_repeat('-', 10), str_repeat('-', 8));

foreach ($totals as $key => $stats) {
    $avg = $stats['count'] > 0 ? round($stats['total_ms'] / $stats['count'], 1) : 0;
    printf("%-35s %8d %10.1f %8.1f\n", $key, $stats['count'], $avg, $stats['max_ms']);
}

echo "\n--- Hourly breakdown ---\n\n";
foreach ($hourly as $bucket => $endpoints) {
    echo "[{$bucket}]\n";
    foreach ($endpoints as $key => $stats) {
        $avg = $stats['count'] > 0 ? round($stats['total_ms'] / $stats['count'], 1) : 0;
        printf("  %-33s %6d reqs, avg %6.1f ms, max %6.1f ms\n", $key, $stats['count'], $avg, $stats['max_ms']);
    }
}
