<?php
/**
 * Teacher Validation System - Sprint 7 E7-HIL
 *
 * Human-in-the-loop: Les enseignants peuvent valider/ajuster:
 * - Les recommandations IA avant envoi aux élèves
 * - Les niveaux de difficulté adaptatifs
 * - Les sessions Focus suggérées
 * - Les contenus générés par IA
 */

class TeacherValidationService {
    private $db;

    // Types d'items validables
    const VALIDATION_TYPES = [
        'recommendation' => 'Recommandation IA',
        'difficulty_adjustment' => 'Ajustement de difficulté',
        'focus_session' => 'Session Focus',
        'ai_content' => 'Contenu généré par IA'
    ];

    // Statuts de validation
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_MODIFIED = 'modified';

    public function __construct() {
        $this->db = db();
    }

    /**
     * Soumettre un item pour validation enseignant
     *
     * @param string $teacherId ID de l'enseignant validateur
     * @param string $tenantId ID du tenant
     * @param string $type Type d'item (recommendation, difficulty_adjustment, etc.)
     * @param array $data Données de l'item
     * @param array $metadata Métadonnées additionnelles
     * @return array Résultat de la soumission
     */
    public function submitForValidation($teacherId, $tenantId, $type, $data, $metadata = []) {
        if (!array_key_exists($type, self::VALIDATION_TYPES)) {
            throw new Exception('Invalid validation type: ' . $type);
        }

        $validationId = generateId('val');

        $this->db->execute(
            'INSERT INTO teacher_validations
             (id, tenant_id, teacher_id, validation_type, item_data, metadata, status, created_at)
             VALUES (:id, :tenant_id, :teacher_id, :type, :data, :metadata, :status, NOW())',
            [
                'id' => $validationId,
                'tenant_id' => $tenantId,
                'teacher_id' => $teacherId,
                'type' => $type,
                'data' => json_encode($data),
                'metadata' => json_encode($metadata),
                'status' => self::STATUS_PENDING
            ]
        );

        logInfo('Item submitted for teacher validation', [
            'validation_id' => $validationId,
            'teacher_id' => $teacherId,
            'type' => $type
        ]);

        return [
            'validation_id' => $validationId,
            'status' => self::STATUS_PENDING,
            'type' => $type,
            'created_at' => date('c')
        ];
    }

    /**
     * Lister les items en attente de validation pour un enseignant
     *
     * @param string $teacherId ID de l'enseignant
     * @param string $tenantId ID du tenant
     * @param array $filters Filtres optionnels
     * @return array Liste des items
     */
    public function getPendingValidations($teacherId, $tenantId, $filters = []) {
        $sql = 'SELECT v.*,
                       s.firstname as student_firstname,
                       s.lastname as student_lastname
                FROM teacher_validations v
                LEFT JOIN students s ON JSON_EXTRACT(v.metadata, "$.student_id") = s.id
                WHERE v.tenant_id = :tenant_id
                  AND v.status = :status';

        $params = [
            'tenant_id' => $tenantId,
            'status' => self::STATUS_PENDING
        ];

        // Filtrer par enseignant spécifique ou tous les enseignants du tenant
        if (!empty($filters['my_items_only'])) {
            $sql .= ' AND v.teacher_id = :teacher_id';
            $params['teacher_id'] = $teacherId;
        }

        // Filtrer par type
        if (!empty($filters['type'])) {
            $sql .= ' AND v.validation_type = :type';
            $params['type'] = $filters['type'];
        }

        $sql .= ' ORDER BY v.created_at DESC LIMIT 50';

        $items = $this->db->query($sql, $params);

        // Décoder les JSON
        foreach ($items as &$item) {
            $item['item_data'] = json_decode($item['item_data'], true);
            $item['metadata'] = json_decode($item['metadata'], true);
        }

        return $items;
    }

    /**
     * Valider (approuver) un item
     *
     * @param string $validationId ID de la validation
     * @param string $teacherId ID de l'enseignant
     * @param string $tenantId ID du tenant
     * @param array $modifications Modifications éventuelles
     * @return array Résultat
     */
    public function approve($validationId, $teacherId, $tenantId, $modifications = []) {
        return $this->processValidation(
            $validationId,
            $teacherId,
            $tenantId,
            empty($modifications) ? self::STATUS_APPROVED : self::STATUS_MODIFIED,
            $modifications
        );
    }

    /**
     * Rejeter un item
     *
     * @param string $validationId ID de la validation
     * @param string $teacherId ID de l'enseignant
     * @param string $tenantId ID du tenant
     * @param string $reason Raison du rejet
     * @return array Résultat
     */
    public function reject($validationId, $teacherId, $tenantId, $reason = '') {
        return $this->processValidation(
            $validationId,
            $teacherId,
            $tenantId,
            self::STATUS_REJECTED,
            null,
            $reason
        );
    }

    /**
     * Traiter une validation
     */
    private function processValidation($validationId, $teacherId, $tenantId, $status, $modifications = null, $reason = '') {
        // Récupérer l'item
        $item = $this->db->queryOne(
            'SELECT * FROM teacher_validations
             WHERE id = :id AND tenant_id = :tenant_id',
            [
                'id' => $validationId,
                'tenant_id' => $tenantId
            ]
        );

        if (!$item) {
            throw new Exception('Validation item not found');
        }

        if ($item['status'] !== self::STATUS_PENDING) {
            throw new Exception('Item already processed');
        }

        // Mettre à jour le statut
        $this->db->execute(
            'UPDATE teacher_validations
             SET status = :status,
                 validated_by = :teacher_id,
                 validated_at = NOW(),
                 modifications = :modifications,
                 rejection_reason = :reason,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $validationId,
                'status' => $status,
                'teacher_id' => $teacherId,
                'modifications' => $modifications ? json_encode($modifications) : null,
                'reason' => $reason
            ]
        );

