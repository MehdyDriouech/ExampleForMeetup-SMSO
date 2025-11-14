<?php
/**
 * Configuration globale - StudyMate School Orchestrator
 * Sprint 17: Mode Démo activé
 */

// Mode Démo (Sprint 17)
define('DEMO_MODE', true); // Mettre à false pour désactiver le mode démo

// Database
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'studymate_orchestrator');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// JWT
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'your-secret-key-change-in-production');
define('JWT_EXPIRY', 86400); // 24 heures

// API
define('API_VERSION', 'v1');
define('API_RATE_LIMIT', 100); // requêtes par minute

// ErgoMate Integration
define('ERGOMATE_BASE_URL', getenv('ERGOMATE_BASE_URL') ?: 'https://ergomate.example.com');
define('ERGOMATE_API_KEY', getenv('ERGOMATE_API_KEY') ?: '');

// AI Service (OpenAI)
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
define('OPENAI_MODEL', 'gpt-4');
define('OPENAI_MAX_TOKENS', 2000);

// IA Governance (Sprint 15)
define('IA_BUDGET_MONTHLY', 500); // euros par mois
define('IA_AUDIT_ENABLED', true);
define('IA_GDPR_MODE', true);

// Telemetry
define('TELEMETRY_ENABLED', true);
define('TELEMETRY_RETENTION_DAYS', 90);

// Logs
define('LOG_LEVEL', getenv('LOG_LEVEL') ?: 'INFO');
define('LOG_FILE', __DIR__ . '/logs/app.log');

// Uploads
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 10 MB
define('UPLOAD_DIR', __DIR__ . '/uploads');

// CORS
define('CORS_ALLOWED_ORIGINS', explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: '*'));

// Multi-tenancy
define('TENANT_ISOLATION_ENABLED', true);

// Session
define('SESSION_TIMEOUT', 3600); // 1 heure

// Cache
define('CACHE_ENABLED', true);
define('CACHE_TTL', 300); // 5 minutes

// Timezone
date_default_timezone_set('Europe/Paris');

// Error Reporting
if (getenv('ENV') === 'production') {
    error_reporting(E_ERROR | E_WARNING);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Configuration exposée au frontend
function getPublicConfig() {
    return [
        'demoMode' => DEMO_MODE,
        'apiVersion' => API_VERSION,
        'features' => [
            'iaGovernance' => IA_AUDIT_ENABLED,
            'telemetry' => TELEMETRY_ENABLED,
            'multiTenant' => TENANT_ISOLATION_ENABLED
        ]
    ];
}
