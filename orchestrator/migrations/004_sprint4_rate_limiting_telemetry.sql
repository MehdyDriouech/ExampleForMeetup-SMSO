-- Sprint 4: API Rate Limiting & Telemetry
-- Migration 004
-- Date: 2025-11-12

-- ============================================================================
-- Table: api_keys
-- Description: API keys for partner integrations with rate limiting quotas
-- ============================================================================
CREATE TABLE IF NOT EXISTS api_keys (
    id VARCHAR(64) PRIMARY KEY,
    tenant_id VARCHAR(64) NOT NULL,
    owner VARCHAR(255) NOT NULL COMMENT 'Partner name or service',
    key_hash VARCHAR(128) NOT NULL COMMENT 'SHA256 hash of the API key',
    scopes JSON NOT NULL COMMENT 'Array of allowed scopes/permissions',
    quota_daily INT NOT NULL DEFAULT 10000 COMMENT 'Max requests per day',
    quota_per_minute INT NOT NULL DEFAULT 60 COMMENT 'Max requests per minute',
    quota_per_hour INT NOT NULL DEFAULT 1000 COMMENT 'Max requests per hour',
    status ENUM('active', 'suspended', 'revoked') NOT NULL DEFAULT 'active',
    metadata JSON COMMENT 'Additional metadata',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,

    INDEX idx_tenant (tenant_id),
    INDEX idx_key_hash (key_hash),
    INDEX idx_status (status),
    INDEX idx_last_used (last_used_at),

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Table: rate_limit_buckets
-- Description: Token bucket implementation for rate limiting
-- ============================================================================
CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id VARCHAR(64) NOT NULL,
    bucket_type ENUM('minute', 'hour', 'day') NOT NULL,
    window_start TIMESTAMP NOT NULL COMMENT 'Start of the time window',
    request_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_bucket (api_key_id, bucket_type, window_start),
    INDEX idx_api_key (api_key_id),
    INDEX idx_window (window_start),
    INDEX idx_cleanup (updated_at),

    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Table: api_telemetry
-- Description: API request/response telemetry for observability
-- ============================================================================
CREATE TABLE IF NOT EXISTS api_telemetry (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(36) NOT NULL COMMENT 'UUID for request correlation',
    tenant_id VARCHAR(64) NOT NULL,
    api_key_id VARCHAR(64) NULL COMMENT 'If authenticated via API key',
    user_id VARCHAR(64) NULL COMMENT 'If authenticated via JWT',

    -- Request data
    method VARCHAR(10) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    status_code INT NOT NULL,

    -- Performance metrics
    duration_ms DECIMAL(10, 2) NOT NULL COMMENT 'Request duration in milliseconds',
    db_queries INT DEFAULT 0 COMMENT 'Number of database queries',
    db_time_ms DECIMAL(10, 2) DEFAULT 0 COMMENT 'Total DB query time',

    -- Metadata
    user_agent TEXT,
    ip_address VARCHAR(45),
    error_message TEXT NULL,
    error_code VARCHAR(64) NULL,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date DATE GENERATED ALWAYS AS (DATE(created_at)) STORED,

    INDEX idx_request_id (request_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_api_key (api_key_id),
    INDEX idx_endpoint (endpoint),
    INDEX idx_status (status_code),
    INDEX idx_date (date),
    INDEX idx_created (created_at),
    INDEX idx_performance (duration_ms),

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (TO_DAYS(created_at)) (
    PARTITION p_initial VALUES LESS THAN (TO_DAYS('2025-12-01')),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- ============================================================================
-- Table: telemetry_daily_summary
-- Description: Pre-aggregated daily telemetry for fast dashboard queries
-- ============================================================================
CREATE TABLE IF NOT EXISTS telemetry_daily_summary (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(64) NOT NULL,
    api_key_id VARCHAR(64) NULL,
    endpoint VARCHAR(255) NOT NULL,
    date DATE NOT NULL,

    -- Aggregated metrics
    total_requests INT NOT NULL DEFAULT 0,
    successful_requests INT NOT NULL DEFAULT 0,
    failed_requests INT NOT NULL DEFAULT 0,
    avg_duration_ms DECIMAL(10, 2),
    p50_duration_ms DECIMAL(10, 2),
    p95_duration_ms DECIMAL(10, 2),
    p99_duration_ms DECIMAL(10, 2),
    max_duration_ms DECIMAL(10, 2),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_summary (tenant_id, api_key_id, endpoint, date),
    INDEX idx_tenant_date (tenant_id, date),
    INDEX idx_api_key_date (api_key_id, date),
    INDEX idx_date (date),

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Insert sample API keys for testing
-- ============================================================================
INSERT INTO api_keys (id, tenant_id, owner, key_hash, scopes, quota_daily, quota_per_minute, quota_per_hour, status)
VALUES
(
    'APIKEY_PARIS_PARTNER_001',
    'TENANT_INST_PARIS',
    'External Partner Service',
    SHA2('test_partner_key_paris_001', 256),
    '["students:read", "assignments:read", "stats:read"]',
    50000,
    100,
    2000,
    'active'
),
(
    'APIKEY_PARIS_INTERNAL_001',
    'TENANT_INST_PARIS',
    'Internal Automation Bot',
    SHA2('test_internal_key_paris_001', 256),
    '["students:read", "students:write", "assignments:read", "assignments:write", "stats:read"]',
    100000,
    200,
    5000,
    'active'
)
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- ============================================================================
-- Stored Procedures
-- ============================================================================

DELIMITER $$

-- Check rate limit for an API key
CREATE PROCEDURE IF NOT EXISTS check_rate_limit(
    IN p_api_key_id VARCHAR(64),
    IN p_bucket_type ENUM('minute', 'hour', 'day'),
    IN p_quota INT,
    OUT p_allowed BOOLEAN,
    OUT p_current_count INT,
    OUT p_reset_at TIMESTAMP
)
BEGIN
    DECLARE v_window_start TIMESTAMP;
    DECLARE v_count INT DEFAULT 0;

    -- Calculate window start based on bucket type
    IF p_bucket_type = 'minute' THEN
        SET v_window_start = DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:00');
    ELSEIF p_bucket_type = 'hour' THEN
        SET v_window_start = DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00');
    ELSE -- day
        SET v_window_start = DATE_FORMAT(NOW(), '%Y-%m-%d 00:00:00');
    END IF;

    -- Get or create bucket
    INSERT INTO rate_limit_buckets (api_key_id, bucket_type, window_start, request_count)
    VALUES (p_api_key_id, p_bucket_type, v_window_start, 1)
    ON DUPLICATE KEY UPDATE
        request_count = request_count + 1,
        updated_at = CURRENT_TIMESTAMP;

    -- Get current count
    SELECT request_count INTO v_count
    FROM rate_limit_buckets
    WHERE api_key_id = p_api_key_id
        AND bucket_type = p_bucket_type
        AND window_start = v_window_start;

    SET p_current_count = v_count;
    SET p_allowed = (v_count <= p_quota);

    -- Calculate reset time
    IF p_bucket_type = 'minute' THEN
        SET p_reset_at = DATE_ADD(v_window_start, INTERVAL 1 MINUTE);
    ELSEIF p_bucket_type = 'hour' THEN
        SET p_reset_at = DATE_ADD(v_window_start, INTERVAL 1 HOUR);
    ELSE
        SET p_reset_at = DATE_ADD(v_window_start, INTERVAL 1 DAY);
    END IF;
END$$

-- Cleanup old rate limit buckets (run daily via cron)
CREATE PROCEDURE IF NOT EXISTS cleanup_rate_limit_buckets()
BEGIN
    DELETE FROM rate_limit_buckets
    WHERE window_start < DATE_SUB(NOW(), INTERVAL 2 DAY);

    SELECT ROW_COUNT() as deleted_rows;
END$$

-- Cleanup old telemetry (run weekly via cron)
CREATE PROCEDURE IF NOT EXISTS cleanup_old_telemetry(IN p_retention_days INT)
BEGIN
    DELETE FROM api_telemetry
    WHERE created_at < DATE_SUB(NOW(), INTERVAL p_retention_days DAY);

    SELECT ROW_COUNT() as deleted_rows;
END$$

DELIMITER ;

-- ============================================================================
-- Events for automatic cleanup (if MySQL Event Scheduler is enabled)
-- ============================================================================

-- Daily cleanup of rate limit buckets
CREATE EVENT IF NOT EXISTS evt_cleanup_rate_limits
ON SCHEDULE EVERY 1 DAY
STARTS (TIMESTAMP(CURRENT_DATE) + INTERVAL 1 DAY + INTERVAL 3 HOUR)
DO
    CALL cleanup_rate_limit_buckets();

-- Weekly cleanup of old telemetry (keep 90 days)
CREATE EVENT IF NOT EXISTS evt_cleanup_telemetry
ON SCHEDULE EVERY 1 WEEK
STARTS (TIMESTAMP(CURRENT_DATE) + INTERVAL 1 WEEK + INTERVAL 4 HOUR)
DO
    CALL cleanup_old_telemetry(90);

-- ============================================================================
-- Grant permissions (adjust as needed for your environment)
-- ============================================================================
-- GRANT SELECT, INSERT, UPDATE ON api_keys TO 'orchestrator_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON rate_limit_buckets TO 'orchestrator_user'@'localhost';
-- GRANT SELECT, INSERT ON api_telemetry TO 'orchestrator_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON telemetry_daily_summary TO 'orchestrator_user'@'localhost';
-- GRANT EXECUTE ON PROCEDURE check_rate_limit TO 'orchestrator_user'@'localhost';

-- ============================================================================
-- Migration complete
-- ============================================================================
SELECT 'Sprint 4 migration completed successfully' AS status;
