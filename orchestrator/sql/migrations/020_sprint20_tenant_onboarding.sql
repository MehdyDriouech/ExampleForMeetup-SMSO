-- ============================================================
-- Sprint 20: Tenant Onboarding Complet - Migration
-- Date: 2025-11-15
-- Description: Extension tenant + onboarding tracking + CSV import
-- ============================================================

-- ============================================================
-- 1. Extension de la table tenants
-- ============================================================

-- Ajouter colonnes pour onboarding et configuration
ALTER TABLE tenants
ADD COLUMN logo VARCHAR(500) NULL COMMENT 'URL/chemin du logo établissement' AFTER settings,
ADD COLUMN branding JSON DEFAULT NULL COMMENT 'Couleurs, thème visuel personnalisé' AFTER logo,
ADD COLUMN smtp_config JSON DEFAULT NULL COMMENT 'Configuration SMTP pour envoi emails' AFTER branding,
ADD COLUMN ia_policy JSON DEFAULT NULL COMMENT 'Politique d\'usage IA (GPT, Mistral)' AFTER smtp_config,
ADD COLUMN quota_ia JSON DEFAULT NULL COMMENT 'Quotas génération IA par mois' AFTER ia_policy,
ADD COLUMN onboarding_completed BOOLEAN DEFAULT FALSE COMMENT 'Onboarding terminé ou non' AFTER quota_ia,
ADD COLUMN onboarding_completed_at TIMESTAMP NULL COMMENT 'Date de fin d\'onboarding' AFTER onboarding_completed;

-- Index pour rechercher les tenants non onboardés
CREATE INDEX idx_onboarding_status ON tenants(onboarding_completed);

-- ============================================================
-- 2. Table onboarding_progress (Suivi étape par étape)
-- ============================================================

