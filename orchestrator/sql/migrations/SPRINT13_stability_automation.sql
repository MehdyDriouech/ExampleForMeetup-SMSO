-- ==================================================
-- Sprint 13: Stability, Automation & UX Excellence
-- Database Migration Script
-- Generated: 2025-11-13
-- ==================================================

-- ===========================
-- 1. User Onboarding Fields
-- ===========================

-- Add onboarding tracking fields to users table
ALTER TABLE users
ADD COLUMN IF NOT EXISTS first_login BOOLEAN DEFAULT 1 COMMENT 'Flag for first-time login',
ADD COLUMN IF NOT EXISTS onboarding_completed BOOLEAN DEFAULT 0 COMMENT 'User completed onboarding',
ADD COLUMN IF NOT EXISTS preferences JSON COMMENT 'User preferences and settings';

-- Index for onboarding queries
CREATE INDEX IF NOT EXISTS idx_users_onboarding ON users(onboarding_completed, first_login);

-- ===========================
-- 2. Quality Reports Table
-- ===========================

CREATE TABLE IF NOT EXISTS quality_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    theme_id INT NOT NULL,
    tenant_id INT NOT NULL,
    report_type ENUM('ai_confidence', 'linting', 'full') NOT NULL,
    confidence_score DECIMAL(3,2) DEFAULT 0.00 COMMENT 'Overall confidence score (0-1)',
    report_data JSON NOT NULL COMMENT 'Full quality report',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,

    INDEX idx_quality_theme (theme_id),
    INDEX idx_quality_tenant (tenant_id),
    INDEX idx_quality_score (confidence_score),
    INDEX idx_quality_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Sprint 13: AI quality analysis and content linting reports';

-- ===========================
-- 3. Backup Metadata Table
-- ===========================

