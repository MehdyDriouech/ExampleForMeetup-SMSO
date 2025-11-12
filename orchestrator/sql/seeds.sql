-- ============================================================
-- Study-mate School Orchestrator - Seeds v1.0
-- Données de test : 2 écoles, 4 profs, 6 classes, élèves
-- Date: 2025-11-10
-- ============================================================

-- ============================================================
-- TENANTS (2 écoles)
-- ============================================================
INSERT INTO tenants (id, name, type, email, phone, address, status) VALUES
('TENANT_INST_PARIS', 'Institut Formation Ergothérapie Paris', 'private', 'contact@ife-paris.fr', '01 23 45 67 89', '15 Rue de la Santé, 75014 Paris', 'active'),
('TENANT_UNIV_LYON', 'Université Santé Lyon', 'public', 'ergo@univ-lyon.fr', '04 78 90 12 34', '8 Avenue Rockefeller, 69008 Lyon', 'active');

-- ============================================================
-- USERS (4 professeurs + 2 directions)
-- Mot de passe par défaut pour tous : "Ergo2025!" (hash bcrypt)
-- ============================================================
INSERT INTO users (id, tenant_id, email, password_hash, firstname, lastname, role, status, last_login_at) VALUES
-- École Paris
('USER_DIR_PARIS', 'TENANT_INST_PARIS', 'direction@ife-paris.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sophie', 'Martin', 'direction', 'active', '2025-11-09 14:30:00'),
('USER_PROF_PARIS_1', 'TENANT_INST_PARIS', 'claire.dubois@ife-paris.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Claire', 'Dubois', 'teacher', 'active', '2025-11-10 08:15:00'),
('USER_PROF_PARIS_2', 'TENANT_INST_PARIS', 'marc.bernard@ife-paris.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Marc', 'Bernard', 'teacher', 'active', '2025-11-09 16:45:00'),

-- École Lyon
('USER_DIR_LYON', 'TENANT_UNIV_LYON', 'direction.ergo@univ-lyon.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jean', 'Rousseau', 'direction', 'active', '2025-11-08 10:00:00'),
('USER_PROF_LYON_1', 'TENANT_UNIV_LYON', 'marie.laurent@univ-lyon.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Marie', 'Laurent', 'teacher', 'active', '2025-11-10 09:00:00'),
('USER_PROF_LYON_2', 'TENANT_UNIV_LYON', 'thomas.petit@univ-lyon.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Thomas', 'Petit', 'teacher', 'active', '2025-11-09 11:20:00');

-- ============================================================
-- PROMOTIONS (Années scolaires)
-- ============================================================
INSERT INTO promotions (id, tenant_id, name, year_start, year_end, level, status) VALUES
-- Paris
('PROMO_PARIS_L1_2025', 'TENANT_INST_PARIS', 'L1 Ergothérapie 2025-2026', 2025, 2026, 'L1', 'active'),
('PROMO_PARIS_L2_2024', 'TENANT_INST_PARIS', 'L2 Ergothérapie 2024-2025', 2024, 2025, 'L2', 'active'),

-- Lyon
('PROMO_LYON_L1_2025', 'TENANT_UNIV_LYON', 'L1 Santé 2025-2026', 2025, 2026, 'L1', 'active'),
('PROMO_LYON_L2_2025', 'TENANT_UNIV_LYON', 'L2 Santé 2025-2026', 2025, 2026, 'L2', 'active');

-- ============================================================
-- CLASSES (6 classes réparties)
-- ============================================================
INSERT INTO classes (id, tenant_id, promo_id, name, description, teacher_id, status) VALUES
-- Paris (3 classes)
('CLASS_PARIS_L1_A', 'TENANT_INST_PARIS', 'PROMO_PARIS_L1_2025', 'L1-A Anatomie & Physiologie', 'Groupe A - Cours du matin', 'USER_PROF_PARIS_1', 'active'),
('CLASS_PARIS_L1_B', 'TENANT_INST_PARIS', 'PROMO_PARIS_L1_2025', 'L1-B Psychologie & Développement', 'Groupe B - Cours de l\'après-midi', 'USER_PROF_PARIS_2', 'active'),
('CLASS_PARIS_L2_A', 'TENANT_INST_PARIS', 'PROMO_PARIS_L2_2024', 'L2-A Neurosciences', 'Spécialisation neuro', 'USER_PROF_PARIS_1', 'active'),

