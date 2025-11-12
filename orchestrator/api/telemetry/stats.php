<?php
/**
 * GET /api/telemetry/stats - API telemetry statistics
 *
 * Returns telemetry data for observability and performance monitoring.
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

    // Check permissions - only admin and direction can view telemetry
    requirePermission($auth, 'telemetry', 'read');

    $tenantId = $tenantContext->getTenantId();

    // Get query parameters
    $view = $_GET['view'] ?? 'overview'; // overview, endpoints, errors, performance
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $endpoint = $_GET['endpoint'] ?? null;
    $limit = min((int)($_GET['limit'] ?? 100), 500);

    $db = db();
    $response = [
        'tenant_id' => $tenantId,
        'period' => [
            'start_date' => $startDate,
            'end_date' => $endDate
        ],
        'view' => $view
    ];

    switch ($view) {
        case 'overview':
            // Overall statistics
            $overview = $db->queryOne(
                "SELECT
                    COUNT(*) as total_requests,
                    COUNT(DISTINCT DATE(created_at)) as active_days,
                    SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests,
                    SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) as client_errors,
                    SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as server_errors,
                    AVG(duration_ms) as avg_duration_ms,
                    MAX(duration_ms) as max_duration_ms,
                    AVG(db_queries) as avg_db_queries,
                    AVG(db_time_ms) as avg_db_time_ms
                 FROM api_telemetry
                 WHERE tenant_id = :tenant_id
                   AND DATE(created_at) BETWEEN :start_date AND :end_date",
                [
                    'tenant_id' => $tenantId,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]
            );

            // Daily breakdown
            $dailyStats = $db->query(
                "SELECT
                    DATE(created_at) as date,
                    COUNT(*) as requests,
                    SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as errors,
                    AVG(duration_ms) as avg_duration_ms,
                    MAX(duration_ms) as max_duration_ms
                 FROM api_telemetry
                 WHERE tenant_id = :tenant_id
                   AND DATE(created_at) BETWEEN :start_date AND :end_date
                 GROUP BY DATE(created_at)
                 ORDER BY date ASC",
                [
                    'tenant_id' => $tenantId,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]
            );

            // Hourly distribution (last 24h)
            $hourlyStats = $db->query(
                "SELECT
                    HOUR(created_at) as hour,
                    COUNT(*) as requests,
                    AVG(duration_ms) as avg_duration_ms
                 FROM api_telemetry
                 WHERE tenant_id = :tenant_id
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 GROUP BY HOUR(created_at)
                 ORDER BY hour ASC",
                ['tenant_id' => $tenantId]
            );

            $response['overview'] = $overview;
            $response['daily_stats'] = $dailyStats;
            $response['hourly_stats'] = $hourlyStats;
            break;

        case 'endpoints':
            // Endpoint statistics
            $endpointStats = $db->query(
                "SELECT
                    endpoint,
                    COUNT(*) as total_requests,
                    SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed_requests,
                    AVG(duration_ms) as avg_duration_ms,
                    MAX(duration_ms) as max_duration_ms,
                    AVG(db_queries) as avg_db_queries,
                    AVG(db_time_ms) as avg_db_time_ms,
                    ROUND(100.0 * SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) / COUNT(*), 2) as error_rate_pct
                 FROM api_telemetry
                 WHERE tenant_id = :tenant_id
                   AND DATE(created_at) BETWEEN :start_date AND :end_date
                 GROUP BY endpoint
                 ORDER BY total_requests DESC
                 LIMIT :limit",
                [
                    'tenant_id' => $tenantId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'limit' => $limit
                ]
            );

            $response['endpoints'] = $endpointStats;
            break;

        case 'errors':
            // Error analysis
            $errorStats = $db->query(
                "SELECT
                    status_code,
                    error_code,
                    COUNT(*) as occurrences,
                    endpoint,
                    error_message
                 FROM api_telemetry
                 WHERE tenant_id = :tenant_id
                   AND status_code >= 400
                   AND DATE(created_at) BETWEEN :start_date AND :end_date
                 GROUP BY status_code, error_code, endpoint, error_message
                 ORDER BY occurrences DESC
                 LIMIT :limit",
                [
                    'tenant_id' => $tenantId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'limit' => $limit
                ]
            );

            // Recent errors with details
            $recentErrors = $db->query(
                "SELECT
                    request_id,
                    method,
                    endpoint,
                    status_code,
                    error_code,
                    error_message,
                    duration_ms,
                    ip_address,
                    created_at
                 FROM api_telemetry
                 WHERE tenant_id = :tenant_id
                   AND status_code >= 400
                   AND DATE(created_at) BETWEEN :start_date AND :end_date
                 ORDER BY created_at DESC
                 LIMIT :limit",
                [
                    'tenant_id' => $tenantId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'limit' => min($limit, 100)
                ]
            );

            $response['error_groups'] = $errorStats;
            $response['recent_errors'] = $recentErrors;
            break;

        case 'performance':
            // Performance analysis
            if ($endpoint) {
                // Detailed stats for specific endpoint
                $perfStats = getTelemetryPercentiles($tenantId, $endpoint, $endDate);

                // Recent slow requests for this endpoint
                $slowRequests = $db->query(
                    "SELECT
                        request_id,
                        method,
                        status_code,
                        duration_ms,
                        db_queries,
                        db_time_ms,
                        created_at
                     FROM api_telemetry
                     WHERE tenant_id = :tenant_id
                       AND endpoint = :endpoint
                       AND DATE(created_at) BETWEEN :start_date AND :end_date
                     ORDER BY duration_ms DESC
                     LIMIT :limit",
                    [
                        'tenant_id' => $tenantId,
                        'endpoint' => $endpoint,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'limit' => min($limit, 50)
                    ]
                );

                $response['endpoint'] = $endpoint;
                $response['performance_stats'] = $perfStats;
                $response['slow_requests'] = $slowRequests;
            } else {
                // Overall slow queries
                $slowQueries = getSlowQueries($tenantId, 1000, $limit);

                // Performance by endpoint
                $endpointPerf = $db->query(
                    "SELECT
                        endpoint,
                        COUNT(*) as total_requests,
                        AVG(duration_ms) as avg_duration_ms,
                        MAX(duration_ms) as max_duration_ms,
                        AVG(db_queries) as avg_db_queries,
                        AVG(db_time_ms) as avg_db_time_ms
                     FROM api_telemetry
                     WHERE tenant_id = :tenant_id
                       AND DATE(created_at) BETWEEN :start_date AND :end_date
                     GROUP BY endpoint
                     ORDER BY avg_duration_ms DESC
                     LIMIT :limit",
                    [
                        'tenant_id' => $tenantId,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'limit' => $limit
                    ]
                );

                $response['slow_queries'] = $slowQueries;
                $response['endpoint_performance'] = $endpointPerf;
            }
            break;

        default:
            $telemetry->end(400);
            errorResponse('INVALID_VIEW', 'Invalid view parameter. Must be: overview, endpoints, errors, or performance', 400);
    }

    $telemetry->end(200);
    jsonResponse($response);

} catch (Exception $e) {
    $telemetry->end(500, $e->getMessage());
    errorResponse('INTERNAL_ERROR', $e->getMessage(), 500);
}
