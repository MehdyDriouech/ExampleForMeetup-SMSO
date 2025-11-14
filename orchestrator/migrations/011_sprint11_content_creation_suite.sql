-- ============================================================================
-- Migration: Sprint 11 - Content Creation Suite
-- Date: 2025-11-13
-- Description: Tables pour l'édition, versioning, collaboration et export
-- ============================================================================

-- Table: theme_versions
-- Description: Historique des versions de thèmes
CREATE TABLE IF NOT EXISTS theme_versions (
    id VARCHAR(50) PRIMARY KEY,
    theme_id VARCHAR(50) NOT NULL,
    version_number INT NOT NULL,
    content JSON NOT NULL,
    changed_by VARCHAR(50) NOT NULL,
    change_summary VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_theme_versions_theme (theme_id),
    INDEX idx_theme_versions_created (created_at),
    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_theme_version (theme_id, version_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: theme_workflow_log
-- Description: Journal des changements de statut workflow
CREATE TABLE IF NOT EXISTS theme_workflow_log (
    id VARCHAR(50) PRIMARY KEY,
    theme_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    status ENUM('draft', 'in_review', 'approved', 'published', 'archived') NOT NULL,
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_workflow_theme (theme_id),
    INDEX idx_workflow_created (created_at),
    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: theme_comments
-- Description: Commentaires et suggestions sur les thèmes
CREATE TABLE IF NOT EXISTS theme_comments (
    id VARCHAR(50) PRIMARY KEY,
    theme_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    element_id VARCHAR(50),
    element_type ENUM('question', 'flashcard', 'fiche_section', 'general'),
    comment_type ENUM('comment', 'suggestion', 'approval', 'rejection') DEFAULT 'comment',
    content TEXT NOT NULL,
    status ENUM('open', 'resolved', 'archived') DEFAULT 'open',
    parent_comment_id VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_comments_theme (theme_id),
    INDEX idx_comments_user (user_id),
    INDEX idx_comments_element (element_id),
    INDEX idx_comments_created (created_at),
    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_comment_id) REFERENCES theme_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: collaborative_edit_sessions
-- Description: Sessions d'édition collaborative (locks)
CREATE TABLE IF NOT EXISTS collaborative_edit_sessions (
    id VARCHAR(50) PRIMARY KEY,
    theme_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    lock_acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    status ENUM('active', 'idle', 'released') DEFAULT 'active',
    heartbeat_expires_at TIMESTAMP,
    INDEX idx_collab_theme (theme_id),
    INDEX idx_collab_user (user_id),
    INDEX idx_collab_status (status),
    INDEX idx_collab_expires (heartbeat_expires_at),
    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_active_lock (theme_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: theme_templates
-- Description: Templates de thèmes réutilisables
CREATE TABLE IF NOT EXISTS theme_templates (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    created_by VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    template_type ENUM('quiz', 'flashcards', 'fiche', 'complete') NOT NULL,
    structure JSON NOT NULL,
    preview_image VARCHAR(500),
    is_public BOOLEAN DEFAULT FALSE,
    usage_count INT DEFAULT 0,
    tags JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_templates_tenant (tenant_id),
    INDEX idx_templates_creator (created_by),
    INDEX idx_templates_type (template_type),
    INDEX idx_templates_public (is_public),
    INDEX idx_templates_category (category),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: theme_folders
-- Description: Dossiers pour organiser les thèmes
CREATE TABLE IF NOT EXISTS theme_folders (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    parent_folder_id VARCHAR(50),
    color VARCHAR(20),
    icon VARCHAR(50),
    position INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_folders_tenant (tenant_id),
    INDEX idx_folders_user (user_id),
    INDEX idx_folders_parent (parent_folder_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_folder_id) REFERENCES theme_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: theme_folder_items
-- Description: Association thèmes-dossiers
CREATE TABLE IF NOT EXISTS theme_folder_items (
    id VARCHAR(50) PRIMARY KEY,
    folder_id VARCHAR(50) NOT NULL,
    theme_id VARCHAR(50) NOT NULL,
    position INT DEFAULT 0,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_folder_items_folder (folder_id),
    INDEX idx_folder_items_theme (theme_id),
    FOREIGN KEY (folder_id) REFERENCES theme_folders(id) ON DELETE CASCADE,
    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_folder_theme (folder_id, theme_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: theme_exports
-- Description: Historique des exports de thèmes
CREATE TABLE IF NOT EXISTS theme_exports (
    id VARCHAR(50) PRIMARY KEY,
    theme_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    export_format ENUM('json', 'pdf', 'csv', 'qti', 'quizlet', 'kahoot') NOT NULL,
    file_path VARCHAR(500),
    file_size_bytes INT,
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP,
    INDEX idx_exports_theme (theme_id),
    INDEX idx_exports_user (user_id),
    INDEX idx_exports_created (created_at),
    INDEX idx_exports_status (status),
    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: theme_imports
-- Description: Historique des imports de thèmes
CREATE TABLE IF NOT EXISTS theme_imports (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    source_format ENUM('json', 'csv', 'qti', 'quizlet', 'kahoot') NOT NULL,
    original_filename VARCHAR(255),
    file_path VARCHAR(500),
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    themes_created INT DEFAULT 0,
    validation_errors JSON,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP,
    INDEX idx_imports_tenant (tenant_id),
    INDEX idx_imports_user (user_id),
    INDEX idx_imports_created (created_at),
    INDEX idx_imports_status (status),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: theme_preview_sessions
-- Description: Sessions de prévisualisation pour tests
CREATE TABLE IF NOT EXISTS theme_preview_sessions (
    id VARCHAR(50) PRIMARY KEY,
    theme_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    session_type ENUM('teacher_preview', 'student_simulation') DEFAULT 'teacher_preview',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP,
    answers JSON,
    score INT,
    duration_seconds INT,
    feedback TEXT,
    INDEX idx_preview_theme (theme_id),
    INDEX idx_preview_user (user_id),
    INDEX idx_preview_started (started_at),
    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Altérations de la table themes existante
-- Ajouter les colonnes nécessaires au Sprint 11

ALTER TABLE themes
ADD COLUMN IF NOT EXISTS workflow_status ENUM('draft', 'in_review', 'approved', 'published', 'archived') DEFAULT 'draft' AFTER ergomate_validated_at,
ADD COLUMN IF NOT EXISTS version_number INT DEFAULT 1 AFTER workflow_status,
ADD COLUMN IF NOT EXISTS content_hash VARCHAR(64) AFTER content,
ADD INDEX idx_themes_workflow (workflow_status),
ADD INDEX idx_themes_hash (content_hash);

-- ============================================================================
-- Données de test pour le développement
-- ============================================================================

-- Template de quiz par défaut
INSERT IGNORE INTO theme_templates (
    id, tenant_id, created_by, name, description, category,
    template_type, structure, is_public, tags
) VALUES (
    'tpl_default_quiz',
    'tenant_demo',
    'system',
    'Quiz standard 10 questions',
    'Template de quiz avec 10 questions QCM à 4 choix',
    'Education',
    'quiz',
    JSON_OBJECT(
        'questions', JSON_ARRAY(
            JSON_OBJECT(
                'id', 'q1',
                'text', '[Votre question ici]',
                'choices', JSON_ARRAY('Choix A', 'Choix B', 'Choix C', 'Choix D'),
                'correctAnswer', 0,
                'explanation', '[Explication ici]',
                'difficulty', 'medium',
                'points', 10
            )
        ),
        'question_count', 10
    ),
    TRUE,
    JSON_ARRAY('quiz', 'standard', 'qcm')
);

-- Template de flashcards
INSERT IGNORE INTO theme_templates (
    id, tenant_id, created_by, name, description, category,
    template_type, structure, is_public, tags
) VALUES (
    'tpl_flashcards_20',
    'tenant_demo',
    'system',
    'Set de 20 flashcards',
    'Template de flashcards pour révisions rapides',
    'Education',
    'flashcards',
    JSON_OBJECT(
        'flashcards', JSON_ARRAY(
            JSON_OBJECT(
                'id', 'f1',
                'front', '[Concept/Question]',
                'back', '[Définition/Réponse]',
                'difficulty', 'medium'
            )
        ),
        'flashcard_count', 20
    ),
    TRUE,
    JSON_ARRAY('flashcards', 'révision', 'mémorisation')
);

-- ============================================================================
-- Vues utiles
-- ============================================================================

-- Vue: Statistiques de collaboration par thème
CREATE OR REPLACE VIEW v_theme_collaboration_stats AS
SELECT
    t.id as theme_id,
    t.title,
    t.workflow_status,
    COUNT(DISTINCT tv.id) as version_count,
    COUNT(DISTINCT tc.id) as comment_count,
    COUNT(DISTINCT tc.user_id) as collaborator_count,
    MAX(tv.created_at) as last_modified_at
FROM themes t
LEFT JOIN theme_versions tv ON t.id = tv.theme_id
LEFT JOIN theme_comments tc ON t.id = tc.theme_id
GROUP BY t.id;

-- Vue: Activité récente de l'enseignant
CREATE OR REPLACE VIEW v_teacher_recent_activity AS
SELECT
    u.id as teacher_id,
    u.name as teacher_name,
    'theme_created' as activity_type,
    t.id as item_id,
    t.title as item_title,
    t.created_at as activity_at
FROM users u
JOIN themes t ON u.id = t.created_by
WHERE u.role = 'teacher'
UNION ALL
SELECT
    u.id as teacher_id,
    u.name as teacher_name,
    'theme_edited' as activity_type,
    tv.theme_id as item_id,
    t.title as item_title,
    tv.created_at as activity_at
FROM users u
JOIN theme_versions tv ON u.id = tv.changed_by
JOIN themes t ON tv.theme_id = t.id
WHERE u.role = 'teacher'
UNION ALL
SELECT
    u.id as teacher_id,
    u.name as teacher_name,
    'comment_added' as activity_type,
    tc.theme_id as item_id,
    t.title as item_title,
    tc.created_at as activity_at
FROM users u
JOIN theme_comments tc ON u.id = tc.user_id
JOIN themes t ON tc.theme_id = t.id
WHERE u.role = 'teacher'
ORDER BY activity_at DESC;

-- ============================================================================
-- Triggers
-- ============================================================================

-- Trigger: Mettre à jour usage_count des templates
DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_template_usage_increment
AFTER INSERT ON themes
FOR EACH ROW
BEGIN
    IF NEW.content IS NOT NULL AND JSON_EXTRACT(NEW.content, '$.metadata.template_id') IS NOT NULL THEN
        UPDATE theme_templates
        SET usage_count = usage_count + 1
        WHERE id = JSON_UNQUOTE(JSON_EXTRACT(NEW.content, '$.metadata.template_id'));
    END IF;
END//
DELIMITER ;

-- Trigger: Auto-release des locks inactifs (expirations)
DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_check_session_expiry
BEFORE UPDATE ON collaborative_edit_sessions
FOR EACH ROW
BEGIN
    IF NEW.heartbeat_expires_at < NOW() AND NEW.status = 'active' THEN
        SET NEW.status = 'released';
    END IF;
END//
DELIMITER ;

-- ============================================================================
-- Procédures stockées utiles
-- ============================================================================

-- Procédure: Nettoyer les anciennes versions (garder les 10 dernières)
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_cleanup_old_versions()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_theme_id VARCHAR(50);
    DECLARE theme_cursor CURSOR FOR SELECT DISTINCT theme_id FROM theme_versions;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN theme_cursor;

    read_loop: LOOP
        FETCH theme_cursor INTO v_theme_id;
        IF done THEN
            LEAVE read_loop;
        END IF;

        -- Garder les 10 dernières versions
        DELETE FROM theme_versions
        WHERE theme_id = v_theme_id
        AND version_number NOT IN (
            SELECT version_number FROM (
                SELECT version_number
                FROM theme_versions
                WHERE theme_id = v_theme_id
                ORDER BY version_number DESC
                LIMIT 10
            ) as keep_versions
        );
    END LOOP;

    CLOSE theme_cursor;
END//
DELIMITER ;

-- Procédure: Libérer les locks expirés
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS sp_release_expired_locks()
BEGIN
    UPDATE collaborative_edit_sessions
    SET status = 'released'
    WHERE status = 'active'
    AND heartbeat_expires_at < NOW();
END//
DELIMITER ;

-- ============================================================================
-- Événements planifiés (nécessite SUPER privilege)
-- ============================================================================

-- Libérer automatiquement les locks expirés toutes les 5 minutes
-- SET GLOBAL event_scheduler = ON;
-- CREATE EVENT IF NOT EXISTS evt_release_expired_locks
-- ON SCHEDULE EVERY 5 MINUTE
-- DO CALL sp_release_expired_locks();

-- ============================================================================
-- Index de performance
-- ============================================================================

-- Index pour la recherche full-text sur les thèmes
ALTER TABLE themes ADD FULLTEXT INDEX idx_themes_fulltext (title, description);

-- Index composites pour les requêtes fréquentes
CREATE INDEX IF NOT EXISTS idx_themes_tenant_status ON themes(tenant_id, workflow_status);
CREATE INDEX IF NOT EXISTS idx_themes_creator_status ON themes(created_by, workflow_status);
CREATE INDEX IF NOT EXISTS idx_themes_updated ON themes(updated_at DESC);

-- ============================================================================
-- Migration complétée
-- ============================================================================
-- Version: Sprint 11 v1.0
-- Tables créées: 11
-- Vues créées: 2
-- Triggers créés: 2
-- Procédures créées: 2
-- ============================================================================
