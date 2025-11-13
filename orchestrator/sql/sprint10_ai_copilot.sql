-- ============================================================
-- Study-mate School Orchestrator - Sprint 10 Migration
-- Date: 2025-11-13
-- Description: AI Copilot - Quiz/Fiches generation, Class insights, Pedagogical coach, Ergo-Mate publishing
-- ============================================================

-- ============================================================
-- TABLE: ai_coach_sessions
-- Conversations avec le coach pédagogique IA
-- ============================================================
CREATE TABLE IF NOT EXISTS ai_coach_sessions (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL COMMENT 'Enseignant utilisant le coach',
    context_type ENUM('class', 'student', 'assignment', 'general') NOT NULL,
    context_id VARCHAR(50) NULL COMMENT 'ID de la classe, élève ou assignment concerné',
    session_goal VARCHAR(255) NULL COMMENT 'Objectif de la session',
    status ENUM('active', 'completed', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_tenant_user (tenant_id, user_id),
    INDEX idx_context (context_type, context_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: ai_coach_messages
-- Messages dans les conversations du coach
-- ============================================================
CREATE TABLE IF NOT EXISTS ai_coach_messages (
    id VARCHAR(50) PRIMARY KEY,
    session_id VARCHAR(50) NOT NULL,
    role ENUM('user', 'assistant', 'system') NOT NULL,
    content TEXT NOT NULL COMMENT 'Contenu du message',
    metadata JSON DEFAULT NULL COMMENT 'Données supplémentaires (références, suggestions, etc.)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES ai_coach_sessions(id) ON DELETE CASCADE,
    INDEX idx_session (session_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: class_insights
-- Insights et analytics par classe (top difficultés, tendances)
-- ============================================================
CREATE TABLE IF NOT EXISTS class_insights (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    class_id VARCHAR(50) NOT NULL,
    insight_type ENUM('difficulty', 'performance', 'engagement', 'progress', 'recommendation') NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    severity ENUM('info', 'warning', 'critical') DEFAULT 'info',
    data JSON DEFAULT NULL COMMENT 'Données détaillées de l\'insight',
    student_ids JSON DEFAULT NULL COMMENT 'Liste des élèves concernés',
    theme_ids JSON DEFAULT NULL COMMENT 'Liste des thèmes concernés',
    priority INT DEFAULT 50 COMMENT 'Priorité d\'affichage (1-100)',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL COMMENT 'Date d\'expiration de l\'insight',
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    INDEX idx_tenant_class (tenant_id, class_id),
    INDEX idx_type_severity (insight_type, severity),
    INDEX idx_priority (priority DESC),
    INDEX idx_is_read (is_read),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: ergomate_publications
-- Journal des publications vers Ergo-Mate
-- ============================================================
CREATE TABLE IF NOT EXISTS ergomate_publications (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL COMMENT 'Enseignant ayant publié',
    theme_id VARCHAR(50) NOT NULL,
    generation_id VARCHAR(50) NULL COMMENT 'ID de la génération IA source',
    publication_type ENUM('catalog', 'assignment') NOT NULL COMMENT 'Publié dans le catalogue ou comme affectation',
    target_classes JSON DEFAULT NULL COMMENT 'Classes cibles si assignment',
    target_students JSON DEFAULT NULL COMMENT 'Élèves cibles si assignment',
    ergomate_theme_id VARCHAR(50) NULL COMMENT 'ID du thème dans Ergo-Mate',
    ergomate_assignment_id VARCHAR(50) NULL COMMENT 'ID de l\'assignment dans Ergo-Mate',
    status ENUM('pending', 'published', 'acknowledged', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,
    ack_received_at TIMESTAMP NULL COMMENT 'Date de réception de l\'accusé',
    ack_data JSON DEFAULT NULL COMMENT 'Données de l\'accusé de réception',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE,
    FOREIGN KEY (generation_id) REFERENCES ai_generations(id) ON DELETE SET NULL,
    INDEX idx_tenant_user (tenant_id, user_id),
    INDEX idx_theme (theme_id),
    INDEX idx_generation (generation_id),
    INDEX idx_status (status),
    INDEX idx_type (publication_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: ai_content_extractions
-- Tracking des extractions de contenu (PDF, audio)
-- ============================================================
CREATE TABLE IF NOT EXISTS ai_content_extractions (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    source_type ENUM('pdf', 'audio', 'video', 'url') NOT NULL,
    source_path VARCHAR(500) NOT NULL COMMENT 'Chemin du fichier uploadé',
    source_filename VARCHAR(255) NOT NULL,
    source_size_bytes INT NOT NULL,
    extraction_method VARCHAR(50) NULL COMMENT 'Méthode utilisée (tesseract, whisper, etc.)',
    extracted_text TEXT NULL COMMENT 'Texte extrait',
    extraction_status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,
    processing_time_ms INT NULL,
    metadata JSON DEFAULT NULL COMMENT 'Métadonnées du fichier (pages, durée, etc.)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_tenant_user (tenant_id, user_id),
    INDEX idx_status (extraction_status),
    INDEX idx_source_type (source_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Amélioration de la table ai_generations (ajout de colonnes)
-- ============================================================

-- Ajouter support pour les fiches illustrées et images générées
ALTER TABLE ai_generations
ADD COLUMN IF NOT EXISTS has_images BOOLEAN DEFAULT FALSE COMMENT 'Contient des images générées ou suggestions d\'images' AFTER validation_errors,
ADD COLUMN IF NOT EXISTS image_urls JSON DEFAULT NULL COMMENT 'URLs des images générées ou suggérées' AFTER has_images;

-- Ajouter lien vers l'extraction de contenu source
ALTER TABLE ai_generations
ADD COLUMN IF NOT EXISTS extraction_id VARCHAR(50) NULL COMMENT 'ID de l\'extraction de contenu source' AFTER source_hash,
ADD INDEX IF NOT EXISTS idx_extraction (extraction_id);

-- Ajouter métadonnées de validation Ergo-Mate
ALTER TABLE ai_generations
ADD COLUMN IF NOT EXISTS ergomate_validation JSON DEFAULT NULL COMMENT 'Résultat de la validation du schéma Ergo-Mate' AFTER validation_errors;

-- ============================================================
-- Amélioration de la table themes (ajout support Ergo-Mate)
-- ============================================================

-- Ajouter conformité schéma Ergo-Mate
ALTER TABLE themes
ADD COLUMN IF NOT EXISTS ergomate_compliant BOOLEAN DEFAULT FALSE COMMENT 'Conforme au schéma Ergo-Mate' AFTER source,
ADD COLUMN IF NOT EXISTS ergomate_validated_at TIMESTAMP NULL COMMENT 'Date de validation Ergo-Mate' AFTER ergomate_compliant,
ADD COLUMN IF NOT EXISTS ergomate_validation_errors JSON DEFAULT NULL COMMENT 'Erreurs de validation Ergo-Mate' AFTER ergomate_validated_at;

-- ============================================================
-- Vue: v_class_difficulty_insights
-- Vue matérialisée des difficultés par classe
-- ============================================================
CREATE OR REPLACE VIEW v_class_difficulty_insights AS
SELECT
    s.class_id,
    c.name as class_name,
    t.id as theme_id,
    t.title as theme_title,
    t.difficulty as theme_difficulty,
    COUNT(DISTINCT s.id) as student_count,
    AVG(st.success_rate) as avg_success_rate,
    AVG(st.completion_rate) as avg_completion_rate,
    AVG(st.avg_score) as avg_score,
    SUM(st.total_attempts) as total_attempts,
    SUM(st.completed_themes) as completed_count,
    COUNT(DISTINCT CASE WHEN st.success_rate < 50 THEN s.id END) as struggling_student_count,
    MAX(st.last_activity_at) as last_activity_at
FROM students s
JOIN classes c ON s.class_id = c.id
JOIN stats st ON s.id = st.student_id
JOIN themes t ON st.theme_id = t.id
WHERE s.status = 'active'
  AND c.status = 'active'
GROUP BY s.class_id, c.name, t.id, t.title, t.difficulty
HAVING avg_success_rate IS NOT NULL;

-- ============================================================
-- Vue: v_teacher_publications
-- Vue des publications par enseignant
-- ============================================================
CREATE OR REPLACE VIEW v_teacher_publications AS
SELECT
    p.id,
    p.tenant_id,
    p.user_id,
    CONCAT(u.firstname, ' ', u.lastname) as teacher_name,
    p.theme_id,
    t.title as theme_title,
    t.difficulty as theme_difficulty,
    p.publication_type,
    p.status,
    p.ergomate_theme_id,
    p.ergomate_assignment_id,
    p.ack_received_at,
    p.created_at,
    p.updated_at,
    TIMESTAMPDIFF(SECOND, p.created_at, p.ack_received_at) as ack_delay_seconds
FROM ergomate_publications p
JOIN users u ON p.user_id = u.id
JOIN themes t ON p.theme_id = t.id
ORDER BY p.created_at DESC;

-- ============================================================
-- Triggers pour automatisation
-- ============================================================

-- Trigger: Créer automatiquement un insight quand une classe a des difficultés
DELIMITER $$

CREATE TRIGGER IF NOT EXISTS trg_detect_class_difficulties
AFTER INSERT ON stats
FOR EACH ROW
BEGIN
    DECLARE v_class_id VARCHAR(50);
    DECLARE v_tenant_id VARCHAR(50);
    DECLARE v_struggling_count INT;

    -- Récupérer la classe et le tenant
    SELECT class_id, tenant_id INTO v_class_id, v_tenant_id
    FROM students
    WHERE id = NEW.student_id;

    -- Compter les élèves en difficulté sur ce thème
    SELECT COUNT(DISTINCT s.id) INTO v_struggling_count
    FROM students s
    JOIN stats st ON s.id = st.student_id
    WHERE s.class_id = v_class_id
      AND st.theme_id = NEW.theme_id
      AND st.success_rate < 50
      AND st.total_attempts >= 3;

    -- Si au moins 3 élèves en difficulté, créer un insight
    IF v_struggling_count >= 3 THEN
        INSERT INTO class_insights (
            id, tenant_id, class_id, insight_type, title, description,
            severity, data, theme_ids, priority, created_at
        ) VALUES (
            CONCAT('insight_', UUID_SHORT()),
            v_tenant_id,
            v_class_id,
            'difficulty',
            CONCAT('Difficulté détectée : ', (SELECT title FROM themes WHERE id = NEW.theme_id)),
            CONCAT(v_struggling_count, ' élèves rencontrent des difficultés sur ce thème'),
            CASE
                WHEN v_struggling_count >= 10 THEN 'critical'
                WHEN v_struggling_count >= 5 THEN 'warning'
                ELSE 'info'
            END,
            JSON_OBJECT(
                'struggling_count', v_struggling_count,
                'success_rate', NEW.success_rate,
                'theme_id', NEW.theme_id
            ),
            JSON_ARRAY(NEW.theme_id),
            CASE
                WHEN v_struggling_count >= 10 THEN 90
                WHEN v_struggling_count >= 5 THEN 70
                ELSE 50
            END,
            NOW()
        )
        ON DUPLICATE KEY UPDATE updated_at = NOW();
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- Index supplémentaires pour performance
-- ============================================================

-- Optimiser les requêtes d'insights
ALTER TABLE stats
ADD INDEX IF NOT EXISTS idx_success_rate (success_rate);

ALTER TABLE stats
ADD INDEX IF NOT EXISTS idx_completion_rate (completion_rate);

ALTER TABLE students
ADD INDEX IF NOT EXISTS idx_class_status (class_id, status);

-- ============================================================
-- Données de seed pour tests
-- ============================================================

-- Exemple d'insight de test
INSERT IGNORE INTO class_insights (
    id, tenant_id, class_id, insight_type, title, description,
    severity, priority, created_at
) VALUES (
    'insight_demo_001',
    'ife-paris',
    (SELECT id FROM classes WHERE name LIKE '%L1%' LIMIT 1),
    'difficulty',
    'Top Difficulté: Algorithmique de base',
    'Plusieurs élèves rencontrent des difficultés sur les concepts d\'algorithmique. Envisager une session de révision collective.',
    'warning',
    80,
    NOW()
);

-- ============================================================
-- FIN DE LA MIGRATION SPRINT 10
-- ============================================================
