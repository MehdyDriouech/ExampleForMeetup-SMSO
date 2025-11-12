<?php
/**
 * Telemetry Middleware
 *
 * Captures API request/response telemetry for observability and analytics.
 * Logs performance metrics, errors, and usage patterns.
 * Provides request correlation via X-Request-ID header.
 *
 * Usage:
 *   require_once __DIR__ . '/_middleware_telemetry.php';
 *   $telemetry = startTelemetry();
 *   // ... process request ...
 *   $telemetry->end($statusCode, $errorMessage);
 *
 * Events logged:
 *   - api.request: Request started
 *   - api.response: Request completed
 *   - api.error: Request failed
 *
 * @version 1.0
 * @date 2025-11-12
 */

// Load core libraries if not already loaded
if (!function_exists('db')) {
    require_once __DIR__ . '/../.env.php';
}

/**
 * Telemetry context object
 */
class TelemetryContext {
    public $requestId;
    public $startTime;
    public $startMemory;
    public $tenantId;
    public $apiKeyId;
    public $userId;
    public $method;
    public $endpoint;
    public $userAgent;
    public $ipAddress;
    public $dbQueryCount = 0;
    public $dbQueryTime = 0;

    public function __construct() {
        $this->requestId = $this->generateRequestId();
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage();
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $this->endpoint = $this->normalizeEndpoint($_SERVER['REQUEST_URI'] ?? '/');
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $this->ipAddress = getClientIp();

        // Set request ID in response header
        header('X-Request-ID: ' . $this->requestId);
    }

