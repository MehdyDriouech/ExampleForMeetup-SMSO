<?php
/**
 * Admin Import API - Sprint 20
 *
 * Endpoints:
 * - POST   /api/admin/import/upload            - Upload CSV file
 * - POST   /api/admin/import/validate/:id      - Validate uploaded CSV
 * - POST   /api/admin/import/execute/:id       - Execute import
 * - GET    /api/admin/import/status/:id        - Get import job status
 * - GET    /api/admin/import/template/:type    - Download CSV template
 * - GET    /api/admin/import/jobs              - List import jobs
 *
 * @version 1.0
 * @date 2025-11-15
 */

require_once __DIR__ . '/../../.env.php';
require_once __DIR__ . '/../_middleware_tenant.php';
require_once __DIR__ . '/../_middleware_rbac.php';
require_once __DIR__ . '/../../services/CSVImportService.php';
require_once __DIR__ . '/../../services/OnboardingService.php';
require_once __DIR__ . '/../../services/audit_log.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// Parse path
$requestUri = $_SERVER['REQUEST_URI'];
$pathParts = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));

$action = null;
$resourceId = null;

// Extract action: /api/admin/import/{action}/{id?}
if (count($pathParts) >= 4 && $pathParts[0] === 'api' && $pathParts[1] === 'admin' && $pathParts[2] === 'import') {
    if (isset($pathParts[3])) {
        $action = $pathParts[3];
    }
    if (isset($pathParts[4])) {
        $resourceId = $pathParts[4];
    }
}

// Authentication & Authorization
$auth = requireAuth();
$tenantContext = enforceTenantIsolation();
enforceTenantAuthMatch($tenantContext, $auth);
$rbac = enforceRBAC($auth);

$tenantId = $tenantContext->getTenantId();
$auditLog = createAuditLog($tenantId, $auth);

// Instancier les services
$csvImportService = new CSVImportService(db());
$onboardingService = new OnboardingService(db());

// Répertoire uploads
$uploadsDir = __DIR__ . '/../../uploads/csv';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

// ============================================================
// POST /api/admin/import/upload - Upload CSV
// ============================================================
if ($method === 'POST' && $action === 'upload') {
    $rbac->requirePermission('imports', 'create');

    try {
        // Vérifier qu'un fichier a été uploadé
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            errorResponse('VALIDATION_ERROR', 'No file uploaded or upload error', 400);
        }

        // Type d'import (students, teachers, classes, promotions)
        $importType = $_POST['import_type'] ?? null;
        if (!$importType) {
            errorResponse('VALIDATION_ERROR', 'import_type is required', 400);
        }

        // Valider le type
        $validTypes = ['students', 'teachers', 'classes', 'promotions'];
        if (!in_array($importType, $validTypes)) {
            errorResponse('VALIDATION_ERROR', 'Invalid import_type', 400);
        }

        $file = $_FILES['file'];

        // Vérifier l'extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            errorResponse('VALIDATION_ERROR', 'Only CSV files are allowed', 400);
        }

        // Vérifier la taille (max 10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
            errorResponse('VALIDATION_ERROR', 'File too large (max 10MB)', 400);
        }

        // Générer un nom de fichier sécurisé
        $filename = $tenantId . '_' . $importType . '_' . time() . '.csv';
        $filepath = $uploadsDir . '/' . $filename;

        // Déplacer le fichier
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            errorResponse('SERVER_ERROR', 'Failed to save uploaded file', 500);
        }

        // Créer le job d'import
        $result = $csvImportService->createImportJob(
            $tenantId,
            $auth->getUserId(),
            $importType,
            $filepath
        );

        if (!$result['success']) {
            errorResponse('CREATE_FAILED', $result['error'], 500);
        }

        // Audit log
        $auditLog->logAction(
            'create',
            'import_job',
            $result['job_id'],
            [
                'import_type' => $importType,
                'filename' => $file['name']
            ]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/import/upload', 201, $duration);

        jsonResponse([
            'job_id' => $result['job_id'],
            'import_type' => $result['import_type'],
            'status' => $result['status'],
            'message' => 'File uploaded successfully. Use /validate to check the data.'
        ], 201);

    } catch (Exception $e) {
        logError('Failed to upload CSV', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to upload file', 500);
    }
}

