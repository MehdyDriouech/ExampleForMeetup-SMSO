<?php
/**
 * API Ingestion - Upload et extraction de contenu (PDF, Audio)
 *
 * POST /api/ingest/upload     - Upload un fichier (PDF/audio) et extrait le texte
 * POST /api/ingest/generate   - Générer du contenu IA depuis une extraction
 * GET  /api/ingest/extractions - Liste des extractions
 * GET  /api/ingest/extractions/{id} - Détails d'une extraction
 */

require_once __DIR__ . '/../.env.php';
require_once __DIR__ . '/../lib/content_extractor.php';
require_once __DIR__ . '/../lib/ai_service.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);
$uri = $_SERVER['REQUEST_URI'];

// Parser l'URI
$path = parse_url($uri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$action = $pathParts[3] ?? null; // /api/ingest/{action}
$extractionId = $pathParts[4] ?? null;

// ============================================================
// POST /api/ingest/upload - Upload et extraction
// ============================================================
if ($method === 'POST' && $action === 'upload') {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();
    $user = $auth->getUser();
    $userId = $user['user_id'] ?? null;

    if (!$userId) {
        errorResponse('UNAUTHORIZED', 'User ID not found', 401);
    }

    // Vérifier qu'un fichier a été uploadé
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        errorResponse('VALIDATION_ERROR', 'No file uploaded', 400);
    }

    $file = $_FILES['file'];

    // Valider le type MIME
    $allowedTypes = [
        'pdf' => ['application/pdf'],
        'audio' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/m4a', 'audio/ogg', 'audio/webm']
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $sourceType = null;
    foreach ($allowedTypes as $type => $mimes) {
        if (in_array($mimeType, $mimes)) {
            $sourceType = $type;
            break;
        }
    }

    if (!$sourceType) {
        errorResponse('VALIDATION_ERROR', 'Invalid file type. Only PDF and audio files allowed.', 400);
    }

    // Valider la taille (max 50 MB pour audio, 10 MB pour PDF)
    $maxSize = $sourceType === 'audio' ? 50 * 1024 * 1024 : 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        $maxSizeMB = $maxSize / 1024 / 1024;
        errorResponse('VALIDATION_ERROR', "File too large. Max {$maxSizeMB}MB for {$sourceType}.", 400);
    }

    try {
        // Créer le répertoire uploads si nécessaire
        $uploadsDir = dirname(__DIR__) . '/uploads/' . $sourceType;
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        // Déplacer le fichier uploadé
        $filename = $userId . '_' . time() . '_' . basename($file['name']);
        $filepath = $uploadsDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception('Failed to move uploaded file');
        }

        // Extraire le texte
        $extractor = new ContentExtractor();

        if ($sourceType === 'pdf') {
            $extractionResult = $extractor->extractFromPDF($filepath, $userId, $tenantId);
        } else {
            // Audio - récupérer la clé API Whisper si disponible
            $whisperApiKey = null;
            $apiKeyRecord = db()->queryOne(
                'SELECT key_encrypted FROM api_keys
                 WHERE tenant_id = :tenant_id AND user_id = :user_id AND provider = \'openai\' AND status = \'active\'
                 ORDER BY created_at DESC
                 LIMIT 1',
                ['tenant_id' => $tenantId, 'user_id' => $userId]
            );

            if ($apiKeyRecord) {
                $whisperApiKey = $apiKeyRecord['key_encrypted'];
            }

            if (!$whisperApiKey) {
                // Nettoyer le fichier uploadé
                unlink($filepath);
                errorResponse('CONFIGURATION_ERROR', 'No Whisper API key configured. Please add an OpenAI API key to your account.', 400);
            }

            $extractionResult = $extractor->extractFromAudio($filepath, $userId, $tenantId, $whisperApiKey);
        }

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/ingest/upload', 200, $duration);

        jsonResponse([
            'extraction_id' => $extractionResult['extraction_id'],
            'source_type' => $sourceType,
            'text' => $extractionResult['text'],
            'metadata' => $extractionResult['metadata'],
            'processing_time_ms' => $extractionResult['processing_time_ms']
        ], 200);

    } catch (Exception $e) {
        logError('Ingestion upload failed', [
            'error' => $e->getMessage(),
            'tenant_id' => $tenantId
        ]);

        // Nettoyer le fichier si l'extraction a échoué
        if (isset($filepath) && file_exists($filepath)) {
            unlink($filepath);
        }

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/ingest/upload', 500, $duration);

        errorResponse('SERVER_ERROR', $e->getMessage(), 500);
    }
}

