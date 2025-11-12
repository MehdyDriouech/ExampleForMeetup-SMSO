<?php
/**
 * API Assignments - Gestion des affectations
 *
 * GET    /api/assignments              - Liste des assignments (teacher/admin)
 * POST   /api/assignments              - Créer un assignment
 * GET    /api/assignments/{id}         - Détails d'un assignment
 * PATCH  /api/assignments/{id}         - Modifier un assignment
 * DELETE /api/assignments/{id}         - Supprimer un assignment (soft delete)
 * GET    /api/assignments/{id}/events  - Événements de tracking pour un assignment
 * POST   /api/assignments/ack          - Accusé de réception depuis Ergo-Mate
 * PATCH  /api/assignments/{id}/status  - Mise à jour du statut depuis Ergo-Mate
 */

require_once __DIR__ . '/../.env.php';
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rbac.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);
$uri = $_SERVER['REQUEST_URI'];

// Parser l'URI pour extraire l'ID et l'action
$path = parse_url($uri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$assignmentId = $pathParts[3] ?? null; // /api/assignments/{id}
$action = $pathParts[4] ?? null; // /api/assignments/{id}/events

// ============================================================
// GET /api/assignments - Liste des assignments
// ============================================================
if ($method === 'GET' && !$assignmentId) {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);
    $rbac = enforceRBAC($auth);

    // Permission check - teachers can read their own, others can read all
    $rbac->requirePermission('assignments', 'read');

    $tenantId = $tenantContext->getTenantId();

    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? null;
    $teacherId = $_GET['teacher_id'] ?? null;
    $classId = $_GET['class_id'] ?? null;

    // Construire la requête
    $where = ['a.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];

    // Apply ownership filter for teachers (admin/direction/inspector can see all)
    $ownershipWhere = $rbac->ownershipWhere('assignments', 'teacher_id', 'a');
    if ($ownershipWhere !== '1=1') {
        $where[] = $ownershipWhere;
    }

    if ($status) {
        $where[] = 'a.status = :status';
        $params['status'] = $status;
    }

    if ($teacherId) {
        $where[] = 'a.teacher_id = :teacher_id';
        $params['teacher_id'] = $teacherId;
    }

    // Si filtre par classe, joindre avec assignment_targets
    if ($classId) {
        $sql = "SELECT DISTINCT a.*,
                       t.title as theme_title,
                       CONCAT(u.firstname, ' ', u.lastname) as teacher_name
                FROM assignments a
                JOIN assignment_targets at ON a.id = at.assignment_id
                JOIN themes t ON a.theme_id = t.id
                JOIN users u ON a.teacher_id = u.id
                WHERE " . implode(' AND ', $where) . "
                  AND (at.target_type = 'class' AND at.target_id = :class_id)
                ORDER BY a.created_at DESC
                LIMIT :limit OFFSET :offset";
        $params['class_id'] = $classId;
    } else {
        $sql = "SELECT a.*,
                       t.title as theme_title,
                       CONCAT(u.firstname, ' ', u.lastname) as teacher_name
                FROM assignments a
                JOIN themes t ON a.theme_id = t.id
                JOIN users u ON a.teacher_id = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.created_at DESC
                LIMIT :limit OFFSET :offset";
    }

    $params['limit'] = $limit;
    $params['offset'] = $offset;

    $stmt = db()->getPdo()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $assignments = $stmt->fetchAll();

    // Compter le total
    $whereClause = str_replace('a.', 'assignments.', implode(' AND ', $where));
    $countSql = "SELECT COUNT(DISTINCT assignments.id) as total FROM assignments WHERE $whereClause";
    if ($classId) {
        $countSql = "SELECT COUNT(DISTINCT a.id) as total
                     FROM assignments a
                     JOIN assignment_targets at ON a.id = at.assignment_id
                     WHERE " . implode(' AND ', $where) . "
                       AND (at.target_type = 'class' AND at.target_id = :class_id)";
    }
    $countStmt = db()->getPdo()->prepare($countSql);
    foreach ($params as $key => $value) {
        if ($key !== 'limit' && $key !== 'offset') {
            $countStmt->bindValue(":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
    $countStmt->execute();
    $total = $countStmt->fetch()['total'];

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/assignments', 200, $duration);

    jsonResponse([
        'assignments' => $assignments,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ]
    ]);
}

// ============================================================
// POST /api/assignments - Créer un assignment
// ============================================================
if ($method === 'POST' && !$assignmentId) {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);
    $rbac = enforceRBAC($auth);

    // Permission check
    $rbac->requirePermission('assignments', 'create');

    $tenantId = $tenantContext->getTenantId();
    $teacherId = $rbac->getUserId();

    $body = getRequestBody();

    // Validation
    validateRequired($body, ['title', 'type', 'theme_id', 'targets']);

    $title = sanitize($body['title']);
    $type = $body['type']; // quiz, flashcards, fiche, annales
    $themeId = $body['theme_id'];
    $targets = $body['targets']; // Array of {type: 'student|class|promo', id: 'xxx'}
    $instructions = $body['instructions'] ?? null;
    $dueAt = $body['due_at'] ?? null;
    $mode = $body['mode'] ?? 'post-cours';

    // Valider le type
    if (!in_array($type, ['quiz', 'flashcards', 'fiche', 'annales'])) {
        errorResponse('VALIDATION_ERROR', 'Invalid type', 400);
    }

    // Valider le mode
    if (!in_array($mode, ['post-cours', 'pre-examen', 'revision-generale'])) {
        errorResponse('VALIDATION_ERROR', 'Invalid mode', 400);
    }

    // Vérifier que le thème existe et appartient au tenant
    $theme = db()->queryOne(
        'SELECT id, tenant_id FROM themes WHERE id = :id',
        ['id' => $themeId]
    );

    if (!$theme) {
        errorResponse('NOT_FOUND', 'Theme not found', 404);
    }

    // Verify theme belongs to same tenant
    $tenantContext->verifyOwnership($theme['tenant_id'], 'theme', $theme['id']);

    // Calculer le hash du payload pour idempotence
    $payloadHash = payloadHash([
        'tenant_id' => $tenantId,
        'teacher_id' => $teacherId,
        'theme_id' => $themeId,
        'title' => $title,
        'type' => $type,
        'targets' => $targets,
        'due_at' => $dueAt
    ]);

    // Vérifier si un assignment identique existe déjà (dans les 5 dernières minutes)
    $existingAssignment = db()->queryOne(
        'SELECT id FROM assignments
         WHERE payload_hash = :hash
         AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)',
        ['hash' => $payloadHash]
    );

    if ($existingAssignment) {
        logWarn('Duplicate assignment detected', [
            'assignment_id' => $existingAssignment['id'],
            'payload_hash' => $payloadHash
        ]);
        jsonResponse([
            'assignment_id' => $existingAssignment['id'],
            'status' => 'duplicate',
            'message' => 'Assignment already exists'
        ], 200);
    }

    // Créer l'assignment
    db()->beginTransaction();

    try {
        $assignmentId = generateId('assign');

        db()->execute(
            'INSERT INTO assignments
             (id, tenant_id, teacher_id, theme_id, title, type, mode, due_at, instructions, status, payload_hash, target_count, created_at)
             VALUES (:id, :tenant_id, :teacher_id, :theme_id, :title, :type, :mode, :due_at, :instructions, :status, :payload_hash, :target_count, NOW())',
            [
                'id' => $assignmentId,
                'tenant_id' => $tenantId,
                'teacher_id' => $teacherId,
                'theme_id' => $themeId,
                'title' => $title,
                'type' => $type,
                'mode' => $mode,
                'due_at' => $dueAt,
                'instructions' => $instructions,
                'status' => 'draft',
                'payload_hash' => $payloadHash,
                'target_count' => count($targets)
            ]
        );

        // Insérer les cibles (avec tenant_id pour isolation complète)
        foreach ($targets as $target) {
            if (!isset($target['type']) || !isset($target['id'])) {
                continue;
            }

            db()->execute(
                'INSERT INTO assignment_targets (assignment_id, tenant_id, target_type, target_id)
                 VALUES (:assignment_id, :tenant_id, :target_type, :target_id)',
                [
                    'assignment_id' => $assignmentId,
                    'tenant_id' => $tenantId,
                    'target_type' => $target['type'],
                    'target_id' => $target['id']
                ]
            );
        }

        // Logger dans sync_logs
        $syncLogId = generateId('sync');
        db()->execute(
            'INSERT INTO sync_logs
             (id, tenant_id, triggered_by, direction, type, status, payload, created_at)
             VALUES (:id, :tenant_id, :triggered_by, :direction, :type, :status, :payload, NOW())',
            [
                'id' => $syncLogId,
                'tenant_id' => $tenantId,
                'triggered_by' => $teacherId,
                'direction' => 'push',
                'type' => 'assignment',
                'status' => 'queued',
                'payload' => json_encode([
                    'assignment_id' => $assignmentId,
                    'action' => 'create'
                ])
            ]
        );

        db()->commit();

        logInfo('Assignment created', [
            'assignment_id' => $assignmentId,
            'teacher_id' => $teacherId,
            'tenant_id' => $tenantId
        ]);

        // Déclencher les notifications (async en production)
        require_once __DIR__ . '/../lib/notify.php';
        $notificationService = new NotificationService();
        $notificationService->notifyAssignmentCreated($assignmentId, $targets);

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/assignments', 201, $duration);

        jsonResponse([
            'assignment_id' => $assignmentId,
            'status' => 'created',
            'sync_log_id' => $syncLogId
        ], 201);

    } catch (Exception $e) {
        db()->rollback();
        logError('Failed to create assignment', [
            'error' => $e->getMessage(),
            'tenant_id' => $tenantId
        ]);
        errorResponse('SERVER_ERROR', 'Failed to create assignment', 500);
    }
}

// ============================================================
// GET /api/assignments/{id} - Détails d'un assignment
// ============================================================
if ($method === 'GET' && $assignmentId && !$action) {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);
    $rbac = enforceRBAC($auth);

    // Permission check
    $rbac->requirePermission('assignments', 'read');

    $tenantId = $tenantContext->getTenantId();

    $assignment = db()->queryOne(
        'SELECT a.*,
                t.title as theme_title,
                t.content as theme_content,
                CONCAT(u.firstname, \' \', u.lastname) as teacher_name
         FROM assignments a
         JOIN themes t ON a.theme_id = t.id
         JOIN users u ON a.teacher_id = u.id
         WHERE a.id = :id AND a.tenant_id = :tenant_id',
        ['id' => $assignmentId, 'tenant_id' => $tenantId]
    );

    if (!$assignment) {
        errorResponse('NOT_FOUND', 'Assignment not found', 404);
    }

    // Check ownership if teacher role
    if (!$rbac->can('assignments', 'read_all') && !$rbac->owns('assignments', $assignment)) {
        errorResponse('FORBIDDEN', 'You can only view your own assignments', 403);
    }

    // Récupérer les cibles
    $targets = db()->query(
        'SELECT * FROM assignment_targets WHERE assignment_id = :assignment_id',
        ['assignment_id' => $assignmentId]
    );
    $assignment['targets'] = $targets;

    // Récupérer les statistiques de progression
    $stats = db()->queryOne(
        'SELECT
            COUNT(DISTINCT CASE WHEN event_type = \'received\' THEN student_id END) as received_count,
            COUNT(DISTINCT CASE WHEN event_type = \'opened\' THEN student_id END) as opened_count,
            COUNT(DISTINCT CASE WHEN event_type = \'completed\' THEN student_id END) as completed_count
         FROM assignment_events
         WHERE assignment_id = :assignment_id',
        ['assignment_id' => $assignmentId]
    );
    $assignment['stats'] = $stats;

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/assignments/' . $assignmentId, 200, $duration);

    jsonResponse($assignment);
}

// ============================================================
// GET /api/assignments/{id}/events - Événements de tracking
// ============================================================
if ($method === 'GET' && $assignmentId && $action === 'events') {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);
    $rbac = enforceRBAC($auth);

    // Permission check
    $rbac->requirePermission('assignments', 'read');

    $tenantId = $tenantContext->getTenantId();

    // Vérifier que l'assignment existe et appartient au tenant
    $assignment = db()->queryOne(
        'SELECT id FROM assignments WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $assignmentId, 'tenant_id' => $tenantId]
    );

    if (!$assignment) {
        errorResponse('NOT_FOUND', 'Assignment not found', 404);
    }

    // Récupérer les événements avec les infos des étudiants
    $events = db()->query(
        'SELECT ae.*,
                CONCAT(s.firstname, \' \', s.lastname) as student_name,
                s.email_scolaire as student_email
         FROM assignment_events ae
         JOIN students s ON ae.student_id = s.id
         WHERE ae.assignment_id = :assignment_id
         ORDER BY ae.created_at DESC',
        ['assignment_id' => $assignmentId]
    );

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/assignments/' . $assignmentId . '/events', 200, $duration);

    jsonResponse(['events' => $events]);
}

// ============================================================
// POST /api/assignments/ack - Accusé de réception
// ============================================================
if ($method === 'POST' && $pathParts[3] === 'ack') {
    // Pas d'auth stricte pour permettre à Ergo-Mate d'envoyer les ACK
    // On pourrait utiliser une clé API partagée

    $body = getRequestBody();
    validateRequired($body, ['assignment_id', 'student_id', 'event_type']);

    $assignmentId = $body['assignment_id'];
    $studentId = $body['student_id'];
    $eventType = $body['event_type']; // received, opened, started, in_progress, completed
    $metadata = $body['metadata'] ?? null;

    // Valider l'event_type
    if (!in_array($eventType, ['received', 'opened', 'started', 'in_progress', 'completed', 'error'])) {
        errorResponse('VALIDATION_ERROR', 'Invalid event_type', 400);
    }

    // Vérifier que l'assignment existe
    $assignment = db()->queryOne(
        'SELECT tenant_id FROM assignments WHERE id = :id',
        ['id' => $assignmentId]
    );

    if (!$assignment) {
        errorResponse('NOT_FOUND', 'Assignment not found', 404);
    }

    // Vérifier que l'étudiant existe
    $student = db()->queryOne(
        'SELECT id FROM students WHERE id = :id',
        ['id' => $studentId]
    );

    if (!$student) {
        errorResponse('NOT_FOUND', 'Student not found', 404);
    }

    // Créer l'événement
    $eventId = generateId('evt');
    db()->execute(
        'INSERT INTO assignment_events
         (id, assignment_id, student_id, event_type, metadata, created_at)
         VALUES (:id, :assignment_id, :student_id, :event_type, :metadata, NOW())',
        [
            'id' => $eventId,
            'assignment_id' => $assignmentId,
            'student_id' => $studentId,
            'event_type' => $eventType,
            'metadata' => $metadata ? json_encode($metadata) : null
        ]
    );

    // Mettre à jour les compteurs dans l'assignment
    if ($eventType === 'received') {
        db()->execute(
            'UPDATE assignments
             SET received_count = received_count + 1,
                 ergo_ack_at = NOW()
             WHERE id = :id',
            ['id' => $assignmentId]
        );
    }

    if ($eventType === 'completed') {
        db()->execute(
            'UPDATE assignments
             SET completed_count = completed_count + 1
             WHERE id = :id',
            ['id' => $assignmentId]
        );
    }

    logInfo('Assignment event recorded', [
        'event_id' => $eventId,
        'assignment_id' => $assignmentId,
        'student_id' => $studentId,
        'event_type' => $eventType
    ]);

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/assignments/ack', 201, $duration);

    jsonResponse([
        'event_id' => $eventId,
        'status' => 'recorded'
    ], 201);
}

// ============================================================
// PATCH /api/assignments/{id}/status - Mise à jour du statut
// ============================================================
if ($method === 'PATCH' && $assignmentId && $action === 'status') {
    $body = getRequestBody();
    validateRequired($body, ['status']);

    $status = $body['status'];

    // Valider le statut
    if (!in_array($status, ['draft', 'queued', 'pushed', 'ack', 'error'])) {
        errorResponse('VALIDATION_ERROR', 'Invalid status', 400);
    }

    // Mettre à jour le statut
    $updated = db()->execute(
        'UPDATE assignments
         SET status = :status, updated_at = NOW()
         WHERE id = :id',
        ['id' => $assignmentId, 'status' => $status]
    );

    if ($updated === 0) {
        errorResponse('NOT_FOUND', 'Assignment not found', 404);
    }

    logInfo('Assignment status updated', [
        'assignment_id' => $assignmentId,
        'status' => $status
    ]);

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/assignments/' . $assignmentId . '/status', 200, $duration);

    jsonResponse(['status' => 'updated']);
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
