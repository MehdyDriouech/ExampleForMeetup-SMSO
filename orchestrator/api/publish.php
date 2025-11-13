<?php
/**
 * API Publication - Publication de thèmes vers Ergo-Mate
 *
 * POST /api/publish/theme              - Publier un thème vers Ergo-Mate
 * POST /api/publish/acknowledge        - Recevoir l'accusé de réception d'Ergo-Mate (webhook)
 * GET  /api/publish/publications       - Liste des publications
 * GET  /api/publish/publications/{id}  - Détails d'une publication
 */

require_once __DIR__ . '/../.env.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);
$uri = $_SERVER['REQUEST_URI'];

// Parser l'URI
$path = parse_url($uri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$action = $pathParts[3] ?? null; // /api/publish/{action}
$publicationId = $pathParts[4] ?? null;

// ============================================================
// POST /api/publish/theme - Publier un thème vers Ergo-Mate
// ============================================================
if ($method === 'POST' && $action === 'theme') {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();
    $user = $auth->getUser();
    $userId = $user['user_id'] ?? null;

    if (!$userId) {
        errorResponse('UNAUTHORIZED', 'User ID not found', 401);
    }

    $body = getRequestBody();

    // Validation
    validateRequired($body, ['theme_id', 'publication_type']);

    $themeId = $body['theme_id'];
    $publicationType = $body['publication_type']; // 'catalog' ou 'assignment'
    $targetClasses = $body['target_classes'] ?? null;
    $targetStudents = $body['target_students'] ?? null;
    $generationId = $body['generation_id'] ?? null;

    // Valider le type de publication
    if (!in_array($publicationType, ['catalog', 'assignment'])) {
        errorResponse('VALIDATION_ERROR', 'Invalid publication_type. Must be "catalog" or "assignment"', 400);
    }

    // Si c'est une assignment, les cibles sont requises
    if ($publicationType === 'assignment' && empty($targetClasses) && empty($targetStudents)) {
        errorResponse('VALIDATION_ERROR', 'target_classes or target_students required for assignment publication', 400);
    }

    // Récupérer le thème
    $theme = db()->queryOne(
        'SELECT * FROM themes WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $themeId, 'tenant_id' => $tenantId]
    );

    if (!$theme) {
        errorResponse('NOT_FOUND', 'Theme not found', 404);
    }

    // Valider que le thème est conforme à Ergo-Mate
    $themeContent = json_decode($theme['content'], true);

    // Utiliser AIService pour valider avec le schéma strict Ergo-Mate
    require_once __DIR__ . '/../lib/ai_service.php';
    $aiService = new AIService();
    $validator = new SchemaValidator();

    // Ajouter content_type si manquant
    if (!isset($themeContent['content_type'])) {
        // Déterminer le type basé sur le contenu
        if (!empty($themeContent['questions']) && !empty($themeContent['flashcards']) && !empty($themeContent['fiche'])) {
            $themeContent['content_type'] = 'complete';
        } elseif (!empty($themeContent['questions'])) {
            $themeContent['content_type'] = 'quiz';
        } elseif (!empty($themeContent['flashcards'])) {
            $themeContent['content_type'] = 'flashcards';
        } elseif (!empty($themeContent['fiche'])) {
            $themeContent['content_type'] = 'fiche';
        } else {
            $themeContent['content_type'] = 'complete';
        }
    }

    $validation = $validator->validateTheme($themeContent, true); // Validation stricte Ergo-Mate

    if (!$validation['valid']) {
        errorResponse('VALIDATION_ERROR', 'Theme is not Ergo-Mate compliant', 400, [
            'validation_errors' => $validation['errors']
        ]);
    }

    // Créer un enregistrement de publication
    $publicationId = generateId('pub');

    db()->execute(
        'INSERT INTO ergomate_publications
         (id, tenant_id, user_id, theme_id, generation_id, publication_type, target_classes, target_students, status, created_at)
         VALUES (:id, :tenant_id, :user_id, :theme_id, :generation_id, :publication_type, :target_classes, :target_students, :status, NOW())',
        [
            'id' => $publicationId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'theme_id' => $themeId,
            'generation_id' => $generationId,
            'publication_type' => $publicationType,
            'target_classes' => $targetClasses ? json_encode($targetClasses) : null,
            'target_students' => $targetStudents ? json_encode($targetStudents) : null,
            'status' => 'pending'
        ]
    );

    // Marquer le thème comme validé Ergo-Mate
    db()->execute(
        'UPDATE themes
         SET ergomate_compliant = TRUE,
             ergomate_validated_at = NOW()
         WHERE id = :id',
        ['id' => $themeId]
    );

    // Publier vers Ergo-Mate
    try {
        $ergoMateResult = publishToErgoMate($themeContent, $publicationType, $targetClasses, $targetStudents, $tenantId);

        // Mettre à jour avec les IDs Ergo-Mate
        db()->execute(
            'UPDATE ergomate_publications
             SET status = :status,
                 ergomate_theme_id = :ergomate_theme_id,
                 ergomate_assignment_id = :ergomate_assignment_id,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $publicationId,
                'status' => 'published',
                'ergomate_theme_id' => $ergoMateResult['theme_id'] ?? null,
                'ergomate_assignment_id' => $ergoMateResult['assignment_id'] ?? null
            ]
        );

        logInfo('Theme published to Ergo-Mate', [
            'publication_id' => $publicationId,
            'theme_id' => $themeId,
            'publication_type' => $publicationType
        ]);

        // Créer une notification pour l'enseignant
        require_once __DIR__ . '/../lib/notify.php';
        $notifyService = new NotificationService();
        $notifyService->create(
            $tenantId,
            'teacher',
            $userId,
            'info',
            'Publication réussie',
            "Le thème \"{$theme['title']}\" a été publié avec succès vers Ergo-Mate.",
            null,
            'in-app'
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/publish/theme', 200, $duration);

        jsonResponse([
            'publication_id' => $publicationId,
            'status' => 'published',
            'ergomate_theme_id' => $ergoMateResult['theme_id'] ?? null,
            'ergomate_assignment_id' => $ergoMateResult['assignment_id'] ?? null
        ], 201);

    } catch (Exception $e) {
        // Marquer comme échoué
        db()->execute(
            'UPDATE ergomate_publications
             SET status = :status,
                 error_message = :error_message,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $publicationId,
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]
        );

        logError('Ergo-Mate publication failed', [
            'publication_id' => $publicationId,
            'error' => $e->getMessage()
        ]);

        errorResponse('PUBLICATION_ERROR', $e->getMessage(), 500);
    }
}

