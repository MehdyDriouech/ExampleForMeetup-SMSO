<?php
/**
 * Sprint 20 - Tenant Onboarding Complet
 * Service: TenantConfigService
 * Description: Gestion de la configuration d'un établissement
 *
 * Fonctionnalités:
 * - Configuration SMTP (avec vérification connexion)
 * - Upload et gestion logo établissement
 * - Branding personnalisé (couleurs, thème)
 * - Gestion quotas IA
 * - Politique d'usage IA
 * - Settings généraux établissement
 *
 * @version 1.0.0
 * @date 2025-11-15
 */

class TenantConfigService {
    private $db;

    // Répertoire de stockage des logos
    const LOGO_UPLOAD_DIR = __DIR__ . '/../uploads/logos';
    const LOGO_MAX_SIZE = 2 * 1024 * 1024; // 2 MB
    const LOGO_ALLOWED_TYPES = ['image/png', 'image/jpeg', 'image/svg+xml'];

    // Configuration SMTP par défaut
    const DEFAULT_SMTP_CONFIG = [
        'enabled' => false,
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => '',
        'verified' => false
    ];

    // Branding par défaut
    const DEFAULT_BRANDING = [
        'primary_color' => '#3B82F6',
        'secondary_color' => '#10B981',
        'accent_color' => '#F59E0B',
        'logo_url' => null,
        'favicon_url' => null,
        'custom_css' => null
    ];

    // Quotas IA par défaut
    const DEFAULT_IA_QUOTA = [
        'monthly_quota' => 1000,
        'used_this_month' => 0,
        'reset_day' => 1,
        'warning_threshold' => 80,
        'last_reset' => null
    ];

    // Politique IA par défaut
    const DEFAULT_IA_POLICY = [
        'allow_ai_generation' => true,
        'providers' => ['mistral'],
        'require_review' => true,
        'auto_publish' => false,
        'max_generations_per_user_per_day' => 10
    ];

    public function __construct($db) {
        $this->db = $db;

        // Créer le répertoire logos s'il n'existe pas
        if (!is_dir(self::LOGO_UPLOAD_DIR)) {
            mkdir(self::LOGO_UPLOAD_DIR, 0755, true);
        }
    }

