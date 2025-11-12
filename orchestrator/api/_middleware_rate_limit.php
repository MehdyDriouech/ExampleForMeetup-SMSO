<?php
/**
 * Rate Limiting Middleware
 *
 * Enforces API rate limits and quotas based on API key.
 * Returns 429 Too Many Requests when quota is exceeded.
 * Adds X-RateLimit-* headers to all responses.
 *
 * Usage:
 *   require_once __DIR__ . '/_middleware_rate_limit.php';
 *   $rateLimitInfo = enforceRateLimit($apiKeyId);
 *   // Response will include X-RateLimit-* headers
 *
 * Headers added:
 *   X-RateLimit-Limit: Maximum requests allowed in the window
 *   X-RateLimit-Remaining: Remaining requests in current window
 *   X-RateLimit-Reset: Unix timestamp when the limit resets
 *
 * @version 1.0
 * @date 2025-11-12
 */

// Load core libraries if not already loaded
if (!function_exists('db')) {
    require_once __DIR__ . '/../.env.php';
}

/**
 * Rate limit information object
 */
class RateLimitInfo {
    public $apiKeyId;
    public $apiKey;
    public $allowed;
    public $limits;
    public $remaining;
    public $resetAt;
    public $retryAfter;

    public function __construct($apiKeyId, $apiKey, $allowed, $limits, $remaining, $resetAt) {
        $this->apiKeyId = $apiKeyId;
        $this->apiKey = $apiKey;
        $this->allowed = $allowed;
        $this->limits = $limits;
        $this->remaining = $remaining;
        $this->resetAt = $resetAt;
        $this->retryAfter = max(0, $resetAt - time());
    }

    /**
     * Get tenant ID from API key
     */
    public function getTenantId() {
        return $this->apiKey['tenant_id'] ?? null;
    }

    /**
     * Get scopes from API key
     */
    public function getScopes() {
        $scopes = $this->apiKey['scopes'] ?? '[]';
        return json_decode($scopes, true) ?: [];
    }

    /**
     * Check if API key has a specific scope
     */
    public function hasScope($scope) {
        return in_array($scope, $this->getScopes());
    }

    /**
     * Add rate limit headers to response
     */
    public function addHeaders() {
        header('X-RateLimit-Limit: ' . $this->limits['minute']);
        header('X-RateLimit-Remaining: ' . max(0, $this->remaining['minute']));
        header('X-RateLimit-Reset: ' . $this->resetAt['minute']);

        // Add additional context headers
        header('X-RateLimit-Limit-Hour: ' . $this->limits['hour']);
        header('X-RateLimit-Remaining-Hour: ' . max(0, $this->remaining['hour']));
        header('X-RateLimit-Limit-Day: ' . $this->limits['day']);
        header('X-RateLimit-Remaining-Day: ' . max(0, $this->remaining['day']));

        if (!$this->allowed) {
            header('Retry-After: ' . $this->retryAfter);
        }
    }
}

/**
 * Extract API key from request
 *
 * Checks multiple sources:
 * 1. X-API-Key header
 * 2. api_key in request body (URLENCODED)
 * 3. api_key in query params
 *
 * @return string|null API key if found
 */
function extractApiKey() {
    // 1. Check X-API-Key header (preferred)
    $headers = getallheaders();
    if (isset($headers['X-API-Key']) && !empty($headers['X-API-Key'])) {
        return trim($headers['X-API-Key']);
    }
    if (isset($headers['X-Api-Key']) && !empty($headers['X-Api-Key'])) {
        return trim($headers['X-Api-Key']);
    }

    // 2. Check request body
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PATCH' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (strpos($contentType, 'application/json') !== false) {
            $body = json_decode(file_get_contents('php://input'), true);
            if (isset($body['api_key']) && !empty($body['api_key'])) {
                return trim($body['api_key']);
            }
        } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            if (isset($_POST['api_key']) && !empty($_POST['api_key'])) {
                return trim($_POST['api_key']);
            }
        }
    }

    // 3. Check query params
    if (isset($_GET['api_key']) && !empty($_GET['api_key'])) {
        return trim($_GET['api_key']);
    }

    return null;
}

/**
 * Validate and load API key
 *
 * @param string $apiKey Plain text API key
 * @return array|null API key data if valid
 */
