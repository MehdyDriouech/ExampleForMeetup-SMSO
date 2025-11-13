<?php
/**
 * Recommendation Engine - Sprint 7
 *
 * Système de recommandations personnalisées basé sur:
 * - Historique des performances (scores, maîtrise)
 * - Patterns d'apprentissage (temps, fréquence)
 * - Difficultés identifiées (erreurs fréquentes)
 * - Objectives pédagogiques (ZPD - Zone Proximale de Développement)
 *
 * Combine heuristiques + LLM pour suggestions contextuelles
 */

class RecommendationEngine {
    private $db;
    private $aiService;

    // Poids des différents facteurs dans le calcul de recommandation
    const WEIGHT_PERFORMANCE = 0.35;    // Performance passée
    const WEIGHT_DIFFICULTY = 0.30;     // Niveau de difficulté adapté
    const WEIGHT_RECENCY = 0.20;        // Activité récente
    const WEIGHT_COMPLETION = 0.15;     // Taux de complétion

    public function __construct() {
        $this->db = db();
        $this->aiService = new AIService();
    }

    /**
     * Générer 3 recommandations personnalisées pour un élève
     *
     * @param string $studentId ID de l'élève
     * @param string $tenantId ID du tenant
     * @param array $options Options de filtrage
     * @return array Liste de 3 recommandations avec explicabilité
     */
    public function generateRecommendations($studentId, $tenantId, $options = []) {
        // 1. Récupérer le profil de l'élève
        $profile = $this->getStudentProfile($studentId, $tenantId);

        if (!$profile) {
            throw new Exception('Student not found');
        }

        // 2. Analyser les forces et faiblesses
        $analysis = $this->analyzeStudentPerformance($studentId, $tenantId);

        // 3. Calculer les scores de recommandation pour tous les thèmes disponibles
        $candidates = $this->scoreCandidateThemes($studentId, $tenantId, $analysis);

        // 4. Sélectionner les 3 meilleurs
        $topRecommendations = array_slice($candidates, 0, 3);

        // 5. Générer l'explicabilité pour chaque recommandation
        $recommendations = array_map(function($rec) use ($analysis) {
            return $this->enrichRecommendation($rec, $analysis);
        }, $topRecommendations);

        // 6. Logger la recommandation pour feedback loop
        $this->logRecommendation($studentId, $tenantId, $recommendations);

        return [
            'student_id' => $studentId,
            'recommendations' => $recommendations,
            'profile_summary' => [
                'avg_score' => $analysis['avg_score'],
                'avg_mastery' => $analysis['avg_mastery'],
                'total_sessions' => $analysis['total_sessions'],
                'learning_velocity' => $analysis['learning_velocity']
            ],
            'generated_at' => date('c')
        ];
    }

    /**
     * Récupérer le profil de l'élève
     */
    private function getStudentProfile($studentId, $tenantId) {
        return $this->db->queryOne(
            'SELECT s.*, c.name as class_name
             FROM students s
             LEFT JOIN classes c ON s.class_id = c.id
             WHERE s.id = :student_id AND s.tenant_id = :tenant_id',
            [
                'student_id' => $studentId,
                'tenant_id' => $tenantId
            ]
        );
    }

