<?php
/**
 * Sprint 13 - System Management API
 * Provides diagnostics, backup management, and system health checks
 *
 * Endpoints:
 * - GET /api/system/diagnostic - Run comprehensive system diagnostic
 * - GET /api/system/backups - List available backups
 * - POST /api/system/backup - Create manual backup
 * - GET /api/system/backup/:name/download - Download backup file
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/logging.php';
require_once __DIR__ . '/../lib/ai_service.php';
require_once __DIR__ . '/../jobs/backup.php';
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rbac.php';

header('Content-Type: application/json; charset=utf-8');

$tenantContext = tenantMiddleware();
$user = rbacMiddleware(['admin', 'direction']);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

try {
    // GET /api/system/diagnostic - System Health Check
    if ($method === 'GET' && preg_match('#/api/system/diagnostic$#', $path)) {
        $diagnostic = [
            'timestamp' => date('c'),
            'status' => 'healthy',
            'checks' => []
        ];

        // 1. Database Check
        try {
            $dbStart = microtime(true);
            $stmt = db()->query("SELECT 1");
            $dbTime = (microtime(true) - $dbStart) * 1000;

            $diagnostic['checks']['database'] = [
                'status' => 'ok',
                'latency_ms' => round($dbTime, 2),
                'message' => 'Database connection healthy'
            ];
        } catch (Exception $e) {
            $diagnostic['status'] = 'unhealthy';
            $diagnostic['checks']['database'] = [
                'status' => 'error',
                'message' => 'Database connection failed: ' . $e->getMessage()
            ];
        }

        // 2. Database Tables Check
        try {
            $requiredTables = ['tenants', 'users', 'themes', 'students', 'assignments'];
            $existingTables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $missingTables = array_diff($requiredTables, $existingTables);

            if (empty($missingTables)) {
                $diagnostic['checks']['database_schema'] = [
                    'status' => 'ok',
                    'message' => 'All required tables present',
                    'table_count' => count($existingTables)
                ];
            } else {
                $diagnostic['status'] = 'degraded';
                $diagnostic['checks']['database_schema'] = [
                    'status' => 'warning',
                    'message' => 'Missing tables: ' . implode(', ', $missingTables)
                ];
            }
        } catch (Exception $e) {
            $diagnostic['checks']['database_schema'] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }

        // 3. AI Service Check
        try {
            $aiStart = microtime(true);
            $testPrompt = "Test";
            // We don't actually call the AI, just check configuration
            $apiKey = getenv('MISTRAL_API_KEY') ?: null;
            $aiTime = (microtime(true) - $aiStart) * 1000;

            if ($apiKey) {
                $diagnostic['checks']['ai_service'] = [
                    'status' => 'ok',
                    'message' => 'AI service configured',
                    'provider' => 'Mistral AI'
                ];
            } else {
                $diagnostic['status'] = 'degraded';
                $diagnostic['checks']['ai_service'] = [
                    'status' => 'warning',
                    'message' => 'AI API key not configured'
                ];
            }
        } catch (Exception $e) {
            $diagnostic['checks']['ai_service'] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }

        // 4. Filesystem Check
        $writablePaths = [
            __DIR__ . '/../logs',
            __DIR__ . '/../../backups',
            __DIR__ . '/../../public/catalog'
        ];

        $filesystemIssues = [];
        foreach ($writablePaths as $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            if (!is_writable($path)) {
                $filesystemIssues[] = basename($path);
            }
        }

        if (empty($filesystemIssues)) {
            $diagnostic['checks']['filesystem'] = [
                'status' => 'ok',
                'message' => 'All directories writable'
            ];
        } else {
            $diagnostic['status'] = 'degraded';
            $diagnostic['checks']['filesystem'] = [
                'status' => 'warning',
                'message' => 'Non-writable directories: ' . implode(', ', $filesystemIssues)
            ];
        }

        // 5. Disk Space Check
        $totalSpace = disk_total_space(__DIR__);
        $freeSpace = disk_free_space(__DIR__);
        $usedPercent = (($totalSpace - $freeSpace) / $totalSpace) * 100;

        $diskStatus = 'ok';
        if ($usedPercent > 90) {
            $diskStatus = 'critical';
            $diagnostic['status'] = 'unhealthy';
        } elseif ($usedPercent > 80) {
            $diskStatus = 'warning';
            $diagnostic['status'] = 'degraded';
        }

        $diagnostic['checks']['disk_space'] = [
            'status' => $diskStatus,
            'used_percent' => round($usedPercent, 2),
            'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
            'total_gb' => round($totalSpace / 1024 / 1024 / 1024, 2)
        ];

        // 6. PHP Configuration Check
        $memoryLimit = ini_get('memory_limit');
        $maxExecutionTime = ini_get('max_execution_time');
        $uploadMaxFilesize = ini_get('upload_max_filesize');

        $diagnostic['checks']['php_config'] = [
            'status' => 'info',
            'version' => PHP_VERSION,
            'memory_limit' => $memoryLimit,
            'max_execution_time' => $maxExecutionTime,
            'upload_max_filesize' => $uploadMaxFilesize
        ];

        // 7. API Rate Limits Check
        try {
            $stmt = db()->prepare("
                SELECT tenant_id, COUNT(*) as request_count
                FROM sync_logs
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                GROUP BY tenant_id
                ORDER BY request_count DESC
                LIMIT 1
            ");
            $stmt->execute();
            $topTenant = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($topTenant && $topTenant['request_count'] > 1000) {
                $diagnostic['checks']['rate_limits'] = [
                    'status' => 'warning',
                    'message' => "High request rate detected: {$topTenant['request_count']} req/hour",
                    'top_tenant' => $topTenant['tenant_id']
                ];
            } else {
                $diagnostic['checks']['rate_limits'] = [
                    'status' => 'ok',
                    'message' => 'API usage within normal limits'
                ];
            }
        } catch (Exception $e) {
            $diagnostic['checks']['rate_limits'] = [
                'status' => 'info',
                'message' => 'Unable to check rate limits'
            ];
        }

        // 8. Recent Backups Check
        try {
            $backupManager = new BackupManager();
            $backups = $backupManager->listBackups();
            $latestBackup = !empty($backups) ? $backups[0] : null;

            if ($latestBackup && $latestBackup['age_days'] <= 7) {
                $diagnostic['checks']['backups'] = [
                    'status' => 'ok',
                    'message' => "Latest backup: {$latestBackup['age_days']} days old",
                    'backup_count' => count($backups)
                ];
            } elseif ($latestBackup) {
                $diagnostic['checks']['backups'] = [
                    'status' => 'warning',
                    'message' => "Latest backup is {$latestBackup['age_days']} days old",
                    'backup_count' => count($backups)
                ];
            } else {
                $diagnostic['checks']['backups'] = [
                    'status' => 'warning',
                    'message' => 'No backups found'
                ];
            }
        } catch (Exception $e) {
            $diagnostic['checks']['backups'] = [
                'status' => 'info',
                'message' => 'Unable to check backups'
            ];
        }

        logEvent('system_diagnostic_run', [
            'status' => $diagnostic['status'],
            'user_id' => $user['user_id']
        ]);

        echo json_encode($diagnostic);
        exit;
    }

    // GET /api/system/backups - List Backups
    if ($method === 'GET' && preg_match('#/api/system/backups$#', $path)) {
        $manager = new BackupManager();
        $backups = $manager->listBackups();

        echo json_encode([
            'success' => true,
            'backups' => $backups,
            'count' => count($backups)
        ]);
        exit;
    }

    // POST /api/system/backup - Create Manual Backup
    if ($method === 'POST' && preg_match('#/api/system/backup$#', $path)) {
        $input = json_decode(file_get_contents('php://input'), true);
        $force = $input['force'] ?? false;

        $manager = new BackupManager();
        $result = $manager->run($force);

        if ($result['success']) {
            echo json_encode($result);
        } else {
            http_response_code(500);
            echo json_encode($result);
        }
        exit;
    }

    // GET /api/system/backup/:name/download - Download Backup
    if ($method === 'GET' && preg_match('#/api/system/backup/([^/]+)/download$#', $path, $matches)) {
        $backupName = $matches[1];
        $backupPath = __DIR__ . '/../../backups/' . basename($backupName);

        if (!file_exists($backupPath) || !is_file($backupPath)) {
            http_response_code(404);
            echo json_encode(['error' => 'Backup not found']);
            exit;
        }

        // Validate file extension
        if (!preg_match('/\.zip$/', $backupName)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid backup file']);
            exit;
        }

        logEvent('backup_downloaded', [
            'backup_name' => $backupName,
            'user_id' => $user['user_id']
        ]);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($backupName) . '"');
        header('Content-Length: ' . filesize($backupPath));
        readfile($backupPath);
        exit;
    }

    // Method not allowed
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Exception $e) {
    logError('System API error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