function validateApiKey($apiKey) {
    if (empty($apiKey)) {
        return null;
    }

    try {
        $db = db();
        $keyHash = hash('sha256', $apiKey);

        $apiKeyData = $db->queryOne(
            "SELECT id, tenant_id, owner, scopes,
                    quota_daily, quota_per_minute, quota_per_hour,
                    status, expires_at, last_used_at
             FROM api_keys
             WHERE key_hash = ? AND status = 'active'",
            [$keyHash]
        );

        if (!$apiKeyData) {
            return null;
        }

        // Check expiration
        if ($apiKeyData['expires_at'] && strtotime($apiKeyData['expires_at']) < time()) {
            logWarn("Expired API key used", [
                'api_key_id' => $apiKeyData['id'],
                'expired_at' => $apiKeyData['expires_at']
            ]);
            return null;
        }

        // Update last_used_at asynchronously (fire and forget)
        $db->execute(
            "UPDATE api_keys SET last_used_at = NOW() WHERE id = ?",
            [$apiKeyData['id']]
        );

        return $apiKeyData;

    } catch (Exception $e) {
        logError("API key validation failed", [
            'error' => $e->getMessage()
        ]);
        return null;
    }
}

/**
 * Check rate limit for an API key
 *
 * Uses token bucket algorithm with three time windows:
 * - Per minute
 * - Per hour
 * - Per day
 *
 * @param string $apiKeyId API key ID
 * @param array $apiKey API key data
 * @return RateLimitInfo Rate limit information
 */
function checkRateLimit($apiKeyId, $apiKey) {
    $db = db();
    $pdo = $db->getPdo();

    $limits = [
        'minute' => (int)$apiKey['quota_per_minute'],
        'hour' => (int)$apiKey['quota_per_hour'],
        'day' => (int)$apiKey['quota_daily']
    ];

    $remaining = [];
    $resetAt = [];
    $allowed = true;

    foreach (['minute', 'hour', 'day'] as $bucketType) {
        try {
            // Use stored procedure for atomic check-and-increment
            $stmt = $pdo->prepare("CALL check_rate_limit(?, ?, ?, @allowed, @current_count, @reset_at)");
            $stmt->execute([$apiKeyId, $bucketType, $limits[$bucketType]]);

            // Get output parameters
            $result = $pdo->query("SELECT @allowed as allowed, @current_count as current_count, @reset_at as reset_at")->fetch();

            $bucketAllowed = (bool)$result['allowed'];
            $currentCount = (int)$result['current_count'];
            $resetTimestamp = strtotime($result['reset_at']);

            $remaining[$bucketType] = max(0, $limits[$bucketType] - $currentCount);
            $resetAt[$bucketType] = $resetTimestamp;

            if (!$bucketAllowed) {
                $allowed = false;
                logWarn("Rate limit exceeded", [
                    'api_key_id' => $apiKeyId,
                    'bucket_type' => $bucketType,
                    'limit' => $limits[$bucketType],
                    'current' => $currentCount,
                    'reset_at' => date('c', $resetTimestamp)
                ]);
            }

        } catch (Exception $e) {
            logError("Rate limit check failed", [
                'api_key_id' => $apiKeyId,
                'bucket_type' => $bucketType,
                'error' => $e->getMessage()
            ]);

            // On error, allow the request but log it
            $remaining[$bucketType] = $limits[$bucketType];
            $resetAt[$bucketType] = time() + 60;
        }
    }

    return new RateLimitInfo(
        $apiKeyId,
        $apiKey,
        $allowed,
        $limits,
        $remaining,
        $resetAt
    );
}

/**
 * Enforce rate limit
 *
 * This is the main middleware function that should be called for API endpoints
 * that require rate limiting via API keys.
 *
 * @param bool $required Whether API key is required (default: false)
 * @return RateLimitInfo|null Rate limit info if API key provided, null otherwise
 * @throws Exception with 401/403/429 status codes on validation failures
 */
