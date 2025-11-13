<?php
/**
 * API Student Missions - E5-MISSIONS
 * Sprint 5: Learning Cycle - Student Side
 *
 * GET /api/student/missions/pull - Get missions for a student
 * PATCH /api/student/missions/{id}/status - Update mission status (local)
 */

require_once __DIR__ . '/../../.env.php';
require_once __DIR__ . '/../_middleware_tenant.php';
require_once __DIR__ . '/../_middleware_rbac.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);
$uri = $_SERVER['REQUEST_URI'];

// Parse URI
$path = parse_url($uri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$missionId = $pathParts[4] ?? null; // /api/student/missions/{id}
$action = $pathParts[5] ?? null; // /api/student/missions/{id}/status

// ============================================================
// GET /api/student/missions/pull - Pull missions for student
// ============================================================
if ($method === 'GET' && $pathParts[3] === 'pull') {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);

    $tenantId = $tenantContext->getTenantId();

    // Get student ID from query or auth context
    $studentId = $_GET['student_id'] ?? null;

    if (!$studentId) {
        errorResponse('VALIDATION_ERROR', 'student_id is required', 400);
    }

    // Verify student belongs to tenant
    $student = db()->queryOne(
        'SELECT id, tenant_id, uuid_scolaire, email_scolaire, firstname, lastname, class_id
         FROM students
         WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $studentId, 'tenant_id' => $tenantId]
    );

    if (!$student) {
        errorResponse('NOT_FOUND', 'Student not found', 404);
    }

    // Get missions (assignments) for this student
    // Missions can be targeted to: student, class, or promo
    $sql = "
        SELECT DISTINCT
            a.id,
            a.title,
            a.type,
            a.mode,
            a.due_at,
            a.instructions,
            a.created_at,
            t.id as theme_id,
            t.title as theme_title,
            t.difficulty as theme_difficulty,
            CONCAT(u.firstname, ' ', u.lastname) as teacher_name,
            -- Check completion status
            COALESCE(se.status, 'a_faire') as local_status,
            se.started_at,
            se.completed_at,
            se.score,
            se.time_spent
        FROM assignments a
        JOIN assignment_targets at ON a.id = at.assignment_id
        JOIN themes t ON a.theme_id = t.id
        JOIN users u ON a.teacher_id = u.id
        LEFT JOIN student_sessions se ON se.assignment_id = a.id AND se.student_id = :student_id
        WHERE a.tenant_id = :tenant_id
          AND a.status IN ('pushed', 'ack')
          AND (
            (at.target_type = 'student' AND at.target_id = :student_id)
            OR (at.target_type = 'class' AND at.target_id = :class_id)
            OR (at.target_type = 'promo' AND at.target_id IN (
                SELECT promo_id FROM students WHERE id = :student_id
            ))
          )
        ORDER BY
            CASE WHEN a.due_at IS NULL THEN 1 ELSE 0 END,
            a.due_at ASC,
            a.created_at DESC
    ";

    $missions = db()->query($sql, [
        'tenant_id' => $tenantId,
        'student_id' => $studentId,
        'class_id' => $student['class_id']
    ]);

    // Add additional metadata
    foreach ($missions as &$mission) {
        $mission['is_overdue'] = $mission['due_at'] && strtotime($mission['due_at']) < time();
        $mission['progress_percent'] = 0;

        if ($mission['local_status'] === 'en_cours' && $mission['time_spent']) {
            // Estimate progress based on time spent (rough estimate)
            $mission['progress_percent'] = min(100, ($mission['time_spent'] / 600) * 100); // assume 10min average
        } elseif ($mission['local_status'] === 'terminee') {
            $mission['progress_percent'] = 100;
        }
    }

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/student/missions/pull', 200, $duration);

    jsonResponse([
        'student' => [
            'id' => $student['id'],
            'firstname' => $student['firstname'],
            'lastname' => $student['lastname'],
            'uuid_scolaire' => $student['uuid_scolaire']
        ],
        'missions' => $missions,
        'count' => count($missions),
        'synced_at' => date('c')
    ]);
}

// ============================================================
// PATCH /api/student/missions/{id}/status - Update local status
// ============================================================
if ($method === 'PATCH' && $missionId && $action === 'status') {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);

    $tenantId = $tenantContext->getTenantId();
    $body = getRequestBody();

    validateRequired($body, ['student_id', 'status']);

    $studentId = $body['student_id'];
    $status = $body['status']; // a_faire, en_cours, terminee

    // Validate status
    if (!in_array($status, ['a_faire', 'en_cours', 'terminee'])) {
        errorResponse('VALIDATION_ERROR', 'Invalid status. Must be: a_faire, en_cours, terminee', 400);
    }

    // Verify assignment exists and belongs to tenant
    $assignment = db()->queryOne(
        'SELECT id FROM assignments WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $missionId, 'tenant_id' => $tenantId]
    );

    if (!$assignment) {
        errorResponse('NOT_FOUND', 'Mission not found', 404);
    }

    // Verify student belongs to tenant
    $student = db()->queryOne(
        'SELECT id FROM students WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $studentId, 'tenant_id' => $tenantId]
    );

    if (!$student) {
        errorResponse('NOT_FOUND', 'Student not found', 404);
    }

    // Update or insert session status
    $existingSession = db()->queryOne(
        'SELECT id FROM student_sessions WHERE student_id = :student_id AND assignment_id = :assignment_id',
        ['student_id' => $studentId, 'assignment_id' => $missionId]
    );

    if ($existingSession) {
        // Update existing session
        $updates = ['status' => $status];

        if ($status === 'en_cours') {
            $updates['started_at'] = date('Y-m-d H:i:s');
        } elseif ($status === 'terminee') {
            $updates['completed_at'] = date('Y-m-d H:i:s');
        }

        $updateFields = [];
        foreach ($updates as $key => $value) {
            $updateFields[] = "$key = :$key";
        }

        db()->execute(
            'UPDATE student_sessions SET ' . implode(', ', $updateFields) . ' WHERE id = :id',
            array_merge($updates, ['id' => $existingSession['id']])
        );

        $sessionId = $existingSession['id'];
    } else {
        // Insert new session
        $sessionId = generateId('sess');
        $data = [
            'id' => $sessionId,
            'student_id' => $studentId,
            'assignment_id' => $missionId,
            'tenant_id' => $tenantId,
            'status' => $status,
            'created_at' => date('Y-m-d H:i:s')
        ];

        if ($status === 'en_cours') {
            $data['started_at'] = date('Y-m-d H:i:s');
        } elseif ($status === 'terminee') {
            $data['completed_at'] = date('Y-m-d H:i:s');
        }

        db()->execute(
            'INSERT INTO student_sessions (id, student_id, assignment_id, tenant_id, status, created_at, started_at, completed_at)
             VALUES (:id, :student_id, :assignment_id, :tenant_id, :status, :created_at, :started_at, :completed_at)',
            $data
        );
    }

    logInfo('Mission status updated', [
        'mission_id' => $missionId,
        'student_id' => $studentId,
        'status' => $status
    ]);

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/student/missions/' . $missionId . '/status', 200, $duration);

    jsonResponse([
        'session_id' => $sessionId,
        'status' => 'updated',
        'new_status' => $status
    ]);
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
