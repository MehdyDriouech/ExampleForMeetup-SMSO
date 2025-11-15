<?php
/**
 * Admin Onboarding API - Sprint 20
 *
 * Endpoints:
 * - POST   /api/admin/onboarding/tenant         - Create new tenant
 * - POST   /api/admin/onboarding/admin-user     - Create admin user for tenant
 * - GET    /api/admin/onboarding/progress/:id   - Get onboarding progress
 * - PATCH  /api/admin/onboarding/step           - Update step status
 * - POST   /api/admin/onboarding/complete       - Mark onboarding as complete
 * - GET    /api/admin/onboarding/templates      - List onboarding templates
 * - POST   /api/admin/onboarding/invite         - Send invitation to join tenant
 *
 * @version 1.0
 * @date 2025-11-15
 */

require_once __DIR__ . '/../../.env.php';
require_once __DIR__ . '/../_middleware_tenant.php';
require_once __DIR__ . '/../_middleware_rbac.php';
require_once __DIR__ . '/../../services/OnboardingService.php';
require_once __DIR__ . '/../../services/audit_log.php';
require_once __DIR__ . '/../../services/mailer.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// Parse path to get action and ID
$requestUri = $_SERVER['REQUEST_URI'];
$pathParts = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));

$action = null;
$resourceId = null;

// Extract action from path: /api/admin/onboarding/{action}/{id?}
if (count($pathParts) >= 4 && $pathParts[0] === 'api' && $pathParts[1] === 'admin' && $pathParts[2] === 'onboarding') {
    if (isset($pathParts[3])) {
        $action = $pathParts[3];
    }
    if (isset($pathParts[4])) {
        $resourceId = $pathParts[4];
    }
}

// Instancier le service
$onboardingService = new OnboardingService(db());