CREATE TABLE onboarding_progress (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    step VARCHAR(100) NOT NULL COMMENT 'welcome, tenant_info, admin_user, import_structure, config_smtp, config_branding, config_quotas, tour, completed',
    status ENUM('pending', 'in_progress', 'completed', 'skipped') DEFAULT 'pending',
    data JSON DEFAULT NULL COMMENT 'Données spécifiques à l\'étape',
    started_at TIMESTAMP NULL COMMENT 'Début de l\'étape',
    completed_at TIMESTAMP NULL COMMENT 'Fin de l\'étape',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_step (tenant_id, step),
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_step (step)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracking du parcours d\'onboarding étape par étape';

-- ============================================================
-- 3. Table import_jobs (Jobs d'import CSV)
-- ============================================================

CREATE TABLE import_jobs (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    triggered_by VARCHAR(50) NOT NULL COMMENT 'User ID qui a lancé l\'import',
    import_type ENUM('students', 'teachers', 'classes', 'promotions', 'bulk_structure') NOT NULL,
    file_path VARCHAR(500) NOT NULL COMMENT 'Chemin du fichier CSV uploadé',
    file_hash VARCHAR(64) NOT NULL COMMENT 'SHA256 du fichier CSV',
    status ENUM('pending', 'validating', 'processing', 'completed', 'failed', 'partial') DEFAULT 'pending',
    total_rows INT DEFAULT 0 COMMENT 'Nombre total de lignes dans le CSV',
    imported_count INT DEFAULT 0 COMMENT 'Nombre de lignes importées avec succès',
    skipped_count INT DEFAULT 0 COMMENT 'Nombre de lignes ignorées (doublons)',
    error_count INT DEFAULT 0 COMMENT 'Nombre de lignes en erreur',
    errors JSON DEFAULT NULL COMMENT 'Détails des erreurs ligne par ligne',
    warnings JSON DEFAULT NULL COMMENT 'Avertissements non bloquants',
    validation_report JSON DEFAULT NULL COMMENT 'Rapport de validation pré-import',
    result_summary JSON DEFAULT NULL COMMENT 'Résumé post-import (IDs créés, etc.)',
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_triggered_by (triggered_by),
    INDEX idx_type (import_type),
    INDEX idx_created (created_at),
    INDEX idx_file_hash (file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Historique et tracking des imports CSV massifs';

-- ============================================================
-- 4. Table onboarding_templates (Templates de configuration)
-- ============================================================

CREATE TABLE onboarding_templates (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Nom du template (ex: Collège Standard, Lycée Pro)',
    type ENUM('college', 'lycee', 'universite', 'ecole_primaire', 'custom') NOT NULL,
    description TEXT,
    default_settings JSON NOT NULL COMMENT 'Settings tenant par défaut',
    default_ia_policy JSON NOT NULL COMMENT 'Politique IA par défaut',
    default_quota_ia JSON NOT NULL COMMENT 'Quotas IA par défaut',
    default_licences JSON NOT NULL COMMENT 'Quotas licences par défaut',
    is_system BOOLEAN DEFAULT TRUE COMMENT 'Template système ou personnalisé',
    created_by VARCHAR(50) NULL COMMENT 'User qui a créé (si custom)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_system (is_system)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Templates de configuration pour accélérer l\'onboarding';

-- ============================================================
-- 5. Table tenant_onboarding_invites (Invitations équipe)
-- ============================================================

CREATE TABLE tenant_onboarding_invites (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    invited_by VARCHAR(50) NULL COMMENT 'User qui a invité',
    email VARCHAR(255) NOT NULL,
    role ENUM('admin', 'direction', 'teacher', 'inspector', 'referent', 'intervenant') NOT NULL,
    token VARCHAR(100) NOT NULL COMMENT 'Token d\'invitation unique',
    status ENUM('pending', 'accepted', 'expired', 'revoked') DEFAULT 'pending',
    expires_at TIMESTAMP NOT NULL COMMENT 'Expiration du lien (72h)',
    accepted_at TIMESTAMP NULL,
    created_user_id VARCHAR(50) NULL COMMENT 'User créé après acceptation',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_token (token),
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_email (email),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Invitations en attente pour rejoindre un établissement';

-- ============================================================
-- 6. Données initiales: Templates d'onboarding
-- ============================================================

INSERT INTO onboarding_templates (id, name, type, description, default_settings, default_ia_policy, default_quota_ia, default_licences, is_system) VALUES
(
    'template_college_standard',
    'Collège Standard',
    'college',
    'Configuration par défaut pour un collège (400 élèves, 30 profs)',
    JSON_OBJECT(
        'academic_year_start', 9,
        'academic_year_end', 7,
        'timezone', 'Europe/Paris',
        'language', 'fr'
    ),
    JSON_OBJECT(
        'allow_ai_generation', true,
        'providers', JSON_ARRAY('mistral'),
        'require_review', true,
        'auto_publish', false,
        'max_generations_per_user_per_day', 10
    ),
    JSON_OBJECT(
        'monthly_quota', 1000,
        'used_this_month', 0,
        'reset_day', 1,
        'warning_threshold', 80
    ),
    JSON_OBJECT(
        'max_teachers', 30,
        'max_students', 400,
        'max_classes', 16,
        'subscription_type', 'standard',
        'expires_months', 12
    ),
    true
),
(
    'template_lycee_standard',
    'Lycée Standard',
    'lycee',
    'Configuration par défaut pour un lycée (800 élèves, 60 profs)',
    JSON_OBJECT(
        'academic_year_start', 9,
        'academic_year_end', 7,
        'timezone', 'Europe/Paris',
        'language', 'fr'
    ),
    JSON_OBJECT(
        'allow_ai_generation', true,
        'providers', JSON_ARRAY('mistral', 'openai'),
        'require_review', true,
        'auto_publish', false,
        'max_generations_per_user_per_day', 15
    ),
    JSON_OBJECT(
        'monthly_quota', 2000,
        'used_this_month', 0,
        'reset_day', 1,
        'warning_threshold', 80
    ),
    JSON_OBJECT(
        'max_teachers', 60,
        'max_students', 800,
        'max_classes', 30,
        'subscription_type', 'pro',
        'expires_months', 12
    ),
    true
),
(
    'template_universite',
    'Université / Grande École',
    'universite',
    'Configuration pour enseignement supérieur (2000 étudiants, 100 profs)',
    JSON_OBJECT(
        'academic_year_start', 9,
        'academic_year_end', 6,
        'timezone', 'Europe/Paris',
        'language', 'fr'
    ),
    JSON_OBJECT(
        'allow_ai_generation', true,
        'providers', JSON_ARRAY('mistral', 'openai'),
        'require_review', false,
        'auto_publish', true,
        'max_generations_per_user_per_day', 25
    ),
    JSON_OBJECT(
        'monthly_quota', 5000,
        'used_this_month', 0,
        'reset_day', 1,
        'warning_threshold', 85
    ),
    JSON_OBJECT(
        'max_teachers', 100,
        'max_students', 2000,
        'max_classes', 80,
        'subscription_type', 'enterprise',
        'expires_months', 12
    ),
    true
);

-- ============================================================
-- 7. Extension audit_log pour onboarding
-- ============================================================

-- Ajout d'actions spécifiques onboarding dans l'audit_log
-- (Pas de modification structurelle, juste utilisation de nouveaux action_type)
-- Exemples: 'onboarding:start', 'onboarding:complete_step', 'import:csv', 'tenant:configure'

-- ============================================================
-- 8. Vue simplifiée pour dashboard onboarding
-- ============================================================

CREATE OR REPLACE VIEW v_onboarding_dashboard AS
SELECT
    t.id AS tenant_id,
    t.name AS tenant_name,
    t.type AS tenant_type,
    t.status AS tenant_status,
    t.onboarding_completed,
    t.onboarding_completed_at,
    t.created_at AS tenant_created_at,

    -- Progrès onboarding
    COUNT(DISTINCT op.id) AS total_steps,
    SUM(CASE WHEN op.status = 'completed' THEN 1 ELSE 0 END) AS completed_steps,
    SUM(CASE WHEN op.status = 'skipped' THEN 1 ELSE 0 END) AS skipped_steps,
    ROUND(
        (SUM(CASE WHEN op.status IN ('completed', 'skipped') THEN 1 ELSE 0 END) * 100.0) / NULLIF(COUNT(DISTINCT op.id), 0),
        2
    ) AS progress_percentage,

    -- Stats utilisateurs
    tl.max_teachers,
    tl.used_teachers,
    tl.max_students,
    tl.used_students,
    tl.max_classes,
    tl.used_classes,

    -- Stats imports
    (SELECT COUNT(*) FROM import_jobs WHERE tenant_id = t.id AND status = 'completed') AS successful_imports,
    (SELECT COUNT(*) FROM import_jobs WHERE tenant_id = t.id AND status = 'failed') AS failed_imports

FROM tenants t
LEFT JOIN onboarding_progress op ON t.id = op.tenant_id
LEFT JOIN tenant_licences tl ON t.id = tl.tenant_id
GROUP BY t.id, t.name, t.type, t.status, t.onboarding_completed, t.onboarding_completed_at, t.created_at,
         tl.max_teachers, tl.used_teachers, tl.max_students, tl.used_students, tl.max_classes, tl.used_classes;

-- ============================================================
-- 9. Trigger pour initialiser onboarding_progress
-- ============================================================

DELIMITER $$

CREATE TRIGGER trg_tenant_init_onboarding
AFTER INSERT ON tenants
FOR EACH ROW
BEGIN
    -- Créer les étapes d'onboarding par défaut
    INSERT INTO onboarding_progress (id, tenant_id, step, status) VALUES
        (CONCAT('onb_', NEW.id, '_welcome'), NEW.id, 'welcome', 'pending'),
        (CONCAT('onb_', NEW.id, '_tenant_info'), NEW.id, 'tenant_info', 'pending'),
        (CONCAT('onb_', NEW.id, '_admin_user'), NEW.id, 'admin_user', 'pending'),
        (CONCAT('onb_', NEW.id, '_import_structure'), NEW.id, 'import_structure', 'pending'),
        (CONCAT('onb_', NEW.id, '_config_smtp'), NEW.id, 'config_smtp', 'pending'),
        (CONCAT('onb_', NEW.id, '_config_branding'), NEW.id, 'config_branding', 'pending'),
        (CONCAT('onb_', NEW.id, '_config_quotas'), NEW.id, 'config_quotas', 'pending'),
        (CONCAT('onb_', NEW.id, '_tour'), NEW.id, 'tour', 'pending'),
        (CONCAT('onb_', NEW.id, '_completed'), NEW.id, 'completed', 'pending');

    -- Initialiser les licences par défaut si pas déjà créées
    INSERT IGNORE INTO tenant_licences (tenant_id, max_teachers, max_students, max_classes, subscription_type)
    VALUES (NEW.id, 10, 100, 10, 'trial');
END$$

DELIMITER ;

-- ============================================================
-- 10. Fonction helper pour vérifier si onboarding est complet
-- ============================================================

DELIMITER $$

CREATE FUNCTION fn_check_onboarding_complete(p_tenant_id VARCHAR(50))
RETURNS BOOLEAN
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_total INT;
    DECLARE v_completed INT;
    DECLARE v_required INT;

    -- Compter les étapes obligatoires (toutes sauf tour qui est optionnel)
    SELECT COUNT(*) INTO v_required
    FROM onboarding_progress
    WHERE tenant_id = p_tenant_id
      AND step != 'tour';

    -- Compter les étapes complétées ou skippées
    SELECT COUNT(*) INTO v_completed
    FROM onboarding_progress
    WHERE tenant_id = p_tenant_id
      AND step != 'tour'
      AND status IN ('completed', 'skipped');

    -- Retourner TRUE si toutes les étapes obligatoires sont faites
    RETURN (v_completed = v_required);
END$$

DELIMITER ;

-- ============================================================
-- FIN MIGRATION SPRINT 20
-- ============================================================

-- Vérification
SELECT 'Sprint 20 migration completed successfully!' AS status;
