<?php
/**
 * Sprint 13 - US13-4: Automated Backup System
 * Weekly automated backups of database, catalog, and configurations
 *
 * Usage:
 *   - Automated: Add to crontab: 0 2 * * 0 /usr/bin/php /path/to/backup.php
 *   - Manual: php backup.php [--force] [--output=/path/to/backup]
 *
 * @package StudyMate\Jobs
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/logging.php';

class BackupManager {
    private $backupDir;
    private $retentionDays = 30;
    private $maxBackups = 10;

    public function __construct($backupDir = null) {
        $this->backupDir = $backupDir ?? __DIR__ . '/../../backups';

        // Ensure backup directory exists
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * Run full backup
     *
     * @param bool $force Force backup even if recent backup exists
     * @return array Backup result
     */
    public function run($force = false) {
        logEvent('backup_started', ['force' => $force]);

        try {
            // Check if recent backup exists
            if (!$force && $this->hasRecentBackup()) {
                $message = 'Recent backup exists (less than 6 days old), skipping';
                logEvent('backup_skipped', ['reason' => $message]);
                return ['success' => true, 'skipped' => true, 'message' => $message];
            }

            $timestamp = date('Y-m-d_H-i-s');
            $backupName = "studymate_backup_{$timestamp}";
            $backupPath = "{$this->backupDir}/{$backupName}";

            // Create temporary backup directory
            mkdir($backupPath, 0755, true);

            // 1. Backup database
            $this->backupDatabase($backupPath);

            // 2. Backup catalog files
            $this->backupCatalog($backupPath);

            // 3. Backup configurations
            $this->backupConfigurations($backupPath);

            // 4. Create backup manifest
            $this->createManifest($backupPath);

            // 5. Compress backup
            $zipPath = $this->compressBackup($backupPath, $backupName);

            // 6. Clean up temporary files
            $this->removeDirectory($backupPath);

            // 7. Clean old backups
            $this->cleanOldBackups();

            $size = filesize($zipPath);
            $sizeHuman = $this->formatBytes($size);

            logEvent('backup_completed', [
                'backup_name' => $backupName,
                'size' => $size,
                'path' => $zipPath
            ]);

            return [
                'success' => true,
                'backup_name' => $backupName,
                'backup_path' => $zipPath,
                'size' => $size,
                'size_human' => $sizeHuman,
                'timestamp' => $timestamp
            ];

        } catch (Exception $e) {
            logError('backup_failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check if recent backup exists (within 6 days)
     *
     * @return bool
     */
    private function hasRecentBackup() {
        $files = glob("{$this->backupDir}/studymate_backup_*.zip");
        if (empty($files)) return false;

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $latestBackup = $files[0];
        $age = time() - filemtime($latestBackup);

        return $age < (6 * 24 * 3600); // 6 days
    }

    /**
     * Backup database
     *
     * @param string $backupPath
     */
    private function backupDatabase($backupPath) {
        $dbPath = "{$backupPath}/database";
        mkdir($dbPath, 0755, true);

        // Get database connection info
        $config = $this->getDatabaseConfig();

        // Dump all tables
        $tables = $this->getAllTables();

        foreach ($tables as $table) {
            $this->dumpTable($table, $dbPath, $config);
        }

        // Export schema
        $this->exportSchema($dbPath);
    }

    /**
     * Get database configuration
     */
    private function getDatabaseConfig() {
        // Read from environment or config
        return [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'name' => getenv('DB_NAME') ?: 'studymate',
            'user' => getenv('DB_USER') ?: 'root',
            'pass' => getenv('DB_PASS') ?: ''
        ];
    }

    /**
     * Get all database tables
     */
    private function getAllTables() {
        $stmt = db()->query("SHOW TABLES");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Dump single table to SQL file
     */
    private function dumpTable($table, $dbPath, $config) {
        $filename = "{$dbPath}/{$table}.sql";
        $mysqldump = "mysqldump";

        // Check if mysqldump is available
        exec("which mysqldump", $output, $returnCode);
        if ($returnCode !== 0) {
            // Fallback to manual export
            $this->manualDumpTable($table, $filename);
            return;
        }

        // Use mysqldump
        $command = sprintf(
            "%s -h%s -u%s %s %s %s > %s 2>&1",
            $mysqldump,
            escapeshellarg($config['host']),
            escapeshellarg($config['user']),
            !empty($config['pass']) ? '-p' . escapeshellarg($config['pass']) : '',
            escapeshellarg($config['name']),
            escapeshellarg($table),
            escapeshellarg($filename)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("Failed to dump table {$table}");
        }
    }

    /**
     * Manual table dump (fallback)
     */
    private function manualDumpTable($table, $filename) {
        $fp = fopen($filename, 'w');

        // Write table structure
        $createTable = db()->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        fwrite($fp, $createTable['Create Table'] . ";\n\n");

        // Write table data
        $stmt = db()->query("SELECT * FROM `{$table}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $values = array_map(function($v) {
                if ($v === null) return 'NULL';
                return "'" . addslashes($v) . "'";
            }, array_values($row));

            $sql = "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n";
            fwrite($fp, $sql);
        }

        fclose($fp);
    }

    /**
     * Export database schema
     */
    private function exportSchema($dbPath) {
        $schemaFile = "{$dbPath}/_schema.sql";
        $fp = fopen($schemaFile, 'w');

        $tables = $this->getAllTables();

        foreach ($tables as $table) {
            $createTable = db()->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            fwrite($fp, "-- Table: {$table}\n");
            fwrite($fp, $createTable['Create Table'] . ";\n\n");
        }

        fclose($fp);
    }

    /**
     * Backup catalog files
     *
     * @param string $backupPath
     */
    private function backupCatalog($backupPath) {
        $catalogPath = "{$backupPath}/catalog";
        $sourceCatalog = __DIR__ . '/../../public/catalog';

        if (!is_dir($sourceCatalog)) {
            return; // No catalog to backup
        }

        mkdir($catalogPath, 0755, true);
        $this->copyDirectory($sourceCatalog, $catalogPath);
    }

    /**
     * Backup configurations
     *
     * @param string $backupPath
     */
    private function backupConfigurations($backupPath) {
        $configPath = "{$backupPath}/config";
        mkdir($configPath, 0755, true);

        // Backup environment file (if exists)
        $envFile = __DIR__ . '/../../.env';
        if (file_exists($envFile)) {
            copy($envFile, "{$configPath}/.env");
        }

        // Backup config directory (if exists)
        $configDir = __DIR__ . '/../../config';
        if (is_dir($configDir)) {
            $this->copyDirectory($configDir, "{$configPath}/config");
        }

        // Backup OpenAPI spec
        $openapiFile = __DIR__ . '/../docs/openapi-orchestrator.yaml';
        if (file_exists($openapiFile)) {
            copy($openapiFile, "{$configPath}/openapi-orchestrator.yaml");
        }
    }

    /**
     * Create backup manifest
     *
     * @param string $backupPath
     */
    private function createManifest($backupPath) {
        $manifest = [
            'version' => 'SPRINT_13',
            'timestamp' => date('c'),
            'hostname' => gethostname(),
            'php_version' => PHP_VERSION,
            'files' => $this->listFiles($backupPath),
            'database_tables' => $this->getAllTables(),
            'checksums' => $this->calculateChecksums($backupPath)
        ];

        file_put_contents(
            "{$backupPath}/manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT)
        );
    }

    /**
     * List all files in backup
     */
    private function listFiles($path) {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace($path . '/', '', $file->getPathname());
            }
        }

        return $files;
    }

    /**
     * Calculate checksums for verification
     */
    private function calculateChecksums($path) {
        $checksums = [];
        $files = $this->listFiles($path);

        foreach ($files as $file) {
            $fullPath = "{$path}/{$file}";
            if (file_exists($fullPath)) {
                $checksums[$file] = md5_file($fullPath);
            }
        }

        return $checksums;
    }

    /**
     * Compress backup to ZIP
     *
     * @param string $sourcePath
     * @param string $backupName
     * @return string Path to ZIP file
     */
    private function compressBackup($sourcePath, $backupName) {
        $zipPath = "{$this->backupDir}/{$backupName}.zip";

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Failed to create ZIP archive");
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourcePath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * Clean old backups based on retention policy
     */
    private function cleanOldBackups() {
        $files = glob("{$this->backupDir}/studymate_backup_*.zip");

        // Sort by modification time (newest first)
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        // Remove backups exceeding max count
        $toDelete = array_slice($files, $this->maxBackups);

        foreach ($toDelete as $file) {
            unlink($file);
            logEvent('backup_deleted', ['file' => basename($file), 'reason' => 'max_count_exceeded']);
        }

        // Remove backups older than retention period
        $cutoff = time() - ($this->retentionDays * 24 * 3600);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                logEvent('backup_deleted', ['file' => basename($file), 'reason' => 'retention_expired']);
            }
        }
    }

    /**
     * List available backups
     *
     * @return array
     */
    public function listBackups() {
        $files = glob("{$this->backupDir}/studymate_backup_*.zip");
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $backups = [];

        foreach ($files as $file) {
            $backups[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'size_human' => $this->formatBytes(filesize($file)),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                'age_days' => floor((time() - filemtime($file)) / 86400)
            ];
        }

        return $backups;
    }

    // ========== Utility Methods ==========

    private function copyDirectory($src, $dst) {
        $dir = opendir($src);
        @mkdir($dst);

        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    $this->copyDirectory($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }

        closedir($dir);
    }

    private function removeDirectory($dir) {
        if (!is_dir($dir)) return;

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
}

// ========== CLI Execution ==========

if (php_sapi_name() === 'cli') {
    $force = in_array('--force', $argv);
    $outputDir = null;

    foreach ($argv as $arg) {
        if (strpos($arg, '--output=') === 0) {
            $outputDir = substr($arg, 9);
        }
    }

    $manager = new BackupManager($outputDir);
    $result = $manager->run($force);

    if ($result['success']) {
        if (isset($result['skipped'])) {
            echo "✓ {$result['message']}\n";
        } else {
            echo "✓ Backup completed successfully\n";
            echo "  Name: {$result['backup_name']}\n";
            echo "  Path: {$result['backup_path']}\n";
            echo "  Size: {$result['size_human']}\n";
        }
        exit(0);
    } else {
        echo "✗ Backup failed: {$result['error']}\n";
        exit(1);
    }
}
