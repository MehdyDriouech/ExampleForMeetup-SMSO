<?php
/**
 * Sprint 12 - Pedagogical Library
 * API: Internal Catalog Management
 *
 * Endpoints pour le catalogue interne de thèmes pédagogiques:
 * - Listing et recherche
 * - Consultation en lecture seule
 * - Proposition et validation
 * - Versioning
 * - Attribution aux classes
 *
 * Permissions:
 * - Enseignant: consulter, proposer
 * - Référent: valider, commenter
 * - Direction: publier, archiver
 *
 * @version 1.0.0
 * @date 2025-11-13
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Tenant-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rbac.php';
require_once __DIR__ . '/../services/WorkflowManager.php';
require_once __DIR__ . '/../services/VersionService.php';

// ============================================================================
// Authentification et initialisation
// ============================================================================

try {
    // Authentification
    $auth = requireAuth();
    $rbac = enforceRBAC($auth);
    $user = $auth->getUser();
    $tenantId = $auth->getTenantId();

    if (!$tenantId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing tenant_id']);
        exit;
    }

    // Initialiser services
    $db = db();
    $workflowManager = new WorkflowManager($db);
    $versionService = new VersionService($db);

    // Router
    $requestMethod = $_SERVER['REQUEST_METHOD'];
    $requestUri = $_SERVER['REQUEST_URI'];
    $parsedUrl = parse_url($requestUri);
    $path = $parsedUrl['path'];

    // Extraire le sous-chemin
    $pathPattern = '#^/api/catalog(/.*)?$#';
    if (preg_match($pathPattern, $path, $matches)) {
        $subPath = $matches[1] ?? '/';
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }

    // ========================================================================
    // GET /api/catalog/list - Liste des thèmes du catalogue
    // ========================================================================
    if ($requestMethod === 'GET' && ($subPath === '/list' || $subPath === '/')) {
        $rbac->requirePermission('catalog', 'read');

        // Filtres de recherche
        $filters = [
            'title' => $_GET['title'] ?? null,
            'tags' => $_GET['tags'] ?? null,
            'subject' => $_GET['subject'] ?? null,
            'level' => $_GET['level'] ?? null,
            'difficulty' => $_GET['difficulty'] ?? null,
            'workflow_status' => $_GET['status'] ?? 'published', // Par défaut: thèmes publiés
            'search' => $_GET['search'] ?? null,
            'order_by' => $_GET['order_by'] ?? 'updated_at',
            'order_dir' => $_GET['order_dir'] ?? 'DESC',
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 50,
            'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : 0
        ];

        // Construction de la requête
        $where = ["ce.tenant_id = ?"];
        $params = [$tenantId];

        if ($filters['title']) {
            $where[] = "ce.title LIKE ?";
            $params[] = '%' . $filters['title'] . '%';
        }

        if ($filters['subject']) {
            $where[] = "ce.subject = ?";
            $params[] = $filters['subject'];
        }

        if ($filters['level']) {
            $where[] = "ce.level = ?";
            $params[] = $filters['level'];
        }

        if ($filters['difficulty']) {
            $where[] = "ce.difficulty = ?";
            $params[] = $filters['difficulty'];
        }

        if ($filters['workflow_status']) {
            $where[] = "ce.workflow_status = ?";
            $params[] = $filters['workflow_status'];
        }

        if ($filters['search']) {
            $where[] = "(ce.title LIKE ? OR ce.description LIKE ? OR ce.tags LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Tags (JSON search)
        if ($filters['tags']) {
            $where[] = "JSON_CONTAINS(ce.tags, ?)";
            $params[] = json_encode(explode(',', $filters['tags']));
        }

        $whereClause = implode(' AND ', $where);
        $orderBy = $filters['order_by'];
        $orderDir = $filters['order_dir'];

        $sql = "SELECT
                    ce.*,
                    u.name as author_name,
                    u.email as author_email,
                    cv.version_number,
                    cv.version_label
                FROM catalog_entries ce
                LEFT JOIN users u ON ce.created_by = u.id
                LEFT JOIN catalog_versions cv ON ce.current_version_id = cv.id
                WHERE {$whereClause}
                ORDER BY ce.{$orderBy} {$orderDir}
                LIMIT ? OFFSET ?";

        $params[] = $filters['limit'];
        $params[] = $filters['offset'];

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Décoder les champs JSON
        foreach ($entries as &$entry) {
            $entry['tags'] = json_decode($entry['tags'] ?? '[]', true);
        }

        echo json_encode([
            'success' => true,
            'catalog_entries' => $entries,
            'count' => count($entries),
            'filters' => $filters
        ]);
    }

    // ========================================================================
    // GET /api/catalog/{id} - Détails d'un thème du catalogue
    // ========================================================================
    elseif ($requestMethod === 'GET' && preg_match('#^/([a-z0-9_\-]+)$#', $subPath, $matches)) {
        $rbac->requirePermission('catalog', 'read');

        $catalogId = $matches[1];

        $stmt = $db->prepare(
            "SELECT
                ce.*,
                u.name as author_name,
                u.email as author_email,
                cv.version_number,
                cv.version_label,
                cv.content as theme_content
             FROM catalog_entries ce
             LEFT JOIN users u ON ce.created_by = u.id
             LEFT JOIN catalog_versions cv ON ce.current_version_id = cv.id
             WHERE ce.id = ? AND ce.tenant_id = ?"
        );

        $stmt->execute([$catalogId, $tenantId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entry) {
            http_response_code(404);
            echo json_encode(['error' => 'Catalog entry not found']);
            exit;
        }

        // Décoder JSON
        $entry['tags'] = json_decode($entry['tags'] ?? '[]', true);
        $entry['theme_content'] = json_decode($entry['theme_content'] ?? '{}', true);

        // Ajouter l'historique du workflow
        $entry['workflow_history'] = $workflowManager->getWorkflowHistory($catalogId);

        // Ajouter les versions
        $entry['versions'] = $versionService->getVersionHistory($catalogId, 10);

        echo json_encode([
            'success' => true,
            'catalog_entry' => $entry
        ]);
    }

    // ========================================================================
    // POST /api/catalog/submit - Proposer un thème pour validation
    // ========================================================================
    elseif ($requestMethod === 'POST' && $subPath === '/submit') {
        $rbac->requirePermission('catalog', 'submit');

        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['catalog_entry_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing catalog_entry_id']);
            exit;
        }

        $result = $workflowManager->submitForValidation(
            $input['catalog_entry_id'],
            $user['id'],
            $input['comment'] ?? null
        );

        if ($result['success']) {
            logInfo("Theme submitted for validation", [
                'catalog_entry_id' => $input['catalog_entry_id'],
                'user_id' => $user['id']
            ]);

            echo json_encode($result);
        } else {
            http_response_code(400);
            echo json_encode($result);
        }
    }

    // ========================================================================
    // PATCH /api/catalog/validate - Valider ou rejeter un thème
    // ========================================================================
    elseif ($requestMethod === 'PATCH' && $subPath === '/validate') {
        $rbac->requirePermission('catalog', 'validate');

        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['catalog_entry_id']) || empty($input['action'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing catalog_entry_id or action']);
            exit;
        }

        $action = $input['action']; // 'validate' ou 'reject'
        $comment = $input['comment'] ?? null;

        if ($action === 'validate') {
            $result = $workflowManager->validateTheme(
                $input['catalog_entry_id'],
                $user['id'],
                $comment
            );
        } elseif ($action === 'reject') {
            if (empty($comment)) {
                http_response_code(400);
                echo json_encode(['error' => 'Comment is required for rejection']);
                exit;
            }

            $result = $workflowManager->rejectTheme(
                $input['catalog_entry_id'],
                $user['id'],
                $comment
            );
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use "validate" or "reject"']);
            exit;
        }

        if ($result['success']) {
            logInfo("Theme validation action completed", [
                'action' => $action,
                'catalog_entry_id' => $input['catalog_entry_id'],
                'user_id' => $user['id']
            ]);

            echo json_encode($result);
        } else {
            http_response_code(400);
            echo json_encode($result);
        }
    }

    // ========================================================================
    // POST /api/catalog/publish - Publier un thème validé
    // ========================================================================
    elseif ($requestMethod === 'POST' && $subPath === '/publish') {
        $rbac->requirePermission('catalog', 'publish');

        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['catalog_entry_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing catalog_entry_id']);
            exit;
        }

        $result = $workflowManager->publishTheme(
            $input['catalog_entry_id'],
            $user['id'],
            $input['comment'] ?? null
        );

        if ($result['success']) {
            logInfo("Theme published to catalog", [
                'catalog_entry_id' => $input['catalog_entry_id'],
                'user_id' => $user['id']
            ]);

            echo json_encode($result);
        } else {
            http_response_code(400);
            echo json_encode($result);
        }
    }

    // ========================================================================
    // DELETE /api/catalog/{id}/archive - Archiver un thème
    // ========================================================================
    elseif ($requestMethod === 'DELETE' && preg_match('#^/([a-z0-9_\-]+)/archive$#', $subPath, $matches)) {
        $rbac->requirePermission('catalog', 'archive');

        $catalogId = $matches[1];
        $input = json_decode(file_get_contents('php://input'), true);

        $result = $workflowManager->archiveTheme(
            $catalogId,
            $user['id'],
            $input['reason'] ?? null
        );

        if ($result['success']) {
            logInfo("Theme archived", [
                'catalog_entry_id' => $catalogId,
                'user_id' => $user['id']
            ]);

            echo json_encode($result);
        } else {
            http_response_code(400);
            echo json_encode($result);
        }
    }

    // ========================================================================
    // GET /api/catalog/{id}/versions - Historique des versions
    // ========================================================================
    elseif ($requestMethod === 'GET' && preg_match('#^/([a-z0-9_\-]+)/versions$#', $subPath, $matches)) {
        $rbac->requirePermission('catalog', 'read');

        $catalogId = $matches[1];
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

        $versions = $versionService->getVersionHistory($catalogId, $limit);

        echo json_encode([
            'success' => true,
            'catalog_entry_id' => $catalogId,
            'versions' => $versions,
            'count' => count($versions)
        ]);
    }

    // ========================================================================
    // POST /api/catalog/{id}/versions/{versionId}/rollback - Restaurer une version
    // ========================================================================
    elseif ($requestMethod === 'POST' && preg_match('#^/([a-z0-9_\-]+)/versions/([a-z0-9_\-]+)/rollback$#', $subPath, $matches)) {
        $rbac->requirePermission('catalog', 'update');

        $catalogId = $matches[1];
        $versionId = $matches[2];

        $result = $versionService->restoreVersion($catalogId, $versionId, $user['id']);

        if ($result['success']) {
            logInfo("Version rollback", [
                'catalog_entry_id' => $catalogId,
                'version_id' => $versionId,
                'user_id' => $user['id']
            ]);

            echo json_encode($result);
        } else {
            http_response_code(400);
            echo json_encode($result);
        }
    }

    // ========================================================================
    // POST /api/catalog/publish-to-ergo - Publier vers Ergo-Mate
    // ========================================================================
    elseif ($requestMethod === 'POST' && $subPath === '/publish-to-ergo') {
        $rbac->requirePermission('catalog', 'publish_to_ergo');

        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['catalog_entry_id']) || empty($input['class_ids'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing catalog_entry_id or class_ids']);
            exit;
        }

        // Récupérer le thème
        $stmt = $db->prepare(
            "SELECT ce.*, cv.content as theme_content
             FROM catalog_entries ce
             LEFT JOIN catalog_versions cv ON ce.current_version_id = cv.id
             WHERE ce.id = ? AND ce.tenant_id = ?"
        );

        $stmt->execute([$input['catalog_entry_id'], $tenantId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entry) {
            http_response_code(404);
            echo json_encode(['error' => 'Catalog entry not found']);
            exit;
        }

        // Vérifier que le thème est publié
        if ($entry['workflow_status'] !== 'published') {
            http_response_code(400);
            echo json_encode(['error' => 'Only published themes can be pushed to Ergo-Mate']);
            exit;
        }

        // Préparer le payload pour Ergo-Mate
        $themeData = json_decode($entry['theme_content'], true);

        // Appeler le webhook Ergo-Mate
        $ergoMateUrl = getenv('ERGOMATE_URL') ?: 'http://localhost:8081';
        $webhookUrl = $ergoMateUrl . '/api/v1/themes/push';

        $payload = [
            'tenant_id' => $tenantId,
            'theme_id' => $input['catalog_entry_id'],
            'class_ids' => $input['class_ids'],
            'theme' => $themeData,
            'metadata' => [
                'title' => $entry['title'],
                'description' => $entry['description'],
                'author' => $entry['created_by'],
                'version' => $entry['version_label']
            ]
        ];

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Tenant-Id: ' . $tenantId
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 || $httpCode === 201) {
            logInfo("Theme published to Ergo-Mate", [
                'catalog_entry_id' => $input['catalog_entry_id'],
                'class_ids' => $input['class_ids']
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Theme successfully pushed to Ergo-Mate',
                'ergo_response' => json_decode($response, true)
            ]);
        } else {
            logError("Failed to publish theme to Ergo-Mate", [
                'catalog_entry_id' => $input['catalog_entry_id'],
                'http_code' => $httpCode,
                'response' => $response
            ]);

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to push theme to Ergo-Mate',
                'details' => json_decode($response, true)
            ]);
        }
    }

    // ========================================================================
    // GET /api/catalog/stats - Statistiques du catalogue
    // ========================================================================
    elseif ($requestMethod === 'GET' && $subPath === '/stats') {
        $rbac->requirePermission('catalog', 'read');

        $stats = $workflowManager->getWorkflowStats($tenantId);

        // Ajouter stats additionnelles
        $stmt = $db->prepare(
            "SELECT COUNT(*) as total_entries FROM catalog_entries WHERE tenant_id = ?"
        );
        $stmt->execute([$tenantId]);
        $totalRow = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'tenant_id' => $tenantId,
            'total_entries' => (int)$totalRow['total_entries'],
            'by_status' => $stats
        ]);
    }

    // ========================================================================
    // Endpoint non trouvé
    // ========================================================================
    else {
        http_response_code(404);
        echo json_encode([
            'error' => 'Endpoint not found',
            'method' => $requestMethod,
            'path' => $subPath
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
        'trace' => (getenv('DEBUG_MODE') === 'true') ? $e->getTraceAsString() : null
    ]);

    logError("Catalog API error", [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
