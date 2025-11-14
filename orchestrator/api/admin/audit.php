<?php
/**
 * Admin Audit Log API - Sprint 14
 *
 * Endpoints:
 * - GET    /api/admin/audit          - Get audit logs
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

// Only admin and direction can view audit logs
requireAnyPermission($rbac, 'users', ['read', 'update']);

// ============================================================
// GET /api/admin/audit - Get audit logs
// ============================================================
if ($method === 'GET') {
    try {
        // Parse filters from query params
        $filters = [];

        if (isset($_GET['from']) && !empty($_GET['from'])) {
            $filters['from'] = $_GET['from'];
        }

        if (isset($_GET['to']) && !empty($_GET['to'])) {
            $filters['to'] = $_GET['to'];
        }

        if (isset($_GET['action_type']) && !empty($_GET['action_type'])) {
            $filters['action_type'] = $_GET['action_type'];
        }

        if (isset($_GET['target_type']) && !empty($_GET['target_type'])) {
            $filters['target_type'] = $_GET['target_type'];
        }

        if (isset($_GET['actor_user_id']) && !empty($_GET['actor_user_id'])) {
            $filters['actor_user_id'] = $_GET['actor_user_id'];
        }

        if (isset($_GET['result']) && !empty($_GET['result'])) {
            $filters['result'] = $_GET['result'];
        }

        // Pagination
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 500) : 100;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

        // Get logs
        $logs = $auditLog->getLogs($filters, $limit, $offset);
        $total = $auditLog->getCount($filters);

        // Format logs
        $formattedLogs = array_map(function($log) {
            return [
                'id' => $log['id'],
                'actorUserId' => $log['actor_user_id'],
                'actorEmail' => $log['actor_email'],
                'actorName' => $log['actor_firstname'] ? ($log['actor_firstname'] . ' ' . $log['actor_lastname']) : null,
                'actionType' => $log['action_type'],
                'targetType' => $log['target_type'],
                'targetId' => $log['target_id'],
                'payload' => $log['payload'] ? json_decode($log['payload'], true) : null,
                'ipAddress' => $log['ip_address'],
                'userAgent' => $log['user_agent'],
                'result' => $log['result'],
                'errorMessage' => $log['error_message'],
                'createdAt' => $log['created_at']
            ];
        }, $logs);

        // Get available action types and target types for filters
        $actionTypes = db()->query(
            "SELECT DISTINCT action_type FROM audit_log WHERE tenant_id = :tenant_id ORDER BY action_type",
            ['tenant_id' => $tenantId]
        );

        $targetTypes = db()->query(
            "SELECT DISTINCT target_type FROM audit_log WHERE tenant_id = :tenant_id ORDER BY target_type",
            ['tenant_id' => $tenantId]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/audit', 200, $duration);

        jsonResponse([
            'logs' => $formattedLogs,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'hasMore' => ($offset + $limit) < $total
            ],
            'filters' => [
                'available_action_types' => array_column($actionTypes, 'action_type'),
                'available_target_types' => array_column($targetTypes, 'target_type')
            ]
        ]);
    } catch (Exception $e) {
        logError('Failed to get audit logs', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to retrieve audit logs', 500);
    }
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
