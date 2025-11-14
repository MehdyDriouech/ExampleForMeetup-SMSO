-- ============================================================
-- Study-mate School Orchestrator - Database Schema v1.0
-- MySQL 5.7+ / MariaDB 10.3+
-- Date: 2025-11-10
-- ============================================================

-- Suppression des tables existantes (dev only)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS mistral_queue;
DROP TABLE IF EXISTS api_keys;
DROP TABLE IF EXISTS assignment_targets;
DROP TABLE IF EXISTS assignments;
DROP TABLE IF EXISTS stats;
DROP TABLE IF EXISTS sync_logs;
DROP TABLE IF EXISTS themes;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS classes;
DROP TABLE IF EXISTS promotions;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS tenants;
DROP TABLE IF EXISTS sessions;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- TABLE: tenants (Écoles/Établissements)
-- ============================================================
CREATE TABLE tenants (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type ENUM('public', 'private') DEFAULT 'public',
    email VARCHAR(255),
    phone VARCHAR(50),
    address TEXT,
    settings JSON DEFAULT NULL COMMENT 'Configuration spécifique école',
    status ENUM('active', 'suspended', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: users (Professeurs, Direction, Admin)
-- ============================================================
CREATE TABLE users (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL COMMENT 'bcrypt hash',
    firstname VARCHAR(100) NOT NULL,
    lastname VARCHAR(100) NOT NULL,
    role ENUM('admin', 'direction', 'teacher', 'inspector', 'referent', 'intervenant') NOT NULL,
    status ENUM('active', 'inactive', 'pending') DEFAULT 'pending',
    last_login_at TIMESTAMP NULL,
    deactivated_at TIMESTAMP NULL COMMENT 'Date de désactivation',
    deactivated_by VARCHAR(50) NULL COMMENT 'User ID qui a désactivé',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_role (tenant_id, role),
    INDEX idx_email (email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: promotions (Années/Cohortes)
-- ============================================================
CREATE TABLE promotions (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    year_start INT NOT NULL,
    year_end INT NOT NULL,
    level VARCHAR(50) COMMENT 'L1, L2, L3, M1, M2...',
    status ENUM('active', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_year (tenant_id, year_start),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: classes (Classes/Groupes)
-- ============================================================
CREATE TABLE classes (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    promo_id VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    teacher_id VARCHAR(50) COMMENT 'Professeur principal',
    status ENUM('active', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (promo_id) REFERENCES promotions(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tenant_promo (tenant_id, promo_id),
    INDEX idx_teacher (teacher_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: students (Élèves)
-- ============================================================
CREATE TABLE students (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    class_id VARCHAR(50) NOT NULL,
    promo_id VARCHAR(50) NOT NULL,
    uuid_scolaire VARCHAR(100) NOT NULL COMMENT 'UUID ErgoMate de l\'élève',
    email_scolaire VARCHAR(255) NOT NULL COMMENT 'Email scolaire',
    firstname VARCHAR(100),
    lastname VARCHAR(100),
    consent_sharing BOOLEAN DEFAULT FALSE COMMENT 'Consentement partage stats',
    status ENUM('active', 'graduated', 'withdrawn') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (promo_id) REFERENCES promotions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_uuid_email (uuid_scolaire, email_scolaire),
    INDEX idx_tenant_class (tenant_id, class_id),
    INDEX idx_uuid (uuid_scolaire),
    INDEX idx_email (email_scolaire),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: themes (Contenus pédagogiques)
-- ============================================================
CREATE TABLE themes (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    created_by VARCHAR(50) NOT NULL COMMENT 'User ID du créateur',
    title VARCHAR(255) NOT NULL,
    description TEXT,
    content JSON NOT NULL COMMENT 'Contenu du thème (questions, flashcards...)',
    tags JSON DEFAULT NULL COMMENT 'Tags pour recherche',
    difficulty ENUM('beginner', 'intermediate', 'advanced') DEFAULT 'intermediate',
    source ENUM('manual', 'pdf_mistral', 'import') DEFAULT 'manual',
    version INT DEFAULT 1,
    is_public BOOLEAN DEFAULT FALSE COMMENT 'Visible par autres écoles',
    status ENUM('draft', 'active', 'archived') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_created_by (created_by),
    INDEX idx_source (source),
    FULLTEXT INDEX idx_search (title, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: assignments (Affectations d'activités)
-- ============================================================
CREATE TABLE assignments (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    teacher_id VARCHAR(50) NOT NULL,
    theme_id VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    type ENUM('quiz', 'flashcards', 'fiche', 'annales') NOT NULL,
    mode ENUM('post-cours', 'pre-examen', 'revision-generale') DEFAULT 'post-cours',
    due_at TIMESTAMP NULL COMMENT 'Date limite',
    instructions TEXT,
    status ENUM('draft', 'queued', 'pushed', 'ack', 'error') DEFAULT 'draft',
    ergo_push_at TIMESTAMP NULL COMMENT 'Date de push vers ErgoMate',
    ergo_ack_at TIMESTAMP NULL COMMENT 'Date de confirmation ErgoMate',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE,
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_teacher (teacher_id),
    INDEX idx_due_at (due_at),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: assignment_targets (Cibles des affectations)
-- ============================================================
CREATE TABLE assignment_targets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id VARCHAR(50) NOT NULL,
    target_type ENUM('student', 'class', 'promo') NOT NULL,
    target_id VARCHAR(50) NOT NULL COMMENT 'ID de l\'élève, classe ou promo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    INDEX idx_assignment (assignment_id),
    INDEX idx_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: stats (Statistiques élèves depuis ErgoMate)
-- ============================================================
CREATE TABLE stats (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    theme_id VARCHAR(50) NULL COMMENT 'Null = stats globales',
    attempts INT DEFAULT 0,
    score DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Score moyen 0-100',
    mastery DECIMAL(3,2) DEFAULT 0.00 COMMENT 'Niveau de maîtrise 0-1',
    time_spent INT DEFAULT 0 COMMENT 'Temps total en secondes',
    last_activity_at TIMESTAMP NULL,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Dernière sync',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE SET NULL,
    UNIQUE KEY unique_student_theme (student_id, theme_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_student (student_id),
    INDEX idx_synced (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: sync_logs (Logs de synchronisation avec ErgoMate)
-- ============================================================
CREATE TABLE sync_logs (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    triggered_by VARCHAR(50) NULL COMMENT 'User ID qui a déclenché',
    direction ENUM('pull', 'push') NOT NULL,
    type ENUM('stats', 'assignment', 'webhook') NOT NULL,
    status ENUM('queued', 'running', 'ok', 'error') DEFAULT 'queued',
    payload JSON DEFAULT NULL COMMENT 'Données envoyées/reçues',
    error_message TEXT NULL,
    started_at TIMESTAMP NULL,
    ended_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_direction (direction),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: api_keys (Clés API Mistral BYOK)
-- ============================================================
CREATE TABLE api_keys (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    provider ENUM('mistral') DEFAULT 'mistral',
    key_encrypted TEXT NOT NULL COMMENT 'Clé chiffrée',
    label VARCHAR(255) DEFAULT 'Ma clé Mistral',
    status ENUM('active', 'invalid', 'expired') DEFAULT 'active',
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_tenant_user (tenant_id, user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: mistral_queue (File d'attente génération Mistral)
-- ============================================================
CREATE TABLE mistral_queue (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    pdf_path VARCHAR(500) NOT NULL COMMENT 'Chemin du fichier PDF',
    pdf_hash VARCHAR(64) NOT NULL COMMENT 'SHA256 du PDF',
    job_type ENUM('quiz', 'flashcards', 'fiche', 'full_theme') DEFAULT 'full_theme',
    status ENUM('pending', 'processing', 'completed', 'error') DEFAULT 'pending',
    priority INT DEFAULT 0 COMMENT 'Plus élevé = plus prioritaire',
    result_theme_id VARCHAR(50) NULL COMMENT 'ID du thème créé',
    error_message TEXT NULL,
    metadata JSON DEFAULT NULL COMMENT 'Paramètres génération',
    attempts INT DEFAULT 0,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (result_theme_id) REFERENCES themes(id) ON DELETE SET NULL,
    INDEX idx_status_priority (status, priority DESC),
    INDEX idx_tenant (tenant_id),
    INDEX idx_pdf_hash (pdf_hash),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: sessions (Sessions JWT - optionnel pour invalidation)
-- ============================================================
CREATE TABLE sessions (
    id VARCHAR(50) PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    token_hash VARCHAR(64) NOT NULL COMMENT 'Hash du JWT',
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at TIMESTAMP NOT NULL,
    revoked BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_token (token_hash),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: user_class_assignments (Rattachements enseignants/classes)
-- ============================================================
CREATE TABLE user_class_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    class_id VARCHAR(50) NOT NULL,
    tenant_id VARCHAR(50) NOT NULL,
    is_primary BOOLEAN DEFAULT FALSE COMMENT 'Enseignant principal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_class (user_id, class_id),
    INDEX idx_user (user_id),
    INDEX idx_class (class_id),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: roles_matrix (Configuration des rôles et permissions)
-- ============================================================
CREATE TABLE roles_matrix (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    role ENUM('admin', 'direction', 'teacher', 'inspector', 'referent', 'intervenant') NOT NULL,
    permission_key VARCHAR(100) NOT NULL COMMENT 'ex: assignments:create, users:read',
    allowed BOOLEAN DEFAULT TRUE,
    custom_config JSON DEFAULT NULL COMMENT 'Configuration additionnelle',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_role_perm (tenant_id, role, permission_key),
    INDEX idx_tenant_role (tenant_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: tenant_licences (Quotas et licences établissement)
-- ============================================================
CREATE TABLE tenant_licences (
    tenant_id VARCHAR(50) PRIMARY KEY,
    max_teachers INT DEFAULT 10 COMMENT 'Nombre max enseignants',
    max_students INT DEFAULT 100 COMMENT 'Nombre max élèves',
    max_classes INT DEFAULT 20 COMMENT 'Nombre max classes',
    used_teachers INT DEFAULT 0 COMMENT 'Nombre enseignants utilisés',
    used_students INT DEFAULT 0 COMMENT 'Nombre élèves utilisés',
    used_classes INT DEFAULT 0 COMMENT 'Nombre classes utilisées',
    status ENUM('active', 'warning', 'suspended', 'expired') DEFAULT 'active',
    subscription_type VARCHAR(50) DEFAULT 'standard' COMMENT 'Type abonnement',
    expires_at TIMESTAMP NULL COMMENT 'Date expiration licence',
    last_check_at TIMESTAMP NULL COMMENT 'Dernière vérification quotas',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: audit_log (Historique des actions admin)
-- ============================================================
CREATE TABLE audit_log (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    actor_user_id VARCHAR(50) NULL COMMENT 'User qui a effectué l\'action',
    action_type VARCHAR(50) NOT NULL COMMENT 'create, update, delete, deactivate, etc.',
    target_type VARCHAR(50) NOT NULL COMMENT 'user, class, role, licence, etc.',
    target_id VARCHAR(50) NULL COMMENT 'ID de la cible',
    payload JSON DEFAULT NULL COMMENT 'Détails de l\'action',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    result ENUM('success', 'failed') DEFAULT 'success',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tenant_date (tenant_id, created_at),
    INDEX idx_actor (actor_user_id),
    INDEX idx_action_type (action_type),
    INDEX idx_target (target_type, target_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- FIN DU SCHÉMA
-- ============================================================
