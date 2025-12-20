<?php
/**
 * helpers.php - Shared utility functions for the clip system
 *
 * Consolidates common functions to reduce code duplication and ensure consistency.
 */

/**
 * Clean and validate a login/channel name
 * Returns lowercase alphanumeric + underscore only, or "default" if empty
 */
function clean_login($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace("/[^a-z0-9_]/", "", $s);
    return $s ?: "default";
}

/**
 * Clean and validate a username
 * Same rules as login but allows empty string
 */
function clean_user($s) {
    $s = strtolower(trim((string)$s));
    return preg_replace("/[^a-z0-9_]/", "", $s);
}

/**
 * Clean and validate a seq number
 * Returns positive integer or 0
 */
function clean_seq($s) {
    return max(0, (int)$s);
}

/**
 * Check admin authentication using timing-safe comparison
 * Exits with 403 if authentication fails
 */
function require_admin_auth() {
    $key = $_GET['key'] ?? $_POST['key'] ?? '';
    $adminKey = getenv('ADMIN_KEY') ?: '';

    if ($adminKey === '' || !hash_equals($adminKey, (string)$key)) {
        http_response_code(403);
        echo "forbidden";
        exit;
    }
}

/**
 * Check admin authentication without exiting
 * Returns true if authenticated, false otherwise
 */
function check_admin_auth() {
    $key = $_GET['key'] ?? $_POST['key'] ?? '';
    $adminKey = getenv('ADMIN_KEY') ?: '';

    return $adminKey !== '' && hash_equals($adminKey, (string)$key);
}

/**
 * Send a JSON response with proper headers
 */
function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a JSON error response
 */
function json_error($message, $statusCode = 400) {
    json_response(['error' => $message], $statusCode);
}

/**
 * Get the runtime cache directory (writable location for temp files)
 * Returns /tmp/clipsystem_cache on Railway, or local cache folder otherwise
 */
function get_runtime_dir() {
    $dir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/../cache";
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

/**
 * Set standard CORS headers for API endpoints
 */
function set_cors_headers() {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
}

/**
 * Handle OPTIONS preflight request
 */
function handle_options_request() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }
}

/**
 * Set no-cache headers for polling endpoints
 */
function set_nocache_headers() {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
}
