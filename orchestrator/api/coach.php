<?php
/**
 * API Coach Pédagogique - Assistant IA pour enseignants
 *
 * POST /api/coach/session/start        - Démarrer une session de coaching
 * POST /api/coach/session/{id}/message - Envoyer un message au coach
 * GET  /api/coach/session/{id}         - Récupérer une session et son historique
 * GET  /api/coach/sessions             - Liste des sessions
 * POST /api/coach/suggestions          - Obtenir des suggestions pédagogiques
 */

require_once __DIR__ . '/../.env.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);
$uri = $_SERVER['REQUEST_URI'];

// Parser l'URI
$path = parse_url($uri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$action = $pathParts[3] ?? null; // /api/coach/{action}
$subAction = $pathParts[4] ?? null;
$sessionIdOrAction = $pathParts[5] ?? null;

// ============================================================
// POST /api/coach/session/start - Démarrer une session
// ============================================================
if ($method === 'POST' && $action === 'session' && $subAction === 'start') {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();
    $user = $auth->getUser();
    $userId = $user['user_id'] ?? null;

    if (!$userId) {
        errorResponse('UNAUTHORIZED', 'User ID not found', 401);
    }

    $body = getRequestBody();

    // Validation
    validateRequired($body, ['context_type']);

    $contextType = $body['context_type'];
    $contextId = $body['context_id'] ?? null;
    $goal = $body['goal'] ?? null;

    // Valider le context_type
    if (!in_array($contextType, ['class', 'student', 'assignment', 'general'])) {
        errorResponse('VALIDATION_ERROR', 'Invalid context_type', 400);
    }

    // Si context_type n'est pas 'general', context_id est requis
    if ($contextType !== 'general' && !$contextId) {
        errorResponse('VALIDATION_ERROR', 'context_id required for this context_type', 400);
    }

    // Créer une session
    $sessionId = generateId('coach');

    db()->execute(
        'INSERT INTO ai_coach_sessions
         (id, tenant_id, user_id, context_type, context_id, session_goal, status, created_at)
         VALUES (:id, :tenant_id, :user_id, :context_type, :context_id, :session_goal, :status, NOW())',
        [
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'context_type' => $contextType,
            'context_id' => $contextId,
            'session_goal' => $goal,
            'status' => 'active'
        ]
    );

    // Créer un message système de bienvenue
    $welcomeMessage = generateWelcomeMessage($contextType, $user);

    $messageId = generateId('coachmsg');
    db()->execute(
        'INSERT INTO ai_coach_messages
         (id, session_id, role, content, created_at)
         VALUES (:id, :session_id, :role, :content, NOW())',
        [
            'id' => $messageId,
            'session_id' => $sessionId,
            'role' => 'system',
            'content' => $welcomeMessage
        ]
    );

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/coach/session/start', 200, $duration);

    jsonResponse([
        'session_id' => $sessionId,
        'context_type' => $contextType,
        'context_id' => $contextId,
        'welcome_message' => $welcomeMessage
    ], 201);
}

// ============================================================
// POST /api/coach/session/{id}/message - Envoyer un message
// ============================================================
if ($method === 'POST' && $action === 'session' && $sessionIdOrAction && $subAction !== 'start') {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();
    $user = $auth->getUser();
    $userId = $user['user_id'] ?? null;
    $sessionId = $subAction;
    $messageAction = $sessionIdOrAction;

    if ($messageAction !== 'message') {
        errorResponse('NOT_FOUND', 'Invalid endpoint', 404);
    }

    if (!$userId) {
        errorResponse('UNAUTHORIZED', 'User ID not found', 401);
    }

    $body = getRequestBody();
    validateRequired($body, ['message']);

    // Vérifier que la session existe
    $session = db()->queryOne(
        'SELECT * FROM ai_coach_sessions
         WHERE id = :id AND tenant_id = :tenant_id AND user_id = :user_id',
        [
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId
        ]
    );

    if (!$session) {
        errorResponse('NOT_FOUND', 'Session not found', 404);
    }

    if ($session['status'] !== 'active') {
        errorResponse('VALIDATION_ERROR', 'Session is not active', 400);
    }

    // Enregistrer le message de l'utilisateur
    $userMessageId = generateId('coachmsg');
    db()->execute(
        'INSERT INTO ai_coach_messages
         (id, session_id, role, content, created_at)
         VALUES (:id, :session_id, :role, :content, NOW())',
        [
            'id' => $userMessageId,
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => $body['message']
        ]
    );

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
        // Récupérer l'historique des messages
        $messages = db()->query(
            'SELECT role, content FROM ai_coach_messages
             WHERE session_id = :session_id
             ORDER BY created_at ASC',
            ['session_id' => $sessionId]
        );

        // Générer la réponse du coach
        $response = generateCoachResponse($apiKey, $messages, $session, $tenantId);

        // Enregistrer la réponse
        $assistantMessageId = generateId('coachmsg');
        db()->execute(
            'INSERT INTO ai_coach_messages
             (id, session_id, role, content, metadata, created_at)
             VALUES (:id, :session_id, :role, :content, :metadata, NOW())',
            [
                'id' => $assistantMessageId,
                'session_id' => $sessionId,
                'role' => 'assistant',
                'content' => $response['message'],
                'metadata' => json_encode($response['metadata'] ?? null)
            ]
        );

        // Mettre à jour la session
        db()->execute(
            'UPDATE ai_coach_sessions SET updated_at = NOW() WHERE id = :id',
            ['id' => $sessionId]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/coach/session/message', 200, $duration);

        jsonResponse([
            'message' => $response['message'],
            'metadata' => $response['metadata'] ?? null
        ]);

    } catch (Exception $e) {
        logError('Coach message generation failed', [
            'error' => $e->getMessage(),
            'session_id' => $sessionId
        ]);

        errorResponse('AI_ERROR', $e->getMessage(), 500);
    }
}

// ============================================================
// GET /api/coach/session/{id} - Récupérer une session
// ============================================================
if ($method === 'GET' && $action === 'session' && $subAction && $subAction !== 'start') {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();
    $user = $auth->getUser();
    $userId = $user['user_id'] ?? null;
    $sessionId = $subAction;

    // Récupérer la session
    $session = db()->queryOne(
        'SELECT * FROM ai_coach_sessions
         WHERE id = :id AND tenant_id = :tenant_id AND user_id = :user_id',
        [
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId
        ]
    );

    if (!$session) {
        errorResponse('NOT_FOUND', 'Session not found', 404);
    }

    // Récupérer les messages
    $messages = db()->query(
        'SELECT * FROM ai_coach_messages
         WHERE session_id = :session_id
         ORDER BY created_at ASC',
        ['session_id' => $sessionId]
    );

    // Décoder metadata JSON
    foreach ($messages as &$message) {
        if ($message['metadata']) {
            $message['metadata'] = json_decode($message['metadata'], true);
        }
    }

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/coach/session/' . $sessionId, 200, $duration);

    jsonResponse([
        'session' => $session,
        'messages' => $messages
    ]);
}

// ============================================================
// GET /api/coach/sessions - Liste des sessions
// ============================================================
if ($method === 'GET' && $action === 'sessions') {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();
    $user = $auth->getUser();
    $userId = $user['user_id'] ?? null;

    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? null;

    $where = ['tenant_id = :tenant_id', 'user_id = :user_id'];
    $params = ['tenant_id' => $tenantId, 'user_id' => $userId];

    if ($status) {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }

    $sql = "SELECT * FROM ai_coach_sessions
            WHERE " . implode(' AND ', $where) . "
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset";

    $params['limit'] = $limit;
    $params['offset'] = $offset;

    $stmt = db()->getPdo()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $sessions = $stmt->fetchAll();

    // Compter le total
    $whereClause = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) as total FROM ai_coach_sessions WHERE $whereClause";
    $countStmt = db()->getPdo()->prepare($countSql);
    foreach ($params as $key => $value) {
        if ($key !== 'limit' && $key !== 'offset') {
            $countStmt->bindValue(":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
    $countStmt->execute();
    $total = $countStmt->fetch()['total'];

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/coach/sessions', 200, $duration);

    jsonResponse([
        'sessions' => $sessions,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ]
    ]);
}

// ============================================================
// POST /api/coach/suggestions - Suggestions pédagogiques
// ============================================================
if ($method === 'POST' && $action === 'suggestions') {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();
    $user = $auth->getUser();
    $userId = $user['user_id'] ?? null;

    $body = getRequestBody();
    validateRequired($body, ['context_type']);

    $contextType = $body['context_type'];
    $contextId = $body['context_id'] ?? null;

    // Récupérer des suggestions basées sur le contexte
    $suggestions = generateSuggestions($contextType, $contextId, $tenantId);

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/coach/suggestions', 200, $duration);

    jsonResponse(['suggestions' => $suggestions]);
}

// ============================================================
// Fonctions utilitaires
// ============================================================

function generateWelcomeMessage($contextType, $user) {
    $firstname = $user['firstname'] ?? 'Enseignant';

    $messages = [
        'general' => "Bonjour {$firstname} ! Je suis votre assistant pédagogique IA. Comment puis-je vous aider aujourd'hui ?",
        'class' => "Bonjour {$firstname} ! Je peux vous aider à analyser les performances de votre classe et vous suggérer des stratégies d'enseignement adaptées. De quoi souhaitez-vous parler ?",
        'student' => "Bonjour {$firstname} ! Je peux vous aider à comprendre les difficultés d'un élève spécifique et vous proposer des approches personnalisées. Que souhaitez-vous savoir ?",
        'assignment' => "Bonjour {$firstname} ! Je peux vous aider à optimiser votre assignment et à prévoir les éventuelles difficultés. Comment puis-je vous assister ?"
    ];

    return $messages[$contextType] ?? $messages['general'];
}

function generateCoachResponse($apiKey, $messages, $session, $tenantId) {
    // En mode MOCK
    if (defined('MOCK_MODE') && MOCK_MODE === true) {
        return [
            'message' => "Ceci est une réponse de test du coach pédagogique. En mode production, cette réponse serait générée par l'IA en analysant le contexte de votre classe.",
            'metadata' => ['mock' => true]
        ];
    }

    if (!$apiKey) {
        throw new Exception('Mistral API key not configured');
    }

    // Construire le contexte
    $contextData = buildCoachContext($session, $tenantId);

    // Construire le prompt système
    $systemPrompt = "Tu es un assistant pédagogique expert spécialisé dans l'analyse de données éducatives et le conseil pédagogique.

Contexte de la session:
- Type: {$session['context_type']}
- Objectif: {$session['session_goal']}

Données contextuelles:
" . json_encode($contextData, JSON_PRETTY_PRINT) . "

Ton rôle:
1. Analyser les données fournies
2. Identifier les difficultés et opportunités
3. Proposer des stratégies pédagogiques concrètes et actionnables
4. Répondre de manière claire, bienveillante et professionnelle

Réponds en français et adapte ton niveau de détail aux questions posées.";

    // Préparer les messages pour l'API
    $apiMessages = [
        ['role' => 'system', 'content' => $systemPrompt]
    ];

    foreach ($messages as $msg) {
        if ($msg['role'] !== 'system') {
            $apiMessages[] = [
                'role' => $msg['role'] === 'user' ? 'user' : 'assistant',
                'content' => $msg['content']
            ];
        }
    }

    // Appeler Mistral API
    $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'mistral-medium',
        'messages' => $apiMessages,
        'temperature' => 0.7,
        'max_tokens' => 1500
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Mistral API error: HTTP ' . $httpCode);
    }

    $data = json_decode($response, true);

    if (!isset($data['choices'][0]['message']['content'])) {
        throw new Exception('Invalid Mistral API response');
    }

    return [
        'message' => $data['choices'][0]['message']['content'],
        'metadata' => ['model' => 'mistral-medium']
    ];
}

function buildCoachContext($session, $tenantId) {
    $context = [];

    if ($session['context_type'] === 'class' && $session['context_id']) {
        // Récupérer des stats de la classe
        $classStats = db()->query(
            'SELECT COUNT(DISTINCT s.id) as student_count,
                    AVG(st.success_rate) as avg_success_rate,
                    AVG(st.completion_rate) as avg_completion_rate
             FROM students s
             LEFT JOIN stats st ON s.id = st.student_id
             WHERE s.class_id = :class_id
               AND s.tenant_id = :tenant_id',
            ['class_id' => $session['context_id'], 'tenant_id' => $tenantId]
        );

        $context['class_stats'] = $classStats[0] ?? [];

        // Récupérer les insights récents
        $insights = db()->query(
            'SELECT insight_type, title, description, severity
             FROM class_insights
             WHERE class_id = :class_id
               AND is_read = FALSE
             ORDER BY priority DESC
             LIMIT 5',
            ['class_id' => $session['context_id']]
        );

        $context['recent_insights'] = $insights;
    }

    return $context;
}

function generateSuggestions($contextType, $contextId, $tenantId) {
    $suggestions = [];

    if ($contextType === 'class' && $contextId) {
        // Suggestions basées sur les insights de la classe
        $insights = db()->query(
            'SELECT * FROM class_insights
             WHERE class_id = :class_id
               AND severity IN (\'warning\', \'critical\')
               AND is_read = FALSE
             ORDER BY priority DESC
             LIMIT 3',
            ['class_id' => $contextId]
        );

        foreach ($insights as $insight) {
            $suggestions[] = [
                'type' => 'insight_action',
                'title' => "Agir sur : " . $insight['title'],
                'description' => $insight['description'],
                'action' => 'review_insight',
                'action_data' => ['insight_id' => $insight['id']]
            ];
        }
    }

    // Suggestions génériques
    $suggestions[] = [
        'type' => 'content_generation',
        'title' => 'Créer du nouveau contenu avec l\'IA',
        'description' => 'Générez automatiquement des quiz et fiches à partir de vos documents',
        'action' => 'open_ai_creator'
    ];

    $suggestions[] = [
        'type' => 'analytics',
        'title' => 'Consulter les analytics détaillées',
        'description' => 'Explorez les performances de vos élèves en profondeur',
        'action' => 'open_analytics'
    ];

    return $suggestions;
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
