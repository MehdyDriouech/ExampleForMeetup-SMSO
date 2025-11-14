<?php
/**
 * Admin Users API - Sprint 14
 *
 * Endpoints:
 * - GET    /api/admin/users          - List all users in tenant
 * - POST   /api/admin/users          - Create a new user
 * - GET    /api/admin/users/:id      - Get user details
 * - PATCH  /api/admin/users/:id      - Update user
 * - PATCH  /api/admin/users/:id/status - Change user status (activate/deactivate)
 *
 * @version 1.0
 * @date 2025-11-14
 */

require_once __DIR__ . '/../../.env.php';
require_once __DIR__ . '/../_middleware_tenant.php';
require_once __DIR__ . '/../_middleware_rbac.php';
require_once __DIR__ . '/../../services/audit_log.php';
require_once __DIR__ . '/../../services/mailer.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// Parse path to get user ID if present
$requestUri = $_SERVER['REQUEST_URI'];
$pathParts = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));
$userId = null;
$action = null;

// Extract user ID and action from path
// /api/admin/users/:id or /api/admin/users/:id/status
if (count($pathParts) >= 4 && $pathParts[0] === 'api' && $pathParts[1] === 'admin' && $pathParts[2] === 'users') {
    if (isset($pathParts[3]) && $pathParts[3] !== '') {
        $userId = $pathParts[3];
    }
    if (isset($pathParts[4])) {
        $action = $pathParts[4]; // e.g., 'status'
    }
}

// Authentication & Authorization
$auth = requireAuth();
$tenantContext = enforceTenantIsolation();
enforceTenantAuthMatch($tenantContext, $auth);
$rbac = enforceRBAC($auth);

$tenantId = $tenantContext->getTenantId();
$auditLog = createAuditLog($tenantId, $auth);

// ============================================================
// GET /api/admin/users - List users
// ============================================================
if ($method === 'GET' && !$userId) {
    $rbac->requirePermission('users', 'read');

    try {
        $filters = [];
        $params = ['tenant_id' => $tenantId];

        // Filter by role
        if (isset($_GET['role']) && !empty($_GET['role'])) {
            $filters[] = 'role = :role';
            $params['role'] = $_GET['role'];
        }

        // Filter by status
        if (isset($_GET['status']) && !empty($_GET['status'])) {
            $filters[] = 'status = :status';
            $params['status'] = $_GET['status'];
        }

        // Search by name or email
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $search = '%' . $_GET['search'] . '%';
            $filters[] = '(email LIKE :search OR firstname LIKE :search OR lastname LIKE :search)';
            $params['search'] = $search;
        }

        $whereClause = 'tenant_id = :tenant_id';
        if (count($filters) > 0) {
            $whereClause .= ' AND ' . implode(' AND ', $filters);
        }

        // Get users with their class assignments
        $sql = "SELECT u.*,
                       GROUP_CONCAT(DISTINCT uca.class_id) as class_ids,
                       GROUP_CONCAT(DISTINCT c.name) as class_names
                FROM users u
                LEFT JOIN user_class_assignments uca ON u.id = uca.user_id
                LEFT JOIN classes c ON uca.class_id = c.id
                WHERE {$whereClause}
                GROUP BY u.id
                ORDER BY u.created_at DESC";

        $users = db()->query($sql, $params);

        // Format response
        $result = array_map(function($user) {
            return [
                'id' => $user['id'],
                'email' => $user['email'],
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname'],
                'role' => $user['role'],
                'status' => $user['status'],
                'lastLoginAt' => $user['last_login_at'],
                'deactivatedAt' => $user['deactivated_at'],
                'createdAt' => $user['created_at'],
                'updatedAt' => $user['updated_at'],
                'classes' => $user['class_ids'] ? [
                    'ids' => explode(',', $user['class_ids']),
                    'names' => explode(',', $user['class_names'])
                ] : null
            ];
        }, $users);

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/users', 200, $duration);

        jsonResponse([
            'users' => $result,
            'total' => count($result)
        ]);
    } catch (Exception $e) {
        logError('Failed to list users', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to retrieve users', 500);
    }
}

