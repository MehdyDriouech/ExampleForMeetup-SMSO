<?php
/**
 * Sprint 12 - Pedagogical Library
 * Service: Workflow Manager
 *
 * Gestion du workflow de validation des thèmes du catalogue interne:
 * - Draft → Proposed → Validated → Published
 * - Rejection avec commentaires
 * - Archivage
 * - Historique des transitions
 *
 * @version 1.0.0
 * @date 2025-11-13
 */

class WorkflowManager {
    private $db;

    // États possibles du workflow
    const STATUS_DRAFT = 'draft';
    const STATUS_PROPOSED = 'proposed';
    const STATUS_VALIDATED = 'validated';
    const STATUS_PUBLISHED = 'published';
    const STATUS_REJECTED = 'rejected';
    const STATUS_ARCHIVED = 'archived';

    // Transitions autorisées [from => [to states]]
    const ALLOWED_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_PROPOSED, self::STATUS_ARCHIVED],
        self::STATUS_PROPOSED => [self::STATUS_VALIDATED, self::STATUS_REJECTED, self::STATUS_DRAFT],
        self::STATUS_VALIDATED => [self::STATUS_PUBLISHED, self::STATUS_DRAFT],
        self::STATUS_PUBLISHED => [self::STATUS_ARCHIVED, self::STATUS_DRAFT],
        self::STATUS_REJECTED => [self::STATUS_DRAFT, self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED => [self::STATUS_DRAFT]
    ];

    // Permissions requises par transition
    const TRANSITION_PERMISSIONS = [
        self::STATUS_PROPOSED => ['teacher', 'admin', 'direction'],
        self::STATUS_VALIDATED => ['referent', 'admin', 'direction'],
        self::STATUS_PUBLISHED => ['direction', 'admin'],
        self::STATUS_REJECTED => ['referent', 'admin', 'direction'],
        self::STATUS_ARCHIVED => ['admin', 'direction'],
        self::STATUS_DRAFT => ['teacher', 'admin', 'direction']
    ];

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Vérifier si une transition est autorisée
     *
     * @param string $fromStatus État actuel
     * @param string $toStatus État cible
     * @return bool
     */
    public function isTransitionAllowed($fromStatus, $toStatus) {
        if (!isset(self::ALLOWED_TRANSITIONS[$fromStatus])) {
            return false;
        }

        return in_array($toStatus, self::ALLOWED_TRANSITIONS[$fromStatus]);
    }

    /**
     * Vérifier si un utilisateur peut effectuer une transition
     *
     * @param string $toStatus État cible
     * @param string $userRole Rôle de l'utilisateur
     * @return bool
     */
    public function canUserTransition($toStatus, $userRole) {
        if (!isset(self::TRANSITION_PERMISSIONS[$toStatus])) {
            return false;
        }

        return in_array($userRole, self::TRANSITION_PERMISSIONS[$toStatus]);
    }

    /**
     * Proposer un thème pour validation
     *
     * @param string $catalogEntryId ID de l'entrée catalogue
     * @param string $userId ID de l'utilisateur
     * @param string $comment Commentaire optionnel
     * @return array Résultat de l'opération
     */
    public function submitForValidation($catalogEntryId, $userId, $comment = null) {
        try {
            $entry = $this->getCatalogEntry($catalogEntryId);

            if (!$entry) {
                return ['success' => false, 'error' => 'Catalog entry not found'];
            }

            // Vérifier que c'est bien un draft
            if ($entry['workflow_status'] !== self::STATUS_DRAFT) {
                return [
                    'success' => false,
                    'error' => 'Only draft themes can be submitted for validation'
                ];
            }

            // Vérifier ownership
            if ($entry['created_by'] !== $userId) {
                return ['success' => false, 'error' => 'You can only submit your own themes'];
            }

            // Transition vers proposed
            $result = $this->transitionStatus(
                $catalogEntryId,
                $userId,
                self::STATUS_DRAFT,
                self::STATUS_PROPOSED,
                $comment ?? 'Submitted for validation'
            );

            if ($result['success']) {
                // Notifier les référents pédagogiques
                $this->notifyReferents($catalogEntryId, $entry['tenant_id']);
            }

            return $result;

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Valider un thème
     *
     * @param string $catalogEntryId ID de l'entrée catalogue
     * @param string $userId ID du validateur
     * @param string $comment Commentaire de validation
     * @return array Résultat de l'opération
     */
    public function validateTheme($catalogEntryId, $userId, $comment = null) {
        try {
            $entry = $this->getCatalogEntry($catalogEntryId);

            if (!$entry) {
                return ['success' => false, 'error' => 'Catalog entry not found'];
            }

            // Vérifier que le thème est en statut proposed
            if ($entry['workflow_status'] !== self::STATUS_PROPOSED) {
                return [
                    'success' => false,
                    'error' => 'Only proposed themes can be validated'
                ];
            }

            // Transition vers validated
            $result = $this->transitionStatus(
                $catalogEntryId,
                $userId,
                self::STATUS_PROPOSED,
                self::STATUS_VALIDATED,
                $comment ?? 'Theme validated'
            );

            if ($result['success']) {
                // Notifier l'auteur
                $this->notifyAuthor($catalogEntryId, $entry['created_by'], 'validated');
            }

            return $result;

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Rejeter un thème avec commentaire obligatoire
     *
     * @param string $catalogEntryId ID de l'entrée catalogue
     * @param string $userId ID du validateur
     * @param string $comment Commentaire de rejet (obligatoire)
     * @return array Résultat de l'opération
     */
    public function rejectTheme($catalogEntryId, $userId, $comment) {
        try {
            if (empty($comment)) {
                return [
                    'success' => false,
                    'error' => 'Rejection comment is required'
                ];
            }

            $entry = $this->getCatalogEntry($catalogEntryId);

            if (!$entry) {
                return ['success' => false, 'error' => 'Catalog entry not found'];
            }

            // Vérifier que le thème est en statut proposed
            if ($entry['workflow_status'] !== self::STATUS_PROPOSED) {
                return [
                    'success' => false,
                    'error' => 'Only proposed themes can be rejected'
                ];
            }

            // Transition vers rejected
            $result = $this->transitionStatus(
                $catalogEntryId,
                $userId,
                self::STATUS_PROPOSED,
                self::STATUS_REJECTED,
                $comment
            );

            if ($result['success']) {
                // Notifier l'auteur
                $this->notifyAuthor($catalogEntryId, $entry['created_by'], 'rejected', $comment);
            }

            return $result;

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Publier un thème validé au catalogue
     *
     * @param string $catalogEntryId ID de l'entrée catalogue
     * @param string $userId ID de l'utilisateur (direction)
     * @param string $comment Commentaire optionnel
     * @return array Résultat de l'opération
     */
    public function publishTheme($catalogEntryId, $userId, $comment = null) {
        try {
            $entry = $this->getCatalogEntry($catalogEntryId);

            if (!$entry) {
                return ['success' => false, 'error' => 'Catalog entry not found'];
            }

            // Vérifier que le thème est validé
            if ($entry['workflow_status'] !== self::STATUS_VALIDATED) {
                return [
                    'success' => false,
                    'error' => 'Only validated themes can be published'
                ];
            }

            // Transition vers published
            $result = $this->transitionStatus(
                $catalogEntryId,
                $userId,
                self::STATUS_VALIDATED,
                self::STATUS_PUBLISHED,
                $comment ?? 'Theme published to catalog'
            );

            if ($result['success']) {
                // Mettre à jour la date de publication
                $stmt = $this->db->prepare(
                    "UPDATE catalog_entries SET published_at = NOW() WHERE id = ?"
                );
                $stmt->execute([$catalogEntryId]);

                // Notifier tous les enseignants du tenant
                $this->notifyTenantTeachers($entry['tenant_id'], $catalogEntryId);
            }

            return $result;

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Archiver un thème
     *
     * @param string $catalogEntryId ID de l'entrée catalogue
     * @param string $userId ID de l'utilisateur
     * @param string $reason Raison de l'archivage
     * @return array Résultat de l'opération
     */
    public function archiveTheme($catalogEntryId, $userId, $reason = null) {
        try {
            $entry = $this->getCatalogEntry($catalogEntryId);

            if (!$entry) {
                return ['success' => false, 'error' => 'Catalog entry not found'];
            }

            // Transition vers archived
            $result = $this->transitionStatus(
                $catalogEntryId,
                $userId,
                $entry['workflow_status'],
                self::STATUS_ARCHIVED,
                $reason ?? 'Theme archived'
            );

            return $result;

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Effectuer une transition de statut
     *
     * @param string $catalogEntryId ID de l'entrée
     * @param string $userId ID de l'utilisateur effectuant la transition
     * @param string $fromStatus Statut actuel
     * @param string $toStatus Nouveau statut
     * @param string $comment Commentaire
     * @return array Résultat
     */
    private function transitionStatus($catalogEntryId, $userId, $fromStatus, $toStatus, $comment) {
        try {
            // Vérifier que la transition est autorisée
            if (!$this->isTransitionAllowed($fromStatus, $toStatus)) {
                return [
                    'success' => false,
                    'error' => "Transition from {$fromStatus} to {$toStatus} is not allowed"
                ];
            }

            $this->db->beginTransaction();

            // Mettre à jour le statut
            $stmt = $this->db->prepare(
                "UPDATE catalog_entries
                 SET workflow_status = ?,
                     updated_at = NOW(),
                     updated_by = ?
                 WHERE id = ?"
            );
            $stmt->execute([$toStatus, $userId, $catalogEntryId]);

            // Enregistrer la transition dans l'historique
            $this->logTransition($catalogEntryId, $userId, $fromStatus, $toStatus, $comment);

            $this->db->commit();

            return [
                'success' => true,
                'catalog_entry_id' => $catalogEntryId,
                'previous_status' => $fromStatus,
                'new_status' => $toStatus,
                'comment' => $comment
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Enregistrer une transition dans l'historique
     */
    private function logTransition($catalogEntryId, $userId, $fromStatus, $toStatus, $comment) {
        $stmt = $this->db->prepare(
            "INSERT INTO catalog_workflow_history
             (id, catalog_entry_id, user_id, from_status, to_status, comment, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );

        $historyId = 'wfh_' . bin2hex(random_bytes(16));
        $stmt->execute([
            $historyId,
            $catalogEntryId,
            $userId,
            $fromStatus,
            $toStatus,
            $comment
        ]);
    }

    /**
     * Récupérer l'historique du workflow d'un thème
     *
     * @param string $catalogEntryId ID de l'entrée
     * @return array Liste des transitions
     */
    public function getWorkflowHistory($catalogEntryId) {
        $stmt = $this->db->prepare(
            "SELECT h.*, u.name as user_name, u.email as user_email
             FROM catalog_workflow_history h
             LEFT JOIN users u ON h.user_id = u.id
             WHERE h.catalog_entry_id = ?
             ORDER BY h.created_at DESC"
        );

        $stmt->execute([$catalogEntryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupérer une entrée du catalogue
     */
    private function getCatalogEntry($catalogEntryId) {
        $stmt = $this->db->prepare(
            "SELECT * FROM catalog_entries WHERE id = ?"
        );
        $stmt->execute([$catalogEntryId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Notifier les référents pédagogiques
     */
    private function notifyReferents($catalogEntryId, $tenantId) {
        // TODO: Implémenter notification
        // - Email ou notification in-app
        // - Liste des référents du tenant

        $stmt = $this->db->prepare(
            "INSERT INTO notifications (id, tenant_id, user_role, type, data, created_at)
             VALUES (?, ?, 'referent', 'theme_submitted', ?, NOW())"
        );

        $notifId = 'notif_' . bin2hex(random_bytes(16));
        $data = json_encode(['catalog_entry_id' => $catalogEntryId]);

        $stmt->execute([$notifId, $tenantId, $data]);
    }

    /**
     * Notifier l'auteur d'un thème
     */
    private function notifyAuthor($catalogEntryId, $authorId, $action, $comment = null) {
        // TODO: Implémenter notification auteur

        $stmt = $this->db->prepare(
            "INSERT INTO notifications (id, user_id, type, data, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );

        $notifId = 'notif_' . bin2hex(random_bytes(16));
        $data = json_encode([
            'catalog_entry_id' => $catalogEntryId,
            'action' => $action,
            'comment' => $comment
        ]);

        $stmt->execute([$notifId, $authorId, 'theme_' . $action, $data]);
    }

    /**
     * Notifier tous les enseignants d'un tenant
     */
    private function notifyTenantTeachers($tenantId, $catalogEntryId) {
        // TODO: Implémenter notification tenant-wide

        $stmt = $this->db->prepare(
            "INSERT INTO notifications (id, tenant_id, user_role, type, data, created_at)
             VALUES (?, ?, 'teacher', 'new_catalog_theme', ?, NOW())"
        );

        $notifId = 'notif_' . bin2hex(random_bytes(16));
        $data = json_encode(['catalog_entry_id' => $catalogEntryId]);

        $stmt->execute([$notifId, $tenantId, $data]);
    }

    /**
     * Obtenir les statistiques du workflow pour un tenant
     *
     * @param string $tenantId ID du tenant
     * @return array Statistiques par statut
     */
    public function getWorkflowStats($tenantId) {
        $stmt = $this->db->prepare(
            "SELECT workflow_status, COUNT(*) as count
             FROM catalog_entries
             WHERE tenant_id = ?
             GROUP BY workflow_status"
        );

        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = [];
        foreach ($rows as $row) {
            $stats[$row['workflow_status']] = (int)$row['count'];
        }

        return $stats;
    }
}