    /**
     * Analyser les performances de l'élève
     */
    private function analyzeStudentPerformance($studentId, $tenantId) {
        // Récupérer les stats globales
        $globalStats = $this->db->queryOne(
            'SELECT
                COUNT(*) as total_sessions,
                AVG(score) as avg_score,
                AVG(mastery) as avg_mastery,
                SUM(time_spent) as total_time,
                MIN(completed_at) as first_session,
                MAX(completed_at) as last_session
             FROM student_sessions
             WHERE student_id = :student_id
               AND tenant_id = :tenant_id
               AND status = \'completed\'',
            [
                'student_id' => $studentId,
                'tenant_id' => $tenantId
            ]
        );

        // Récupérer les performances par thème
        $themePerformance = $this->db->query(
            'SELECT
                ss.theme_id,
                t.title as theme_title,
                t.difficulty as theme_difficulty,
                COUNT(*) as attempts,
                AVG(ss.score) as avg_score,
                AVG(ss.mastery) as avg_mastery,
                MAX(ss.completed_at) as last_attempt,
                SUM(CASE WHEN ss.score >= 70 THEN 1 ELSE 0 END) as success_count,
                COUNT(CASE WHEN ss.score < 50 THEN 1 END) as failure_count
             FROM student_sessions ss
             JOIN themes t ON ss.theme_id = t.id
             WHERE ss.student_id = :student_id
               AND ss.tenant_id = :tenant_id
               AND ss.status = \'completed\'
             GROUP BY ss.theme_id, t.title, t.difficulty',
            [
                'student_id' => $studentId,
                'tenant_id' => $tenantId
            ]
        );

        // Calculer la vélocité d'apprentissage (progression dans le temps)
        $learningVelocity = $this->calculateLearningVelocity($studentId, $tenantId);

        return [
            'total_sessions' => (int)($globalStats['total_sessions'] ?? 0),
            'avg_score' => round((float)($globalStats['avg_score'] ?? 0), 1),
            'avg_mastery' => round((float)($globalStats['avg_mastery'] ?? 0), 2),
            'total_time' => (int)($globalStats['total_time'] ?? 0),
            'learning_velocity' => $learningVelocity,
            'theme_performance' => $themePerformance,
            'strengths' => $this->identifyStrengths($themePerformance),
            'weaknesses' => $this->identifyWeaknesses($themePerformance)
        ];
    }

    /**
     * Calculer la vélocité d'apprentissage (pente de progression)
     */
    private function calculateLearningVelocity($studentId, $tenantId) {
        $recentSessions = $this->db->query(
            'SELECT score, completed_at
             FROM student_sessions
             WHERE student_id = :student_id
               AND tenant_id = :tenant_id
               AND status = \'completed\'
             ORDER BY completed_at DESC
             LIMIT 10',
            [
                'student_id' => $studentId,
                'tenant_id' => $tenantId
            ]
        );

        if (count($recentSessions) < 3) {
            return 'insufficient_data';
        }

        // Calculer la tendance (simple: comparer 5 dernières vs 5 précédentes)
        $recent5 = array_slice($recentSessions, 0, 5);
        $previous5 = array_slice($recentSessions, 5, 5);

        $avgRecent = array_sum(array_column($recent5, 'score')) / count($recent5);
        $avgPrevious = count($previous5) > 0
            ? array_sum(array_column($previous5, 'score')) / count($previous5)
            : $avgRecent;

        $diff = $avgRecent - $avgPrevious;

        if ($diff > 5) return 'improving';
        if ($diff < -5) return 'declining';
        return 'stable';
    }

    /**
     * Identifier les forces (thèmes avec bonne maîtrise)
     */
    private function identifyStrengths($themePerformance) {
        $strengths = array_filter($themePerformance, function($theme) {
            return $theme['avg_mastery'] >= 0.75 && $theme['avg_score'] >= 75;
        });

        usort($strengths, function($a, $b) {
            return $b['avg_mastery'] <=> $a['avg_mastery'];
        });

        return array_slice($strengths, 0, 3);
    }

    /**
     * Identifier les faiblesses (thèmes avec difficulté)
     */
    private function identifyWeaknesses($themePerformance) {
        $weaknesses = array_filter($themePerformance, function($theme) {
            return $theme['avg_mastery'] < 0.50 || $theme['avg_score'] < 50;
        });

        usort($weaknesses, function($a, $b) {
            return $a['avg_mastery'] <=> $b['avg_mastery'];
        });

        return array_slice($weaknesses, 0, 3);
    }

    /**
     * Scorer tous les thèmes candidats
     */
    private function scoreCandidateThemes($studentId, $tenantId, $analysis) {
        // Récupérer tous les thèmes actifs
        $allThemes = $this->db->query(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM student_sessions ss
                     WHERE ss.theme_id = t.id AND ss.student_id = :student_id) as attempt_count,
                    (SELECT AVG(score) FROM student_sessions ss
                     WHERE ss.theme_id = t.id AND ss.student_id = :student_id) as student_avg_score,
                    (SELECT MAX(completed_at) FROM student_sessions ss
                     WHERE ss.theme_id = t.id AND ss.student_id = :student_id) as last_attempt_date
             FROM themes t
             WHERE t.tenant_id = :tenant_id
               AND t.status = \'active\'',
            [
                'student_id' => $studentId,
                'tenant_id' => $tenantId
            ]
        );