// ============================================================
// POST /api/admin/onboarding/tenant - Créer un tenant
// ============================================================
if ($method === 'POST' && $action === 'tenant') {
    // Note: Cette route est publique pour la création initiale
    // Pas d'auth requise pour créer un nouveau tenant

    try {
        $input = getJsonInput();

        // Validation
        if (empty($input['name'])) {
            errorResponse('VALIDATION_ERROR', 'Tenant name is required', 400);
        }

        $tenantData = [
            'name' => $input['name'],
            'type' => $input['type'] ?? 'public',
            'email' => $input['email'] ?? null,
            'phone' => $input['phone'] ?? null,
            'address' => $input['address'] ?? null
        ];

        $templateId = $input['template_id'] ?? 'template_college_standard';

        $result = $onboardingService->createTenant($tenantData, $templateId);

        if (!$result['success']) {
            errorResponse('CREATE_FAILED', $result['error'], 500);
        }

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/onboarding/tenant', 201, $duration);

        jsonResponse([
            'tenant_id' => $result['tenant_id'],
            'tenant_name' => $result['tenant_name'],
            'template' => $result['template'],
            'expires_at' => $result['expires_at'],
            'message' => 'Tenant created successfully'
        ], 201);

    } catch (Exception $e) {
        logError('Failed to create tenant', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to create tenant', 500);
    }
}

// ============================================================
// POST /api/admin/onboarding/admin-user - Créer l'admin initial
// ============================================================
if ($method === 'POST' && $action === 'admin-user') {
    // Route publique pour créer l'admin initial
    // L'auth se fera avec le tenant_id fourni

    try {
        $input = getJsonInput();

        // Validation
        if (empty($input['tenant_id'])) {
            errorResponse('VALIDATION_ERROR', 'tenant_id is required', 400);
        }
        if (empty($input['email']) || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            errorResponse('VALIDATION_ERROR', 'Valid email is required', 400);
        }
        if (empty($input['password']) || strlen($input['password']) < 8) {
            errorResponse('VALIDATION_ERROR', 'Password must be at least 8 characters', 400);
        }
        if (empty($input['firstname']) || empty($input['lastname'])) {
            errorResponse('VALIDATION_ERROR', 'First name and last name are required', 400);
        }

        $adminData = [
            'email' => $input['email'],
            'firstname' => $input['firstname'],
            'lastname' => $input['lastname'],
            'password' => $input['password']
        ];

        $result = $onboardingService->createAdminUser($input['tenant_id'], $adminData);

        if (!$result['success']) {
            errorResponse('CREATE_FAILED', $result['error'], 500);
        }

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/onboarding/admin-user', 201, $duration);

        jsonResponse([
            'user_id' => $result['user_id'],
            'email' => $result['email'],
            'role' => $result['role'],
            'message' => 'Admin user created successfully'
        ], 201);

    } catch (Exception $e) {
        logError('Failed to create admin user', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to create admin user', 500);
    }
}

// Les routes suivantes nécessitent une authentification
if (in_array($action, ['progress', 'step', 'complete', 'invite'])) {
    $auth = requireAuth();
    $tenantContext = enforceTenantIsolation();
    enforceTenantAuthMatch($tenantContext, $auth);
    $rbac = enforceRBAC($auth);

    $tenantId = $tenantContext->getTenantId();
    $auditLog = createAuditLog($tenantId, $auth);
}

// ============================================================
// GET /api/admin/onboarding/progress/:id - Obtenir le progrès
// ============================================================
if ($method === 'GET' && $action === 'progress' && $resourceId) {
    $rbac->requirePermission('tenant', 'read');

    try {
        $result = $onboardingService->getProgress($resourceId);

        if (!$result['success']) {
            errorResponse('FETCH_FAILED', $result['error'], 500);
        }

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/onboarding/progress', 200, $duration);

        jsonResponse($result);

    } catch (Exception $e) {
        logError('Failed to get onboarding progress', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to get progress', 500);
    }
}

// ============================================================
// PATCH /api/admin/onboarding/step - Mettre à jour une étape
// ============================================================
if ($method === 'PATCH' && $action === 'step') {
    $rbac->requirePermission('tenant', 'update');

    try {
        $input = getJsonInput();

        if (empty($input['tenant_id']) || empty($input['step']) || empty($input['status'])) {
            errorResponse('VALIDATION_ERROR', 'tenant_id, step, and status are required', 400);
        }

        $result = null;

        switch ($input['status']) {
            case 'in_progress':
                $result = $onboardingService->startStep($input['tenant_id'], $input['step']);
                break;

            case 'completed':
                $data = $input['data'] ?? null;
                $result = $onboardingService->completeStep($input['tenant_id'], $input['step'], $data);
                break;

            case 'skipped':
                $result = $onboardingService->skipStep($input['tenant_id'], $input['step']);
                break;

            default:
                errorResponse('VALIDATION_ERROR', 'Invalid status', 400);
        }

        if (!$result['success']) {
            errorResponse('UPDATE_FAILED', $result['error'], 500);
        }

        // Audit log
        $auditLog->logAction(
            'update',
            'onboarding_step',
            $input['step'],
            [
                'tenant_id' => $input['tenant_id'],
                'step' => $input['step'],
                'status' => $input['status']
            ]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/onboarding/step', 200, $duration);

        jsonResponse([
            'step' => $result['step'],
            'status' => $result['status'],
            'message' => 'Step updated successfully'
        ]);

    } catch (Exception $e) {
        logError('Failed to update step', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to update step', 500);
    }
}

// ============================================================
// POST /api/admin/onboarding/complete - Finaliser l'onboarding
// ============================================================
if ($method === 'POST' && $action === 'complete') {
    $rbac->requirePermission('tenant', 'update');

    try {
        $input = getJsonInput();

        if (empty($input['tenant_id'])) {
            errorResponse('VALIDATION_ERROR', 'tenant_id is required', 400);
        }

        // Marquer l'étape finale comme complétée
        $result = $onboardingService->completeStep(
            $input['tenant_id'],
            OnboardingService::STEP_COMPLETED,
            ['completed_by' => $auth->getUserId()]
        );

        if (!$result['success']) {
            errorResponse('UPDATE_FAILED', $result['error'], 500);
        }

        // Audit log
        $auditLog->logAction(
            'complete',
            'onboarding',
            $input['tenant_id'],
            ['completed_by' => $auth->getUserId()]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/onboarding/complete', 200, $duration);

        jsonResponse([
            'tenant_id' => $input['tenant_id'],
            'onboarding_completed' => true,
            'message' => 'Onboarding completed successfully'
        ]);

    } catch (Exception $e) {
        logError('Failed to complete onboarding', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to complete onboarding', 500);
    }
}

// ============================================================
// GET /api/admin/onboarding/templates - Liste des templates
// ============================================================
if ($method === 'GET' && $action === 'templates') {
    // Route publique pour voir les templates disponibles

    try {
        $result = $onboardingService->listTemplates();

        if (!$result['success']) {
            errorResponse('FETCH_FAILED', $result['error'], 500);
        }

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/onboarding/templates', 200, $duration);

        jsonResponse([
            'templates' => $result['templates'],
            'total' => count($result['templates'])
        ]);

    } catch (Exception $e) {
        logError('Failed to list templates', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to list templates', 500);
    }
}

// ============================================================
// POST /api/admin/onboarding/invite - Inviter un utilisateur
// ============================================================
if ($method === 'POST' && $action === 'invite') {
    $rbac->requirePermission('users', 'create');

    try {
        $input = getJsonInput();

        if (empty($input['email']) || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            errorResponse('VALIDATION_ERROR', 'Valid email is required', 400);
        }
        if (empty($input['role'])) {
            errorResponse('VALIDATION_ERROR', 'Role is required', 400);
        }

        $result = $onboardingService->createInvite(
            $tenantId,
            $auth->getUserId(),
            $input['email'],
            $input['role']
        );

        if (!$result['success']) {
            errorResponse('CREATE_FAILED', $result['error'], 500);
        }

        // Envoyer l'email d'invitation
        $mailer = new MailerService();
        $mailer->sendInvitation(
            $input['email'],
            $result['invite_url'],
            $tenantContext->getTenantName()
        );

        // Audit log
        $auditLog->logAction(
            'create',
            'invitation',
            $result['invite_id'],
            [
                'email' => $input['email'],
                'role' => $input['role']
            ]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/onboarding/invite', 201, $duration);

        jsonResponse([
            'invite_id' => $result['invite_id'],
            'invite_url' => $result['invite_url'],
            'expires_at' => $result['expires_at'],
            'message' => 'Invitation sent successfully'
        ], 201);

    } catch (Exception $e) {
        logError('Failed to create invitation', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to create invitation', 500);
    }
}

// Route non trouvée
errorResponse('NOT_FOUND', 'Endpoint not found', 404);
