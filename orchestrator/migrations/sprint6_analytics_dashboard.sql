-- Migration: Sprint 6 - Learning Analytics Dashboard
-- Date: 2025-11-13
-- Description: Performance optimizations for analytics queries

-- ============================================================
-- Performance Indexes for Analytics
-- ============================================================

-- Optimize stats table for analytics aggregations
-- These indexes improve query performance for KPIs and heatmap
CREATE INDEX IF NOT EXISTS idx_stats_analytics_theme_score
    ON stats(tenant_id, theme_id, score, synced_at);

CREATE INDEX IF NOT EXISTS idx_stats_analytics_mastery
    ON stats(tenant_id, mastery, last_activity_at);

-- Optimize student_sessions for analytics queries
CREATE INDEX IF NOT EXISTS idx_student_sessions_analytics
    ON student_sessions(tenant_id, status, created_at, score);

CREATE INDEX IF NOT EXISTS idx_student_sessions_completed
    ON student_sessions(tenant_id, completed_at, score)
    WHERE completed_at IS NOT NULL;

-- Optimize assignments for dashboard queries
CREATE INDEX IF NOT EXISTS idx_assignments_analytics
    ON assignments(tenant_id, status, teacher_id, theme_id);

-- Composite index for heatmap queries (theme difficulty analysis)
CREATE INDEX IF NOT EXISTS idx_stats_heatmap
    ON stats(tenant_id, theme_id, score, attempts, synced_at);

-- Index for student activity tracking (last 7 days filter)
CREATE INDEX IF NOT EXISTS idx_stats_recent_activity
    ON stats(tenant_id, student_id, last_activity_at)
    WHERE last_activity_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);

-- ============================================================
-- Comments for Documentation
-- ============================================================

-- Add helpful comments for analytics-related tables
ALTER TABLE stats COMMENT = 'Student performance statistics from ErgoMate. Used for KPIs and heatmap analytics (Sprint 6).';

ALTER TABLE student_sessions COMMENT = 'Individual student sessions on assignments. Tracks detailed progress for analytics (Sprint 5/6).';

-- ============================================================
-- Verify Indexes
-- ============================================================

-- Show all indexes on stats table
SHOW INDEX FROM stats;

-- Show all indexes on student_sessions table
SHOW INDEX FROM student_sessions;

-- ============================================================
-- Analytics Cache Table (Optional - for future optimization)
-- ============================================================

-- If needed in future sprints, we could add a pre-aggregated cache table:
-- CREATE TABLE IF NOT EXISTS analytics_cache (
--     id VARCHAR(64) PRIMARY KEY,
--     tenant_id VARCHAR(64) NOT NULL,
--     cache_key VARCHAR(255) NOT NULL,
--     cache_type ENUM('kpis', 'heatmap', 'trends') NOT NULL,
--     filters JSON DEFAULT NULL,
--     data JSON NOT NULL,
--     created_at DATETIME NOT NULL,
--     expires_at DATETIME NOT NULL,
--     INDEX idx_analytics_cache_key (tenant_id, cache_key, cache_type),
--     INDEX idx_analytics_cache_expiry (expires_at)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- ============================================================
-- Sprint 6 Summary
-- ============================================================

-- **Implemented**:
-- 1. GET /api/analytics/kpis - Consolidated KPIs with filters
-- 2. GET /api/analytics/heatmap - Difficulty heatmap by theme
-- 3. Dashboard frontend with analytics section (view-dashboard.js)
-- 4. RBAC permissions for analytics endpoints
-- 5. 5-minute cache for performance (<1s on 10k rows)
-- 6. Filter persistence (localStorage)
-- 7. Remediation CTA on high-failure themes

-- **Future Sprints** (E6-REP, E6-ALERT, E6-EXP):
-- - AI-generated reports (Mistral synthesis)
-- - Early warning alerts (dropout detection)
-- - PDF/CSV exports
