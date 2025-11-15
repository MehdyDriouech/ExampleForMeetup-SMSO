<?php
/**
 * Sprint 20 - Tenant Onboarding Complet
 * Service: OnboardingService
 * Description: Orchestration du flux d'onboarding d'un établissement
 *
 * Workflow: welcome → tenant_info → admin_user → import_structure →
 *          config_smtp → config_branding → config_quotas → tour → completed
 *
 * Fonctionnalités:
 * - Création de tenant avec template
 * - Suivi du progrès étape par étape
 * - Validation de complétude
 * - Notifications et emails d'invitation
 * - Intégration avec TenantConfigService et CSVImportService
 *
 * @version 1.0.0
 * @date 2025-11-15
 */

class OnboardingService {
    private $db;

    // Étapes du workflow d'onboarding
    const STEP_WELCOME = 'welcome';
    const STEP_TENANT_INFO = 'tenant_info';
    const STEP_ADMIN_USER = 'admin_user';
    const STEP_IMPORT_STRUCTURE = 'import_structure';
    const STEP_CONFIG_SMTP = 'config_smtp';
    const STEP_CONFIG_BRANDING = 'config_branding';
    const STEP_CONFIG_QUOTAS = 'config_quotas';
    const STEP_TOUR = 'tour';
    const STEP_COMPLETED = 'completed';

    // Statuts d'étape
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_SKIPPED = 'skipped';

    // Ordre des étapes (requis pour validation)
    const STEP_ORDER = [
        self::STEP_WELCOME,
        self::STEP_TENANT_INFO,
        self::STEP_ADMIN_USER,
        self::STEP_IMPORT_STRUCTURE,
        self::STEP_CONFIG_SMTP,
        self::STEP_CONFIG_BRANDING,
        self::STEP_CONFIG_QUOTAS,
        self::STEP_TOUR,
        self::STEP_COMPLETED
    ];

    // Étapes obligatoires (les autres peuvent être skippées)
    const REQUIRED_STEPS = [
        self::STEP_WELCOME,
        self::STEP_TENANT_INFO,
        self::STEP_ADMIN_USER,
        self::STEP_IMPORT_STRUCTURE
    ];

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Créer un nouveau tenant avec onboarding
     *
     * @param array $tenantData Données du tenant (name, type, email, phone, address)
     * @param string $templateId ID du template à utiliser (optionnel)
     * @return array Résultat avec tenant_id
     */
    public function createTenant($tenantData, $templateId = 'template_college_standard') {
        try {
            $this->db->beginTransaction();

            // Charger le template
            $template = $this->getTemplate($templateId);
            if (!$template) {
                throw new Exception('Template not found: ' . $templateId);
            }

            // Générer ID tenant
            $tenantId = 'tenant_' . bin2hex(random_bytes(12));

            // Préparer les données tenant avec template
            $insertData = [
                'id' => $tenantId,
                'name' => $tenantData['name'],
                'type' => $tenantData['type'] ?? 'public',
                'email' => $tenantData['email'] ?? null,
                'phone' => $tenantData['phone'] ?? null,
                'address' => $tenantData['address'] ?? null,
                'settings' => json_encode($template['default_settings']),
                'ia_policy' => json_encode($template['default_ia_policy']),
                'quota_ia' => json_encode($template['default_quota_ia']),
                'status' => 'active',
                'onboarding_completed' => false
            ];

            // Insérer tenant
            $stmt = $this->db->prepare("
                INSERT INTO tenants (id, name, type, email, phone, address, settings, ia_policy, quota_ia, status, onboarding_completed)
                VALUES (:id, :name, :type, :email, :phone, :address, :settings, :ia_policy, :quota_ia, :status, :onboarding_completed)
            ");
            $stmt->execute($insertData);

            // Créer les licences (trigger auto-crée onboarding_progress)
            $licences = $template['default_licences'];
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $licences['expires_months'] . ' months'));

            $stmt = $this->db->prepare("
                INSERT INTO tenant_licences (
                    tenant_id, max_teachers, max_students, max_classes,
                    subscription_type, expires_at, status
                ) VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $tenantId,
                $licences['max_teachers'],
                $licences['max_students'],
                $licences['max_classes'],
                $licences['subscription_type'],
                $expiresAt
            ]);