        $scoredThemes = [];

        foreach ($allThemes as $theme) {
            $score = $this->calculateRecommendationScore($theme, $analysis, $studentId);

            if ($score > 0) {
                $scoredThemes[] = [
                    'theme_id' => $theme['id'],
                    'theme_title' => $theme['title'],
                    'theme_description' => $theme['description'],
                    'theme_difficulty' => $theme['difficulty'],
                    'recommendation_score' => $score,
                    'attempt_count' => (int)$theme['attempt_count'],
                    'student_avg_score' => $theme['student_avg_score'] ? round($theme['student_avg_score'], 1) : null,
                    'last_attempt_date' => $theme['last_attempt_date']
                ];
            }
        }

        // Trier par score décroissant
        usort($scoredThemes, function($a, $b) {
            return $b['recommendation_score'] <=> $a['recommendation_score'];
        });

        return $scoredThemes;
    }

    /**
     * Calculer le score de recommandation pour un thème
     */
    private function calculateRecommendationScore($theme, $analysis, $studentId) {
        $score = 0;

        // 1. Performance Factor (0-100)
        // Recommander thèmes avec score moyen (pas trop facile, pas trop difficile)
        $studentAvgScore = $theme['student_avg_score'] ?? 50;
        if ($studentAvgScore >= 50 && $studentAvgScore < 80) {
            $performanceScore = 100 - abs(65 - $studentAvgScore); // Optimal autour de 65%
        } elseif ($studentAvgScore < 50) {
            $performanceScore = 80; // Besoin de révision
        } else {
            $performanceScore = 30; // Déjà bien maîtrisé
        }
        $score += $performanceScore * self::WEIGHT_PERFORMANCE;

        // 2. Difficulty Factor (0-100)
        // Adapter selon le niveau général de l'élève
        $difficultyScore = $this->calculateDifficultyFit($theme['difficulty'], $analysis);
        $score += $difficultyScore * self::WEIGHT_DIFFICULTY;

        // 3. Recency Factor (0-100)
        // Pénaliser les thèmes trop récemment faits, favoriser révision après délai optimal
        $recencyScore = $this->calculateRecencyScore($theme['last_attempt_date']);
        $score += $recencyScore * self::WEIGHT_RECENCY;

        // 4. Completion Factor (0-100)
        // Favoriser thèmes avec peu de tentatives (nouveau contenu)
        $attemptCount = (int)$theme['attempt_count'];
        if ($attemptCount == 0) {
            $completionScore = 100; // Nouveau
        } elseif ($attemptCount <= 2) {
            $completionScore = 70; // En cours
        } else {
            $completionScore = 40; // Déjà bien travaillé
        }
        $score += $completionScore * self::WEIGHT_COMPLETION;

        return round($score, 2);
    }

    /**
     * Calculer l'adéquation de la difficulté
     */
    private function calculateDifficultyFit($themeDifficulty, $analysis) {
        $avgScore = $analysis['avg_score'];
        $avgMastery = $analysis['avg_mastery'];

        // Déterminer le niveau de l'élève
        if ($avgScore >= 80 && $avgMastery >= 0.70) {
            $studentLevel = 'advanced';
        } elseif ($avgScore >= 60 && $avgMastery >= 0.50) {
            $studentLevel = 'intermediate';
        } else {
            $studentLevel = 'beginner';
        }

        // Recommander niveau légèrement supérieur (ZPD)
        $idealDifficulty = [
            'beginner' => ['beginner' => 70, 'intermediate' => 100, 'advanced' => 40],
            'intermediate' => ['beginner' => 40, 'intermediate' => 80, 'advanced' => 100],
            'advanced' => ['beginner' => 20, 'intermediate' => 60, 'advanced' => 100]
        ];

        return $idealDifficulty[$studentLevel][$themeDifficulty] ?? 50;
    }

    /**
     * Calculer le score de récence
     */
    private function calculateRecencyScore($lastAttemptDate) {
        if (!$lastAttemptDate) {
            return 100; // Jamais fait
        }

        $daysSinceLastAttempt = (time() - strtotime($lastAttemptDate)) / 86400;

        // Courbe de l'oubli: optimal entre 3-7 jours
        if ($daysSinceLastAttempt < 1) {
            return 20; // Trop récent
        } elseif ($daysSinceLastAttempt <= 3) {
            return 60; // Encore frais
        } elseif ($daysSinceLastAttempt <= 7) {
            return 100; // Optimal pour révision
        } elseif ($daysSinceLastAttempt <= 14) {
            return 80; // Bon timing
        } elseif ($daysSinceLastAttempt <= 30) {
            return 60; // À réviser
        } else {
            return 40; // Trop ancien
        }
    }

    /**
     * Enrichir la recommandation avec explicabilité
     */
    private function enrichRecommendation($recommendation, $analysis) {
        $reasons = [];

        // Générer les raisons de la recommandation
        if ($recommendation['attempt_count'] == 0) {
            $reasons[] = [
                'type' => 'new_content',
                'label' => 'Nouveau contenu',
                'description' => 'Vous n\'avez pas encore exploré ce thème'
            ];
        }

        if ($recommendation['student_avg_score'] && $recommendation['student_avg_score'] < 60) {
            $reasons[] = [
                'type' => 'needs_review',
                'label' => 'À réviser',
                'description' => 'Votre score moyen est de ' . round($recommendation['student_avg_score'], 1) . '%'
            ];
        }

        // Difficulté adaptée
        $reasons[] = [
            'type' => 'adaptive_difficulty',
            'label' => 'Niveau adapté',
            'description' => 'Ce thème correspond à votre niveau actuel (ZPD)'
        ];

        // Timing optimal
        if ($recommendation['last_attempt_date']) {
            $daysSince = (time() - strtotime($recommendation['last_attempt_date'])) / 86400;
            if ($daysSince >= 3 && $daysSince <= 7) {
                $reasons[] = [
                    'type' => 'optimal_timing',
                    'label' => 'Timing optimal',
                    'description' => 'Moment idéal pour réviser (courbe de l\'oubli)'
                ];
            }
        }

        $recommendation['reasons'] = $reasons;
        $recommendation['explainability'] = $this->generateExplainability($recommendation, $reasons);

        return $recommendation;
    }

    /**
     * Générer une explication textuelle
     */
    private function generateExplainability($recommendation, $reasons) {
        $parts = [];

        foreach ($reasons as $reason) {
            $parts[] = $reason['description'];
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * Logger la recommandation pour analyse future
     */
    private function logRecommendation($studentId, $tenantId, $recommendations) {
        try {
            foreach ($recommendations as $rec) {
                $this->db->execute(
                    'INSERT INTO recommendation_logs
                     (id, tenant_id, student_id, theme_id, recommendation_score, reasons, created_at)
                     VALUES (:id, :tenant_id, :student_id, :theme_id, :score, :reasons, NOW())',
                    [
                        'id' => generateId('reclog'),
                        'tenant_id' => $tenantId,
                        'student_id' => $studentId,
                        'theme_id' => $rec['theme_id'],
                        'score' => $rec['recommendation_score'],
                        'reasons' => json_encode($rec['reasons'])
                    ]
                );
            }
        } catch (Exception $e) {
            logError('Failed to log recommendation', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Enregistrer le feedback de l'élève sur une recommandation
     */
    public function recordFeedback($studentId, $tenantId, $themeId, $feedback) {
        try {
            $this->db->execute(
                'UPDATE recommendation_logs
                 SET feedback = :feedback, feedback_at = NOW()
                 WHERE student_id = :student_id
                   AND tenant_id = :tenant_id
                   AND theme_id = :theme_id
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                 ORDER BY created_at DESC
                 LIMIT 1',
                [
                    'student_id' => $studentId,
                    'tenant_id' => $tenantId,
                    'theme_id' => $themeId,
                    'feedback' => $feedback
                ]
            );

            logInfo('Recommendation feedback recorded', [
                'student_id' => $studentId,
                'theme_id' => $themeId,
                'feedback' => $feedback
            ]);

            return ['success' => true];

        } catch (Exception $e) {
            logError('Failed to record feedback', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
