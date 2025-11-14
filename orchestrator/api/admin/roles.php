<?php
/**
 * Admin Roles Matrix API - Sprint 14
 *
 * Endpoints:
 * - GET    /api/admin/roles          - Get roles matrix
 * - PUT    /api/admin/roles          - Update roles matrix
 *
 * @version 1.0
 * @date 2025-11-14
 */

require_once __DIR__ . '/../../.env.php';
require_once __DIR__ . '/../_middleware_tenant.php';
require_once __DIR__ . '/../_middleware_rbac.php';
require_once __DIR__ . '/../../services/audit_log.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// Authentication & Authorization
$auth = requireAuth();
$tenantContext = enforceTenantIsolation();
enforceTenantAuthMatch($tenantContext, $auth);
$rbac = enforceRBAC($auth);

$tenantId = $tenantContext->getTenantId();
$auditLog = createAuditLog($tenantId, $auth);

// Only admin and direction can manage roles
requireAnyPermission($rbac, 'users', ['read', 'update']);

// ============================================================
// GET /api/admin/roles - Get roles matrix
// ============================================================
if ($method === 'GET') {
    try {
        // Get default RBAC permissions from middleware
        $defaultMatrix = getRBACMatrix();

        // Get custom tenant overrides
        $customRoles = db()->query(
            "SELECT * FROM roles_matrix WHERE tenant_id = :tenant_id ORDER BY role, permission_key",
            ['tenant_id' => $tenantId]
        );

        // Build matrix with defaults and overrides
        $matrix = [];

        // Start with default permissions
        foreach ($defaultMatrix as $resource => $actions) {
            foreach ($actions as $action => $allowedRoles) {
                foreach ($allowedRoles as $role) {
                    if (!isset($matrix[$role])) {
                        $matrix[$role] = [];
                    }
                    $permKey = "{$resource}:{$action}";
                    $matrix[$role][$permKey] = [
                        'allowed' => true,
                        'isDefault' => true,
                        'resource' => $resource,
                        'action' => $action
                    ];
                }
            }
        }

        // Apply tenant-specific overrides
        foreach ($customRoles as $custom) {
            $role = $custom['role'];
            $permKey = $custom['permission_key'];

            if (!isset($matrix[$role])) {
                $matrix[$role] = [];
            }

            list($resource, $action) = explode(':', $permKey);

            $matrix[$role][$permKey] = [
                'allowed' => (bool)$custom['allowed'],
                'isDefault' => false,
                'resource' => $resource,
                'action' => $action,
                'customConfig' => $custom['custom_config'] ? json_decode($custom['custom_config'], true) : null
            ];
        }

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/roles', 200, $duration);

        jsonResponse([
            'matrix' => $matrix,
            'roles' => ['admin', 'direction', 'teacher', 'inspector', 'referent', 'intervenant'],
            'resources' => array_keys($defaultMatrix)
        ]);
    } catch (Exception $e) {
        logError('Failed to get roles matrix', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to retrieve roles matrix', 500);
    }
}

// ============================================================
// PUT /api/admin/roles - Update roles matrix
// ============================================================
if ($method === 'PUT') {
    // Only admin can modify roles
    if ($rbac->getRole() !== 'admin') {
        errorResponse('FORBIDDEN', 'Only admin can modify roles configuration', 403);
    }

    try {
        $body = getRequestBody();

        if (!isset($body['changes']) || !is_array($body['changes'])) {
            errorResponse('VALIDATION_ERROR', 'Missing changes array', 400);
        }

        $changes = $body['changes'];

        db()->beginTransaction();

        foreach ($changes as $change) {
            if (!isset($change['role']) || !isset($change['permission_key']) || !isset($change['allowed'])) {
                continue;
            }

            $role = $change['role'];
            $permKey = $change['permission_key'];
            $allowed = (bool)$change['allowed'];
            $customConfig = $change['custom_config'] ?? null;

            // Prevent admin from removing critical permissions
            if ($role === 'admin' && in_array($permKey, ['users:read', 'users:update', 'users:create'])) {
                logWarn('Attempted to remove critical admin permission', [
                    'role' => $role,
                    'permission' => $permKey
                ]);
                continue; // Skip this change
            }

            // Check if override exists
            $existing = db()->queryOne(
                "SELECT id FROM roles_matrix WHERE tenant_id = :tenant_id AND role = :role AND permission_key = :perm",
                ['tenant_id' => $tenantId, 'role' => $role, 'perm' => $permKey]
            );

            if ($existing) {
                // Update existing override
                db()->update('roles_matrix',
                    [
                        'allowed' => $allowed,
                        'custom_config' => $customConfig ? json_encode($customConfig) : null
                    ],
                    'id = :id',
                    ['id' => $existing['id']]
                );
            } else {
                // Create new override
                db()->insert('roles_matrix', [
                    'tenant_id' => $tenantId,
                    'role' => $role,
                    'permission_key' => $permKey,
                    'allowed' => $allowed,
                    'custom_config' => $customConfig ? json_encode($customConfig) : null
                ]);
            }
        }

        // Audit log
        $auditLog->logRoleConfigUpdate('multiple', $changes);

        db()->commit();

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/roles', 200, $duration);

        jsonResponse([
            'updated' => true,
            'changesApplied' => count($changes)
        ]);
    } catch (Exception $e) {
        db()->rollback();
        logError('Failed to update roles matrix', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to update roles matrix', 500);
    }
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