-- Lyon (3 classes)
('CLASS_LYON_L1_A', 'TENANT_UNIV_LYON', 'PROMO_LYON_L1_2025', 'L1-A Bases Ergothérapie', 'Tronc commun santé', 'USER_PROF_LYON_1', 'active'),
('CLASS_LYON_L2_A', 'TENANT_UNIV_LYON', 'PROMO_LYON_L2_2025', 'L2-A Pratiques Cliniques', 'Stage et pratique', 'USER_PROF_LYON_2', 'active'),
('CLASS_LYON_L2_B', 'TENANT_UNIV_LYON', 'PROMO_LYON_L2_2025', 'L2-B Approches Rééducatives', 'Techniques avancées', 'USER_PROF_LYON_1', 'active');

-- ============================================================
-- STUDENTS (Élèves répartis dans les classes)
-- UUID générés de façon cohérente : uuid-school-{tenant}-{number}
-- ============================================================
INSERT INTO students (id, tenant_id, class_id, promo_id, uuid_scolaire, email_scolaire, firstname, lastname, consent_sharing, status) VALUES
-- Paris L1-A (5 élèves)
('STU_PARIS_001', 'TENANT_INST_PARIS', 'CLASS_PARIS_L1_A', 'PROMO_PARIS_L1_2025', 'uuid-ergo-paris-001', 'alice.dupont@etudiant.ife-paris.fr', 'Alice', 'Dupont', TRUE, 'active'),
('STU_PARIS_002', 'TENANT_INST_PARIS', 'CLASS_PARIS_L1_A', 'PROMO_PARIS_L1_2025', 'uuid-ergo-paris-002', 'lucas.moreau@etudiant.ife-paris.fr', 'Lucas', 'Moreau', TRUE, 'active'),
('STU_PARIS_003', 'TENANT_INST_PARIS', 'CLASS_PARIS_L1_A', 'PROMO_PARIS_L1_2025', 'uuid-ergo-paris-003', 'emma.leroy@etudiant.ife-paris.fr', 'Emma', 'Leroy', TRUE, 'active'),
('STU_PARIS_004', 'TENANT_INST_PARIS', 'CLASS_PARIS_L1_A', 'PROMO_PARIS_L1_2025', 'uuid-ergo-paris-004', 'hugo.martinez@etudiant.ife-paris.fr', 'Hugo', 'Martinez', FALSE, 'active'),
('STU_PARIS_005', 'TENANT_INST_PARIS', 'CLASS_PARIS_L1_A', 'PROMO_PARIS_L1_2025', 'uuid-ergo-paris-005', 'lea.robert@etudiant.ife-paris.fr', 'Léa', 'Robert', TRUE, 'active'),

-- Paris L1-B (4 élèves)
('STU_PARIS_006', 'TENANT_INST_PARIS', 'CLASS_PARIS_L1_B', 'PROMO_PARIS_L1_2025', 'uuid-ergo-paris-006', 'noah.garcia@etudiant.ife-paris.fr', 'Noah', 'Garcia', TRUE, 'active'),
('STU_PARIS_007', 'TENANT_INST_PARIS', 'CLASS_PARIS_L1_B', 'PROMO_PARIS_L1_2025', 'uuid-ergo-paris-007', 'chloe.simon@etudiant.ife-paris.fr', 'Chloé', 'Simon', TRUE, 'active'),
('STU_PARIS_008', 'TENANT_INST_PARIS', 'CLASS_PARIS_L1_B', 'PROMO_PARIS_L1_2025', 'uuid-ergo-paris-008', 'theo.michel@etudiant.ife-paris.fr', 'Théo', 'Michel', FALSE, 'active'),
('STU_PARIS_009', 'TENANT_INST_PARIS', 'CLASS_PARIS_L1_B', 'PROMO_PARIS_L1_2025', 'uuid-ergo-paris-009', 'jade.fernandez@etudiant.ife-paris.fr', 'Jade', 'Fernandez', TRUE, 'active'),

-- Paris L2-A (3 élèves)
('STU_PARIS_010', 'TENANT_INST_PARIS', 'CLASS_PARIS_L2_A', 'PROMO_PARIS_L2_2024', 'uuid-ergo-paris-010', 'arthur.thomas@etudiant.ife-paris.fr', 'Arthur', 'Thomas', TRUE, 'active'),
('STU_PARIS_011', 'TENANT_INST_PARIS', 'CLASS_PARIS_L2_A', 'PROMO_PARIS_L2_2024', 'uuid-ergo-paris-011', 'sarah.roux@etudiant.ife-paris.fr', 'Sarah', 'Roux', TRUE, 'active'),
('STU_PARIS_012', 'TENANT_INST_PARIS', 'CLASS_PARIS_L2_A', 'PROMO_PARIS_L2_2024', 'uuid-ergo-paris-012', 'maxime.blanc@etudiant.ife-paris.fr', 'Maxime', 'Blanc', FALSE, 'active'),