// ============================================================
// POST /api/admin/import/validate/:id - Valider CSV
// ============================================================
if ($method === 'POST' && $action === 'validate' && $resourceId) {
    $rbac->requirePermission('imports', 'create');

    try {
        $result = $csvImportService->validateCSV($resourceId);

        if (!$result['success']) {
            errorResponse('VALIDATION_FAILED', $result['error'], 500);
        }

        // Audit log
        $auditLog->logAction(
            'validate',
            'import_job',
            $resourceId,
            [
                'valid' => $result['valid'],
                'error_count' => count($result['errors']),
                'warning_count' => count($result['warnings'])
            ]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/import/validate', 200, $duration);

        jsonResponse([
            'job_id' => $resourceId,
            'valid' => $result['valid'],
            'report' => $result['report'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'message' => $result['valid']
                ? 'Validation passed. Use /execute to import the data.'
                : 'Validation failed. Please fix the errors and upload again.'
        ]);

    } catch (Exception $e) {
        logError('Failed to validate CSV', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to validate file', 500);
    }
}

// ============================================================
// POST /api/admin/import/execute/:id - Exécuter import
// ============================================================
if ($method === 'POST' && $action === 'execute' && $resourceId) {
    $rbac->requirePermission('imports', 'create');

    try {
        $result = $csvImportService->executeImport($resourceId);

        if (!$result['success']) {
            errorResponse('IMPORT_FAILED', $result['error'], 500);
        }

        // Marquer l'étape d'onboarding comme complétée si applicable
        $onboardingService->completeStep(
            $tenantId,
            OnboardingService::STEP_IMPORT_STRUCTURE,
            [
                'import_job_id' => $resourceId,
                'imported_count' => $result['imported'],
                'import_type' => $result['status']
            ]
        );

        // Audit log
        $auditLog->logAction(
            'execute',
            'import_job',
            $resourceId,
            [
                'status' => $result['status'],
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'errors' => $result['errors']
            ]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/import/execute', 200, $duration);

        jsonResponse([
            'job_id' => $result['job_id'],
            'status' => $result['status'],
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
            'created_ids' => $result['created_ids'],
            'summary' => $result['summary'],
            'message' => 'Import completed successfully'
        ]);

    } catch (Exception $e) {
        logError('Failed to execute import', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to execute import', 500);
    }
}

// ============================================================
// GET /api/admin/import/status/:id - Statut d'un job
// ============================================================
if ($method === 'GET' && $action === 'status' && $resourceId) {
    $rbac->requirePermission('imports', 'read');

    try {
        $result = $csvImportService->getJobStatus($resourceId);

        if (!$result['success']) {
            errorResponse('FETCH_FAILED', $result['error'], 404);
        }

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/import/status', 200, $duration);

        jsonResponse($result);

    } catch (Exception $e) {
        logError('Failed to get job status', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to get job status', 500);
    }
}

// ============================================================
// GET /api/admin/import/template/:type - Télécharger template
// ============================================================
if ($method === 'GET' && $action === 'template' && $resourceId) {
    // Pas de permission requise pour télécharger un template

    try {
        $type = $resourceId; // students, teachers, classes, promotions

        // Templates CSV
        $templates = [
            'students' => [
                'filename' => 'template_students.csv',
                'headers' => ['firstname', 'lastname', 'email_scolaire', 'class_name', 'promo_name', 'uuid_scolaire', 'consent_sharing'],
                'sample' => [
                    ['Jean', 'Dupont', 'jean.dupont@ecole.fr', '6emeA', '2024-2025', 'student_abc123', 'true'],
                    ['Marie', 'Martin', 'marie.martin@ecole.fr', '6emeA', '2024-2025', 'student_def456', 'false']
                ]
            ],
            'teachers' => [
                'filename' => 'template_teachers.csv',
                'headers' => ['firstname', 'lastname', 'email', 'role', 'class_names'],
                'sample' => [
                    ['Pierre', 'Durand', 'pierre.durand@ecole.fr', 'teacher', '6emeA,6emeB'],
                    ['Sophie', 'Bernard', 'sophie.bernard@ecole.fr', 'direction', '']
                ]
            ],
            'classes' => [
                'filename' => 'template_classes.csv',
                'headers' => ['name', 'promo_name', 'description', 'teacher_email'],
                'sample' => [
                    ['6emeA', '2024-2025', 'Classe de 6ème A', 'pierre.durand@ecole.fr'],
                    ['6emeB', '2024-2025', 'Classe de 6ème B', '']
                ]
            ],
            'promotions' => [
                'filename' => 'template_promotions.csv',
                'headers' => ['name', 'year_start', 'year_end', 'level'],
                'sample' => [
                    ['2024-2025', '2024', '2025', '6eme'],
                    ['2025-2026', '2025', '2026', '6eme']
                ]
            ]
        ];

        if (!isset($templates[$type])) {
            errorResponse('NOT_FOUND', 'Template not found', 404);
        }

        $template = $templates[$type];

        // Générer le CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $template['filename'] . '"');

        $output = fopen('php://output', 'w');

        // Headers
        fputcsv($output, $template['headers']);

        // Sample data
        foreach ($template['sample'] as $row) {
            fputcsv($output, $row);
        }

        fclose($output);

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/import/template', 200, $duration);

        exit;

    } catch (Exception $e) {
        logError('Failed to generate template', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to generate template', 500);
    }
}

// ============================================================
// GET /api/admin/import/jobs - Liste des jobs d'import
// ============================================================
if ($method === 'GET' && $action === 'jobs') {
    $rbac->requirePermission('imports', 'read');

    try {
        // Filtres
        $filters = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if (isset($_GET['import_type']) && !empty($_GET['import_type'])) {
            $filters[] = 'import_type = :import_type';
            $params['import_type'] = $_GET['import_type'];
        }

        if (isset($_GET['status']) && !empty($_GET['status'])) {
            $filters[] = 'status = :status';
            $params['status'] = $_GET['status'];
        }

        $whereClause = implode(' AND ', $filters);

        $jobs = db()->query(
            "SELECT id, import_type, status, total_rows, imported_count, skipped_count, error_count,
                    started_at, completed_at, created_at
             FROM import_jobs
             WHERE {$whereClause}
             ORDER BY created_at DESC
             LIMIT 50",
            $params
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/import/jobs', 200, $duration);

        jsonResponse([
            'jobs' => $jobs,
            'total' => count($jobs)
        ]);

    } catch (Exception $e) {
        logError('Failed to list import jobs', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to list jobs', 500);
    }
}

// Route non trouvée
errorResponse('NOT_FOUND', 'Endpoint not found', 404);