    /**
     * Generate a unique request ID (UUID v4)
     */
    private function generateRequestId() {
        // Check if request ID is already provided by client or load balancer
        $headers = getallheaders();
        if (isset($headers['X-Request-ID']) && !empty($headers['X-Request-ID'])) {
            return $headers['X-Request-ID'];
        }
        if (isset($headers['X-Request-Id']) && !empty($headers['X-Request-Id'])) {
            return $headers['X-Request-Id'];
        }

        // Generate UUID v4
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Normalize endpoint for aggregation
     * Replace IDs and dynamic segments with placeholders
     */
    private function normalizeEndpoint($uri) {
        // Remove query string
        $path = parse_url($uri, PHP_URL_PATH);

        // Normalize common patterns
        $path = preg_replace('/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/{uuid}', $path);
        $path = preg_replace('/\/\d+/', '/{id}', $path);
        $path = preg_replace('/\/[A-Z0-9_]{10,}/', '/{id}', $path);

        return $path;
    }

    /**
     * Set tenant context
     */
    public function setTenant($tenantId) {
        $this->tenantId = $tenantId;
    }

    /**
     * Set API key context
     */
    public function setApiKey($apiKeyId) {
        $this->apiKeyId = $apiKeyId;
    }

    /**
     * Set user context
     */
    public function setUser($userId) {
        $this->userId = $userId;
    }

    /**
     * Record a database query
     */
    public function recordDbQuery($durationMs) {
        $this->dbQueryCount++;
        $this->dbQueryTime += $durationMs;
    }

    /**
     * Get request duration in milliseconds
     */
    public function getDuration() {
        return (microtime(true) - $this->startTime) * 1000;
    }

    /**
     * Get memory usage in bytes
     */
    public function getMemoryUsage() {
        return memory_get_usage() - $this->startMemory;
    }

    /**
     * End telemetry and log to database
     */
    public function end($statusCode, $errorMessage = null, $errorCode = null) {
        $duration = $this->getDuration();

        // Log event
        $logLevel = $statusCode >= 500 ? 'ERROR' : ($statusCode >= 400 ? 'WARN' : 'INFO');
        $logMessage = sprintf(
            'API %s %s - %d (%.2fms)',
            $this->method,
            $this->endpoint,
            $statusCode,
            $duration
        );

        $context = [
            'request_id' => $this->requestId,
            'duration_ms' => round($duration, 2),
            'status_code' => $statusCode,
            'db_queries' => $this->dbQueryCount,
            'db_time_ms' => round($this->dbQueryTime, 2),
            'memory_mb' => round($this->getMemoryUsage() / 1024 / 1024, 2)
        ];

        if ($errorMessage) {
            $context['error'] = $errorMessage;
        }
        if ($errorCode) {
            $context['error_code'] = $errorCode;
        }

        if ($logLevel === 'ERROR') {
            logError($logMessage, $context);
        } elseif ($logLevel === 'WARN') {
            logWarn($logMessage, $context);
        } else {
            logInfo($logMessage, $context);
        }

        // Store in telemetry database (async - don't block response)
        $this->storeTelemetry($statusCode, $duration, $errorMessage, $errorCode);

        // Add performance headers
        header('X-Response-Time: ' . round($duration, 2) . 'ms');
        header('X-DB-Queries: ' . $this->dbQueryCount);
    }

    /**
     * Store telemetry in database
     */
    private function storeTelemetry($statusCode, $duration, $errorMessage, $errorCode) {
        try {
            $db = db();

            $data = [
                'request_id' => $this->requestId,
                'tenant_id' => $this->tenantId,
                'api_key_id' => $this->apiKeyId,
                'user_id' => $this->userId,
                'method' => $this->method,
                'endpoint' => $this->endpoint,
                'status_code' => $statusCode,
                'duration_ms' => round($duration, 2),
                'db_queries' => $this->dbQueryCount,
                'db_time_ms' => round($this->dbQueryTime, 2),
                'user_agent' => $this->userAgent ? substr($this->userAgent, 0, 500) : null,
                'ip_address' => $this->ipAddress,
                'error_message' => $errorMessage ? substr($errorMessage, 0, 1000) : null,
                'error_code' => $errorCode
            ];

            $db->insert('api_telemetry', $data);

        } catch (Exception $e) {
            // Telemetry failure should not break the request
            logError('Failed to store telemetry', [
                'request_id' => $this->requestId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Log an API event
     */
    public function logEvent($eventType, $data = []) {
        logInfo('API Event: ' . $eventType, array_merge([
            'request_id' => $this->requestId,
            'endpoint' => $this->endpoint
        ], $data));
    }
}

/**
 * Start telemetry for current request
 *
 * This should be called at the beginning of every API endpoint.
 *
 * @return TelemetryContext Telemetry context
 */
function startTelemetry() {
    global $TELEMETRY_CONTEXT;

    if (!isset($TELEMETRY_CONTEXT)) {
        $TELEMETRY_CONTEXT = new TelemetryContext();

        // Log request started
        logDebug('API request started', [
            'request_id' => $TELEMETRY_CONTEXT->requestId,
            'method' => $TELEMETRY_CONTEXT->method,
            'endpoint' => $TELEMETRY_CONTEXT->endpoint,
            'ip' => $TELEMETRY_CONTEXT->ipAddress
        ]);
    }

    return $TELEMETRY_CONTEXT;
}

/**
 * Get current telemetry context
 *
 * @return TelemetryContext|null Current context or null if not started
 */
function getTelemetry() {
    global $TELEMETRY_CONTEXT;
    return $TELEMETRY_CONTEXT ?? null;
}

/**
 * Enhanced DB wrapper with telemetry
 *
 * Wraps database calls to track query count and duration.
 */
class TelemetryDatabase {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function query($sql, $params = []) {
        $start = microtime(true);
        try {
            $result = $this->db->query($sql, $params);
            $this->recordQuery($start);
            return $result;
        } catch (Exception $e) {
            $this->recordQuery($start);
            throw $e;
        }
    }

    public function execute($sql, $params = []) {
        $start = microtime(true);
        try {
            $result = $this->db->execute($sql, $params);
            $this->recordQuery($start);
            return $result;
        } catch (Exception $e) {
            $this->recordQuery($start);
            throw $e;
        }
    }

    public function queryOne($sql, $params = []) {
        $start = microtime(true);
        try {
            $result = $this->db->queryOne($sql, $params);
            $this->recordQuery($start);
            return $result;
        } catch (Exception $e) {
            $this->recordQuery($start);
            throw $e;
        }
    }

    public function insert($table, $data) {
        $start = microtime(true);
        try {
            $result = $this->db->insert($table, $data);
            $this->recordQuery($start);
            return $result;
        } catch (Exception $e) {
            $this->recordQuery($start);
            throw $e;
        }
    }

    public function update($table, $data, $where, $whereParams = []) {
        $start = microtime(true);
        try {
            $result = $this->db->update($table, $data, $where, $whereParams);
            $this->recordQuery($start);
            return $result;
        } catch (Exception $e) {
            $this->recordQuery($start);
            throw $e;
        }
    }

    public function delete($table, $where, $params = []) {
        $start = microtime(true);
        try {
            $result = $this->db->delete($table, $where, $params);
            $this->recordQuery($start);
            return $result;
        } catch (Exception $e) {
            $this->recordQuery($start);
            throw $e;
        }
    }

    private function recordQuery($startTime) {
        $duration = (microtime(true) - $startTime) * 1000;
        $telemetry = getTelemetry();
        if ($telemetry) {
            $telemetry->recordDbQuery($duration);
        }
    }

    // Delegate all other methods to original DB
    public function __call($method, $args) {
        return call_user_func_array([$this->db, $method], $args);
    }
}

/**
 * Wrap database instance with telemetry
 *
 * @param Database $db Original database instance
 * @return TelemetryDatabase Wrapped instance
 */
function withTelemetry($db) {
    return new TelemetryDatabase($db);
}

/**
 * Automatic telemetry cleanup on shutdown
 *
 * Ensures telemetry is recorded even if script terminates unexpectedly.
 */
register_shutdown_function(function() {
    $telemetry = getTelemetry();
    if ($telemetry && !headers_sent()) {
        $statusCode = http_response_code();
        if ($statusCode === false) {
            $statusCode = 200; // Default
        }

        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $telemetry->end(500, $error['message'], 'FATAL_ERROR');
        }
    }
});

/**
 * Helper: Get aggregated telemetry stats
 *
 * @param string $tenantId Tenant ID
 * @param string $startDate Start date (Y-m-d)
 * @param string $endDate End date (Y-m-d)
 * @return array Aggregated stats
 */
function getTelemetryStats($tenantId, $startDate, $endDate) {
    $db = db();

    $stats = $db->query(
        "SELECT
            endpoint,
            COUNT(*) as total_requests,
            SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests,
            SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed_requests,
            AVG(duration_ms) as avg_duration_ms,
            MAX(duration_ms) as max_duration_ms,
            AVG(db_queries) as avg_db_queries,
            AVG(db_time_ms) as avg_db_time_ms
         FROM api_telemetry
         WHERE tenant_id = ?
           AND DATE(created_at) BETWEEN ? AND ?
         GROUP BY endpoint
         ORDER BY total_requests DESC",
        [$tenantId, $startDate, $endDate]
    );

    return $stats;
}

/**
 * Helper: Get telemetry percentiles
 *
 * @param string $tenantId Tenant ID
 * @param string $endpoint Endpoint pattern
 * @param string $date Date (Y-m-d)
 * @return array Percentile stats
 */
function getTelemetryPercentiles($tenantId, $endpoint, $date) {
    $db = db();

    $percentiles = $db->query(
        "SELECT
            COUNT(*) as total_requests,
            AVG(duration_ms) as avg_duration_ms,
            MIN(duration_ms) as min_duration_ms,
            MAX(duration_ms) as max_duration_ms,
            -- Approximate percentiles using GROUP_CONCAT trick
            SUBSTRING_INDEX(SUBSTRING_INDEX(
                GROUP_CONCAT(duration_ms ORDER BY duration_ms SEPARATOR ','),
                ',',
                CEIL(COUNT(*) * 0.50)
            ), ',', -1) as p50_duration_ms,
            SUBSTRING_INDEX(SUBSTRING_INDEX(
                GROUP_CONCAT(duration_ms ORDER BY duration_ms SEPARATOR ','),
                ',',
                CEIL(COUNT(*) * 0.95)
            ), ',', -1) as p95_duration_ms,
            SUBSTRING_INDEX(SUBSTRING_INDEX(
                GROUP_CONCAT(duration_ms ORDER BY duration_ms SEPARATOR ','),
                ',',
                CEIL(COUNT(*) * 0.99)
            ), ',', -1) as p99_duration_ms
         FROM api_telemetry
         WHERE tenant_id = ?
           AND endpoint = ?
           AND DATE(created_at) = ?
         GROUP BY endpoint",
        [$tenantId, $endpoint, $date]
    );

    return $percentiles[0] ?? null;
}

/**
 * Helper: Get slow queries (outliers)
 *
 * @param string $tenantId Tenant ID
 * @param float $thresholdMs Duration threshold in milliseconds
 * @param int $limit Maximum results
 * @return array Slow queries
 */
function getSlowQueries($tenantId, $thresholdMs = 1000, $limit = 50) {
    $db = db();

    $queries = $db->query(
        "SELECT request_id, method, endpoint, status_code,
                duration_ms, db_queries, db_time_ms, created_at
         FROM api_telemetry
         WHERE tenant_id = ?
           AND duration_ms > ?
           AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
         ORDER BY duration_ms DESC
         LIMIT ?",
        [$tenantId, $thresholdMs, $limit]
    );

    return $queries;
}

/**
 * Helper: Get error rate by endpoint
 *
 * @param string $tenantId Tenant ID
 * @param string $startDate Start date (Y-m-d)
 * @param string $endDate End date (Y-m-d)
 * @return array Error rates
 */
function getErrorRates($tenantId, $startDate, $endDate) {
    $db = db();

    $rates = $db->query(
        "SELECT
            endpoint,
            COUNT(*) as total_requests,
            SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) as client_errors,
            SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as server_errors,
            ROUND(100.0 * SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) / COUNT(*), 2) as error_rate_pct
         FROM api_telemetry
         WHERE tenant_id = ?
           AND DATE(created_at) BETWEEN ? AND ?
         GROUP BY endpoint
         HAVING error_rate_pct > 0
         ORDER BY error_rate_pct DESC",
        [$tenantId, $startDate, $endDate]
    );

    return $rates;
}