-- Lyon L1-A (6 élèves)
('STU_LYON_001', 'TENANT_UNIV_LYON', 'CLASS_LYON_L1_A', 'PROMO_LYON_L1_2025', 'uuid-ergo-lyon-001', 'julie.martin@etu.univ-lyon.fr', 'Julie', 'Martin', TRUE, 'active'),
('STU_LYON_002', 'TENANT_UNIV_LYON', 'CLASS_LYON_L1_A', 'PROMO_LYON_L1_2025', 'uuid-ergo-lyon-002', 'paul.durand@etu.univ-lyon.fr', 'Paul', 'Durand', TRUE, 'active'),
('STU_LYON_003', 'TENANT_UNIV_LYON', 'CLASS_LYON_L1_A', 'PROMO_LYON_L1_2025', 'uuid-ergo-lyon-003', 'camille.bernard@etu.univ-lyon.fr', 'Camille', 'Bernard', TRUE, 'active'),
('STU_LYON_004', 'TENANT_UNIV_LYON', 'CLASS_LYON_L1_A', 'PROMO_LYON_L1_2025', 'uuid-ergo-lyon-004', 'antoine.dubois@etu.univ-lyon.fr', 'Antoine', 'Dubois', TRUE, 'active'),
('STU_LYON_005', 'TENANT_UNIV_LYON', 'CLASS_LYON_L1_A', 'PROMO_LYON_L1_2025', 'uuid-ergo-lyon-005', 'manon.lefebvre@etu.univ-lyon.fr', 'Manon', 'Lefebvre', FALSE, 'active'),
('STU_LYON_006', 'TENANT_UNIV_LYON', 'CLASS_LYON_L1_A', 'PROMO_LYON_L1_2025', 'uuid-ergo-lyon-006', 'louis.rousseau@etu.univ-lyon.fr', 'Louis', 'Rousseau', TRUE, 'active'),

-- Lyon L2-A (4 élèves)
('STU_LYON_007', 'TENANT_UNIV_LYON', 'CLASS_LYON_L2_A', 'PROMO_LYON_L2_2025', 'uuid-ergo-lyon-007', 'laura.morel@etu.univ-lyon.fr', 'Laura', 'Morel', TRUE, 'active'),
('STU_LYON_008', 'TENANT_UNIV_LYON', 'CLASS_LYON_L2_A', 'PROMO_LYON_L2_2025', 'uuid-ergo-lyon-008', 'nathan.fournier@etu.univ-lyon.fr', 'Nathan', 'Fournier', TRUE, 'active'),
('STU_LYON_009', 'TENANT_UNIV_LYON', 'CLASS_LYON_L2_A', 'PROMO_LYON_L2_2025', 'uuid-ergo-lyon-009', 'oceane.girard@etu.univ-lyon.fr', 'Océane', 'Girard', TRUE, 'active'),
('STU_LYON_010', 'TENANT_UNIV_LYON', 'CLASS_LYON_L2_A', 'PROMO_LYON_L2_2025', 'uuid-ergo-lyon-010', 'gabriel.andre@etu.univ-lyon.fr', 'Gabriel', 'André', FALSE, 'active'),

-- Lyon L2-B (3 élèves)
('STU_LYON_011', 'TENANT_UNIV_LYON', 'CLASS_LYON_L2_B', 'PROMO_LYON_L2_2025', 'uuid-ergo-lyon-011', 'clara.lambert@etu.univ-lyon.fr', 'Clara', 'Lambert', TRUE, 'active'),
('STU_LYON_012', 'TENANT_UNIV_LYON', 'CLASS_LYON_L2_B', 'PROMO_LYON_L2_2025', 'uuid-ergo-lyon-012', 'romain.bonnet@etu.univ-lyon.fr', 'Romain', 'Bonnet', TRUE, 'active'),
('STU_LYON_013', 'TENANT_UNIV_LYON', 'CLASS_LYON_L2_B', 'PROMO_LYON_L2_2025', 'uuid-ergo-lyon-013', 'elise.roussel@etu.univ-lyon.fr', 'Élise', 'Roussel', TRUE, 'active');