// ============================================================
// GET /api/admin/users/:id - Get user details
// ============================================================
if ($method === 'GET' && $userId && !$action) {
    $rbac->requirePermission('users', 'read');

    try {
        $user = db()->queryOne(
            "SELECT u.* FROM users u WHERE u.id = :id AND u.tenant_id = :tenant_id",
            ['id' => $userId, 'tenant_id' => $tenantId]
        );

        if (!$user) {
            errorResponse('NOT_FOUND', 'User not found', 404);
        }

        // Get class assignments
        $classes = db()->query(
            "SELECT uca.*, c.name as class_name, c.status as class_status
             FROM user_class_assignments uca
             JOIN classes c ON uca.class_id = c.id
             WHERE uca.user_id = :user_id",
            ['user_id' => $userId]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest("/api/admin/users/{$userId}", 200, $duration);

        jsonResponse([
            'id' => $user['id'],
            'email' => $user['email'],
            'firstname' => $user['firstname'],
            'lastname' => $user['lastname'],
            'role' => $user['role'],
            'status' => $user['status'],
            'lastLoginAt' => $user['last_login_at'],
            'deactivatedAt' => $user['deactivated_at'],
            'deactivatedBy' => $user['deactivated_by'],
            'createdAt' => $user['created_at'],
            'updatedAt' => $user['updated_at'],
            'classes' => $classes
        ]);
    } catch (Exception $e) {
        logError('Failed to get user', ['error' => $e->getMessage(), 'user_id' => $userId]);
        errorResponse('SERVER_ERROR', 'Failed to retrieve user', 500);
    }
}

// ============================================================
// POST /api/admin/users - Create user
// ============================================================
if ($method === 'POST' && !$userId) {
    $rbac->requirePermission('users', 'create');

    try {
        $body = getRequestBody();

        // Validate required fields
        $required = ['email', 'firstname', 'lastname', 'role'];
        foreach ($required as $field) {
            if (!isset($body[$field]) || empty($body[$field])) {
                errorResponse('VALIDATION_ERROR', "Missing required field: {$field}", 400);
            }
        }

        $email = trim($body['email']);
        $firstname = trim($body['firstname']);
        $lastname = trim($body['lastname']);
        $role = $body['role'];
        $classIds = $body['class_ids'] ?? [];
        $sendInvitation = $body['send_invitation'] ?? true;

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            errorResponse('VALIDATION_ERROR', 'Invalid email format', 400);
        }

        // Validate role
        $validRoles = ['admin', 'direction', 'teacher', 'inspector', 'referent', 'intervenant'];
        if (!in_array($role, $validRoles)) {
            errorResponse('VALIDATION_ERROR', 'Invalid role', 400);
        }

        // Check if email already exists
        $existing = db()->queryOne(
            'SELECT id FROM users WHERE email = :email',
            ['email' => $email]
        );

        if ($existing) {
            errorResponse('CONFLICT', 'A user with this email already exists', 409);
        }

        // Check licence quotas
        $licences = db()->queryOne(
            'SELECT * FROM tenant_licences WHERE tenant_id = :tenant_id',
            ['tenant_id' => $tenantId]
        );

        if ($licences && in_array($role, ['teacher', 'direction', 'admin'])) {
            if ($licences['used_teachers'] >= $licences['max_teachers']) {
                errorResponse('QUOTA_EXCEEDED', 'Teacher quota exceeded', 403);
            }
        }

        // Generate temporary password
        $tempPassword = bin2hex(random_bytes(8)); // 16 chars
        $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT);

        // Create user
        db()->beginTransaction();

        $newUserId = 'user_' . uniqid() . '_' . bin2hex(random_bytes(8));

        db()->insert('users', [
            'id' => $newUserId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'password_hash' => $passwordHash,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'role' => $role,
            'status' => 'active'
        ]);

        // Assign classes if provided
        if (!empty($classIds) && is_array($classIds)) {
            foreach ($classIds as $classId) {
                db()->insert('user_class_assignments', [
                    'user_id' => $newUserId,
                    'class_id' => $classId,
                    'tenant_id' => $tenantId,
                    'is_primary' => false
                ]);
            }
        }

        // Update licence usage
        if ($licences && in_array($role, ['teacher', 'direction', 'admin'])) {
            db()->execute(
                'UPDATE tenant_licences SET used_teachers = used_teachers + 1 WHERE tenant_id = :tenant_id',
                ['tenant_id' => $tenantId]
            );
        }

        // Audit log
        $auditLog->logUserCreate($newUserId, $email, $role);

        db()->commit();

        // Send invitation email
        if ($sendInvitation) {
            $mailer = new MailerService();
            $tenant = $tenantContext->getTenant();
            $mailer->sendUserInvitation($email, $tempPassword, $tenant['name']);
        }

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/users', 201, $duration);

        jsonResponse([
            'id' => $newUserId,
            'email' => $email,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'role' => $role,
            'status' => 'active',
            'tempPassword' => $sendInvitation ? null : $tempPassword, // Only return if not sent by email
            'invitationSent' => $sendInvitation
        ], 201);
    } catch (Exception $e) {
        db()->rollback();
        logError('Failed to create user', ['error' => $e->getMessage()]);
        $auditLog->logFailed('user_create', 'user', null, $e->getMessage(), ['email' => $email ?? null]);
        errorResponse('SERVER_ERROR', 'Failed to create user: ' . $e->getMessage(), 500);
    }
}

