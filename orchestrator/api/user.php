<?php
/**
 * Sprint 13 - User Profile & Onboarding API
 * Handles user profile management and onboarding status
 *
 * Endpoints:
 * - GET /api/user/profile - Get current user profile
 * - POST /api/user/onboarding-complete - Mark onboarding as completed
 * - PUT /api/user/profile - Update user profile
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/logging.php';
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rbac.php';

header('Content-Type: application/json; charset=utf-8');

// Apply middleware
$tenantContext = tenantMiddleware();
$user = rbacMiddleware(['teacher', 'admin', 'direction', 'intervenant', 'inspector']);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

try {
    // GET /api/user/profile - Get current user profile
    if ($method === 'GET' && preg_match('#/api/user/profile$#', $path)) {
        $stmt = db()->prepare("
            SELECT
                id,
                tenant_id,
                email,
                full_name,
                role,
                first_login,
                onboarding_completed,
                preferences,
                created_at,
                last_login_at
            FROM users
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$user['user_id'], $tenantContext['tenant_id']]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            exit;
        }

        // Parse JSON fields
        $profile['preferences'] = $profile['preferences'] ? json_decode($profile['preferences'], true) : [];
        $profile['first_login'] = (bool)$profile['first_login'];
        $profile['onboarding_completed'] = (bool)$profile['onboarding_completed'];

        logEvent('user_profile_viewed', [
            'user_id' => $user['user_id'],
            'tenant_id' => $tenantContext['tenant_id']
        ]);

        echo json_encode([
            'success' => true,
            'user' => $profile
        ]);
        exit;
    }

    // POST /api/user/onboarding-complete - Mark onboarding as completed
    if ($method === 'POST' && preg_match('#/api/user/onboarding-complete$#', $path)) {
        $stmt = db()->prepare("
            UPDATE users
            SET
                onboarding_completed = 1,
                first_login = 0,
                updated_at = NOW()
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$user['user_id'], $tenantContext['tenant_id']]);

        logEvent('onboarding_completed', [
            'user_id' => $user['user_id'],
            'tenant_id' => $tenantContext['tenant_id']
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Onboarding marked as completed'
        ]);
        exit;
    }

    // PUT /api/user/profile - Update user profile
    if ($method === 'PUT' && preg_match('#/api/user/profile$#', $path)) {
        $input = json_decode(file_get_contents('php://input'), true);

        $allowedFields = ['full_name', 'preferences'];
        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                if ($field === 'preferences') {
                    $updates[] = "preferences = ?";
                    $params[] = json_encode($input[$field]);
                } else {
                    $updates[] = "$field = ?";
                    $params[] = $input[$field];
                }
            }
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['error' => 'No valid fields to update']);
            exit;
        }

        $params[] = $user['user_id'];
        $params[] = $tenantContext['tenant_id'];

        $stmt = db()->prepare("
            UPDATE users
            SET " . implode(', ', $updates) . ", updated_at = NOW()
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute($params);

        logEvent('user_profile_updated', [
            'user_id' => $user['user_id'],
            'tenant_id' => $tenantContext['tenant_id'],
            'fields' => array_keys($input)
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully'
        ]);
        exit;
    }

    // Method not allowed
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Exception $e) {
    logError('User API error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