    /**
     * Configurer SMTP pour un tenant
     *
     * @param string $tenantId ID du tenant
     * @param array $smtpConfig Configuration SMTP
     * @param bool $testConnection Tester la connexion avant de sauvegarder
     * @return array Résultat
     */
    public function configureSMTP($tenantId, $smtpConfig, $testConnection = true) {
        try {
            // Vérifier que le tenant existe
            $tenant = $this->getTenant($tenantId);
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }

            // Valider la config
            $validation = $this->validateSMTPConfig($smtpConfig);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'error' => 'Invalid SMTP configuration',
                    'validation_errors' => $validation['errors']
                ];
            }

            // Tester la connexion si demandé
            if ($testConnection) {
                $testResult = $this->testSMTPConnection($smtpConfig);
                if (!$testResult['success']) {
                    return [
                        'success' => false,
                        'error' => 'SMTP connection test failed',
                        'details' => $testResult['error']
                    ];
                }
                $smtpConfig['verified'] = true;
            } else {
                $smtpConfig['verified'] = false;
            }

            // Sauvegarder la configuration
            $smtpConfigJson = json_encode($smtpConfig);

            $stmt = $this->db->prepare("
                UPDATE tenants
                SET smtp_config = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$smtpConfigJson, $tenantId]);

            return [
                'success' => true,
                'smtp_config' => $smtpConfig,
                'verified' => $smtpConfig['verified']
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to configure SMTP: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Uploader un logo pour le tenant
     *
     * @param string $tenantId ID du tenant
     * @param array $file Fichier uploadé ($_FILES['logo'])
     * @return array Résultat avec URL du logo
     */
    public function uploadLogo($tenantId, $file) {
        try {
            // Vérifier que le tenant existe
            $tenant = $this->getTenant($tenantId);
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }

            // Valider l'upload
            if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File upload error');
            }

            // Vérifier la taille
            if ($file['size'] > self::LOGO_MAX_SIZE) {
                throw new Exception('File too large (max 2MB)');
            }

            // Vérifier le type MIME
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, self::LOGO_ALLOWED_TYPES)) {
                throw new Exception('Invalid file type (only PNG, JPEG, SVG allowed)');
            }

            // Générer un nom de fichier sécurisé
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = $tenantId . '_' . time() . '.' . $extension;
            $filepath = self::LOGO_UPLOAD_DIR . '/' . $filename;

            // Déplacer le fichier
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                throw new Exception('Failed to move uploaded file');
            }

            // URL publique du logo
            $logoUrl = '/uploads/logos/' . $filename;

            // Supprimer l'ancien logo si existant
            if (!empty($tenant['logo']) && file_exists(__DIR__ . '/../' . $tenant['logo'])) {
                unlink(__DIR__ . '/../' . $tenant['logo']);
            }

            // Sauvegarder dans la DB
            $stmt = $this->db->prepare("
                UPDATE tenants
                SET logo = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$logoUrl, $tenantId]);

            return [
                'success' => true,
                'logo_url' => $logoUrl,
                'filename' => $filename
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to upload logo: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Configurer le branding du tenant
     *
     * @param string $tenantId ID du tenant
     * @param array $branding Configuration branding
     * @return array Résultat
     */
    public function configureBranding($tenantId, $branding) {
        try {
            // Vérifier que le tenant existe
            $tenant = $this->getTenant($tenantId);
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }

            // Merger avec les valeurs par défaut
            $currentBranding = $tenant['branding'] ? json_decode($tenant['branding'], true) : self::DEFAULT_BRANDING;
            $newBranding = array_merge($currentBranding, $branding);

            // Valider les couleurs (format hexadécimal)
            if (isset($newBranding['primary_color']) && !$this->isValidHexColor($newBranding['primary_color'])) {
                throw new Exception('Invalid primary_color format');
            }
            if (isset($newBranding['secondary_color']) && !$this->isValidHexColor($newBranding['secondary_color'])) {
                throw new Exception('Invalid secondary_color format');
            }
            if (isset($newBranding['accent_color']) && !$this->isValidHexColor($newBranding['accent_color'])) {
                throw new Exception('Invalid accent_color format');
            }

            // Sauvegarder
            $brandingJson = json_encode($newBranding);

            $stmt = $this->db->prepare("
                UPDATE tenants
                SET branding = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$brandingJson, $tenantId]);

            return [
                'success' => true,
                'branding' => $newBranding
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to configure branding: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Configurer les quotas IA du tenant
     *
     * @param string $tenantId ID du tenant
     * @param array $quotaConfig Configuration quotas IA
     * @return array Résultat
     */
    public function configureIAQuota($tenantId, $quotaConfig) {
        try {
            // Vérifier que le tenant existe
            $tenant = $this->getTenant($tenantId);
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }

            // Merger avec les valeurs actuelles
            $currentQuota = $tenant['quota_ia'] ? json_decode($tenant['quota_ia'], true) : self::DEFAULT_IA_QUOTA;
            $newQuota = array_merge($currentQuota, $quotaConfig);

            // Validation
            if (isset($newQuota['monthly_quota']) && (!is_numeric($newQuota['monthly_quota']) || $newQuota['monthly_quota'] < 0)) {
                throw new Exception('Invalid monthly_quota');
            }
            if (isset($newQuota['warning_threshold']) && ($newQuota['warning_threshold'] < 0 || $newQuota['warning_threshold'] > 100)) {
                throw new Exception('warning_threshold must be between 0 and 100');
            }

            // Sauvegarder
            $quotaJson = json_encode($newQuota);

            $stmt = $this->db->prepare("
                UPDATE tenants
                SET quota_ia = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$quotaJson, $tenantId]);

            return [
                'success' => true,
                'quota_ia' => $newQuota
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to configure IA quota: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Configurer la politique IA du tenant
     *
     * @param string $tenantId ID du tenant
     * @param array $iaPolicy Politique IA
     * @return array Résultat
     */
    public function configureIAPolicy($tenantId, $iaPolicy) {
        try {
            // Vérifier que le tenant existe
            $tenant = $this->getTenant($tenantId);
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }

            // Merger avec les valeurs actuelles
            $currentPolicy = $tenant['ia_policy'] ? json_decode($tenant['ia_policy'], true) : self::DEFAULT_IA_POLICY;
            $newPolicy = array_merge($currentPolicy, $iaPolicy);

            // Validation
            if (isset($newPolicy['providers']) && !is_array($newPolicy['providers'])) {
                throw new Exception('providers must be an array');
            }

            // Sauvegarder
            $policyJson = json_encode($newPolicy);

            $stmt = $this->db->prepare("
                UPDATE tenants
                SET ia_policy = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$policyJson, $tenantId]);

            return [
                'success' => true,
                'ia_policy' => $newPolicy
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to configure IA policy: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtenir toute la configuration d'un tenant
     *
     * @param string $tenantId ID du tenant
     * @return array Configuration complète
     */
    public function getConfig($tenantId) {
        try {
            $tenant = $this->getTenant($tenantId);
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }

            return [
                'success' => true,
                'config' => [
                    'tenant_id' => $tenant['id'],
                    'name' => $tenant['name'],
                    'type' => $tenant['type'],
                    'email' => $tenant['email'],
                    'phone' => $tenant['phone'],
                    'address' => $tenant['address'],
                    'logo' => $tenant['logo'],
                    'branding' => $tenant['branding'] ? json_decode($tenant['branding'], true) : self::DEFAULT_BRANDING,
                    'smtp_config' => $tenant['smtp_config'] ? json_decode($tenant['smtp_config'], true) : self::DEFAULT_SMTP_CONFIG,
                    'ia_policy' => $tenant['ia_policy'] ? json_decode($tenant['ia_policy'], true) : self::DEFAULT_IA_POLICY,
                    'quota_ia' => $tenant['quota_ia'] ? json_decode($tenant['quota_ia'], true) : self::DEFAULT_IA_QUOTA,
                    'settings' => $tenant['settings'] ? json_decode($tenant['settings'], true) : [],
                    'status' => $tenant['status'],
                    'onboarding_completed' => (bool)$tenant['onboarding_completed']
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get config: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Mettre à jour les settings généraux du tenant
     *
     * @param string $tenantId ID du tenant
     * @param array $settings Settings à mettre à jour
     * @return array Résultat
     */
    public function updateSettings($tenantId, $settings) {
        try {
            // Vérifier que le tenant existe
            $tenant = $this->getTenant($tenantId);
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }

            // Merger avec les settings actuels
            $currentSettings = $tenant['settings'] ? json_decode($tenant['settings'], true) : [];
            $newSettings = array_merge($currentSettings, $settings);

            // Sauvegarder
            $settingsJson = json_encode($newSettings);

            $stmt = $this->db->prepare("
                UPDATE tenants
                SET settings = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$settingsJson, $tenantId]);

            return [
                'success' => true,
                'settings' => $newSettings
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to update settings: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Tester une connexion SMTP
     *
     * @param array $smtpConfig Configuration SMTP
     * @return array Résultat du test
     */
    private function testSMTPConnection($smtpConfig) {
        try {
            // Note: Dans un environnement réel, utiliser PHPMailer ou Swift Mailer
            // Ici on fait une simple vérification de socket

            $host = $smtpConfig['host'];
            $port = $smtpConfig['port'];
            $timeout = 5;

            $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

            if (!$socket) {
                throw new Exception("Cannot connect to $host:$port - $errstr ($errno)");
            }

            fclose($socket);

            return [
                'success' => true,
                'message' => 'SMTP connection successful'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Valider une configuration SMTP
     *
     * @param array $config Configuration SMTP
     * @return array Résultat de validation
     */
    private function validateSMTPConfig($config) {
        $errors = [];

        if (empty($config['host'])) {
            $errors[] = 'SMTP host is required';
        }

        if (empty($config['port']) || !is_numeric($config['port'])) {
            $errors[] = 'Valid SMTP port is required';
        }

        if (!in_array($config['encryption'], ['tls', 'ssl', 'none'])) {
            $errors[] = 'Invalid encryption type (use: tls, ssl, none)';
        }

        if (empty($config['from_email']) || !filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid from_email is required';
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors
        ];
    }

    /**
     * Valider une couleur hexadécimale
     *
     * @param string $color Couleur hex (#RRGGBB)
     * @return bool True si valide
     */
    private function isValidHexColor($color) {
        return preg_match('/^#[0-9A-F]{6}$/i', $color) === 1;
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
     * Réinitialiser les quotas IA (appelé mensuellement)
     *
     * @param string $tenantId ID du tenant
     * @return array Résultat
     */
    public function resetIAQuota($tenantId) {
        try {
            $tenant = $this->getTenant($tenantId);
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }

            $quota = $tenant['quota_ia'] ? json_decode($tenant['quota_ia'], true) : self::DEFAULT_IA_QUOTA;
            $quota['used_this_month'] = 0;
            $quota['last_reset'] = date('Y-m-d H:i:s');

            $quotaJson = json_encode($quota);

            $stmt = $this->db->prepare("
                UPDATE tenants
                SET quota_ia = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$quotaJson, $tenantId]);

            return [
                'success' => true,
                'quota_ia' => $quota,
                'message' => 'IA quota reset successfully'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to reset IA quota: ' . $e->getMessage()
            ];
        }
    }
}
