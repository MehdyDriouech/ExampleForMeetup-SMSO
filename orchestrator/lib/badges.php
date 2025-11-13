<?php
/**
 * Badge Service - Sprint 5
 * Handles badge rules, evaluation, and awarding
 */

class BadgeService {
    private $db;

    public function __construct() {
        $this->db = db();
    }

    /**
     * Check and award badges to a student after a session
     * @param string $studentId
     * @param string $tenantId
     * @return array List of newly awarded badges
     */
    public function checkAndAwardBadges($studentId, $tenantId) {
        $newBadges = [];

        // Get all badges for this tenant that the student hasn't earned yet
        $availableBadges = $this->db->query(
            'SELECT b.*
             FROM badges b
             WHERE b.tenant_id = :tenant_id
               AND b.id NOT IN (
                   SELECT badge_id FROM student_badges WHERE student_id = :student_id
               )',
            ['tenant_id' => $tenantId, 'student_id' => $studentId]
        );

        foreach ($availableBadges as $badge) {
            if ($this->evaluateBadgeCriteria($studentId, $badge)) {
                $newBadge = $this->awardBadge($studentId, $badge['id'], $tenantId);
                if ($newBadge) {
                    $newBadges[] = $newBadge;
                }
            }
        }

        return $newBadges;
    }

    /**
     * Evaluate if a student meets the criteria for a badge
     * @param string $studentId
     * @param array $badge
     * @return bool
     */
    private function evaluateBadgeCriteria($studentId, $badge) {
        if (empty($badge['criteria'])) {
            return false;
        }

        $criteria = json_decode($badge['criteria'], true);
        if (!is_array($criteria)) {
            return false;
        }

        // Get student stats
        $stats = $this->getStudentStats($studentId);

        // Evaluate each criterion
        foreach ($criteria as $criterion) {
            $type = $criterion['type'] ?? null;
            $value = $criterion['value'] ?? null;
            $operator = $criterion['operator'] ?? '>=';

            if (!$type || $value === null) {
                continue;
            }

            switch ($type) {
                case 'total_sessions':
                    if (!$this->compareValues($stats['total_sessions'], $operator, $value)) {
                        return false;
                    }
                    break;

                case 'avg_score':
                    if (!$this->compareValues($stats['avg_score'], $operator, $value)) {
                        return false;
                    }
                    break;

                case 'mastery_threshold':
                    if (!$this->compareValues($stats['avg_mastery'] * 100, $operator, $value)) {
                        return false;
                    }
                    break;

                case 'perfect_score_count':
                    $perfectScores = $this->db->queryOne(
                        'SELECT COUNT(*) as count FROM student_sessions
                         WHERE student_id = :student_id AND score >= 100',
                        ['student_id' => $studentId]
                    );
                    if (!$this->compareValues($perfectScores['count'], $operator, $value)) {
                        return false;
                    }
                    break;

                case 'consecutive_days':
                    $consecutiveDays = $this->getConsecutiveDays($studentId);
                    if (!$this->compareValues($consecutiveDays, $operator, $value)) {
                        return false;
                    }
                    break;

                case 'theme_mastery':
                    // Check if student has mastered a specific number of themes
                    $masteredThemes = $this->db->queryOne(
                        'SELECT COUNT(*) as count FROM stats
                         WHERE student_id = :student_id AND mastery >= 0.8',
                        ['student_id' => $studentId]
                    );
                    if (!$this->compareValues($masteredThemes['count'], $operator, $value)) {
                        return false;
                    }
                    break;

                case 'time_spent':
                    // Total time spent in seconds
                    if (!$this->compareValues($stats['total_time_spent'], $operator, $value)) {
                        return false;
                    }
                    break;

                case 'streak_days':
                    $streakDays = $this->getCurrentStreak($studentId);
                    if (!$this->compareValues($streakDays, $operator, $value)) {
                        return false;
                    }
                    break;

                default:
                    // Unknown criterion type - skip
                    break;
            }
        }

        return true;
    }

