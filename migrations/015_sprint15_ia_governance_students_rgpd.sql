-- =====================================================
-- Sprint 15: IA Governance + Students + RGPD
-- =====================================================
-- Generated: 2025-11-14
-- Description: Schema for student identity management,
--              IA policies, IA budgets, and IA audit logs
-- =====================================================

-- ============================================
-- 1. Students table modifications
-- ============================================

-- Add UUID columns for student identity
ALTER TABLE students
ADD COLUMN IF NOT EXISTS uuid_student VARCHAR(36) UNIQUE COMMENT 'UUID pédagogique unique pour l''élève',
ADD COLUMN IF NOT EXISTS uuid_social VARCHAR(36) UNIQUE COMMENT 'UUID pour le suivi social anonymisé',
ADD COLUMN IF NOT EXISTS rgpd_status ENUM('active', 'pseudonymized', 'deleted') DEFAULT 'active' COMMENT 'Statut RGPD de l''élève',
ADD COLUMN IF NOT EXISTS rgpd_pseudonymized_at TIMESTAMP NULL COMMENT 'Date de pseudonymisation',
ADD COLUMN IF NOT EXISTS rgpd_deleted_at TIMESTAMP NULL COMMENT 'Date de suppression logique',
ADD COLUMN IF NOT EXISTS rgpd_export_count INT DEFAULT 0 COMMENT 'Nombre d''exports RGPD demandés';

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_students_uuid_student ON students(uuid_student);
CREATE INDEX IF NOT EXISTS idx_students_uuid_social ON students(uuid_social);
CREATE INDEX IF NOT EXISTS idx_students_rgpd_status ON students(rgpd_status);
CREATE INDEX IF NOT EXISTS idx_students_tenant_rgpd ON students(tenant_id, rgpd_status);

-- ============================================
-- 2. IA Policies table (per tenant)
-- ============================================

