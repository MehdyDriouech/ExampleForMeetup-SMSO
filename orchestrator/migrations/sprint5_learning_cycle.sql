-- Migration: Sprint 5 - Learning Cycle (Pédagogie côté élève)
-- Date: 2025-11-13
-- Description: Tables for student missions, sessions, badges, and reviews

-- ============================================================
-- Student Sessions Table
-- Tracks individual student sessions on assignments
-- ============================================================
CREATE TABLE IF NOT EXISTS student_sessions (
    id VARCHAR(64) PRIMARY KEY,
    student_id VARCHAR(64) NOT NULL,
    assignment_id VARCHAR(64) NOT NULL,
    tenant_id VARCHAR(64) NOT NULL,
    status ENUM('a_faire', 'en_cours', 'terminee') DEFAULT 'a_faire',
    score FLOAT DEFAULT NULL,
    time_spent INT DEFAULT NULL COMMENT 'Time spent in seconds',
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    errors TEXT DEFAULT NULL COMMENT 'JSON array of errors',
    correct_answers INT DEFAULT NULL,
    metadata TEXT DEFAULT NULL COMMENT 'Additional session metadata',
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    INDEX idx_student_sessions_student (student_id),
    INDEX idx_student_sessions_assignment (assignment_id),
    INDEX idx_student_sessions_tenant (tenant_id),
    INDEX idx_student_sessions_status (status),
    INDEX idx_student_sessions_completed (completed_at),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Badges Table
-- Badge definitions
-- ============================================================
CREATE TABLE IF NOT EXISTS badges (
    id VARCHAR(64) PRIMARY KEY,
    tenant_id VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    icon VARCHAR(50) DEFAULT NULL COMMENT 'Emoji or icon identifier',
    category VARCHAR(100) DEFAULT NULL COMMENT 'e.g. débutant, progression, excellence',
    tier ENUM('bronze', 'silver', 'gold', 'platinum') DEFAULT 'bronze',
    criteria TEXT DEFAULT NULL COMMENT 'JSON criteria for earning the badge',
    created_at DATETIME NOT NULL,
    INDEX idx_badges_tenant (tenant_id),
    INDEX idx_badges_category (category),
    INDEX idx_badges_tier (tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Student Badges Table
-- Badges earned by students
-- ============================================================
CREATE TABLE IF NOT EXISTS student_badges (
    id VARCHAR(64) PRIMARY KEY,
    student_id VARCHAR(64) NOT NULL,
    badge_id VARCHAR(64) NOT NULL,
    tenant_id VARCHAR(64) NOT NULL,
    earned_at DATETIME NOT NULL,
    metadata TEXT DEFAULT NULL COMMENT 'Additional context about earning',
    INDEX idx_student_badges_student (student_id),
    INDEX idx_student_badges_badge (badge_id),
    INDEX idx_student_badges_tenant (tenant_id),
    INDEX idx_student_badges_earned (earned_at),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_badge (student_id, badge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Review Sessions Table
-- Track review/remediation sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS review_sessions (
    id VARCHAR(64) PRIMARY KEY,
    student_id VARCHAR(64) NOT NULL,
    theme_id VARCHAR(64) NOT NULL,
    tenant_id VARCHAR(64) NOT NULL,
    items TEXT DEFAULT NULL COMMENT 'JSON array of items to review',
    status ENUM('en_cours', 'terminee', 'abandoned') DEFAULT 'en_cours',
    score FLOAT DEFAULT NULL,
    time_spent INT DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_review_sessions_student (student_id),
    INDEX idx_review_sessions_theme (theme_id),
    INDEX idx_review_sessions_tenant (tenant_id),
    INDEX idx_review_sessions_status (status),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Add columns to existing tables if needed
-- ============================================================

-- Add received_count and completed_count to assignments if not exists
ALTER TABLE assignments
ADD COLUMN IF NOT EXISTS received_count INT DEFAULT 0 COMMENT 'Number of students who received the assignment',
ADD COLUMN IF NOT EXISTS completed_count INT DEFAULT 0 COMMENT 'Number of students who completed',
ADD COLUMN IF NOT EXISTS ergo_ack_at DATETIME DEFAULT NULL COMMENT 'Last acknowledgement from ErgoMate';

-- ============================================================
-- Sample Badges for Testing
-- ============================================================
INSERT INTO badges (id, tenant_id, name, description, icon, category, tier, criteria, created_at)
SELECT
    'BADGE_FIRST_STEP' as id,
    'TENANT_INST_PARIS' as tenant_id,
    'Premier Pas' as name,
    'Complétez votre première session' as description,
    '🎯' as icon,
    'débutant' as category,
    'bronze' as tier,
    '[{"type":"total_sessions","operator":">=","value":1}]' as criteria,
    NOW() as created_at
WHERE NOT EXISTS (SELECT 1 FROM badges WHERE id = 'BADGE_FIRST_STEP');

INSERT INTO badges (id, tenant_id, name, description, icon, category, tier, criteria, created_at)
SELECT
    'BADGE_PERSISTENT' as id,
    'TENANT_INST_PARIS' as tenant_id,
    'Persévérant' as name,
    'Complétez 10 sessions' as description,
    '💪' as icon,
    'progression' as category,
    'bronze' as tier,
    '[{"type":"total_sessions","operator":">=","value":10}]' as criteria,
    NOW() as created_at
WHERE NOT EXISTS (SELECT 1 FROM badges WHERE id = 'BADGE_PERSISTENT');

INSERT INTO badges (id, tenant_id, name, description, icon, category, tier, criteria, created_at)
SELECT
    'BADGE_EXPERT' as id,
    'TENANT_INST_PARIS' as tenant_id,
    'Expert en Herbe' as name,
    'Atteignez un score moyen de 80%' as description,
    '📚' as icon,
    'excellence' as category,
    'silver' as tier,
    '[{"type":"avg_score","operator":">=","value":80}]' as criteria,
    NOW() as created_at
WHERE NOT EXISTS (SELECT 1 FROM badges WHERE id = 'BADGE_EXPERT');

INSERT INTO badges (id, tenant_id, name, description, icon, category, tier, criteria, created_at)
SELECT
    'BADGE_PERFECTIONIST' as id,
    'TENANT_INST_PARIS' as tenant_id,
    'Perfectionniste' as name,
    'Obtenez 5 scores parfaits (100%)' as description,
    '⭐' as icon,
    'excellence' as category,
    'gold' as tier,
    '[{"type":"perfect_score_count","operator":">=","value":5}]' as criteria,
    NOW() as created_at
WHERE NOT EXISTS (SELECT 1 FROM badges WHERE id = 'BADGE_PERFECTIONIST');

INSERT INTO badges (id, tenant_id, name, description, icon, category, tier, criteria, created_at)
SELECT
    'BADGE_REGULAR' as id,
    'TENANT_INST_PARIS' as tenant_id,
    'Régulier' as name,
    'Travaillez 7 jours d\'affilée' as description,
    '🔥' as icon,
    'régularité' as category,
    'silver' as tier,
    '[{"type":"streak_days","operator":">=","value":7}]' as criteria,
    NOW() as created_at
WHERE NOT EXISTS (SELECT 1 FROM badges WHERE id = 'BADGE_REGULAR');

INSERT INTO badges (id, tenant_id, name, description, icon, category, tier, criteria, created_at)
SELECT
    'BADGE_MASTER' as id,
    'TENANT_INST_PARIS' as tenant_id,
    'Maître' as name,
    'Maîtrisez 5 thèmes (80%+)' as description,
    '👑' as icon,
    'maîtrise' as category,
    'gold' as tier,
    '[{"type":"theme_mastery","operator":">=","value":5}]' as criteria,
    NOW() as created_at
WHERE NOT EXISTS (SELECT 1 FROM badges WHERE id = 'BADGE_MASTER');

-- Repeat for Lyon tenant
INSERT INTO badges (id, tenant_id, name, description, icon, category, tier, criteria, created_at)
SELECT
    'BADGE_FIRST_STEP_LYON' as id,
    'TENANT_UNIV_LYON' as tenant_id,
    'Premier Pas' as name,
    'Complétez votre première session' as description,
    '🎯' as icon,
    'débutant' as category,
    'bronze' as tier,
    '[{"type":"total_sessions","operator":">=","value":1}]' as criteria,
    NOW() as created_at
WHERE NOT EXISTS (SELECT 1 FROM badges WHERE id = 'BADGE_FIRST_STEP_LYON');

COMMIT;