// ============================================================
// POST /api/ingest/generate - Générer du contenu IA depuis une extraction
// ============================================================
if ($method === 'POST' && $action === 'generate') {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();
    $user = $auth->getUser();
    $userId = $user['user_id'] ?? null;

    if (!$userId) {
        errorResponse('UNAUTHORIZED', 'User ID not found', 401);
    }

    $body = getRequestBody();

    // Validation
    validateRequired($body, ['extraction_id']);

    $extractionId = $body['extraction_id'];
    $options = [
        'type' => $body['type'] ?? 'theme',
        'difficulty' => $body['difficulty'] ?? 'intermediate'
    ];

    // Valider le type
    if (!in_array($options['type'], ['theme', 'quiz', 'flashcards', 'fiche'])) {
        errorResponse('VALIDATION_ERROR', 'Invalid type', 400);
    }

    // Valider la difficulté
    if (!in_array($options['difficulty'], ['beginner', 'intermediate', 'advanced'])) {
        errorResponse('VALIDATION_ERROR', 'Invalid difficulty', 400);
    }

    // Récupérer l'extraction
    $extraction = db()->queryOne(
        'SELECT * FROM ai_content_extractions
         WHERE id = :id AND tenant_id = :tenant_id AND user_id = :user_id',
        [
            'id' => $extractionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId
        ]
    );

    if (!$extraction) {
        errorResponse('NOT_FOUND', 'Extraction not found', 404);
    }

    if ($extraction['extraction_status'] !== 'completed') {
        errorResponse('VALIDATION_ERROR', 'Extraction not completed yet', 400);
    }

    if (empty($extraction['extracted_text'])) {
        errorResponse('VALIDATION_ERROR', 'No text extracted', 400);
    }

    // Récupérer la clé API Mistral
    $apiKey = null;
    $apiKeyRecord = db()->queryOne(
        'SELECT key_encrypted FROM api_keys
         WHERE tenant_id = :tenant_id AND user_id = :user_id AND provider = \'mistral\' AND status = \'active\'
         ORDER BY created_at DESC
         LIMIT 1',
        ['tenant_id' => $tenantId, 'user_id' => $userId]
    );

    if ($apiKeyRecord) {
        $apiKey = $apiKeyRecord['key_encrypted'];
    }

    try {
        $aiService = new AIService($apiKey);
        $result = $aiService->generateThemeFromText($extraction['extracted_text'], $userId, $tenantId, $options);

        // Lier l'extraction à la génération
        db()->execute(
            'UPDATE ai_generations
             SET extraction_id = :extraction_id
             WHERE id = :id',
            [
                'id' => $result['generation_id'],
                'extraction_id' => $extractionId
            ]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/ingest/generate', 200, $duration);

        jsonResponse($result, 200);

    } catch (Exception $e) {
        logError('Ingestion generation failed', [
            'error' => $e->getMessage(),
            'extraction_id' => $extractionId,
            'tenant_id' => $tenantId
        ]);

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/ingest/generate', 500, $duration);

        errorResponse('AI_ERROR', $e->getMessage(), 500);
    }
}

// ============================================================
// GET /api/ingest/extractions - Liste des extractions
// ============================================================
if ($method === 'GET' && $action === 'extractions' && !$extractionId) {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();

    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $sourceType = $_GET['source_type'] ?? null;
    $status = $_GET['status'] ?? null;

    // Construire la requête
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];

    if ($sourceType) {
        $where[] = 'source_type = :source_type';
        $params['source_type'] = $sourceType;
    }

    if ($status) {
        $where[] = 'extraction_status = :status';
        $params['status'] = $status;
    }

    $sql = "SELECT e.*,
                   CONCAT(u.firstname, ' ', u.lastname) as user_name
            FROM ai_content_extractions e
            JOIN users u ON e.user_id = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.created_at DESC
            LIMIT :limit OFFSET :offset";

    $params['limit'] = $limit;
    $params['offset'] = $offset;

    $stmt = db()->getPdo()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $extractions = $stmt->fetchAll();

    // Compter le total
    $whereClause = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) as total FROM ai_content_extractions WHERE $whereClause";
    $countStmt = db()->getPdo()->prepare($countSql);
    foreach ($params as $key => $value) {
        if ($key !== 'limit' && $key !== 'offset') {
            $countStmt->bindValue(":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
    $countStmt->execute();
    $total = $countStmt->fetch()['total'];

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/ingest/extractions', 200, $duration);

    jsonResponse([
        'extractions' => $extractions,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ]
    ]);
}

// ============================================================
// GET /api/ingest/extractions/{id} - Détails d'une extraction
// ============================================================
if ($method === 'GET' && $action === 'extractions' && $extractionId) {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();

    $extraction = db()->queryOne(
        'SELECT e.*,
                CONCAT(u.firstname, \' \', u.lastname) as user_name
         FROM ai_content_extractions e
         JOIN users u ON e.user_id = u.id
         WHERE e.id = :id AND e.tenant_id = :tenant_id',
        ['id' => $extractionId, 'tenant_id' => $tenantId]
    );

    if (!$extraction) {
        errorResponse('NOT_FOUND', 'Extraction not found', 404);
    }

    // Décoder metadata JSON
    if ($extraction['metadata']) {
        $extraction['metadata'] = json_decode($extraction['metadata'], true);
    }

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/ingest/extractions/' . $extractionId, 200, $duration);

    jsonResponse($extraction);
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
