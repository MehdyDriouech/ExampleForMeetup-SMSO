<?php
/**
 * Sprint 13 - Export API
 * Provides theme export in various formats (QTI, JSON, CSV, etc.)
 *
 * Endpoints:
 * - GET /api/export/qti/:theme_id - Export theme as QTI 2.2
 * - GET /api/export/json/:theme_id - Export theme as JSON
 * - GET /api/export/formats - List supported export formats
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/logging.php';
require_once __DIR__ . '/../services/converters/qti_converter.php';
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rbac.php';

header('Content-Type: application/json; charset=utf-8');

$tenantContext = tenantMiddleware();
$user = rbacMiddleware(['teacher', 'admin', 'direction', 'intervenant']);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

try {
    // GET /api/export/formats - List Export Formats
    if ($method === 'GET' && preg_match('#/api/export/formats$#', $path)) {
        echo json_encode([
            'success' => true,
            'formats' => [
                [
                    'id' => 'qti',
                    'name' => 'QTI 2.2',
                    'description' => 'Format standard pour LMS (Moodle, Canvas, etc.)',
                    'file_extension' => 'zip',
                    'mime_type' => 'application/zip'
                ],
                [
                    'id' => 'json',
                    'name' => 'JSON',
                    'description' => 'Format natif StudyMate',
                    'file_extension' => 'json',
                    'mime_type' => 'application/json'
                ]
            ],
            'lms_platforms' => QTIConverter::getSupportedPlatforms()
        ]);
        exit;
    }

    // GET /api/export/qti/:theme_id - Export as QTI
    if ($method === 'GET' && preg_match('#/api/export/qti/(\d+)$#', $path, $matches)) {
        $themeId = (int)$matches[1];

        // Fetch theme
        $stmt = db()->prepare("
            SELECT id, title, content_json, status, created_by
            FROM themes
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$themeId, $tenantContext['tenant_id']]);
        $theme = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            exit;
        }

        // Check permissions
        if ($user['role'] !== 'admin' && $user['role'] !== 'direction' && $theme['created_by'] != $user['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: You can only export your own themes']);
            exit;
        }

        // Parse content
        $themeData = json_decode($theme['content_json'], true);
        if (!$themeData) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid theme content']);
            exit;
        }

        $themeData['title'] = $theme['title'];

        // Get export options from query params
        $options = [
            'include_explanations' => $_GET['explanations'] ?? true,
            'shuffle_answers' => $_GET['shuffle'] ?? true,
            'target_lms' => $_GET['lms'] ?? 'generic'
        ];

        // Convert to QTI
        $converter = new QTIConverter();

        // Create temporary file for ZIP
        $tempDir = sys_get_temp_dir();
        $zipPath = $tempDir . '/studymate_qti_' . $themeId . '_' . time() . '.zip';

        $result = $converter->exportAsPackage($themeData, $zipPath, $options);

        if (!$result['success']) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Export failed',
                'details' => $converter->getErrors()
            ]);
            exit;
        }

        logEvent('theme_exported_qti', [
            'theme_id' => $themeId,
            'user_id' => $user['user_id'],
            'format' => 'qti',
            'lms' => $options['target_lms']
        ]);

        // Send file
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $theme['title']) . '_qti.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);

        // Clean up
        unlink($zipPath);
        exit;
    }

    // GET /api/export/json/:theme_id - Export as JSON
    if ($method === 'GET' && preg_match('#/api/export/json/(\d+)$#', $path, $matches)) {
        $themeId = (int)$matches[1];

        // Fetch theme
        $stmt = db()->prepare("
            SELECT id, title, content_json, description, difficulty, status, created_by, created_at
            FROM themes
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$themeId, $tenantContext['tenant_id']]);
        $theme = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            exit;
        }

        // Check permissions
        if ($user['role'] !== 'admin' && $user['role'] !== 'direction' && $theme['created_by'] != $user['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        // Build export data
        $exportData = [
            'version' => 'SPRINT_13',
            'exported_at' => date('c'),
            'theme' => [
                'id' => $theme['id'],
                'title' => $theme['title'],
                'description' => $theme['description'],
                'difficulty' => $theme['difficulty'],
                'status' => $theme['status'],
                'created_at' => $theme['created_at'],
                'content' => json_decode($theme['content_json'], true)
            ]
        ];

        logEvent('theme_exported_json', [
            'theme_id' => $themeId,
            'user_id' => $user['user_id']
        ]);

        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $theme['title']) . '.json';

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($exportData, JSON_PRETTY_PRINT);
        exit;
    }

    // Method not allowed
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Exception $e) {
    logError('Export API error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
