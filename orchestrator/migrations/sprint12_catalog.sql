-- ============================================================================
-- Sprint 12 - Pedagogical Library
-- Database Migration: Internal Catalog Tables
-- ============================================================================
-- Description: Création des tables pour le catalogue interne de thèmes
--              pédagogiques avec workflow de validation et versioning
-- Date: 2025-11-13
-- Version: 1.0.0
-- ============================================================================

-- ============================================================================
-- Table: catalog_entries
-- Description: Entrées du catalogue de thèmes validés
-- ============================================================================

CREATE TABLE IF NOT EXISTS catalog_entries (
    -- Identifiants
    id VARCHAR(255) PRIMARY KEY,
    tenant_id VARCHAR(255) NOT NULL,
    theme_id VARCHAR(255) NULL,  -- Référence optionnelle au thème source (de la bibliothèque personnelle)

    -- Métadonnées
    title VARCHAR(500) NOT NULL,
    description TEXT,
    subject VARCHAR(100),  -- Matière (mathématiques, français, etc.)
    level VARCHAR(100),    -- Niveau (6ème, 5ème, seconde, etc.)
    difficulty ENUM('beginner', 'intermediate', 'advanced') DEFAULT 'intermediate',
    tags JSON,             -- ["révision", "bac", "chimie organique"]

    -- Workflow
    workflow_status ENUM('draft', 'proposed', 'validated', 'published', 'rejected', 'archived') DEFAULT 'draft',
    current_version_id VARCHAR(255),  -- Référence à la version actuelle

    -- Auteur et dates
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at TIMESTAMP NULL,

    -- Indexation
    INDEX idx_tenant (tenant_id),
    INDEX idx_status (workflow_status),
    INDEX idx_subject (subject),
    INDEX idx_level (level),
    INDEX idx_created_by (created_by),
    INDEX idx_published_at (published_at),

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Table: catalog_versions
-- Description: Versioning des thèmes du catalogue
-- ============================================================================

CREATE TABLE IF NOT EXISTS catalog_versions (
    -- Identifiants
    id VARCHAR(255) PRIMARY KEY,
    catalog_entry_id VARCHAR(255) NOT NULL,

    -- Version info
    version_number INT NOT NULL DEFAULT 1,
    version_label VARCHAR(50) NOT NULL,  -- "v1.0", "v2.1", etc.
    change_summary TEXT,                 -- Description des modifications

    -- Contenu complet
    content JSON NOT NULL,  -- Contenu complet du thème (questions, flashcards, fiches, annales)

    -- Métadonnées
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Indexation
    INDEX idx_catalog_entry (catalog_entry_id),
    INDEX idx_version_number (version_number),

    FOREIGN KEY (catalog_entry_id) REFERENCES catalog_entries(id) ON DELETE CASCADE,

    UNIQUE KEY unique_version (catalog_entry_id, version_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Table: catalog_workflow_history
-- Description: Historique des transitions de workflow
-- ============================================================================

CREATE TABLE IF NOT EXISTS catalog_workflow_history (
    -- Identifiants
    id VARCHAR(255) PRIMARY KEY,
    catalog_entry_id VARCHAR(255) NOT NULL,

    -- Transition
    from_status ENUM('draft', 'proposed', 'validated', 'published', 'rejected', 'archived') NOT NULL,
    to_status ENUM('draft', 'proposed', 'validated', 'published', 'rejected', 'archived') NOT NULL,

    -- Acteur et commentaire
    user_id VARCHAR(255) NOT NULL,
    comment TEXT,

    -- Date
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Indexation
    INDEX idx_catalog_entry (catalog_entry_id),
    INDEX idx_user (user_id),
    INDEX idx_created_at (created_at),

    FOREIGN KEY (catalog_entry_id) REFERENCES catalog_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Table: catalog_assignments
-- Description: Affectations de thèmes du catalogue aux classes
-- ============================================================================

CREATE TABLE IF NOT EXISTS catalog_assignments (
    -- Identifiants
    id VARCHAR(255) PRIMARY KEY,
    catalog_entry_id VARCHAR(255) NOT NULL,
    class_id VARCHAR(255) NOT NULL,
    tenant_id VARCHAR(255) NOT NULL,

    -- Métadonnées
    assigned_by VARCHAR(255) NOT NULL,  -- Enseignant qui a affecté
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'completed', 'archived') DEFAULT 'active',

    -- Dates limites
    start_date DATE,
    end_date DATE,

    -- Indexation
    INDEX idx_catalog_entry (catalog_entry_id),
    INDEX idx_class (class_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_assigned_by (assigned_by),

    FOREIGN KEY (catalog_entry_id) REFERENCES catalog_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Table: catalog_collaborators
-- Description: Collaborateurs sur un thème (co-édition future)
-- ============================================================================

CREATE TABLE IF NOT EXISTS catalog_collaborators (
    -- Identifiants
    id VARCHAR(255) PRIMARY KEY,
    catalog_entry_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(255) NOT NULL,

    -- Permissions
    role ENUM('viewer', 'editor', 'owner') DEFAULT 'viewer',

    -- Dates
    invited_by VARCHAR(255),
    invited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Indexation
    INDEX idx_catalog_entry (catalog_entry_id),
    INDEX idx_user (user_id),

    FOREIGN KEY (catalog_entry_id) REFERENCES catalog_entries(id) ON DELETE CASCADE,

    UNIQUE KEY unique_collaborator (catalog_entry_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Table: notifications (extension)
-- Description: Notifications pour le workflow du catalogue
-- ============================================================================

CREATE TABLE IF NOT EXISTS notifications (
    -- Identifiants
    id VARCHAR(255) PRIMARY KEY,
    tenant_id VARCHAR(255),
    user_id VARCHAR(255),
    user_role VARCHAR(50),  -- Pour notifications à un rôle entier (ex: "referent")

    -- Type et contenu
    type VARCHAR(100) NOT NULL,  -- "theme_submitted", "theme_validated", "new_catalog_theme", etc.
    data JSON,

    -- État
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,

    -- Date
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Indexation
    INDEX idx_tenant (tenant_id),
    INDEX idx_user (user_id),
    INDEX idx_role (user_role),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at),
    INDEX idx_is_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Ergo-Mate Tables (si hébergé dans la même DB)
-- ============================================================================

-- Table: themes (dans Ergo-Mate)
CREATE TABLE IF NOT EXISTS themes (
    id VARCHAR(255) PRIMARY KEY,
    catalog_theme_id VARCHAR(255),  -- Référence au thème du catalogue
    tenant_id VARCHAR(255) NOT NULL,

    title VARCHAR(500) NOT NULL,
    description TEXT,
    content JSON NOT NULL,
    difficulty VARCHAR(50),
    metadata JSON,
    version VARCHAR(50),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_catalog_theme (catalog_theme_id),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: theme_assignments (dans Ergo-Mate)
CREATE TABLE IF NOT EXISTS theme_assignments (
    id VARCHAR(255) PRIMARY KEY,
    theme_id VARCHAR(255) NOT NULL,
    class_id VARCHAR(255) NOT NULL,
    tenant_id VARCHAR(255) NOT NULL,

    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'completed', 'archived') DEFAULT 'active',

    INDEX idx_theme (theme_id),
    INDEX idx_class (class_id),
    INDEX idx_tenant (tenant_id),

    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: theme_questions (dans Ergo-Mate)
CREATE TABLE IF NOT EXISTS theme_questions (
    id VARCHAR(255) PRIMARY KEY,
    theme_id VARCHAR(255) NOT NULL,
    question_data JSON NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_theme (theme_id),

    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: theme_flashcards (dans Ergo-Mate)
CREATE TABLE IF NOT EXISTS theme_flashcards (
    id VARCHAR(255) PRIMARY KEY,
    theme_id VARCHAR(255) NOT NULL,
    flashcard_data JSON NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_theme (theme_id),

    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: theme_fiches (dans Ergo-Mate)
CREATE TABLE IF NOT EXISTS theme_fiches (
    id VARCHAR(255) PRIMARY KEY,
    theme_id VARCHAR(255) NOT NULL,
    fiche_data JSON NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_theme (theme_id),

    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Vues utilitaires
-- ============================================================================

-- Vue: Thèmes publiés avec stats
CREATE OR REPLACE VIEW v_published_catalog AS
SELECT
    ce.id,
    ce.tenant_id,
    ce.title,
    ce.description,
    ce.subject,
    ce.level,
    ce.difficulty,
    ce.tags,
    ce.workflow_status,
    ce.created_by,
    ce.published_at,
    u.name as author_name,
    u.email as author_email,
    cv.version_label,
    cv.version_number,
    COUNT(DISTINCT ca.class_id) as assigned_classes_count
FROM catalog_entries ce
LEFT JOIN users u ON ce.created_by = u.id
LEFT JOIN catalog_versions cv ON ce.current_version_id = cv.id
LEFT JOIN catalog_assignments ca ON ce.id = ca.catalog_entry_id AND ca.status = 'active'
WHERE ce.workflow_status = 'published'
GROUP BY ce.id;

-- ============================================================================
-- Données initiales / Seed (optionnel)
-- ============================================================================

-- Ajouter le rôle "referent" si nécessaire
-- INSERT IGNORE INTO roles (id, name, description) VALUES
-- ('referent', 'Référent Pédagogique', 'Valide les thèmes du catalogue interne');

-- ============================================================================
-- Migration complète
-- ============================================================================

-- Note: Exécuter ce script avec des privilèges appropriés
-- mysql -u root -p database_name < sprint12_catalog.sql
