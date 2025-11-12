<?php
/**
 * GET /api/partners/usage - Partner API usage dashboard
 *
 * Returns usage statistics and rate limit status for API keys.
 * Requires admin or direction role.
 */

require_once __DIR__ . '/../../.env.php';
require_once __DIR__ . '/../_middleware_telemetry.php';
require_once __DIR__ . '/../_middleware_tenant.php';
require_once __DIR__ . '/../_middleware_rbac.php';

setCorsHeaders();

$telemetry = startTelemetry();
$method = $_SERVER['REQUEST_METHOD'];

// Only GET method allowed
if ($method !== 'GET') {
    $telemetry->end(405);
    errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
}

try {
    // Enforce authentication and RBAC
    $auth = requireAuth();
    $telemetry->setUser($auth->getUser()['id'] ?? null);

    // Enforce tenant isolation
    $tenantContext = enforceTenantIsolation();
    $telemetry->setTenant($tenantContext->getTenantId());

    // Check permissions - only admin and direction can view partner usage
    requirePermission($auth, 'partners', 'read');

    $tenantId = $tenantContext->getTenantId();

    // Get query parameters
    $apiKeyId = $_GET['api_key_id'] ?? null;
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');

    $db = db();
    $response = [
        'tenant_id' => $tenantId,
        'period' => [
            'start_date' => $startDate,
            'end_date' => $endDate
        ],
        'api_keys' => [],
        'summary' => []
    ];

    // Get all API keys for the tenant
    $whereClause = 'tenant_id = :tenant_id';
    $params = ['tenant_id' => $tenantId];

    if ($apiKeyId) {
        $whereClause .= ' AND id = :api_key_id';
        $params['api_key_id'] = $apiKeyId;
    }

    $apiKeys = $db->query(
        "SELECT id, owner, scopes, quota_daily, quota_per_minute, quota_per_hour,
                status, created_at, last_used_at, expires_at
         FROM api_keys
         WHERE $whereClause
         ORDER BY last_used_at DESC",
        $params
    );

    // For each API key, get usage stats
    foreach ($apiKeys as $apiKey) {
        $keyId = $apiKey['id'];

        // Get current rate limit status
        require_once __DIR__ . '/../_middleware_rate_limit.php';
        $rateLimitStatus = getRateLimitStatus($keyId);

        // Get usage stats from telemetry
        $usageStats = $db->queryOne(
            "SELECT
                COUNT(*) as total_requests,
                SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests,
                SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed_requests,
                AVG(duration_ms) as avg_duration_ms,
                MAX(duration_ms) as max_duration_ms,
                MIN(created_at) as first_request,
                MAX(created_at) as last_request
             FROM api_telemetry
             WHERE api_key_id = :api_key_id
               AND DATE(created_at) BETWEEN :start_date AND :end_date",
            [
                'api_key_id' => $keyId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        );

        // Get daily breakdown
        $dailyUsage = $db->query(
            "SELECT
                DATE(created_at) as date,
                COUNT(*) as requests,
                SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed,
                AVG(duration_ms) as avg_duration_ms
             FROM api_telemetry
             WHERE api_key_id = :api_key_id
               AND DATE(created_at) BETWEEN :start_date AND :end_date
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            [
                'api_key_id' => $keyId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        );

        // Get top endpoints
        $topEndpoints = $db->query(
            "SELECT
                endpoint,
                COUNT(*) as requests,
                AVG(duration_ms) as avg_duration_ms,
                SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as errors
             FROM api_telemetry
             WHERE api_key_id = :api_key_id
               AND DATE(created_at) BETWEEN :start_date AND :end_date
             GROUP BY endpoint
             ORDER BY requests DESC
             LIMIT 10",
            [
                'api_key_id' => $keyId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        );

        // Get recent errors
        $recentErrors = $db->query(
            "SELECT request_id, method, endpoint, status_code,
                    error_message, error_code, created_at
             FROM api_telemetry
             WHERE api_key_id = :api_key_id
               AND status_code >= 400
               AND DATE(created_at) BETWEEN :start_date AND :end_date
             ORDER BY created_at DESC
             LIMIT 20",
            [
                'api_key_id' => $keyId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        );

        $response['api_keys'][] = [
            'id' => $apiKey['id'],
            'owner' => $apiKey['owner'],
            'scopes' => json_decode($apiKey['scopes'], true),
            'status' => $apiKey['status'],
            'quotas' => [
                'daily' => (int)$apiKey['quota_daily'],
                'per_hour' => (int)$apiKey['quota_per_hour'],
                'per_minute' => (int)$apiKey['quota_per_minute']
            ],
            'rate_limit_status' => $rateLimitStatus,
            'usage' => [
                'total_requests' => (int)($usageStats['total_requests'] ?? 0),
                'successful_requests' => (int)($usageStats['successful_requests'] ?? 0),
                'failed_requests' => (int)($usageStats['failed_requests'] ?? 0),
                'success_rate' => $usageStats['total_requests'] > 0
                    ? round(100 * $usageStats['successful_requests'] / $usageStats['total_requests'], 2)
                    : 0,
                'avg_duration_ms' => $usageStats['avg_duration_ms'] ? round($usageStats['avg_duration_ms'], 2) : null,
                'max_duration_ms' => $usageStats['max_duration_ms'] ? round($usageStats['max_duration_ms'], 2) : null,
                'first_request' => $usageStats['first_request'],
                'last_request' => $usageStats['last_request']
            ],
            'daily_usage' => $dailyUsage,
            'top_endpoints' => $topEndpoints,
            'recent_errors' => $recentErrors,
            'created_at' => $apiKey['created_at'],
            'last_used_at' => $apiKey['last_used_at'],
            'expires_at' => $apiKey['expires_at']
        ];
    }

    // Calculate summary across all API keys
    $summaryStats = $db->queryOne(
        "SELECT
            COUNT(DISTINCT api_key_id) as active_api_keys,
            COUNT(*) as total_requests,
            SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests,
            SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed_requests,
            AVG(duration_ms) as avg_duration_ms
         FROM api_telemetry
         WHERE tenant_id = :tenant_id
           AND DATE(created_at) BETWEEN :start_date AND :end_date",
        [
            'tenant_id' => $tenantId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]
    );

    $response['summary'] = [
        'total_api_keys' => count($apiKeys),
        'active_api_keys' => (int)($summaryStats['active_api_keys'] ?? 0),
        'total_requests' => (int)($summaryStats['total_requests'] ?? 0),
        'successful_requests' => (int)($summaryStats['successful_requests'] ?? 0),
        'failed_requests' => (int)($summaryStats['failed_requests'] ?? 0),
        'success_rate' => $summaryStats['total_requests'] > 0
            ? round(100 * $summaryStats['successful_requests'] / $summaryStats['total_requests'], 2)
            : 0,
        'avg_duration_ms' => $summaryStats['avg_duration_ms'] ? round($summaryStats['avg_duration_ms'], 2) : null
    ];

    $telemetry->end(200);
    jsonResponse($response);

} catch (Exception $e) {
    $telemetry->end(500, $e->getMessage());
    errorResponse('INTERNAL_ERROR', $e->getMessage(), 500);
}
