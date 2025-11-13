<?php
/**
 * API Insights - Analytics et insights pédagogiques par classe
 *
 * GET  /api/insights/class/{classId}  - Insights pour une classe
 * GET  /api/insights/difficulties     - Top difficultés par classe
 * POST /api/insights/mark-read        - Marquer un insight comme lu
 * DELETE /api/insights/{id}           - Supprimer un insight
 */

require_once __DIR__ . '/../.env.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);
$uri = $_SERVER['REQUEST_URI'];

// Parser l'URI
$path = parse_url($uri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$action = $pathParts[3] ?? null; // /api/insights/{action}
$classIdOrInsightId = $pathParts[4] ?? null;

// ============================================================
// GET /api/insights/class/{classId} - Insights pour une classe
// ============================================================
if ($method === 'GET' && $action === 'class' && $classIdOrInsightId) {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();
    $user = $auth->getUser();
    $classId = $classIdOrInsightId;

    // Vérifier que la classe existe et appartient au tenant
    $class = db()->queryOne(
        'SELECT * FROM classes WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $classId, 'tenant_id' => $tenantId]
    );

    if (!$class) {
        errorResponse('NOT_FOUND', 'Class not found', 404);
    }

    // Filtres
    $insightType = $_GET['type'] ?? null;
    $severity = $_GET['severity'] ?? null;
    $onlyUnread = isset($_GET['unread']) && $_GET['unread'] === 'true';

    // Construire la requête
    $where = ['class_id = :class_id', 'tenant_id = :tenant_id'];
    $params = ['class_id' => $classId, 'tenant_id' => $tenantId];

    if ($insightType) {
        $where[] = 'insight_type = :insight_type';
        $params['insight_type'] = $insightType;
    }

    if ($severity) {
        $where[] = 'severity = :severity';
        $params['severity'] = $severity;
    }

    if ($onlyUnread) {
        $where[] = 'is_read = FALSE';
    }

    // Exclure les insights expirés
    $where[] = '(expires_at IS NULL OR expires_at > NOW())';

    $sql = "SELECT * FROM class_insights
            WHERE " . implode(' AND ', $where) . "
            ORDER BY priority DESC, created_at DESC
            LIMIT 50";

    $stmt = db()->getPdo()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    $insights = $stmt->fetchAll();

    // Décoder les JSON
    foreach ($insights as &$insight) {
        if ($insight['data']) {
            $insight['data'] = json_decode($insight['data'], true);
        }
        if ($insight['student_ids']) {
            $insight['student_ids'] = json_decode($insight['student_ids'], true);
        }
        if ($insight['theme_ids']) {
            $insight['theme_ids'] = json_decode($insight['theme_ids'], true);
        }
    }

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/insights/class/' . $classId, 200, $duration);

    jsonResponse([
        'class' => [
            'id' => $class['id'],
            'name' => $class['name']
        ],
        'insights' => $insights,
        'stats' => [
            'total' => count($insights),
            'unread' => count(array_filter($insights, fn($i) => !$i['is_read'])),
            'critical' => count(array_filter($insights, fn($i) => $i['severity'] === 'critical')),
            'warning' => count(array_filter($insights, fn($i) => $i['severity'] === 'warning'))
        ]
    ]);
}

// ============================================================
// GET /api/insights/difficulties - Top difficultés par classe
// ============================================================
if ($method === 'GET' && $action === 'difficulties') {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();
    $user = $auth->getUser();

    $classId = $_GET['class_id'] ?? null;
    $limit = intval($_GET['limit'] ?? 10);

    if (!$classId) {
        errorResponse('VALIDATION_ERROR', 'class_id parameter required', 400);
    }

    // Vérifier que la classe existe
    $class = db()->queryOne(
        'SELECT * FROM classes WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $classId, 'tenant_id' => $tenantId]
    );

    if (!$class) {
        errorResponse('NOT_FOUND', 'Class not found', 404);
    }

    // Utiliser la vue matérialisée
    $sql = "SELECT *
            FROM v_class_difficulty_insights
            WHERE class_id = :class_id
              AND avg_success_rate < 60
            ORDER BY struggling_student_count DESC, avg_success_rate ASC
            LIMIT :limit";

    $stmt = db()->getPdo()->prepare($sql);
    $stmt->bindValue(':class_id', $classId, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $difficulties = $stmt->fetchAll();

    // Enrichir avec les détails des thèmes
    foreach ($difficulties as &$difficulty) {
        // Récupérer la liste des élèves en difficulté
        $studentsSql = "SELECT s.id, s.firstname, s.lastname, st.success_rate, st.completion_rate
                        FROM students s
                        JOIN stats st ON s.id = st.student_id
                        WHERE s.class_id = :class_id
                          AND st.theme_id = :theme_id
                          AND st.success_rate < 50
                          AND st.total_attempts >= 3
                        ORDER BY st.success_rate ASC
                        LIMIT 10";

        $stmt2 = db()->getPdo()->prepare($studentsSql);
        $stmt2->bindValue(':class_id', $classId, PDO::PARAM_STR);
        $stmt2->bindValue(':theme_id', $difficulty['theme_id'], PDO::PARAM_STR);
        $stmt2->execute();
        $difficulty['struggling_students'] = $stmt2->fetchAll();
    }

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/insights/difficulties', 200, $duration);

    jsonResponse([
        'class' => [
            'id' => $class['id'],
            'name' => $class['name']
        ],
        'difficulties' => $difficulties
    ]);
}

// ============================================================
// POST /api/insights/mark-read - Marquer un insight comme lu
// ============================================================
if ($method === 'POST' && $action === 'mark-read') {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();

    $body = getRequestBody();
    validateRequired($body, ['insight_id']);

    $insightId = $body['insight_id'];

    // Vérifier que l'insight existe
    $insight = db()->queryOne(
        'SELECT * FROM class_insights WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $insightId, 'tenant_id' => $tenantId]
    );

    if (!$insight) {
        errorResponse('NOT_FOUND', 'Insight not found', 404);
    }

    // Marquer comme lu
    db()->execute(
        'UPDATE class_insights SET is_read = TRUE WHERE id = :id',
        ['id' => $insightId]
    );

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/insights/mark-read', 200, $duration);

    jsonResponse(['success' => true]);
}

// ============================================================
// DELETE /api/insights/{id} - Supprimer un insight
// ============================================================
if ($method === 'DELETE' && $classIdOrInsightId && $action !== 'class' && $action !== 'difficulties') {
    $auth = requireAuth();
    $tenantId = $auth->getTenantId();
    $insightId = $classIdOrInsightId;

    // Vérifier que l'insight existe
    $insight = db()->queryOne(
        'SELECT * FROM class_insights WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $insightId, 'tenant_id' => $tenantId]
    );

    if (!$insight) {
        errorResponse('NOT_FOUND', 'Insight not found', 404);
    }

    // Supprimer
    db()->execute(
        'DELETE FROM class_insights WHERE id = :id',
        ['id' => $insightId]
    );

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/insights/' . $insightId, 200, $duration);

    jsonResponse(['success' => true]);
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
