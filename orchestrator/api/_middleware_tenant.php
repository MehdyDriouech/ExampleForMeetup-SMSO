<?php
/**
 * Tenant Isolation Middleware
 *
 * Enforces strict tenant isolation across all API requests.
 * Every request must include a valid tenant identifier.
 * Cross-tenant access attempts are logged and blocked.
 *
 * Usage:
 *   require_once __DIR__ . '/_middleware_tenant.php';
 *   $tenantContext = enforceTenantIsolation();
 *   // Use $tenantContext->tenantId in queries
 *
 * @version 1.0
 * @date 2025-11-12
 */

// Load core libraries if not already loaded
if (!function_exists('db')) {
    require_once __DIR__ . '/../.env.php';
}

/**
 * Tenant context object
 */
class TenantContext {
    public $tenantId;
    public $tenant;
    public $isActive;

    public function __construct($tenantId, $tenant) {
        $this->tenantId = $tenantId;
        $this->tenant = $tenant;
        $this->isActive = ($tenant['status'] ?? '') === 'active';
    }

    /**
     * Get tenant ID
     */
    public function getTenantId() {
        return $this->tenantId;
    }

    /**
     * Get full tenant data
     */
    public function getTenant() {
        return $this->tenant;
    }

    /**
     * Check if tenant is active
     */
    public function isActive() {
        return $this->isActive;
    }

    /**
     * Verify a resource belongs to this tenant
     *
     * @param string $resourceTenantId The tenant_id of the resource
     * @param string $resourceType Type of resource (for logging)
     * @param string $resourceId ID of resource (for logging)
     * @throws Exception if tenant mismatch
     */
    public function verifyOwnership($resourceTenantId, $resourceType = 'resource', $resourceId = null) {
        if ($resourceTenantId !== $this->tenantId) {
            $logContext = [
                'expected_tenant_id' => $this->tenantId,
                'actual_tenant_id' => $resourceTenantId,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
            ];

            logWarn("Cross-tenant access attempt blocked", $logContext);

            throw new Exception("Access denied: resource does not belong to your tenant", 403);
        }
    }
}

/**
 * Extract tenant ID from request
 *
 * Checks multiple sources:
 * 1. X-Orchestrator-Id header (JWT mode)
 * 2. tenant_id in request body (URLENCODED mode)
 * 3. tenant_id in query params (GET requests)
 *
 * @return string|null Tenant ID if found
 */
function extractTenantId() {
    // 1. Check X-Orchestrator-Id header (preferred for JWT)
    $headers = getallheaders();
    if (isset($headers['X-Orchestrator-Id']) && !empty($headers['X-Orchestrator-Id'])) {
        return trim($headers['X-Orchestrator-Id']);
    }

    // 2. Check request body (for URLENCODED)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PATCH' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (strpos($contentType, 'application/json') !== false) {
            $body = json_decode(file_get_contents('php://input'), true);
            if (isset($body['tenant_id']) && !empty($body['tenant_id'])) {
                return trim($body['tenant_id']);
            }
        } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            if (isset($_POST['tenant_id']) && !empty($_POST['tenant_id'])) {
                return trim($_POST['tenant_id']);
            }
        }
    }

    // 3. Check query params (for GET requests)
    if (isset($_GET['tenant_id']) && !empty($_GET['tenant_id'])) {
        return trim($_GET['tenant_id']);
    }

    return null;
}

/**
 * Validate and load tenant
 *
 * @param string $tenantId Tenant ID to validate
 * @return array|null Tenant data if valid
 */
function validateTenant($tenantId) {
    if (empty($tenantId)) {
        return null;
    }

    try {
        $db = db();
        $tenant = $db->queryOne(
            "SELECT id, name, type, status, settings, created_at
             FROM tenants
             WHERE id = ?",
            [$tenantId]
        );

        return $tenant;
    } catch (Exception $e) {
        logError("Tenant validation failed", [
            'tenant_id' => $tenantId,
            'error' => $e->getMessage()
        ]);
        return null;
    }
}

/**
 * Enforce tenant isolation
 *
 * This is the main middleware function that should be called at the
 * beginning of every API endpoint.
 *
 * @param bool $required Whether tenant is required (default: true)
 * @return TenantContext|null Tenant context if successful, null if not required
 * @throws Exception with 400/403 status codes on validation failures
 */
function enforceTenantIsolation($required = true) {
    // Extract tenant ID
    $tenantId = extractTenantId();

    // If no tenant ID found
    if (empty($tenantId)) {
        if ($required) {
            logWarn("Missing tenant identifier", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
            ]);

            http_response_code(400);
            echo json_encode([
                'error' => 'missing_tenant_id',
                'message' => 'Tenant identifier is required. Please provide X-Orchestrator-Id header or tenant_id parameter.'
            ]);
            exit;
        }
        return null;
    }

    // Validate tenant
    $tenant = validateTenant($tenantId);

    if (!$tenant) {
        logWarn("Invalid tenant identifier", [
            'tenant_id' => $tenantId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
        ]);

        http_response_code(403);
        echo json_encode([
            'error' => 'invalid_tenant',
            'message' => 'Tenant not found or invalid.'
        ]);
        exit;
    }

    // Check tenant status
    if ($tenant['status'] !== 'active') {
        logWarn("Inactive tenant access attempt", [
            'tenant_id' => $tenantId,
            'tenant_status' => $tenant['status'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
        ]);

        http_response_code(403);
        echo json_encode([
            'error' => 'tenant_inactive',
            'message' => 'This tenant account is not active. Status: ' . $tenant['status']
        ]);
        exit;
    }

    // Create and return tenant context
    $context = new TenantContext($tenantId, $tenant);

    logDebug("Tenant isolation enforced", [
        'tenant_id' => $tenantId,
        'tenant_name' => $tenant['name'],
        'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
    ]);

    return $context;
}

/**
 * Helper function to enforce tenant match with auth
 *
 * Verifies that the authenticated user's tenant matches the request tenant.
 * Use this after both enforceTenantIsolation() and requireAuth().
 *
 * @param TenantContext $tenantContext
 * @param Auth $auth
 * @throws Exception if tenants don't match
 */
function enforceTenantAuthMatch($tenantContext, $auth) {
    $authTenantId = $auth->getTenantId();
    $requestTenantId = $tenantContext->getTenantId();

    if ($authTenantId !== $requestTenantId) {
        logWarn("Tenant mismatch between auth and request", [
            'auth_tenant_id' => $authTenantId,
            'request_tenant_id' => $requestTenantId,
            'user_id' => $auth->getUser()['id'] ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ]);

        http_response_code(403);
        echo json_encode([
            'error' => 'tenant_mismatch',
            'message' => 'Your authentication tenant does not match the requested tenant.'
        ]);
        exit;
    }
}

/**
 * Add tenant filter to WHERE clause
 *
 * Helper to easily add tenant filtering to SQL queries.
 *
 * @param string $tenantId Tenant ID
 * @param string $tableAlias Optional table alias (e.g., 't', 'a')
 * @return string SQL WHERE condition
 */
function tenantWhere($tenantId, $tableAlias = null) {
    $field = $tableAlias ? "{$tableAlias}.tenant_id" : "tenant_id";
    return "{$field} = " . db()->quote($tenantId);
}

/**
 * Add tenant parameter to query params array
 *
 * @param array $params Existing params
 * @param string $tenantId Tenant ID
 * @return array Updated params with tenant_id
 */
function withTenant($params, $tenantId) {
    return array_merge($params, ['tenant_id' => $tenantId]);
}
