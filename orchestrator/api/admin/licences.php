<?php
/**
 * Admin Licences API - Sprint 14
 *
 * Endpoints:
 * - GET    /api/admin/licences       - Get licence info
 * - PUT    /api/admin/licences       - Update licence quotas
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

// Only admin and direction can view/manage licences
requireAnyPermission($rbac, 'users', ['read', 'update']);

// ============================================================
// GET /api/admin/licences - Get licence info
// ============================================================
if ($method === 'GET') {
    try {
        // Get or create licence record
        $licence = db()->queryOne(
            'SELECT * FROM tenant_licences WHERE tenant_id = :tenant_id',
            ['tenant_id' => $tenantId]
        );

        // If no licence record exists, create default one
        if (!$licence) {
            db()->insert('tenant_licences', [
                'tenant_id' => $tenantId,
                'max_teachers' => 10,
                'max_students' => 100,
                'max_classes' => 20,
                'used_teachers' => 0,
                'used_students' => 0,
                'used_classes' => 0,
                'status' => 'active',
                'subscription_type' => 'standard'
            ]);

            $licence = db()->queryOne(
                'SELECT * FROM tenant_licences WHERE tenant_id = :tenant_id',
                ['tenant_id' => $tenantId]
            );
        }

        // Calculate actual usage
        $actualTeachers = db()->queryOne(
            "SELECT COUNT(*) as count FROM users WHERE tenant_id = :tenant_id AND role IN ('teacher', 'direction', 'admin') AND status = 'active'",
            ['tenant_id' => $tenantId]
        );

        $actualStudents = db()->queryOne(
            "SELECT COUNT(*) as count FROM students WHERE tenant_id = :tenant_id AND status = 'active'",
            ['tenant_id' => $tenantId]
        );

        $actualClasses = db()->queryOne(
            "SELECT COUNT(*) as count FROM classes WHERE tenant_id = :tenant_id AND status = 'active'",
            ['tenant_id' => $tenantId]
        );

        // Update usage counters if they differ
        $usedTeachers = (int)$actualTeachers['count'];
        $usedStudents = (int)$actualStudents['count'];
        $usedClasses = (int)$actualClasses['count'];

        if ($usedTeachers != $licence['used_teachers'] ||
            $usedStudents != $licence['used_students'] ||
            $usedClasses != $licence['used_classes']) {

            db()->update('tenant_licences',
                [
                    'used_teachers' => $usedTeachers,
                    'used_students' => $usedStudents,
                    'used_classes' => $usedClasses,
                    'last_check_at' => date('Y-m-d H:i:s')
                ],
                'tenant_id = :tenant_id',
                ['tenant_id' => $tenantId]
            );
        }

        // Calculate percentages and status
        $teacherUsagePercent = $licence['max_teachers'] > 0 ? ($usedTeachers / $licence['max_teachers']) * 100 : 0;
        $studentUsagePercent = $licence['max_students'] > 0 ? ($usedStudents / $licence['max_students']) * 100 : 0;
        $classUsagePercent = $licence['max_classes'] > 0 ? ($usedClasses / $licence['max_classes']) * 100 : 0;

        $maxUsage = max($teacherUsagePercent, $studentUsagePercent, $classUsagePercent);

        // Determine warning level
        $warningLevel = 'ok';
        if ($maxUsage >= 100) {
            $warningLevel = 'critical';
        } elseif ($maxUsage >= 90) {
            $warningLevel = 'warning';
        } elseif ($maxUsage >= 75) {
            $warningLevel = 'info';
        }

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/licences', 200, $duration);

        jsonResponse([
            'tenantId' => $tenantId,
            'maxTeachers' => (int)$licence['max_teachers'],
            'maxStudents' => (int)$licence['max_students'],
            'maxClasses' => (int)$licence['max_classes'],
            'usedTeachers' => $usedTeachers,
            'usedStudents' => $usedStudents,
            'usedClasses' => $usedClasses,
            'usage' => [
                'teachers' => round($teacherUsagePercent, 1),
                'students' => round($studentUsagePercent, 1),
                'classes' => round($classUsagePercent, 1)
            ],
            'warningLevel' => $warningLevel,
            'status' => $licence['status'],
            'subscriptionType' => $licence['subscription_type'],
            'expiresAt' => $licence['expires_at'],
            'lastCheckAt' => $licence['last_check_at']
        ]);
    } catch (Exception $e) {
        logError('Failed to get licences', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to retrieve licence information', 500);
    }
}

// ============================================================
// PUT /api/admin/licences - Update licence quotas
// ============================================================
if ($method === 'PUT') {
    // Only admin can modify licences
    if ($rbac->getRole() !== 'admin') {
        errorResponse('FORBIDDEN', 'Only admin can modify licence quotas', 403);
    }

    try {
        $body = getRequestBody();

        $updates = [];
        $changes = [];

        // Update quotas
        if (isset($body['max_teachers']) && is_numeric($body['max_teachers'])) {
            $updates['max_teachers'] = (int)$body['max_teachers'];
            $changes['max_teachers'] = $updates['max_teachers'];
        }

        if (isset($body['max_students']) && is_numeric($body['max_students'])) {
            $updates['max_students'] = (int)$body['max_students'];
            $changes['max_students'] = $updates['max_students'];
        }

        if (isset($body['max_classes']) && is_numeric($body['max_classes'])) {
            $updates['max_classes'] = (int)$body['max_classes'];
            $changes['max_classes'] = $updates['max_classes'];
        }

        if (isset($body['status'])) {
            $validStatuses = ['active', 'warning', 'suspended', 'expired'];
            if (in_array($body['status'], $validStatuses)) {
                $updates['status'] = $body['status'];
                $changes['status'] = $updates['status'];
            }
        }

        if (isset($body['subscription_type'])) {
            $updates['subscription_type'] = $body['subscription_type'];
            $changes['subscription_type'] = $updates['subscription_type'];
        }

        if (isset($body['expires_at'])) {
            $updates['expires_at'] = $body['expires_at'];
            $changes['expires_at'] = $updates['expires_at'];
        }

        if (empty($updates)) {
            errorResponse('VALIDATION_ERROR', 'No valid updates provided', 400);
        }

        db()->beginTransaction();

        // Check if licence record exists
        $existing = db()->queryOne(
            'SELECT tenant_id FROM tenant_licences WHERE tenant_id = :tenant_id',
            ['tenant_id' => $tenantId]
        );

        if ($existing) {
            db()->update('tenant_licences', $updates, 'tenant_id = :tenant_id', ['tenant_id' => $tenantId]);
        } else {
            // Create new licence record with defaults
            $defaultData = [
                'tenant_id' => $tenantId,
                'max_teachers' => 10,
                'max_students' => 100,
                'max_classes' => 20,
                'status' => 'active'
            ];
            $licenceData = array_merge($defaultData, $updates);
            db()->insert('tenant_licences', $licenceData);
        }

        // Audit log
        $auditLog->logLicenceUpdate($changes);

        db()->commit();

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/licences', 200, $duration);

        jsonResponse([
            'updated' => true,
            'changes' => $changes
        ]);
    } catch (Exception $e) {
        db()->rollback();
        logError('Failed to update licences', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to update licence information', 500);
    }
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
