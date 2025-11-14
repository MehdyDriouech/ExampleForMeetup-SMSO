<?php
/**
 * Sprint 15 - IA Policy Management
 *
 * GET  /api/admin/ia-policy  - Get tenant IA policy
 * PUT  /api/admin/ia-policy  - Update IA policy (enable/disable, BYOK, models)
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
// GET /api/admin/ia-policy
// ============================================
if ($method === 'GET') {
    $rbac->requirePermission('ia_policy', 'read');

    // Get or create IA policy
    $policy = db()->queryOne(
        'SELECT * FROM ia_policies WHERE tenant_id = :tenant_id',
        ['tenant_id' => $tenantId]
    );

    // If no policy exists, create default one
    if (!$policy) {
        $policyId = generateUUID();
        db()->insert('ia_policies', [
            'id' => $policyId,
            'tenant_id' => $tenantId,
            'ia_enabled' => true,
            'byok_enabled' => false,
            'default_model' => 'gpt-4o-mini',
            'allowed_models' => json_encode(['gpt-4o-mini', 'gpt-4o', 'claude-3-5-sonnet']),
            'content_filter_level' => 'medium',
            'data_retention_days' => 90,
            'created_by' => $auth->getUserId(),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $policy = db()->queryOne(
            'SELECT * FROM ia_policies WHERE id = :id',
            ['id' => $policyId]
        );
    }

    // Parse JSON fields
    $policy['allowed_models'] = json_decode($policy['allowed_models'], true);

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/admin/ia-policy', 200, $duration);

    jsonResponse([
        'success' => true,
        'data' => $policy
    ]);
}

// ============================================
// PUT /api/admin/ia-policy
// ============================================
if ($method === 'PUT') {
    // Only admin and direction can modify IA policy
    if (!in_array($auth->getRole(), ['admin', 'direction'])) {
        errorResponse('FORBIDDEN', 'Only admin or direction can modify IA policy', 403);
    }

    $body = getRequestBody();

    // Get existing policy
    $policy = db()->queryOne(
        'SELECT * FROM ia_policies WHERE tenant_id = :tenant_id',
        ['tenant_id' => $tenantId]
    );

    if (!$policy) {
        errorResponse('NOT_FOUND', 'IA policy not found', 404);
    }

    // Build update data
    $updateData = [];

    // IA enable/disable (kill switch)
    if (isset($body['ia_enabled'])) {
        $iaEnabled = filter_var($body['ia_enabled'], FILTER_VALIDATE_BOOLEAN);
        $updateData['ia_enabled'] = $iaEnabled;

        if (!$iaEnabled) {
            $updateData['ia_disabled_at'] = date('Y-m-d H:i:s');
            $updateData['ia_disabled_by'] = $auth->getUserId();
            $updateData['ia_disabled_reason'] = $body['ia_disabled_reason'] ?? 'Disabled by admin';
        } else {
            $updateData['ia_disabled_at'] = null;
            $updateData['ia_disabled_by'] = null;
            $updateData['ia_disabled_reason'] = null;
        }
    }

    // BYOK configuration
    if (isset($body['byok_enabled'])) {
        $byokEnabled = filter_var($body['byok_enabled'], FILTER_VALIDATE_BOOLEAN);
        $updateData['byok_enabled'] = $byokEnabled;

        if ($byokEnabled && isset($body['api_key'])) {
            // Encrypt API key (simple XOR encryption - should use proper encryption in production)
            $encryptionKey = ENCRYPTION_KEY ?? 'default_key_please_change';
            $encrypted = base64_encode($body['api_key'] ^ $encryptionKey);
            $updateData['api_key_encrypted'] = $encrypted;
            $updateData['api_provider'] = $body['api_provider'] ?? 'openai';
        }
    }

    // Model configuration
    if (isset($body['default_model'])) {
        $updateData['default_model'] = sanitize($body['default_model']);
    }

    if (isset($body['allowed_models'])) {
        if (is_array($body['allowed_models'])) {
            $updateData['allowed_models'] = json_encode($body['allowed_models']);
        }
    }

    // Content filter
    if (isset($body['content_filter_level'])) {
        $filterLevel = $body['content_filter_level'];
        if (in_array($filterLevel, ['none', 'low', 'medium', 'high'])) {
            $updateData['content_filter_level'] = $filterLevel;
        }
    }

    // Data retention
    if (isset($body['data_retention_days'])) {
        $updateData['data_retention_days'] = (int)$body['data_retention_days'];
    }

    if (empty($updateData)) {
        errorResponse('VALIDATION_ERROR', 'No valid fields to update', 400);
    }

    try {
        db()->beginTransaction();

        // Update policy
        db()->update('ia_policies', $updateData, 'tenant_id = :tenant_id', [
            'tenant_id' => $tenantId
        ]);

        // Log audit
        db()->insert('audit_logs', [
            'id' => generateUUID(),
            'tenant_id' => $tenantId,
            'actor_user_id' => $auth->getUserId(),
            'action_type' => 'ia_policy_updated',
            'target_type' => 'ia_policy',
            'target_id' => $policy['id'],
            'payload' => json_encode($updateData),
            'result' => 'success',
            'ip_address' => getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        db()->commit();

        // Get updated policy
        $updatedPolicy = db()->queryOne(
            'SELECT * FROM ia_policies WHERE tenant_id = :tenant_id',
            ['tenant_id' => $tenantId]
        );

        $updatedPolicy['allowed_models'] = json_decode($updatedPolicy['allowed_models'], true);

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/ia-policy', 200, $duration);

        jsonResponse([
            'success' => true,
            'message' => 'IA policy updated successfully',
            'data' => $updatedPolicy
        ]);

    } catch (Exception $e) {
        db()->rollback();
        errorResponse('SERVER_ERROR', 'Failed to update IA policy', 500, $e->getMessage());
    }
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
