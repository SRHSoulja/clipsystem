<?php
/**
 * analytics_track.php - Lightweight event tracking endpoint
 *
 * Accepts POST with event_type and optional metadata.
 * Used by JavaScript frontends (Discord activity, player) to log events.
 * No PII. No cookies. No user identification.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "POST only"]);
    exit;
}

require_once __DIR__ . '/includes/analytics.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['event_type'])) {
    http_response_code(400);
    echo json_encode(["error" => "event_type required"]);
    exit;
}

$event_type = preg_replace('/[^a-z0-9_]/', '', strtolower($input['event_type']));
$metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];

// Sanitize metadata values — strings only, max 200 chars each
$clean_meta = [];
foreach ($metadata as $k => $v) {
    $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$k));
    if ($key && is_string($v)) {
        $clean_meta[$key] = substr($v, 0, 200);
    }
}

track_event($event_type, $clean_meta);
echo json_encode(["ok" => true, "event" => $event_type]);