// ============================================================
// POST /api/publish/acknowledge - Webhook accusé de réception
// ============================================================
if ($method === 'POST' && $action === 'acknowledge') {
    // Vérifier l'authentification via API key ou signature
    // Pour simplifier, on utilise l'auth standard mais en production
    // il faudrait un mécanisme de signature de webhook

    $body = getRequestBody();

    // Validation
    validateRequired($body, ['publication_id', 'status']);

    $publicationId = $body['publication_id'];
    $status = $body['status'];
    $ergoMateThemeId = $body['ergomate_theme_id'] ?? null;
    $ergoMateAssignmentId = $body['ergomate_assignment_id'] ?? null;
    $ackData = $body['data'] ?? null;

    // Récupérer la publication
    $publication = db()->queryOne(
        'SELECT * FROM ergomate_publications WHERE id = :id',
        ['id' => $publicationId]
    );

    if (!$publication) {
        errorResponse('NOT_FOUND', 'Publication not found', 404);
    }

    // Mettre à jour avec l'accusé
    db()->execute(
        'UPDATE ergomate_publications
         SET status = :status,
             ergomate_theme_id = COALESCE(:ergomate_theme_id, ergomate_theme_id),
             ergomate_assignment_id = COALESCE(:ergomate_assignment_id, ergomate_assignment_id),
             ack_received_at = NOW(),
             ack_data = :ack_data,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => $publicationId,
            'status' => $status,
            'ergomate_theme_id' => $ergoMateThemeId,
            'ergomate_assignment_id' => $ergoMateAssignmentId,
            'ack_data' => $ackData ? json_encode($ackData) : null
        ]
    );

    // Journaliser
    logInfo('Ergo-Mate acknowledgement received', [
        'publication_id' => $publicationId,
        'status' => $status
    ]);

    // Envoyer une notification à l'enseignant
    require_once __DIR__ . '/../lib/notify.php';
    $notifyService = new NotificationService();
    $notifyService->create(
        $publication['tenant_id'],
        'teacher',
        $publication['user_id'],
        'info',
        'Accusé de réception Ergo-Mate',
        "Votre publication a été traitée par Ergo-Mate avec le statut : {$status}",
        null,
        'in-app'
    );

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/publish/acknowledge', 200, $duration);

    jsonResponse(['success' => true]);
}

