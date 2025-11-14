<?php
/**
 * Sprint 15 - IA Audit Log
 *
 * GET /api/admin/ia-audit - Get IA audit logs (filterable)
 */

require_once __DIR__ . '/../.env.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rbac.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// Security middleware
$tenantContext = enforceTenantIsolation();
$auth = requireAuth();
enforceTenantAuthMatch($tenantContext, $auth);
$rbac = enforceRBAC($auth);

$tenantId = $tenantContext->getTenantId();

// ============================================
// GET /api/admin/ia-audit - List IA audit logs
// ============================================
if ($method === 'GET') {
    $rbac->requirePermission('ia_audit', 'read');

    // Filters
    $userId = $_GET['user_id'] ?? null;
    $model = $_GET['model'] ?? null;
    $contextType = $_GET['context_type'] ?? null;
    $status = $_GET['status'] ?? null;
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;

    // Pagination
    $limit = min((int)($_GET['limit'] ?? 100), 500);
    $offset = (int)($_GET['offset'] ?? 0);

    // Build query
    $sql = 'SELECT
                l.*,
                u.email as user_email,
                u.firstname as user_firstname,
                u.lastname as user_lastname
            FROM audit_ia_log l
            LEFT JOIN users u ON l.user_id = u.id
            WHERE l.tenant_id = :tenant_id';

    $params = ['tenant_id' => $tenantId];

    if ($userId) {
        $sql .= ' AND l.user_id = :user_id';
        $params['user_id'] = $userId;
    }

    if ($model) {
        $sql .= ' AND l.model_used = :model';
        $params['model'] = $model;
    }

    if ($contextType) {
        $sql .= ' AND l.context_type = :context_type';
        $params['context_type'] = $contextType;
    }

    if ($status) {
        $sql .= ' AND l.status = :status';
        $params['status'] = $status;
    }

    if ($dateFrom) {
        $sql .= ' AND l.created_at >= :date_from';
        $params['date_from'] = $dateFrom;
    }

    if ($dateTo) {
        $sql .= ' AND l.created_at <= :date_to';
        $params['date_to'] = $dateTo;
    }

    // Get total count
    $countSql = 'SELECT COUNT(*) as total FROM (' . $sql . ') as counted';
    $totalResult = db()->queryOne($countSql, $params);
    $total = $totalResult['total'] ?? 0;

    // Add ordering and pagination
    $sql .= ' ORDER BY l.created_at DESC LIMIT :limit OFFSET :offset';

    $stmt = db()->getPdo()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $logs = $stmt->fetchAll();

    // Get aggregated stats
    $stats = db()->queryOne(
        'SELECT
            COUNT(*) as total_requests,
            SUM(tokens_total) as total_tokens,
            AVG(latency_ms) as avg_latency_ms,
            SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as successful_requests,
            SUM(CASE WHEN status = "error" THEN 1 ELSE 0 END) as failed_requests
         FROM audit_ia_log
         WHERE tenant_id = :tenant_id
         ' . ($dateFrom ? 'AND created_at >= :date_from' : '') . '
         ' . ($dateTo ? 'AND created_at <= :date_to' : ''),
        array_filter([
            'tenant_id' => $tenantId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ])
    );

    // Get model usage breakdown
    $modelBreakdown = db()->query(
        'SELECT
            model_used,
            COUNT(*) as requests,
            SUM(tokens_total) as tokens_used
         FROM audit_ia_log
         WHERE tenant_id = :tenant_id
         ' . ($dateFrom ? 'AND created_at >= :date_from' : '') . '
         ' . ($dateTo ? 'AND created_at <= :date_to' : '') . '
         GROUP BY model_used
         ORDER BY requests DESC',
        array_filter([
            'tenant_id' => $tenantId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ])
    );

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/admin/ia-audit', 200, $duration);

    jsonResponse([
        'success' => true,
        'data' => $logs,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + $limit) < $total
        ],
        'stats' => $stats,
        'model_breakdown' => $modelBreakdown,
        'filters' => [
            'user_id' => $userId,
            'model' => $model,
            'context_type' => $contextType,
            'status' => $status,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]
    ]);
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
