<?php
/**
 * admin_api.php - Admin API endpoints
 *
 * Handles admin-only API requests like managing archive applications.
 * Requires super admin authentication.
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/twitch_oauth.php';

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

// Check authentication
$currentUser = getCurrentUser();
if (!$currentUser || !isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database unavailable']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list_applications':
        try {
            // Ensure table exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS archive_applications (
                    id SERIAL PRIMARY KEY,
                    twitch_login VARCHAR(64) NOT NULL,
                    twitch_display_name VARCHAR(64),
                    twitch_id VARCHAR(32),
                    profile_image_url TEXT,
                    reason TEXT,
                    follower_count INTEGER DEFAULT 0,
                    average_viewers INTEGER DEFAULT 0,
                    streaming_years DECIMAL(3,1) DEFAULT 0,
                    status VARCHAR(20) DEFAULT 'pending',
                    admin_notes TEXT,
                    reviewed_by VARCHAR(64),
                    reviewed_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Get all applications (pending first, then recent)
            $stmt = $pdo->query("
                SELECT * FROM archive_applications
                ORDER BY
                    CASE status WHEN 'pending' THEN 0 ELSE 1 END,
                    created_at DESC
                LIMIT 100
            ");
            $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'applications' => $applications
            ]);
        } catch (PDOException $e) {
            error_log("admin_api list_applications error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
        break;

    case 'approve_application':
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid application ID']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE archive_applications
                SET status = 'approved',
                    reviewed_by = ?,
                    reviewed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$currentUser['login'], $id]);

            echo json_encode(['success' => true, 'message' => 'Application approved']);
        } catch (PDOException $e) {
            error_log("admin_api approve_application error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
        break;

    case 'deny_application':
        $id = (int)($_GET['id'] ?? 0);
        $notes = trim($_GET['notes'] ?? '');

        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid application ID']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE archive_applications
                SET status = 'denied',
                    admin_notes = ?,
                    reviewed_by = ?,
                    reviewed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$notes ?: null, $currentUser['login'], $id]);

            echo json_encode(['success' => true, 'message' => 'Application denied']);
        } catch (PDOException $e) {
            error_log("admin_api deny_application error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