-- ============================================================
-- THEMES (Quelques thèmes de test)
-- ============================================================
INSERT INTO themes (id, tenant_id, created_by, title, description, content, tags, difficulty, source, status) VALUES
('THEME_PARIS_001', 'TENANT_INST_PARIS', 'USER_PROF_PARIS_1', 'Anatomie du membre supérieur', 'Quiz sur les os, muscles et articulations du MS', '{"questions":[{"id":"q1","type":"mcq","prompt":"Combien d\'os composent le carpe ?","choices":[{"id":"a","label":"6"},{"id":"b","label":"8"},{"id":"c","label":"10"}],"answer":"b","rationale":"Le carpe est composé de 8 os disposés en deux rangées."}]}', '["anatomie","membre supérieur","os"]', 'beginner', 'manual', 'active'),
('THEME_LYON_001', 'TENANT_UNIV_LYON', 'USER_PROF_LYON_1', 'Introduction à l\'ergothérapie', 'Concepts de base et histoire de la discipline', '{"questions":[{"id":"q1","type":"true_false","prompt":"L\'ergothérapie est une profession paramédicale.","answer":true,"rationale":"L\'ergothérapie fait partie des professions paramédicales de santé."}]}', '["introduction","histoire","concepts"]', 'beginner', 'manual', 'active');

-- ============================================================
-- ASSIGNMENTS (Quelques affectations de test)
-- ============================================================
INSERT INTO assignments (id, tenant_id, teacher_id, theme_id, title, type, mode, due_at, instructions, status) VALUES
('ASSIGN_PARIS_001', 'TENANT_INST_PARIS', 'USER_PROF_PARIS_1', 'THEME_PARIS_001', 'Quiz Anatomie MS - Révision S1', 'quiz', 'post-cours', '2025-11-15 18:00:00', 'Révision suite au cours du 10/11. Objectif : 80% de réussite minimum.', 'queued'),
('ASSIGN_LYON_001', 'TENANT_UNIV_LYON', 'USER_PROF_LYON_1', 'THEME_LYON_001', 'QCM Introduction Ergo', 'quiz', 'pre-examen', '2025-11-20 23:59:59', 'Préparation examen final. Bien lire les rationales.', 'queued');

-- ============================================================
-- ASSIGNMENT_TARGETS (Cibles des affectations)
-- ============================================================
INSERT INTO assignment_targets (assignment_id, target_type, target_id) VALUES
('ASSIGN_PARIS_001', 'class', 'CLASS_PARIS_L1_A'),
('ASSIGN_LYON_001', 'class', 'CLASS_LYON_L1_A');

-- ============================================================
-- STATS (Quelques stats de test - simulées)
-- ============================================================
INSERT INTO stats (id, tenant_id, student_id, theme_id, attempts, score, mastery, time_spent, last_activity_at, synced_at) VALUES
-- Paris
('STAT_001', 'TENANT_INST_PARIS', 'STU_PARIS_001', 'THEME_PARIS_001', 3, 85.50, 0.86, 420, '2025-11-09 18:30:00', '2025-11-09 19:00:00'),
('STAT_002', 'TENANT_INST_PARIS', 'STU_PARIS_002', 'THEME_PARIS_001', 2, 72.00, 0.72, 380, '2025-11-09 17:15:00', '2025-11-09 19:00:00'),
('STAT_003', 'TENANT_INST_PARIS', 'STU_PARIS_003', NULL, 5, 78.20, 0.78, 1200, '2025-11-10 10:00:00', '2025-11-10 10:15:00'),

-- Lyon
('STAT_004', 'TENANT_UNIV_LYON', 'STU_LYON_001', 'THEME_LYON_001', 4, 91.25, 0.91, 540, '2025-11-10 08:45:00', '2025-11-10 09:00:00'),
('STAT_005', 'TENANT_UNIV_LYON', 'STU_LYON_002', NULL, 8, 88.00, 0.88, 1850, '2025-11-09 20:30:00', '2025-11-10 09:00:00');

-- ============================================================
-- SYNC_LOGS (Quelques logs de sync)
-- ============================================================
INSERT INTO sync_logs (id, tenant_id, triggered_by, direction, type, status, started_at, ended_at) VALUES
('SYNC_001', 'TENANT_INST_PARIS', 'USER_PROF_PARIS_1', 'pull', 'stats', 'ok', '2025-11-09 19:00:00', '2025-11-09 19:00:15'),
('SYNC_002', 'TENANT_UNIV_LYON', 'USER_PROF_LYON_1', 'pull', 'stats', 'ok', '2025-11-10 09:00:00', '2025-11-10 09:00:12'),
('SYNC_003', 'TENANT_INST_PARIS', 'USER_PROF_PARIS_1', 'push', 'assignment', 'queued', '2025-11-10 10:30:00', NULL);

-- ============================================================
-- FIN DES SEEDS
-- Note : Les mots de passe sont tous "Ergo2025!" (hash bcrypt)
-- ============================================================