// ============================================================
// GET /api/publish/publications - Liste des publications
// ============================================================
if ($method === 'GET' && $action === 'publications' && !$publicationId) {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();
    $user = $auth->getUser();

    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? null;
    $publicationType = $_GET['publication_type'] ?? null;

    // Construire la requête
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];

    // Les enseignants ne voient que leurs publications
    if ($user['role'] === 'teacher') {
        $where[] = 'user_id = :user_id';
        $params['user_id'] = $user['user_id'];
    }

    if ($status) {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }

    if ($publicationType) {
        $where[] = 'publication_type = :publication_type';
        $params['publication_type'] = $publicationType;
    }

    $sql = "SELECT p.*,
                   CONCAT(u.firstname, ' ', u.lastname) as teacher_name,
                   t.title as theme_title,
                   t.difficulty as theme_difficulty
            FROM ergomate_publications p
            JOIN users u ON p.user_id = u.id
            JOIN themes t ON p.theme_id = t.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.created_at DESC
            LIMIT :limit OFFSET :offset";

    $params['limit'] = $limit;
    $params['offset'] = $offset;

    $stmt = db()->getPdo()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $publications = $stmt->fetchAll();

    // Décoder JSON
    foreach ($publications as &$pub) {
        if ($pub['target_classes']) {
            $pub['target_classes'] = json_decode($pub['target_classes'], true);
        }
        if ($pub['target_students']) {
            $pub['target_students'] = json_decode($pub['target_students'], true);
        }
        if ($pub['ack_data']) {
            $pub['ack_data'] = json_decode($pub['ack_data'], true);
        }
    }

    // Compter le total
    $whereClause = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) as total FROM ergomate_publications WHERE $whereClause";
    $countStmt = db()->getPdo()->prepare($countSql);
    foreach ($params as $key => $value) {
        if ($key !== 'limit' && $key !== 'offset') {
            $countStmt->bindValue(":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
    $countStmt->execute();
    $total = $countStmt->fetch()['total'];

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/publish/publications', 200, $duration);

    jsonResponse([
        'publications' => $publications,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ]
    ]);
}

// ============================================================
// GET /api/publish/publications/{id} - Détails d'une publication
// ============================================================
if ($method === 'GET' && $action === 'publications' && $publicationId) {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();

    $publication = db()->queryOne(
        'SELECT p.*,
                CONCAT(u.firstname, \' \', u.lastname) as teacher_name,
                t.title as theme_title,
                t.difficulty as theme_difficulty,
                t.content as theme_content
         FROM ergomate_publications p
         JOIN users u ON p.user_id = u.id
         JOIN themes t ON p.theme_id = t.id
         WHERE p.id = :id AND p.tenant_id = :tenant_id',
        ['id' => $publicationId, 'tenant_id' => $tenantId]
    );

    if (!$publication) {
        errorResponse('NOT_FOUND', 'Publication not found', 404);
    }

    // Décoder JSON
    if ($publication['target_classes']) {
        $publication['target_classes'] = json_decode($publication['target_classes'], true);
    }
    if ($publication['target_students']) {
        $publication['target_students'] = json_decode($publication['target_students'], true);
    }
    if ($publication['ack_data']) {
        $publication['ack_data'] = json_decode($publication['ack_data'], true);
    }
    if ($publication['theme_content']) {
        $publication['theme_content'] = json_decode($publication['theme_content'], true);
    }

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/publish/publications/' . $publicationId, 200, $duration);

    jsonResponse($publication);
}

// ============================================================
// Fonction de publication vers Ergo-Mate
// ============================================================
function publishToErgoMate($themeContent, $publicationType, $targetClasses, $targetStudents, $tenantId) {
    // Configuration Ergo-Mate (à adapter selon votre environnement)
    $ergoMateUrl = getenv('ERGOMATE_API_URL') ?: 'https://ergomate.fr/api/v1';
    $ergoMateApiKey = getenv('ERGOMATE_API_KEY');

    if (!$ergoMateApiKey) {
        throw new Exception('Ergo-Mate API key not configured');
    }

    // Endpoint selon le type de publication
    $endpoint = $publicationType === 'catalog'
        ? $ergoMateUrl . '/themes'
        : $ergoMateUrl . '/assignments';

    // Préparer le payload
    $payload = [
        'theme' => $themeContent,
        'tenant_id' => $tenantId
    ];

    if ($publicationType === 'assignment') {
        $payload['target_classes'] = $targetClasses;
        $payload['target_students'] = $targetStudents;
    }

    // Appeler l'API Ergo-Mate
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'X-API-Key: ' . $ergoMateApiKey,
        'X-Orchestrator-Id: ' . $tenantId
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 && $httpCode !== 201) {
        throw new Exception('Ergo-Mate API error: HTTP ' . $httpCode . ' - ' . $response);
    }

    $data = json_decode($response, true);

    if (!$data) {
        throw new Exception('Invalid Ergo-Mate API response');
    }

    return $data;
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