    /**
     * Award a badge to a student
     * @param string $studentId
     * @param string $badgeId
     * @param string $tenantId
     * @return array|null
     */
    private function awardBadge($studentId, $badgeId, $tenantId) {
        try {
            $id = generateId('bdg');

            $this->db->execute(
                'INSERT INTO student_badges (id, student_id, badge_id, tenant_id, earned_at)
                 VALUES (:id, :student_id, :badge_id, :tenant_id, NOW())',
                [
                    'id' => $id,
                    'student_id' => $studentId,
                    'badge_id' => $badgeId,
                    'tenant_id' => $tenantId
                ]
            );

            $badge = $this->db->queryOne(
                'SELECT * FROM badges WHERE id = :id',
                ['id' => $badgeId]
            );

            logInfo('Badge awarded', [
                'student_id' => $studentId,
                'badge_id' => $badgeId,
                'badge_name' => $badge['name']
            ]);

            return [
                'id' => $id,
                'badge_id' => $badgeId,
                'name' => $badge['name'],
                'description' => $badge['description'],
                'icon' => $badge['icon'],
                'earned_at' => date('c')
            ];

        } catch (Exception $e) {
            logError('Failed to award badge', [
                'student_id' => $studentId,
                'badge_id' => $badgeId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get student statistics
     * @param string $studentId
     * @return array
     */
    private function getStudentStats($studentId) {
        $stats = $this->db->queryOne(
            'SELECT
                COUNT(DISTINCT ss.id) as total_sessions,
                COALESCE(AVG(ss.score), 0) as avg_score,
                COALESCE(SUM(ss.time_spent), 0) as total_time_spent,
                COALESCE((SELECT AVG(mastery) FROM stats WHERE student_id = :student_id), 0) as avg_mastery
             FROM student_sessions ss
             WHERE ss.student_id = :student_id AND ss.status = "terminee"',
            ['student_id' => $studentId]
        );

        return $stats ?: [
            'total_sessions' => 0,
            'avg_score' => 0,
            'total_time_spent' => 0,
            'avg_mastery' => 0
        ];
    }

    /**
     * Calculate progress towards a badge
     * @param string $studentId
     * @param string $badgeId
     * @return array
     */
    public function calculateBadgeProgress($studentId, $badgeId) {
        $badge = $this->db->queryOne(
            'SELECT criteria FROM badges WHERE id = :id',
            ['id' => $badgeId]
        );

        if (!$badge || empty($badge['criteria'])) {
            return ['percent' => 0, 'details' => []];
        }

        $criteria = json_decode($badge['criteria'], true);
        if (!is_array($criteria)) {
            return ['percent' => 0, 'details' => []];
        }

        $stats = $this->getStudentStats($studentId);
        $progressDetails = [];
        $totalCriteria = count($criteria);
        $metCriteria = 0;

        foreach ($criteria as $criterion) {
            $type = $criterion['type'] ?? null;
            $value = $criterion['value'] ?? null;

            if (!$type || $value === null) {
                continue;
            }

            $current = 0;
            $label = '';

            switch ($type) {
                case 'total_sessions':
                    $current = $stats['total_sessions'];
                    $label = 'Sessions complétées';
                    break;

                case 'avg_score':
                    $current = $stats['avg_score'];
                    $label = 'Score moyen';
                    break;

                case 'mastery_threshold':
                    $current = $stats['avg_mastery'] * 100;
                    $label = 'Niveau de maîtrise';
                    break;

                case 'perfect_score_count':
                    $perfectScores = $this->db->queryOne(
                        'SELECT COUNT(*) as count FROM student_sessions
                         WHERE student_id = :student_id AND score >= 100',
                        ['student_id' => $studentId]
                    );
                    $current = $perfectScores['count'];
                    $label = 'Scores parfaits';
                    break;

                case 'theme_mastery':
                    $masteredThemes = $this->db->queryOne(
                        'SELECT COUNT(*) as count FROM stats
                         WHERE student_id = :student_id AND mastery >= 0.8',
                        ['student_id' => $studentId]
                    );
                    $current = $masteredThemes['count'];
                    $label = 'Thèmes maîtrisés';
                    break;

                case 'streak_days':
                    $current = $this->getCurrentStreak($studentId);
                    $label = 'Série de jours';
                    break;

                default:
                    continue 2;
            }

            $met = $current >= $value;
            if ($met) {
                $metCriteria++;
            }

            $progressDetails[] = [
                'type' => $type,
                'label' => $label,
                'current' => $current,
                'target' => $value,
                'met' => $met,
                'progress_percent' => min(100, ($current / $value) * 100)
            ];
        }

        $overallPercent = $totalCriteria > 0 ? ($metCriteria / $totalCriteria) * 100 : 0;

        return [
            'percent' => round($overallPercent, 1),
            'met_criteria' => $metCriteria,
            'total_criteria' => $totalCriteria,
            'details' => $progressDetails
        ];
    }

    /**
     * Get consecutive days the student has been active
     * @param string $studentId
     * @return int
     */
    private function getConsecutiveDays($studentId) {
        $sessions = $this->db->query(
            'SELECT DISTINCT DATE(completed_at) as date
             FROM student_sessions
             WHERE student_id = :student_id AND status = "terminee"
             ORDER BY date DESC',
            ['student_id' => $studentId]
        );

        if (empty($sessions)) {
            return 0;
        }

        $consecutiveDays = 0;
        $lastDate = null;

        foreach ($sessions as $session) {
            $currentDate = new DateTime($session['date']);

            if ($lastDate === null) {
                $consecutiveDays = 1;
                $lastDate = $currentDate;
                continue;
            }

            $diff = $lastDate->diff($currentDate)->days;

            if ($diff === 1) {
                $consecutiveDays++;
                $lastDate = $currentDate;
            } else {
                break;
            }
        }

        return $consecutiveDays;
    }

    /**
     * Get current streak (including today)
     * @param string $studentId
     * @return int
     */
    private function getCurrentStreak($studentId) {
        $today = date('Y-m-d');

        $sessions = $this->db->query(
            'SELECT DISTINCT DATE(completed_at) as date
             FROM student_sessions
             WHERE student_id = :student_id AND status = "terminee"
             ORDER BY date DESC',
            ['student_id' => $studentId]
        );

        if (empty($sessions)) {
            return 0;
        }

        $streak = 0;
        $expectedDate = new DateTime($today);

        foreach ($sessions as $session) {
            $sessionDate = new DateTime($session['date']);

            if ($sessionDate->format('Y-m-d') === $expectedDate->format('Y-m-d')) {
                $streak++;
                $expectedDate->modify('-1 day');
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Compare values with an operator
     * @param mixed $actual
     * @param string $operator
     * @param mixed $expected
     * @return bool
     */
    private function compareValues($actual, $operator, $expected) {
        switch ($operator) {
            case '>=':
                return $actual >= $expected;
            case '>':
                return $actual > $expected;
            case '<=':
                return $actual <= $expected;
            case '<':
                return $actual < $expected;
            case '==':
            case '=':
                return $actual == $expected;
            default:
                return false;
        }
    }

    /**
     * Initialize default badges for a tenant
     * @param string $tenantId
     */
    public function initializeDefaultBadges($tenantId) {
        $defaultBadges = [
            [
                'name' => 'Premier Pas',
                'description' => 'Complétez votre première session',
                'icon' => '🎯',
                'category' => 'débutant',
                'tier' => 'bronze',
                'criteria' => json_encode([
                    ['type' => 'total_sessions', 'operator' => '>=', 'value' => 1]
                ])
            ],
            [
                'name' => 'Persévérant',
                'description' => 'Complétez 10 sessions',
                'icon' => '💪',
                'category' => 'progression',
                'tier' => 'bronze',
                'criteria' => json_encode([
                    ['type' => 'total_sessions', 'operator' => '>=', 'value' => 10]
                ])
            ],
            [
                'name' => 'Expert en Herbe',
                'description' => 'Atteignez un score moyen de 80%',
                'icon' => '📚',
                'category' => 'excellence',
                'tier' => 'silver',
                'criteria' => json_encode([
                    ['type' => 'avg_score', 'operator' => '>=', 'value' => 80]
                ])
            ],
            [
                'name' => 'Perfectionniste',
                'description' => 'Obtenez 5 scores parfaits (100%)',
                'icon' => '⭐',
                'category' => 'excellence',
                'tier' => 'gold',
                'criteria' => json_encode([
                    ['type' => 'perfect_score_count', 'operator' => '>=', 'value' => 5]
                ])
            ],
            [
                'name' => 'Régulier',
                'description' => 'Travaillez 7 jours d\'affilée',
                'icon' => '🔥',
                'category' => 'régularité',
                'tier' => 'silver',
                'criteria' => json_encode([
                    ['type' => 'streak_days', 'operator' => '>=', 'value' => 7]
                ])
            ],
            [
                'name' => 'Maître',
                'description' => 'Maîtrisez 5 thèmes (80%+)',
                'icon' => '👑',
                'category' => 'maîtrise',
                'tier' => 'gold',
                'criteria' => json_encode([
                    ['type' => 'theme_mastery', 'operator' => '>=', 'value' => 5]
                ])
            ]
        ];

        foreach ($defaultBadges as $badge) {
            $badgeId = generateId('badge');
            $this->db->execute(
                'INSERT INTO badges (id, tenant_id, name, description, icon, category, tier, criteria, created_at)
                 VALUES (:id, :tenant_id, :name, :description, :icon, :category, :tier, :criteria, NOW())',
                array_merge($badge, [
                    'id' => $badgeId,
                    'tenant_id' => $tenantId
                ])
            );
        }
    }
}
