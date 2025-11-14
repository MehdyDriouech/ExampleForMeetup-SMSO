<?php
/**
 * Sprint 15 - IA Budget Management
 *
 * GET  /api/admin/ia-budgets         - List all budgets (tenant + teachers)
 * POST /api/admin/ia-budgets         - Create new budget
 * GET  /api/admin/ia-budgets/:id     - Get specific budget
 * PUT  /api/admin/ia-budgets/:id     - Update budget
 * GET  /api/admin/ia-budgets/usage   - Get current usage stats
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
// GET /api/admin/ia-budgets/usage - Usage stats
// ============================================
if ($method === 'GET' && strpos($_SERVER['REQUEST_URI'], '/usage') !== false) {
    $rbac->requirePermission('ia_budgets', 'read');

    // Get tenant budget
    $tenantBudget = db()->queryOne(
        'SELECT * FROM ia_budgets
         WHERE tenant_id = :tenant_id
         AND budget_type = "tenant"
         AND period_end >= NOW()
         ORDER BY period_end DESC
         LIMIT 1',
        ['tenant_id' => $tenantId]
    );

    // Get teacher budgets summary
    $teacherBudgetsSummary = db()->query(
        'SELECT
            COUNT(*) as total_teachers,
            SUM(used_tokens) as total_tokens_used,
            SUM(max_tokens) as total_tokens_allocated,
            SUM(CASE WHEN status = "exceeded" THEN 1 ELSE 0 END) as budgets_exceeded
         FROM ia_budgets
         WHERE tenant_id = :tenant_id
         AND budget_type = "teacher"
         AND period_end >= NOW()',
        ['tenant_id' => $tenantId]
    );

    // Get recent IA usage from audit log
    $recentUsage = db()->query(
        'SELECT
            DATE(created_at) as date,
            COUNT(*) as requests,
            SUM(tokens_total) as tokens_used
         FROM audit_ia_log
         WHERE tenant_id = :tenant_id
         AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(created_at)
         ORDER BY date DESC',
        ['tenant_id' => $tenantId]
    );

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/admin/ia-budgets/usage', 200, $duration);

    jsonResponse([
        'success' => true,
        'data' => [
            'tenant_budget' => $tenantBudget,
            'teacher_budgets_summary' => $teacherBudgetsSummary[0] ?? null,
            'recent_usage' => $recentUsage
        ]
    ]);
}

// ============================================
// GET /api/admin/ia-budgets - List budgets
// ============================================
if ($method === 'GET' && !isset($_GET['id'])) {
    $rbac->requirePermission('ia_budgets', 'read');

    $budgetType = $_GET['type'] ?? null; // 'tenant' or 'teacher'
    $userId = $_GET['user_id'] ?? null;

    $sql = 'SELECT b.*,
                   u.email as user_email,
                   u.firstname as user_firstname,
                   u.lastname as user_lastname,
                   ROUND((b.used_tokens / b.max_tokens) * 100, 2) as usage_percent
            FROM ia_budgets b
            LEFT JOIN users u ON b.user_id = u.id
            WHERE b.tenant_id = :tenant_id';

    $params = ['tenant_id' => $tenantId];

    if ($budgetType) {
        $sql .= ' AND b.budget_type = :budget_type';
        $params['budget_type'] = $budgetType;
    }

    if ($userId) {
        $sql .= ' AND b.user_id = :user_id';
        $params['user_id'] = $userId;
    }

    $sql .= ' ORDER BY b.period_end DESC, b.budget_type ASC';

    $budgets = db()->query($sql, $params);

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/admin/ia-budgets', 200, $duration);

    jsonResponse([
        'success' => true,
        'data' => $budgets,
        'count' => count($budgets)
    ]);
}

// ============================================
// GET /api/admin/ia-budgets/:id - Get specific budget
// ============================================
if ($method === 'GET' && isset($_GET['id'])) {
    $rbac->requirePermission('ia_budgets', 'read');

    $budget = db()->queryOne(
        'SELECT b.*,
                u.email as user_email,
                u.firstname as user_firstname,
                u.lastname as user_lastname,
                ROUND((b.used_tokens / b.max_tokens) * 100, 2) as usage_percent
         FROM ia_budgets b
         LEFT JOIN users u ON b.user_id = u.id
         WHERE b.id = :id AND b.tenant_id = :tenant_id',
        ['id' => $_GET['id'], 'tenant_id' => $tenantId]
    );

    if (!$budget) {
        errorResponse('NOT_FOUND', 'Budget not found', 404);
    }

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/admin/ia-budgets', 200, $duration);

    jsonResponse([
        'success' => true,
        'data' => $budget
    ]);
}

// ============================================
// POST /api/admin/ia-budgets - Create budget
// ============================================
if ($method === 'POST') {
    // Only admin and direction can create budgets
    if (!in_array($auth->getRole(), ['admin', 'direction'])) {
        errorResponse('FORBIDDEN', 'Only admin or direction can create budgets', 403);
    }

    $body = getRequestBody();

    validateRequired($body, ['budget_type', 'max_tokens', 'period_start', 'period_end']);

    $budgetType = $body['budget_type'];

    if (!in_array($budgetType, ['tenant', 'teacher'])) {
        errorResponse('VALIDATION_ERROR', 'Invalid budget_type. Must be "tenant" or "teacher"', 400);
    }

    // If teacher budget, user_id is required
    if ($budgetType === 'teacher' && empty($body['user_id'])) {
        errorResponse('VALIDATION_ERROR', 'user_id is required for teacher budgets', 400);
    }

    // Check if budget already exists for this period
    $existing = db()->queryOne(
        'SELECT id FROM ia_budgets
         WHERE tenant_id = :tenant_id
         AND budget_type = :budget_type
         AND user_id <=> :user_id
         AND period_start = :period_start
         AND period_end = :period_end',
        [
            'tenant_id' => $tenantId,
            'budget_type' => $budgetType,
            'user_id' => $body['user_id'] ?? null,
            'period_start' => $body['period_start'],
            'period_end' => $body['period_end']
        ]
    );

    if ($existing) {
        errorResponse('VALIDATION_ERROR', 'Budget already exists for this period', 409);
    }

    $budgetData = [
        'id' => generateUUID(),
        'tenant_id' => $tenantId,
        'budget_type' => $budgetType,
        'user_id' => $body['user_id'] ?? null,
        'max_tokens' => (int)$body['max_tokens'],
        'used_tokens' => 0,
        'max_requests' => $body['max_requests'] ?? null,
        'used_requests' => 0,
        'period_start' => $body['period_start'],
        'period_end' => $body['period_end'],
        'auto_reset' => $body['auto_reset'] ?? true,
        'alert_threshold_percent' => $body['alert_threshold_percent'] ?? 80,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s')
    ];

    try {
        db()->beginTransaction();

        db()->insert('ia_budgets', $budgetData);

        // Log audit
        db()->insert('audit_logs', [
            'id' => generateUUID(),
            'tenant_id' => $tenantId,
            'actor_user_id' => $auth->getUserId(),
            'action_type' => 'ia_budget_created',
            'target_type' => 'ia_budget',
            'target_id' => $budgetData['id'],
            'payload' => json_encode($budgetData),
            'result' => 'success',
            'ip_address' => getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        db()->commit();

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/ia-budgets', 201, $duration);

        jsonResponse([
            'success' => true,
            'message' => 'Budget created successfully',
            'data' => $budgetData
        ], 201);

    } catch (Exception $e) {
        db()->rollback();
        errorResponse('SERVER_ERROR', 'Failed to create budget', 500, $e->getMessage());
    }
}

// ============================================
// PUT /api/admin/ia-budgets/:id - Update budget
// ============================================
if ($method === 'PUT' && isset($_GET['id'])) {
    // Only admin and direction can update budgets
    if (!in_array($auth->getRole(), ['admin', 'direction'])) {
        errorResponse('FORBIDDEN', 'Only admin or direction can update budgets', 403);
    }

    $budgetId = $_GET['id'];
    $body = getRequestBody();

    // Get existing budget
    $budget = db()->queryOne(
        'SELECT * FROM ia_budgets WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $budgetId, 'tenant_id' => $tenantId]
    );

    if (!$budget) {
        errorResponse('NOT_FOUND', 'Budget not found', 404);
    }

    $updateData = [];

    if (isset($body['max_tokens'])) {
        $updateData['max_tokens'] = (int)$body['max_tokens'];
    }

    if (isset($body['max_requests'])) {
        $updateData['max_requests'] = (int)$body['max_requests'];
    }

    if (isset($body['alert_threshold_percent'])) {
        $updateData['alert_threshold_percent'] = (int)$body['alert_threshold_percent'];
    }

    if (isset($body['status'])) {
        if (in_array($body['status'], ['active', 'exceeded', 'expired', 'suspended'])) {
            $updateData['status'] = $body['status'];
        }
    }

    if (isset($body['period_end'])) {
        $updateData['period_end'] = $body['period_end'];
    }

    if (empty($updateData)) {
        errorResponse('VALIDATION_ERROR', 'No valid fields to update', 400);
    }

    try {
        db()->beginTransaction();

        db()->update('ia_budgets', $updateData, 'id = :id AND tenant_id = :tenant_id', [
            'id' => $budgetId,
            'tenant_id' => $tenantId
        ]);

        // Log audit
        db()->insert('audit_logs', [
            'id' => generateUUID(),
            'tenant_id' => $tenantId,
            'actor_user_id' => $auth->getUserId(),
            'action_type' => 'ia_budget_updated',
            'target_type' => 'ia_budget',
            'target_id' => $budgetId,
            'payload' => json_encode($updateData),
            'result' => 'success',
            'ip_address' => getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        db()->commit();

        // Get updated budget
        $updatedBudget = db()->queryOne(
            'SELECT * FROM ia_budgets WHERE id = :id',
            ['id' => $budgetId]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/ia-budgets', 200, $duration);

        jsonResponse([
            'success' => true,
            'message' => 'Budget updated successfully',
            'data' => $updatedBudget
        ]);

    } catch (Exception $e) {
        db()->rollback();
        errorResponse('SERVER_ERROR', 'Failed to update budget', 500, $e->getMessage());
    }
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