CREATE TABLE IF NOT EXISTS ia_policies (
    id VARCHAR(36) PRIMARY KEY COMMENT 'UUID de la politique',
    tenant_id VARCHAR(100) NOT NULL COMMENT 'ID du tenant',

    -- IA activation
    ia_enabled BOOLEAN DEFAULT TRUE COMMENT 'IA activée pour ce tenant',
    ia_disabled_reason TEXT NULL COMMENT 'Raison de la désactivation',
    ia_disabled_at TIMESTAMP NULL COMMENT 'Date de désactivation',
    ia_disabled_by VARCHAR(36) NULL COMMENT 'User ID qui a désactivé',

    -- BYOK (Bring Your Own Key)
    byok_enabled BOOLEAN DEFAULT FALSE COMMENT 'Utilisation d''une clé API personnalisée',
    api_key_encrypted TEXT NULL COMMENT 'Clé API chiffrée (AES-256)',
    api_provider VARCHAR(50) NULL COMMENT 'Fournisseur: openai, anthropic, etc.',

    -- Model configuration
    default_model VARCHAR(100) DEFAULT 'gpt-4o-mini' COMMENT 'Modèle par défaut',
    allowed_models JSON COMMENT 'Liste des modèles autorisés',

    -- Safety & compliance
    content_filter_level ENUM('none', 'low', 'medium', 'high') DEFAULT 'medium' COMMENT 'Niveau de filtrage du contenu',
    data_retention_days INT DEFAULT 90 COMMENT 'Durée de conservation des logs IA',

    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by VARCHAR(36) COMMENT 'User ID créateur',

    UNIQUE KEY unique_tenant_policy (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_ia_policies_tenant (tenant_id),
    INDEX idx_ia_policies_enabled (ia_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Politiques IA par tenant';

-- ============================================
-- 3. IA Budgets table (tenant + teacher)
-- ============================================

CREATE TABLE IF NOT EXISTS ia_budgets (
    id VARCHAR(36) PRIMARY KEY COMMENT 'UUID du budget',
    tenant_id VARCHAR(100) NOT NULL COMMENT 'ID du tenant',

    -- Budget ownership
    budget_type ENUM('tenant', 'teacher') NOT NULL COMMENT 'Type de budget',
    user_id VARCHAR(36) NULL COMMENT 'User ID (NULL si budget tenant)',

    -- Budget limits
    max_tokens INT NOT NULL DEFAULT 1000000 COMMENT 'Nombre maximum de tokens',
    used_tokens INT DEFAULT 0 COMMENT 'Tokens utilisés',
    max_requests INT NULL COMMENT 'Nombre maximum de requêtes (optionnel)',
    used_requests INT DEFAULT 0 COMMENT 'Requêtes utilisées',

    -- Period
    period_start TIMESTAMP NOT NULL COMMENT 'Début de la période budgétaire',
    period_end TIMESTAMP NOT NULL COMMENT 'Fin de la période budgétaire',
    auto_reset BOOLEAN DEFAULT TRUE COMMENT 'Réinitialisation automatique à chaque période',

    -- Alerts
    alert_threshold_percent INT DEFAULT 80 COMMENT 'Seuil d''alerte en pourcentage',
    alert_sent BOOLEAN DEFAULT FALSE COMMENT 'Alerte envoyée',
    alert_sent_at TIMESTAMP NULL COMMENT 'Date d''envoi de l''alerte',

    -- Status
    status ENUM('active', 'exceeded', 'expired', 'suspended') DEFAULT 'active' COMMENT 'Statut du budget',

    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_ia_budgets_tenant (tenant_id),
    INDEX idx_ia_budgets_user (user_id),
    INDEX idx_ia_budgets_type (budget_type),
    INDEX idx_ia_budgets_status (status),
    INDEX idx_ia_budgets_period (period_start, period_end),
    UNIQUE KEY unique_tenant_budget (tenant_id, budget_type, user_id, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Budgets IA par tenant et enseignant';

-- ============================================
-- 4. IA Audit Log table
-- ============================================

CREATE TABLE IF NOT EXISTS audit_ia_log (
    id VARCHAR(36) PRIMARY KEY COMMENT 'UUID du log',
    tenant_id VARCHAR(100) NOT NULL COMMENT 'ID du tenant',

    -- Actor
    user_id VARCHAR(36) NOT NULL COMMENT 'User ID qui a fait la requête',
    user_role VARCHAR(50) COMMENT 'Rôle de l''utilisateur',

    -- Request details
    prompt_text TEXT NOT NULL COMMENT 'Texte du prompt',
    prompt_hash VARCHAR(64) COMMENT 'Hash SHA-256 du prompt pour déduplication',

    -- Model details
    model_used VARCHAR(100) NOT NULL COMMENT 'Modèle utilisé',
    model_version VARCHAR(50) COMMENT 'Version du modèle',
    api_provider VARCHAR(50) DEFAULT 'openai' COMMENT 'Fournisseur API',

    -- Response
    response_text TEXT COMMENT 'Réponse générée par l''IA',
    response_truncated BOOLEAN DEFAULT FALSE COMMENT 'Réponse tronquée si trop longue',

    -- Metrics
    tokens_prompt INT COMMENT 'Tokens utilisés pour le prompt',
    tokens_completion INT COMMENT 'Tokens utilisés pour la réponse',
    tokens_total INT COMMENT 'Total de tokens',
    latency_ms INT COMMENT 'Latence en millisecondes',

    -- Context
    context_type VARCHAR(50) COMMENT 'Type de contexte: theme_creation, improvement, coaching, etc.',
    context_id VARCHAR(36) COMMENT 'ID de l''entité concernée',

    -- Status
    status ENUM('success', 'error', 'filtered', 'budget_exceeded') DEFAULT 'success' COMMENT 'Statut de la requête',
    error_message TEXT NULL COMMENT 'Message d''erreur si échec',

    -- Compliance
    content_filtered BOOLEAN DEFAULT FALSE COMMENT 'Contenu filtré pour sécurité',
    flagged_reason TEXT NULL COMMENT 'Raison du filtrage',

    -- Metadata
    ip_address VARCHAR(45) COMMENT 'Adresse IP de l''utilisateur',
    user_agent TEXT COMMENT 'User agent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Date de création',

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_audit_ia_tenant (tenant_id),
    INDEX idx_audit_ia_user (user_id),
    INDEX idx_audit_ia_created (created_at),
    INDEX idx_audit_ia_model (model_used),
    INDEX idx_audit_ia_status (status),
    INDEX idx_audit_ia_context (context_type, context_id),
    INDEX idx_audit_ia_tenant_created (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Journal d''audit des interactions IA';

-- ============================================
-- 5. RGPD Export Requests table (optionnel)
-- ============================================

CREATE TABLE IF NOT EXISTS rgpd_export_requests (
    id VARCHAR(36) PRIMARY KEY COMMENT 'UUID de la demande',
    tenant_id VARCHAR(100) NOT NULL COMMENT 'ID du tenant',
    student_uuid VARCHAR(36) NOT NULL COMMENT 'UUID de l''élève',

    -- Request details
    requested_by VARCHAR(36) NOT NULL COMMENT 'User ID qui a demandé l''export',
    request_reason TEXT COMMENT 'Raison de la demande',

    -- Status
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending' COMMENT 'Statut de la demande',
    export_format VARCHAR(20) DEFAULT 'json' COMMENT 'Format d''export: json, pdf, csv',

    -- Result
    export_file_path TEXT NULL COMMENT 'Chemin du fichier exporté',
    export_size_bytes INT NULL COMMENT 'Taille du fichier en bytes',

    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    error_message TEXT NULL,

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_rgpd_exports_tenant (tenant_id),
    INDEX idx_rgpd_exports_student (student_uuid),
    INDEX idx_rgpd_exports_status (status),
    INDEX idx_rgpd_exports_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Demandes d''export RGPD';

-- ============================================
-- 6. Default data insertion
-- ============================================

-- Insert default IA policy for existing tenants
INSERT INTO ia_policies (id, tenant_id, ia_enabled, default_model, allowed_models, content_filter_level, data_retention_days)
SELECT
    UUID(),
    id AS tenant_id,
    TRUE AS ia_enabled,
    'gpt-4o-mini' AS default_model,
    '["gpt-4o-mini", "gpt-4o", "claude-3-5-sonnet"]' AS allowed_models,
    'medium' AS content_filter_level,
    90 AS data_retention_days
FROM tenants
WHERE id NOT IN (SELECT tenant_id FROM ia_policies);

-- Insert default tenant budgets (1M tokens per month)
INSERT INTO ia_budgets (id, tenant_id, budget_type, max_tokens, period_start, period_end, status)
SELECT
    UUID(),
    id AS tenant_id,
    'tenant' AS budget_type,
    1000000 AS max_tokens,
    DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00') AS period_start,
    LAST_DAY(NOW()) + INTERVAL 1 DAY - INTERVAL 1 SECOND AS period_end,
    'active' AS status
FROM tenants
WHERE id NOT IN (
    SELECT tenant_id FROM ia_budgets WHERE budget_type = 'tenant' AND period_end >= NOW()
);

-- ============================================
-- 7. Update existing students with UUIDs
-- ============================================

-- Generate UUIDs for existing students that don't have them
UPDATE students
SET
    uuid_student = UUID(),
    uuid_social = UUID()
WHERE uuid_student IS NULL OR uuid_social IS NULL;

-- Make uuid_student and uuid_social NOT NULL after population
-- (Run this after verifying all students have UUIDs)
-- ALTER TABLE students MODIFY uuid_student VARCHAR(36) NOT NULL;
-- ALTER TABLE students MODIFY uuid_social VARCHAR(36) NOT NULL;

-- =====================================================
-- END OF MIGRATION
-- =====================================================

-- Verification queries (uncomment to run)
-- SELECT COUNT(*) as total_students, COUNT(uuid_student) as with_uuid FROM students;
-- SELECT COUNT(*) as total_policies FROM ia_policies;
-- SELECT COUNT(*) as total_budgets FROM ia_budgets;
-- SELECT tenant_id, ia_enabled, default_model FROM ia_policies;