function enforceRateLimit($required = false) {
    // Extract API key
    $apiKey = extractApiKey();

    // If no API key provided
    if (empty($apiKey)) {
        if ($required) {
            logWarn("Missing API key", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
            ]);

            http_response_code(401);
            header('WWW-Authenticate: ApiKey realm="API"');
            echo json_encode([
                'error' => 'missing_api_key',
                'message' => 'API key is required. Please provide X-API-Key header or api_key parameter.'
            ]);
            exit;
        }
        return null;
    }

    // Validate API key
    $apiKeyData = validateApiKey($apiKey);

    if (!$apiKeyData) {
        logWarn("Invalid API key", [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
        ]);

        http_response_code(401);
        header('WWW-Authenticate: ApiKey realm="API"');
        echo json_encode([
            'error' => 'invalid_api_key',
            'message' => 'Invalid or expired API key.'
        ]);
        exit;
    }

    // Check rate limit
    $rateLimitInfo = checkRateLimit($apiKeyData['id'], $apiKeyData);

    // Add rate limit headers
    $rateLimitInfo->addHeaders();

    // If rate limit exceeded
    if (!$rateLimitInfo->allowed) {
        http_response_code(429);
        echo json_encode([
            'error' => 'rate_limit_exceeded',
            'message' => 'Rate limit exceeded. Please retry after the reset time.',
            'retry_after' => $rateLimitInfo->retryAfter,
            'reset_at' => date('c', $rateLimitInfo->resetAt['minute']),
            'limits' => [
                'minute' => $rateLimitInfo->limits['minute'],
                'hour' => $rateLimitInfo->limits['hour'],
                'day' => $rateLimitInfo->limits['day']
            ],
            'remaining' => [
                'minute' => $rateLimitInfo->remaining['minute'],
                'hour' => $rateLimitInfo->remaining['hour'],
                'day' => $rateLimitInfo->remaining['day']
            ]
        ]);
        exit;
    }

    logDebug("Rate limit check passed", [
        'api_key_id' => $apiKeyData['id'],
        'owner' => $apiKeyData['owner'],
        'remaining_minute' => $rateLimitInfo->remaining['minute'],
        'remaining_hour' => $rateLimitInfo->remaining['hour'],
        'remaining_day' => $rateLimitInfo->remaining['day']
    ]);

    return $rateLimitInfo;
}

/**
 * Helper function to enforce scope check
 *
 * Verifies that the API key has the required scope.
 * Use this after enforceRateLimit().
 *
 * @param RateLimitInfo $rateLimitInfo
 * @param string $requiredScope Required scope (e.g., "students:read")
 * @throws Exception if scope not granted
 */
function requireScope($rateLimitInfo, $requiredScope) {
    if (!$rateLimitInfo) {
        http_response_code(401);
        echo json_encode([
            'error' => 'authentication_required',
            'message' => 'This endpoint requires authentication with appropriate scope.'
        ]);
        exit;
    }

    if (!$rateLimitInfo->hasScope($requiredScope)) {
        logWarn("Scope not granted", [
            'api_key_id' => $rateLimitInfo->apiKeyId,
            'required_scope' => $requiredScope,
            'granted_scopes' => $rateLimitInfo->getScopes(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ]);

        http_response_code(403);
        echo json_encode([
            'error' => 'insufficient_scope',
            'message' => 'Your API key does not have the required scope: ' . $requiredScope,
            'required_scope' => $requiredScope,
            'your_scopes' => $rateLimitInfo->getScopes()
        ]);
        exit;
    }
}

/**
 * Get current rate limit status without incrementing counter
 *
 * Useful for status endpoints that shouldn't consume quota.
 *
 * @param string $apiKeyId API key ID
 * @return array Rate limit status
 */
function getRateLimitStatus($apiKeyId) {
    $db = db();

    $apiKey = $db->queryOne(
        "SELECT quota_per_minute, quota_per_hour, quota_daily
         FROM api_keys WHERE id = ?",
        [$apiKeyId]
    );

    if (!$apiKey) {
        return null;
    }

    $status = [
        'api_key_id' => $apiKeyId,
        'limits' => [
            'minute' => (int)$apiKey['quota_per_minute'],
            'hour' => (int)$apiKey['quota_per_hour'],
            'day' => (int)$apiKey['quota_daily']
        ],
        'current' => [],
        'remaining' => [],
        'reset_at' => []
    ];

    foreach (['minute', 'hour', 'day'] as $bucketType) {
        // Calculate current window
        if ($bucketType === 'minute') {
            $windowStart = date('Y-m-d H:i:00');
        } elseif ($bucketType === 'hour') {
            $windowStart = date('Y-m-d H:00:00');
        } else {
            $windowStart = date('Y-m-d 00:00:00');
        }

        $bucket = $db->queryOne(
            "SELECT request_count FROM rate_limit_buckets
             WHERE api_key_id = ? AND bucket_type = ? AND window_start = ?",
            [$apiKeyId, $bucketType, $windowStart]
        );

        $current = $bucket ? (int)$bucket['request_count'] : 0;
        $limit = $status['limits'][$bucketType];

        $status['current'][$bucketType] = $current;
        $status['remaining'][$bucketType] = max(0, $limit - $current);

        // Calculate reset time
        if ($bucketType === 'minute') {
            $status['reset_at'][$bucketType] = strtotime($windowStart . ' +1 minute');
        } elseif ($bucketType === 'hour') {
            $status['reset_at'][$bucketType] = strtotime($windowStart . ' +1 hour');
        } else {
            $status['reset_at'][$bucketType] = strtotime($windowStart . ' +1 day');
        }
    }

    return $status;
}
