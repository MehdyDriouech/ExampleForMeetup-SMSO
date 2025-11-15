<?php
/**
 * Admin Tenant Configuration API - Sprint 20
 *
 * Endpoints:
 * - GET    /api/admin/tenant/config          - Get tenant configuration
 * - PATCH  /api/admin/tenant/config          - Update tenant configuration
 * - POST   /api/admin/tenant/logo            - Upload tenant logo
 * - PATCH  /api/admin/tenant/smtp            - Configure SMTP
 * - POST   /api/admin/tenant/smtp/test       - Test SMTP connection
 * - PATCH  /api/admin/tenant/branding        - Configure branding
 * - PATCH  /api/admin/tenant/ia-quota        - Configure IA quotas
 * - PATCH  /api/admin/tenant/ia-policy       - Configure IA policy
 *
 * @version 1.0
 * @date 2025-11-15
 */

require_once __DIR__ . '/../../.env.php';
require_once __DIR__ . '/../_middleware_tenant.php';
require_once __DIR__ . '/../_middleware_rbac.php';
require_once __DIR__ . '/../../services/TenantConfigService.php';
require_once __DIR__ . '/../../services/OnboardingService.php';
require_once __DIR__ . '/../../services/audit_log.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// Parse path
$requestUri = $_SERVER['REQUEST_URI'];
$pathParts = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));

$action = null;
$subAction = null;

// Extract action: /api/admin/tenant/{action}/{subaction?}
if (count($pathParts) >= 4 && $pathParts[0] === 'api' && $pathParts[1] === 'admin' && $pathParts[2] === 'tenant') {
    if (isset($pathParts[3])) {
        $action = $pathParts[3];
    }
    if (isset($pathParts[4])) {
        $subAction = $pathParts[4];
    }
}

// Authentication & Authorization
$auth = requireAuth();
$tenantContext = enforceTenantIsolation();
enforceTenantAuthMatch($tenantContext, $auth);
$rbac = enforceRBAC($auth);

$tenantId = $tenantContext->getTenantId();
$auditLog = createAuditLog($tenantId, $auth);

// Instancier les services
$tenantConfigService = new TenantConfigService(db());
$onboardingService = new OnboardingService(db());