// ============================================================
// PATCH /api/admin/users/:id - Update user
// ============================================================
if ($method === 'PATCH' && $userId && !$action) {
    $rbac->requirePermission('users', 'update');

    try {
        $body = getRequestBody();

        // Check if user exists
        $user = db()->queryOne(
            'SELECT * FROM users WHERE id = :id AND tenant_id = :tenant_id',
            ['id' => $userId, 'tenant_id' => $tenantId]
        );

        if (!$user) {
            errorResponse('NOT_FOUND', 'User not found', 404);
        }

        $updates = [];
        $changes = [];

        // Update firstname
        if (isset($body['firstname'])) {
            $updates['firstname'] = trim($body['firstname']);
            $changes['firstname'] = $updates['firstname'];
        }

        // Update lastname
        if (isset($body['lastname'])) {
            $updates['lastname'] = trim($body['lastname']);
            $changes['lastname'] = $updates['lastname'];
        }

        // Update role
        if (isset($body['role'])) {
            $validRoles = ['admin', 'direction', 'teacher', 'inspector', 'referent', 'intervenant'];
            if (!in_array($body['role'], $validRoles)) {
                errorResponse('VALIDATION_ERROR', 'Invalid role', 400);
            }
            $updates['role'] = $body['role'];
            $changes['role'] = ['from' => $user['role'], 'to' => $body['role']];
        }

        db()->beginTransaction();

        // Apply updates
        if (!empty($updates)) {
            db()->update('users', $updates, 'id = :id', ['id' => $userId]);
        }

        // Update class assignments
        if (isset($body['class_ids'])) {
            // Remove existing assignments
            db()->delete('user_class_assignments', 'user_id = :user_id', ['user_id' => $userId]);

            // Add new assignments
            if (!empty($body['class_ids']) && is_array($body['class_ids'])) {
                foreach ($body['class_ids'] as $classId) {
                    db()->insert('user_class_assignments', [
                        'user_id' => $userId,
                        'class_id' => $classId,
                        'tenant_id' => $tenantId,
                        'is_primary' => false
                    ]);
                }
            }

            $changes['class_ids'] = $body['class_ids'];
        }

        // Audit log
        $auditLog->logUserUpdate($userId, $changes);

        db()->commit();

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest("/api/admin/users/{$userId}", 200, $duration);

        jsonResponse([
            'id' => $userId,
            'updated' => true,
            'changes' => $changes
        ]);
    } catch (Exception $e) {
        db()->rollback();
        logError('Failed to update user', ['error' => $e->getMessage(), 'user_id' => $userId]);
        $auditLog->logFailed('user_update', 'user', $userId, $e->getMessage());
        errorResponse('SERVER_ERROR', 'Failed to update user', 500);
    }
}

// ============================================================
// PATCH /api/admin/users/:id/status - Change user status
// ============================================================
if ($method === 'PATCH' && $userId && $action === 'status') {
    $rbac->requirePermission('users', 'update');

    try {
        $body = getRequestBody();

        if (!isset($body['status'])) {
            errorResponse('VALIDATION_ERROR', 'Missing status field', 400);
        }

        $newStatus = $body['status'];
        $validStatuses = ['active', 'inactive'];

        if (!in_array($newStatus, $validStatuses)) {
            errorResponse('VALIDATION_ERROR', 'Invalid status', 400);
        }

        // Check if user exists
        $user = db()->queryOne(
            'SELECT * FROM users WHERE id = :id AND tenant_id = :tenant_id',
            ['id' => $userId, 'tenant_id' => $tenantId]
        );

        if (!$user) {
            errorResponse('NOT_FOUND', 'User not found', 404);
        }

        db()->beginTransaction();

        // Update status
        $updates = ['status' => $newStatus];

        if ($newStatus === 'inactive') {
            $updates['deactivated_at'] = date('Y-m-d H:i:s');
            $updates['deactivated_by'] = $rbac->getUserId();

            // Audit log
            $auditLog->logUserDeactivate($userId, $user['email'], $body['reason'] ?? null);

            // Send notification email
            $mailer = new MailerService();
            $tenant = $tenantContext->getTenant();
            $mailer->sendDeactivationNotice($user['email'], $tenant['name'], $body['reason'] ?? null);
        } else {
            $updates['deactivated_at'] = null;
            $updates['deactivated_by'] = null;

            // Audit log
            $auditLog->logUserActivate($userId, $user['email']);
        }

        db()->update('users', $updates, 'id = :id', ['id' => $userId]);

        db()->commit();

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest("/api/admin/users/{$userId}/status", 200, $duration);

        jsonResponse([
            'id' => $userId,
            'status' => $newStatus,
            'updated' => true
        ]);
    } catch (Exception $e) {
        db()->rollback();
        logError('Failed to change user status', ['error' => $e->getMessage(), 'user_id' => $userId]);
        errorResponse('SERVER_ERROR', 'Failed to change user status', 500);
    }
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