        // Si approuvé ou modifié, appliquer l'action
        if ($status === self::STATUS_APPROVED || $status === self::STATUS_MODIFIED) {
            $this->applyValidatedItem($item, $modifications);
        }

        logInfo('Validation processed', [
            'validation_id' => $validationId,
            'status' => $status,
            'teacher_id' => $teacherId
        ]);

        return [
            'validation_id' => $validationId,
            'status' => $status,
            'validated_at' => date('c')
        ];
    }

    /**
     * Appliquer un item validé
     */
    private function applyValidatedItem($item, $modifications = null) {
        $data = json_decode($item['item_data'], true);
        $metadata = json_decode($item['metadata'], true);

        // Appliquer les modifications si présentes
        if ($modifications) {
            $data = array_merge($data, $modifications);
        }

        switch ($item['validation_type']) {
            case 'recommendation':
                $this->applyRecommendation($data, $metadata);
                break;

            case 'difficulty_adjustment':
                $this->applyDifficultyAdjustment($data, $metadata);
                break;

            case 'focus_session':
                $this->applyFocusSession($data, $metadata);
                break;

            case 'ai_content':
                $this->applyAIContent($data, $metadata);
                break;

            default:
                logWarn('Unknown validation type, not applied', ['type' => $item['validation_type']]);
        }
    }

    /**
     * Appliquer une recommandation validée
     */
    private function applyRecommendation($data, $metadata) {
        $studentId = $metadata['student_id'] ?? null;

        if (!$studentId) {
            logError('Cannot apply recommendation: missing student_id');
            return;
        }

        // Créer une notification ou assignment pour l'élève
        // TODO: Intégrer avec le système de notifications
        logInfo('Recommendation applied', [
            'student_id' => $studentId,
            'theme_id' => $data['theme_id'] ?? null
        ]);
    }

    /**
     * Appliquer un ajustement de difficulté validé
     */
    private function applyDifficultyAdjustment($data, $metadata) {
        $studentId = $metadata['student_id'] ?? null;
        $newLevel = $data['new_level'] ?? null;

        if (!$studentId || !$newLevel) {
            logError('Cannot apply difficulty adjustment: missing data');
            return;
        }

        // Enregistrer la préférence de niveau
        $this->db->execute(
            'INSERT INTO student_preferences (student_id, preference_key, preference_value, set_by, created_at)
             VALUES (:student_id, :key, :value, :set_by, NOW())
             ON DUPLICATE KEY UPDATE preference_value = :value, set_by = :set_by, updated_at = NOW()',
            [
                'student_id' => $studentId,
                'key' => 'difficulty_level',
                'value' => $newLevel,
                'set_by' => 'teacher'
            ]
        );

        logInfo('Difficulty adjustment applied', [
            'student_id' => $studentId,
            'new_level' => $newLevel
        ]);
    }

    /**
     * Appliquer une session Focus validée
     */
    private function applyFocusSession($data, $metadata) {
        $studentId = $metadata['student_id'] ?? null;

        if (!$studentId) {
            logError('Cannot apply focus session: missing student_id');
            return;
        }

        // Créer l'assignment pour la session Focus
        // TODO: Intégrer avec le système d'assignments
        logInfo('Focus session applied', [
            'student_id' => $studentId,
            'mode' => $data['mode'] ?? 'unknown'
        ]);
    }

    /**
     * Appliquer un contenu IA validé
     */
    private function applyAIContent($data, $metadata) {
        $contentType = $data['content_type'] ?? null;

        if (!$contentType) {
            logError('Cannot apply AI content: missing content_type');
            return;
        }

        // Activer le contenu (changer status de draft à active)
        if ($contentType === 'theme' && !empty($data['theme_id'])) {
            $this->db->execute(
                'UPDATE themes SET status = :status, validated_by = :teacher_id, validated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => $data['theme_id'],
                    'status' => 'active',
                    'teacher_id' => $metadata['teacher_id'] ?? null
                ]
            );
        }

        logInfo('AI content applied', [
            'content_type' => $contentType,
            'content_id' => $data['theme_id'] ?? $data['id'] ?? null
        ]);
    }

    /**
     * Obtenir les statistiques de validation pour un enseignant
     *
     * @param string $teacherId ID de l'enseignant
     * @param string $tenantId ID du tenant
     * @return array Statistiques
     */
    public function getValidationStats($teacherId, $tenantId) {
        $stats = $this->db->queryOne(
            'SELECT
                COUNT(*) as total_validations,
                SUM(CASE WHEN status = :pending THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = :approved THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN status = :rejected THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = :modified THEN 1 ELSE 0 END) as modified_count,
                AVG(TIMESTAMPDIFF(MINUTE, created_at, validated_at)) as avg_response_time_minutes
             FROM teacher_validations
             WHERE tenant_id = :tenant_id
               AND (teacher_id = :teacher_id OR validated_by = :teacher_id)',
            [
                'tenant_id' => $tenantId,
                'teacher_id' => $teacherId,
                'pending' => self::STATUS_PENDING,
                'approved' => self::STATUS_APPROVED,
                'rejected' => self::STATUS_REJECTED,
                'modified' => self::STATUS_MODIFIED
            ]
        );

        // Stats par type
        $byType = $this->db->query(
            'SELECT validation_type, COUNT(*) as count
             FROM teacher_validations
             WHERE tenant_id = :tenant_id
               AND (teacher_id = :teacher_id OR validated_by = :teacher_id)
             GROUP BY validation_type',
            [
                'tenant_id' => $tenantId,
                'teacher_id' => $teacherId
            ]
        );

        return [
            'summary' => $stats,
            'by_type' => $byType
        ];
    }
}
