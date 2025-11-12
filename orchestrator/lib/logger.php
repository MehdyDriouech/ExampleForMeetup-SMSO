<?php
/**
 * Study-mate School Orchestrator - Logger
 * 
 * Logs JSON rotatifs avec purge automatique
 */

class Logger {
    private static $instance = null;
    private $logFile;
    private $level;
    
    const DEBUG = 0;
    const INFO = 1;
    const WARN = 2;
    const ERROR = 3;
    
    private $levels = [
        self::DEBUG => 'DEBUG',
        self::INFO => 'INFO',
        self::WARN => 'WARN',
        self::ERROR => 'ERROR'
    ];
    
    private function __construct() {
        $this->logFile = LOG_FILE;
        $this->level = $this->getLevelFromString(LOG_LEVEL);
        $this->ensureLogDirectory();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function getLevelFromString($levelStr) {
        $map = [
            'DEBUG' => self::DEBUG,
            'INFO' => self::INFO,
            'WARN' => self::WARN,
            'ERROR' => self::ERROR
        ];
        return $map[strtoupper($levelStr)] ?? self::INFO;
    }
    
    private function ensureLogDirectory() {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    /**
     * Logger un message
     */
    private function log($level, $message, $context = []) {
        if ($level < $this->level) {
            return;
        }
        
        $entry = [
            'timestamp' => date('c'),
            'level' => $this->levels[$level],
            'message' => $message,
            'ip' => getClientIp(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'N/A'
        ];
        
        if (!empty($context)) {
            $entry['context'] = $context;
        }
        
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        
        // Rotation si nécessaire
        $this->rotateIfNeeded();
        
        // Écrire
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        
        // Purge des vieux logs
        $this->purgeOldLogs();
    }
    
    /**
     * Rotation des logs
     */
    private function rotateIfNeeded() {
        if (!file_exists($this->logFile)) {
            return;
        }
        
        if (filesize($this->logFile) < LOG_ROTATE_MAX_SIZE) {
            return;
        }
        
        // Décaler les fichiers existants
        for ($i = LOG_ROTATE_MAX_FILES - 1; $i >= 1; $i--) {
            $old = $this->logFile . '.' . $i;
            $new = $this->logFile . '.' . ($i + 1);
            
            if (file_exists($old)) {
                if ($i == LOG_ROTATE_MAX_FILES - 1) {
                    unlink($old); // Supprimer le plus ancien
                } else {
                    rename($old, $new);
                }
            }
        }
        
        // Archiver le fichier actuel
        rename($this->logFile, $this->logFile . '.1');
    }
    
    /**
     * Purge des logs anciens
     */
    private function purgeOldLogs() {
        $dir = dirname($this->logFile);
        $files = glob($dir . '/*.log*');
        $cutoff = time() - (LOG_RETENTION_DAYS * 86400);
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
    
    /**
     * Méthodes publiques
     */
    public function debug($message, $context = []) {
        $this->log(self::DEBUG, $message, $context);
    }
    
    public function info($message, $context = []) {
        $this->log(self::INFO, $message, $context);
    }
    
    public function warn($message, $context = []) {
        $this->log(self::WARN, $message, $context);
    }
    
    public function error($message, $context = []) {
        $this->log(self::ERROR, $message, $context);
    }
    
    /**
     * Logger une requête API
     */
    public function logRequest($endpoint, $statusCode, $durationMs, $context = []) {
        $this->info('API Request', array_merge([
            'endpoint' => $endpoint,
            'status' => $statusCode,
            'duration_ms' => $durationMs
        ], $context));
    }
    
    /**
     * Logger une erreur API
     */
    public function logApiError($endpoint, $error, $context = []) {
        $this->error('API Error', array_merge([
            'endpoint' => $endpoint,
            'error' => $error
        ], $context));
    }

    /**
     * Logger une violation de sécurité tenant
     */
    public function logTenantViolation($type, $context = []) {
        $this->warn('SECURITY: Tenant Violation', array_merge([
            'violation_type' => $type,
            'severity' => 'high'
        ], $context));
    }

    /**
     * Logger un refus RBAC
     */
    public function logRBACDenial($resource, $action, $role, $context = []) {
        $this->warn('SECURITY: RBAC Denial', array_merge([
            'resource' => $resource,
            'action' => $action,
            'role' => $role,
            'severity' => 'medium'
        ], $context));
    }

    /**
     * Logger un événement d'authentification
     */
    public function logAuthEvent($event, $success, $context = []) {
        $level = $success ? self::INFO : self::WARN;
        $this->log($level, 'AUTH: ' . $event, array_merge([
            'success' => $success
        ], $context));
    }

    /**
     * Logger un accès à une ressource sensible
     */
    public function logSensitiveAccess($resource, $resourceId, $action, $context = []) {
        $this->info('AUDIT: Sensitive Access', array_merge([
            'resource' => $resource,
            'resource_id' => $resourceId,
            'action' => $action
        ], $context));
    }
}

/**
 * Helpers globaux
 */
function logger() {
    return Logger::getInstance();
}

function logDebug($message, $context = []) {
    logger()->debug($message, $context);
}

function logInfo($message, $context = []) {
    logger()->info($message, $context);
}

function logWarn($message, $context = []) {
    logger()->warn($message, $context);
}

function logError($message, $context = []) {
    logger()->error($message, $context);
}

function logTenantViolation($type, $context = []) {
    logger()->logTenantViolation($type, $context);
}

function logRBACDenial($resource, $action, $role, $context = []) {
    logger()->logRBACDenial($resource, $action, $role, $context);
}

function logAuthEvent($event, $success, $context = []) {
    logger()->logAuthEvent($event, $success, $context);
}

function logSensitiveAccess($resource, $resourceId, $action, $context = []) {
    logger()->logSensitiveAccess($resource, $resourceId, $action, $context);
}