            // Marquer l'étape welcome comme complétée
            $this->completeStep($tenantId, self::STEP_WELCOME, [
                'template_used' => $templateId,
                'template_name' => $template['name']
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'tenant_id' => $tenantId,
                'tenant_name' => $tenantData['name'],
                'template' => $template['name'],
                'expires_at' => $expiresAt
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'error' => 'Failed to create tenant: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Créer l'utilisateur admin initial
     *
     * @param string $tenantId ID du tenant
     * @param array $adminData Données admin (email, firstname, lastname, password)
     * @return array Résultat avec user_id
     */
    public function createAdminUser($tenantId, $adminData) {
        try {
            $this->db->beginTransaction();

            // Vérifier que le tenant existe
            $tenant = $this->getTenant($tenantId);
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }

            // Générer ID user
            $userId = 'user_' . bin2hex(random_bytes(12));

            // Hash du mot de passe
            $passwordHash = password_hash($adminData['password'], PASSWORD_BCRYPT);

            // Insérer l'admin
            $stmt = $this->db->prepare("
                INSERT INTO users (id, tenant_id, email, password_hash, firstname, lastname, role, status)
                VALUES (?, ?, ?, ?, ?, ?, 'admin', 'active')
            ");
            $stmt->execute([
                $userId,
                $tenantId,
                $adminData['email'],
                $passwordHash,
                $adminData['firstname'],
                $adminData['lastname']
            ]);

            // Mettre à jour les quotas
            $stmt = $this->db->prepare("
                UPDATE tenant_licences
                SET used_teachers = used_teachers + 1
                WHERE tenant_id = ?
            ");
            $stmt->execute([$tenantId]);

            // Marquer l'étape admin_user comme complétée
            $this->completeStep($tenantId, self::STEP_ADMIN_USER, [
                'admin_user_id' => $userId,
                'admin_email' => $adminData['email']
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'user_id' => $userId,
                'email' => $adminData['email'],
                'role' => 'admin'
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'error' => 'Failed to create admin user: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtenir le progrès d'onboarding pour un tenant
     *
     * @param string $tenantId ID du tenant
     * @return array Progrès avec toutes les étapes
     */
    public function getProgress($tenantId) {
        try {
            $stmt = $this->db->prepare("
                SELECT step, status, data, started_at, completed_at
                FROM onboarding_progress
                WHERE tenant_id = ?
                ORDER BY FIELD(step, '" . implode("','", self::STEP_ORDER) . "')
            ");
            $stmt->execute([$tenantId]);
            $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculer le pourcentage de complétion
            $total = count($steps);
            $completed = 0;
            foreach ($steps as $step) {
                if (in_array($step['status'], [self::STATUS_COMPLETED, self::STATUS_SKIPPED])) {
                    $completed++;
                }
            }

            $percentage = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

            // Déterminer l'étape courante
            $currentStep = null;
            foreach ($steps as $step) {
                if ($step['status'] === self::STATUS_PENDING || $step['status'] === self::STATUS_IN_PROGRESS) {
                    $currentStep = $step['step'];
                    break;
                }
            }

            return [
                'success' => true,
                'tenant_id' => $tenantId,
                'steps' => $steps,
                'total_steps' => $total,
                'completed_steps' => $completed,
                'progress_percentage' => $percentage,
                'current_step' => $currentStep,
                'is_complete' => $percentage >= 100
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get progress: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Marquer une étape comme démarrée
     *
     * @param string $tenantId ID du tenant
     * @param string $step Nom de l'étape
     * @return array Résultat
     */
    public function startStep($tenantId, $step) {
        try {
            if (!in_array($step, self::STEP_ORDER)) {
                throw new Exception('Invalid step: ' . $step);
            }

            $stmt = $this->db->prepare("
                UPDATE onboarding_progress
                SET status = ?, started_at = NOW()
                WHERE tenant_id = ? AND step = ?
            ");
            $stmt->execute([self::STATUS_IN_PROGRESS, $tenantId, $step]);

            return ['success' => true, 'step' => $step, 'status' => self::STATUS_IN_PROGRESS];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Marquer une étape comme complétée
     *
     * @param string $tenantId ID du tenant
     * @param string $step Nom de l'étape
     * @param array $data Données associées à l'étape (optionnel)
     * @return array Résultat
     */
    public function completeStep($tenantId, $step, $data = null) {
        try {
            if (!in_array($step, self::STEP_ORDER)) {
                throw new Exception('Invalid step: ' . $step);
            }

            $dataJson = $data ? json_encode($data) : null;

            $stmt = $this->db->prepare("
                UPDATE onboarding_progress
                SET status = ?, data = ?, completed_at = NOW()
                WHERE tenant_id = ? AND step = ?
            ");
            $stmt->execute([self::STATUS_COMPLETED, $dataJson, $tenantId, $step]);

            // Si c'est l'étape finale, marquer l'onboarding comme complet
            if ($step === self::STEP_COMPLETED) {
                $this->markOnboardingComplete($tenantId);
            }

            return ['success' => true, 'step' => $step, 'status' => self::STATUS_COMPLETED];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Skiper une étape (pour les étapes optionnelles)
     *
     * @param string $tenantId ID du tenant
     * @param string $step Nom de l'étape
     * @return array Résultat
     */
    public function skipStep($tenantId, $step) {
        try {
            // Vérifier que ce n'est pas une étape requise
            if (in_array($step, self::REQUIRED_STEPS)) {
                throw new Exception('Cannot skip required step: ' . $step);
            }

            $stmt = $this->db->prepare("
                UPDATE onboarding_progress
                SET status = ?, completed_at = NOW()
                WHERE tenant_id = ? AND step = ?
            ");
            $stmt->execute([self::STATUS_SKIPPED, $tenantId, $step]);

            return ['success' => true, 'step' => $step, 'status' => self::STATUS_SKIPPED];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Marquer l'onboarding comme complet
     *
     * @param string $tenantId ID du tenant
     * @return array Résultat
     */
    private function markOnboardingComplete($tenantId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE tenants
                SET onboarding_completed = TRUE, onboarding_completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$tenantId]);

            return ['success' => true];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Créer une invitation pour rejoindre le tenant
     *
     * @param string $tenantId ID du tenant
     * @param string $invitedBy User ID qui invite
     * @param string $email Email de l'invité
     * @param string $role Rôle à attribuer
     * @return array Résultat avec token d'invitation
     */
    public function createInvite($tenantId, $invitedBy, $email, $role) {
        try {
            $inviteId = 'invite_' . bin2hex(random_bytes(12));
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+72 hours'));

            $stmt = $this->db->prepare("
                INSERT INTO tenant_onboarding_invites
                (id, tenant_id, invited_by, email, role, token, status, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
            ");
            $stmt->execute([$inviteId, $tenantId, $invitedBy, $email, $role, $token, $expiresAt]);

            return [
                'success' => true,
                'invite_id' => $inviteId,
                'token' => $token,
                'invite_url' => getenv('APP_URL') . '/onboarding/accept?token=' . $token,
                'expires_at' => $expiresAt
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obtenir un template d'onboarding
     *
     * @param string $templateId ID du template
     * @return array|null Template data
     */
    private function getTemplate($templateId) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM onboarding_templates WHERE id = ?
            ");
            $stmt->execute([$templateId]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($template) {
                // Décoder les JSON
                $template['default_settings'] = json_decode($template['default_settings'], true);
                $template['default_ia_policy'] = json_decode($template['default_ia_policy'], true);
                $template['default_quota_ia'] = json_decode($template['default_quota_ia'], true);
                $template['default_licences'] = json_decode($template['default_licences'], true);
            }

            return $template;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Obtenir un tenant par ID
     *
     * @param string $tenantId ID du tenant
     * @return array|null Tenant data
     */
    private function getTenant($tenantId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Lister tous les templates disponibles
     *
     * @return array Liste des templates
     */
    public function listTemplates() {
        try {
            $stmt = $this->db->query("
                SELECT id, name, type, description, is_system
                FROM onboarding_templates
                WHERE is_system = TRUE
                ORDER BY type
            ");
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'templates' => $templates
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Vérifier si toutes les étapes obligatoires sont complétées
     *
     * @param string $tenantId ID du tenant
     * @return bool True si complet
     */
    public function isOnboardingComplete($tenantId) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM onboarding_progress
                WHERE tenant_id = ?
                  AND step IN ('" . implode("','", self::REQUIRED_STEPS) . "')
                  AND status IN ('completed', 'skipped')
            ");
            $stmt->execute([$tenantId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result['count'] >= count(self::REQUIRED_STEPS);

        } catch (Exception $e) {
            return false;
        }
    }
}