// ============================================================
// GET /api/admin/tenant/config - Obtenir la configuration
// ============================================================
if ($method === 'GET' && $action === 'config') {
    $rbac->requirePermission('tenant', 'read');

    try {
        $result = $tenantConfigService->getConfig($tenantId);

        if (!$result['success']) {
            errorResponse('FETCH_FAILED', $result['error'], 500);
        }

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/tenant/config', 200, $duration);

        jsonResponse($result['config']);

    } catch (Exception $e) {
        logError('Failed to get tenant config', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to get configuration', 500);
    }
}

// ============================================================
// PATCH /api/admin/tenant/config - Mettre à jour la config
// ============================================================
if ($method === 'PATCH' && $action === 'config') {
    $rbac->requirePermission('tenant', 'update');

    try {
        $input = getJsonInput();

        // Mettre à jour les settings généraux
        if (isset($input['settings'])) {
            $result = $tenantConfigService->updateSettings($tenantId, $input['settings']);
            if (!$result['success']) {
                errorResponse('UPDATE_FAILED', $result['error'], 500);
            }
        }

        // Audit log
        $auditLog->logAction(
            'update',
            'tenant_config',
            $tenantId,
            ['settings' => $input['settings'] ?? []]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/tenant/config', 200, $duration);

        jsonResponse([
            'message' => 'Configuration updated successfully'
        ]);

    } catch (Exception $e) {
        logError('Failed to update tenant config', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to update configuration', 500);
    }
}

// ============================================================
// POST /api/admin/tenant/logo - Upload logo
// ============================================================
if ($method === 'POST' && $action === 'logo') {
    $rbac->requirePermission('tenant', 'update');

    try {
        if (!isset($_FILES['logo'])) {
            errorResponse('VALIDATION_ERROR', 'No logo file uploaded', 400);
        }

        $result = $tenantConfigService->uploadLogo($tenantId, $_FILES['logo']);

        if (!$result['success']) {
            errorResponse('UPLOAD_FAILED', $result['error'], 500);
        }

        // Marquer l'étape de branding comme en progrès si pas encore fait
        $onboardingService->startStep($tenantId, OnboardingService::STEP_CONFIG_BRANDING);

        // Audit log
        $auditLog->logAction(
            'upload',
            'tenant_logo',
            $tenantId,
            ['logo_url' => $result['logo_url']]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/tenant/logo', 200, $duration);

        jsonResponse([
            'logo_url' => $result['logo_url'],
            'message' => 'Logo uploaded successfully'
        ]);

    } catch (Exception $e) {
        logError('Failed to upload logo', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to upload logo', 500);
    }
}

// ============================================================
// PATCH /api/admin/tenant/smtp - Configurer SMTP
// ============================================================
if ($method === 'PATCH' && $action === 'smtp') {
    $rbac->requirePermission('tenant', 'update');

    try {
        $input = getJsonInput();

        $testConnection = $input['test_connection'] ?? true;

        $smtpConfig = [
            'enabled' => $input['enabled'] ?? true,
            'host' => $input['host'],
            'port' => $input['port'],
            'encryption' => $input['encryption'] ?? 'tls',
            'username' => $input['username'] ?? '',
            'password' => $input['password'] ?? '',
            'from_email' => $input['from_email'],
            'from_name' => $input['from_name'] ?? ''
        ];

        $result = $tenantConfigService->configureSMTP($tenantId, $smtpConfig, $testConnection);

        if (!$result['success']) {
            errorResponse('CONFIG_FAILED', $result['error'], 400);
        }

        // Marquer l'étape SMTP comme complétée si vérifiée
        if ($result['verified']) {
            $onboardingService->completeStep(
                $tenantId,
                OnboardingService::STEP_CONFIG_SMTP,
                ['smtp_host' => $smtpConfig['host']]
            );
        }

        // Audit log
        $auditLog->logAction(
            'configure',
            'tenant_smtp',
            $tenantId,
            [
                'host' => $smtpConfig['host'],
                'verified' => $result['verified']
            ]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/tenant/smtp', 200, $duration);

        jsonResponse([
            'smtp_configured' => true,
            'verified' => $result['verified'],
            'message' => $result['verified']
                ? 'SMTP configured and verified successfully'
                : 'SMTP configured but not verified'
        ]);

    } catch (Exception $e) {
        logError('Failed to configure SMTP', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to configure SMTP', 500);
    }
}

// ============================================================
// POST /api/admin/tenant/smtp/test - Tester SMTP
// ============================================================
if ($method === 'POST' && $action === 'smtp' && $subAction === 'test') {
    $rbac->requirePermission('tenant', 'read');

    try {
        $input = getJsonInput();

        $smtpConfig = [
            'host' => $input['host'],
            'port' => $input['port'],
            'encryption' => $input['encryption'] ?? 'tls',
            'username' => $input['username'] ?? '',
            'password' => $input['password'] ?? '',
            'from_email' => $input['from_email'],
            'from_name' => $input['from_name'] ?? ''
        ];

        // Utiliser la méthode privée via reflection (ou créer une méthode publique)
        $result = $tenantConfigService->configureSMTP($tenantId, $smtpConfig, true);

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/tenant/smtp/test', 200, $duration);

        jsonResponse([
            'test_passed' => $result['success'] && ($result['verified'] ?? false),
            'message' => $result['success']
                ? 'SMTP connection test successful'
                : 'SMTP connection test failed: ' . ($result['details'] ?? $result['error'])
        ]);

    } catch (Exception $e) {
        logError('Failed to test SMTP', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to test SMTP connection', 500);
    }
}

// ============================================================
// PATCH /api/admin/tenant/branding - Configurer branding
// ============================================================
if ($method === 'PATCH' && $action === 'branding') {
    $rbac->requirePermission('tenant', 'update');

    try {
        $input = getJsonInput();

        $branding = [];
        if (isset($input['primary_color'])) $branding['primary_color'] = $input['primary_color'];
        if (isset($input['secondary_color'])) $branding['secondary_color'] = $input['secondary_color'];
        if (isset($input['accent_color'])) $branding['accent_color'] = $input['accent_color'];
        if (isset($input['custom_css'])) $branding['custom_css'] = $input['custom_css'];

        $result = $tenantConfigService->configureBranding($tenantId, $branding);

        if (!$result['success']) {
            errorResponse('CONFIG_FAILED', $result['error'], 500);
        }

        // Marquer l'étape de branding comme complétée
        $onboardingService->completeStep(
            $tenantId,
            OnboardingService::STEP_CONFIG_BRANDING,
            ['branding' => $branding]
        );

        // Audit log
        $auditLog->logAction(
            'configure',
            'tenant_branding',
            $tenantId,
            $branding
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/tenant/branding', 200, $duration);

        jsonResponse([
            'branding' => $result['branding'],
            'message' => 'Branding configured successfully'
        ]);

    } catch (Exception $e) {
        logError('Failed to configure branding', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to configure branding', 500);
    }
}

// ============================================================
// PATCH /api/admin/tenant/ia-quota - Configurer quotas IA
// ============================================================
if ($method === 'PATCH' && $action === 'ia-quota') {
    $rbac->requirePermission('tenant', 'update');

    try {
        $input = getJsonInput();

        $quotaConfig = [];
        if (isset($input['monthly_quota'])) $quotaConfig['monthly_quota'] = intval($input['monthly_quota']);
        if (isset($input['warning_threshold'])) $quotaConfig['warning_threshold'] = intval($input['warning_threshold']);
        if (isset($input['reset_day'])) $quotaConfig['reset_day'] = intval($input['reset_day']);

        $result = $tenantConfigService->configureIAQuota($tenantId, $quotaConfig);

        if (!$result['success']) {
            errorResponse('CONFIG_FAILED', $result['error'], 500);
        }

        // Marquer l'étape de quotas comme complétée
        $onboardingService->completeStep(
            $tenantId,
            OnboardingService::STEP_CONFIG_QUOTAS,
            ['quota_ia' => $quotaConfig]
        );

        // Audit log
        $auditLog->logAction(
            'configure',
            'tenant_ia_quota',
            $tenantId,
            $quotaConfig
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/tenant/ia-quota', 200, $duration);

        jsonResponse([
            'quota_ia' => $result['quota_ia'],
            'message' => 'IA quotas configured successfully'
        ]);

    } catch (Exception $e) {
        logError('Failed to configure IA quota', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to configure IA quota', 500);
    }
}

// ============================================================
// PATCH /api/admin/tenant/ia-policy - Configurer politique IA
// ============================================================
if ($method === 'PATCH' && $action === 'ia-policy') {
    $rbac->requirePermission('tenant', 'update');

    try {
        $input = getJsonInput();

        $iaPolicy = [];
        if (isset($input['allow_ai_generation'])) $iaPolicy['allow_ai_generation'] = (bool)$input['allow_ai_generation'];
        if (isset($input['providers'])) $iaPolicy['providers'] = $input['providers'];
        if (isset($input['require_review'])) $iaPolicy['require_review'] = (bool)$input['require_review'];
        if (isset($input['auto_publish'])) $iaPolicy['auto_publish'] = (bool)$input['auto_publish'];
        if (isset($input['max_generations_per_user_per_day'])) {
            $iaPolicy['max_generations_per_user_per_day'] = intval($input['max_generations_per_user_per_day']);
        }

        $result = $tenantConfigService->configureIAPolicy($tenantId, $iaPolicy);

        if (!$result['success']) {
            errorResponse('CONFIG_FAILED', $result['error'], 500);
        }

        // Audit log
        $auditLog->logAction(
            'configure',
            'tenant_ia_policy',
            $tenantId,
            $iaPolicy
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/tenant/ia-policy', 200, $duration);

        jsonResponse([
            'ia_policy' => $result['ia_policy'],
            'message' => 'IA policy configured successfully'
        ]);

    } catch (Exception $e) {
        logError('Failed to configure IA policy', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to configure IA policy', 500);
    }
}

// Route non trouvée
errorResponse('NOT_FOUND', 'Endpoint not found', 404);