CREATE TABLE IF NOT EXISTS backup_metadata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_name VARCHAR(255) NOT NULL,
    backup_path VARCHAR(500) NOT NULL,
    size_bytes BIGINT NOT NULL,
    status ENUM('pending', 'completed', 'failed', 'deleted') DEFAULT 'completed',
    backup_type ENUM('manual', 'automated', 'scheduled') DEFAULT 'automated',
    includes JSON COMMENT 'What was included: database, catalog, config',
    checksum VARCHAR(64) COMMENT 'MD5 checksum for integrity',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,

    UNIQUE KEY uk_backup_name (backup_name),
    INDEX idx_backup_status (status),
    INDEX idx_backup_created (created_at),
    INDEX idx_backup_type (backup_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Sprint 13: Backup tracking and metadata';

-- ===========================
-- 4. System Diagnostics Log
-- ===========================

CREATE TABLE IF NOT EXISTS system_diagnostics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    status ENUM('healthy', 'degraded', 'unhealthy') NOT NULL,
    diagnostic_data JSON NOT NULL COMMENT 'Full diagnostic results',
    checks_passed INT DEFAULT 0,
    checks_failed INT DEFAULT 0,
    checks_warned INT DEFAULT 0,
    run_by INT DEFAULT NULL,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (run_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_diagnostic_status (status),
    INDEX idx_diagnostic_run_at (run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Sprint 13: System diagnostic history';

-- ===========================
-- 5. Export History
-- ===========================

CREATE TABLE IF NOT EXISTS export_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    theme_id INT NOT NULL,
    tenant_id INT NOT NULL,
    export_format VARCHAR(50) NOT NULL COMMENT 'qti, json, csv, etc.',
    target_lms VARCHAR(50) DEFAULT NULL COMMENT 'moodle, canvas, blackboard, etc.',
    file_size BIGINT DEFAULT NULL,
    options JSON COMMENT 'Export options used',
    exported_by INT NOT NULL,
    exported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (exported_by) REFERENCES users(id) ON DELETE CASCADE,

    INDEX idx_export_theme (theme_id),
    INDEX idx_export_tenant (tenant_id),
    INDEX idx_export_format (export_format),
    INDEX idx_export_date (exported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Sprint 13: Theme export history and analytics';

-- ===========================
-- 6. Enhanced Logging
-- ===========================

-- Add Sprint 13 event types to existing sync_logs
ALTER TABLE sync_logs
MODIFY COLUMN event_type VARCHAR(100) COMMENT 'Event type including Sprint 13 events';

-- Add index for new event types
CREATE INDEX IF NOT EXISTS idx_sync_logs_sprint13
ON sync_logs(event_type, created_at)
WHERE event_type IN (
    'onboarding_started', 'onboarding_completed', 'onboarding_skipped',
    'quality_analysis_performed', 'quality_lint_performed',
    'backup_started', 'backup_completed', 'backup_failed', 'backup_downloaded',
    'system_diagnostic_run',
    'theme_exported_qti', 'theme_exported_json'
);

-- ===========================
-- 7. Update Existing Tables
-- ===========================

-- Add quality_score to themes for quick filtering
ALTER TABLE themes
ADD COLUMN IF NOT EXISTS quality_score DECIMAL(3,2) DEFAULT NULL COMMENT 'Latest AI quality score',
ADD COLUMN IF NOT EXISTS quality_checked_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Last quality check';

CREATE INDEX IF NOT EXISTS idx_themes_quality ON themes(quality_score);

-- ===========================
-- 8. Views for Quick Access
-- ===========================

-- View: Recent Quality Reports
CREATE OR REPLACE VIEW v_recent_quality_reports AS
SELECT
    qr.id,
    qr.theme_id,
    t.title AS theme_title,
    qr.report_type,
    qr.confidence_score,
    qr.created_by,
    u.full_name AS created_by_name,
    qr.created_at
FROM quality_reports qr
JOIN themes t ON qr.theme_id = t.id
JOIN users u ON qr.created_by = u.id
WHERE qr.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY qr.created_at DESC;

-- View: Backup Status
CREATE OR REPLACE VIEW v_backup_status AS
SELECT
    backup_type,
    status,
    COUNT(*) AS count,
    SUM(size_bytes) AS total_size_bytes,
    MAX(created_at) AS last_backup_date,
    DATEDIFF(NOW(), MAX(created_at)) AS days_since_last
FROM backup_metadata
WHERE status != 'deleted'
GROUP BY backup_type, status;

-- View: System Health Summary
CREATE OR REPLACE VIEW v_system_health AS
SELECT
    DATE(run_at) AS check_date,
    status,
    COUNT(*) AS check_count,
    AVG(checks_passed) AS avg_checks_passed,
    AVG(checks_failed) AS avg_checks_failed,
    AVG(checks_warned) AS avg_checks_warned
FROM system_diagnostics
WHERE run_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(run_at), status;

-- ===========================
-- 9. Triggers
-- ===========================

-- Trigger: Update theme quality score on new report
DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_update_theme_quality
AFTER INSERT ON quality_reports
FOR EACH ROW
BEGIN
    UPDATE themes
    SET quality_score = NEW.confidence_score,
        quality_checked_at = NEW.created_at
    WHERE id = NEW.theme_id;
END//

DELIMITER ;

-- ===========================
-- 10. Stored Procedures
-- ===========================

-- Procedure: Clean old quality reports (retention: 90 days)
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_cleanup_old_quality_reports()
BEGIN
    DELETE FROM quality_reports
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);

    SELECT ROW_COUNT() AS deleted_reports;
END//

-- Procedure: Archive old diagnostics (retention: 30 days)
CREATE PROCEDURE IF NOT EXISTS sp_cleanup_old_diagnostics()
BEGIN
    DELETE FROM system_diagnostics
    WHERE run_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

    SELECT ROW_COUNT() AS deleted_diagnostics;
END//

-- Procedure: Get system health summary
CREATE PROCEDURE IF NOT EXISTS sp_get_system_health_summary()
BEGIN
    SELECT
        'diagnostics' AS metric,
        COUNT(*) AS count,
        SUM(CASE WHEN status = 'healthy' THEN 1 ELSE 0 END) AS healthy,
        SUM(CASE WHEN status = 'degraded' THEN 1 ELSE 0 END) AS degraded,
        SUM(CASE WHEN status = 'unhealthy' THEN 1 ELSE 0 END) AS unhealthy
    FROM system_diagnostics
    WHERE run_at > DATE_SUB(NOW(), INTERVAL 7 DAY)

    UNION ALL

    SELECT
        'backups' AS metric,
        COUNT(*) AS count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
        0 AS other
    FROM backup_metadata
    WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)

    UNION ALL

    SELECT
        'quality_checks' AS metric,
        COUNT(*) AS count,
        SUM(CASE WHEN confidence_score >= 0.75 THEN 1 ELSE 0 END) AS good,
        SUM(CASE WHEN confidence_score < 0.75 THEN 1 ELSE 0 END) AS needs_review,
        0 AS other
    FROM quality_reports
    WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY);
END//

DELIMITER ;

-- ===========================
-- 11. Insert Initial Data
-- ===========================

-- Mark existing users as having completed onboarding
UPDATE users
SET onboarding_completed = 1,
    first_login = 0
WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- ===========================
-- 12. Permissions & Grants
-- ===========================

-- Grant necessary permissions for backup operations
-- GRANT FILE ON *.* TO 'studymate_app'@'localhost';
-- Note: FILE privilege may be restricted in production

-- ===========================
-- Migration Verification
-- ===========================

-- Verify tables were created
SELECT
    table_name,
    table_rows,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
AND table_name IN (
    'quality_reports',
    'backup_metadata',
    'system_diagnostics',
    'export_history'
)
ORDER BY table_name;

-- ===========================
-- Rollback Script (use with caution)
-- ===========================

/*
-- To rollback this migration (NOT RECOMMENDED IN PRODUCTION):

DROP VIEW IF EXISTS v_recent_quality_reports;
DROP VIEW IF EXISTS v_backup_status;
DROP VIEW IF EXISTS v_system_health;

DROP TRIGGER IF EXISTS trg_update_theme_quality;

DROP PROCEDURE IF EXISTS sp_cleanup_old_quality_reports;
DROP PROCEDURE IF EXISTS sp_cleanup_old_diagnostics;
DROP PROCEDURE IF EXISTS sp_get_system_health_summary;

DROP TABLE IF EXISTS export_history;
DROP TABLE IF EXISTS system_diagnostics;
DROP TABLE IF EXISTS backup_metadata;
DROP TABLE IF EXISTS quality_reports;

ALTER TABLE users
DROP COLUMN IF EXISTS first_login,
DROP COLUMN IF EXISTS onboarding_completed,
DROP COLUMN IF EXISTS preferences;

ALTER TABLE themes
DROP COLUMN IF EXISTS quality_score,
DROP COLUMN IF EXISTS quality_checked_at;
*/

-- ==================================================
-- End of Sprint 13 Migration
-- ==================================================
