<?php
/**
 * API Student Sync - E5-SYNC
 * Sprint 5: Learning Cycle - Auto push student results
 *
 * POST /api/student/sync/push - Push student session results to Orchestrator
 */

require_once __DIR__ . '/../../.env.php';
require_once __DIR__ . '/../_middleware_tenant.php';
require_once __DIR__ . '/../_middleware_rbac.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// ============================================================
// POST /api/student/sync/push - Push session results
// ============================================================
if ($method === 'POST') {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    // Allow both authenticated users and API key auth
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);

    $tenantId = $tenantContext->getTenantId();
    $body = getRequestBody();

    // Validation
    validateRequired($body, [
        'student_id',
        'assignment_id',
        'session_data'
    ]);

    $studentId = $body['student_id'];
    $assignmentId = $body['assignment_id'];
    $sessionData = $body['session_data'];

    // Validate session data structure
    validateRequired($sessionData, ['score', 'time_spent', 'ended_at']);

    // Verify student exists and belongs to tenant
    $student = db()->queryOne(
        'SELECT id, uuid_scolaire, email_scolaire FROM students
         WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $studentId, 'tenant_id' => $tenantId]
    );

    if (!$student) {
        errorResponse('NOT_FOUND', 'Student not found', 404);
    }

    // Verify assignment exists and belongs to tenant
    $assignment = db()->queryOne(
        'SELECT id, theme_id FROM assignments
         WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $assignmentId, 'tenant_id' => $tenantId]
    );

    if (!$assignment) {
        errorResponse('NOT_FOUND', 'Assignment not found', 404);
    }

    // Start transaction
    db()->beginTransaction();

    try {
        // Generate IDs
        $sessionId = generateId('sess');
        $syncLogId = generateId('sync');

        // Insert or update student_sessions
        $existingSession = db()->queryOne(
            'SELECT id FROM student_sessions
             WHERE student_id = :student_id AND assignment_id = :assignment_id',
            ['student_id' => $studentId, 'assignment_id' => $assignmentId]
        );

        if ($existingSession) {
            // Update existing session
            db()->execute(
                'UPDATE student_sessions
                 SET status = :status,
                     score = :score,
                     time_spent = :time_spent,
                     completed_at = :completed_at,
                     errors = :errors,
                     correct_answers = :correct_answers,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => $existingSession['id'],
                    'status' => 'terminee',
                    'score' => $sessionData['score'],
                    'time_spent' => $sessionData['time_spent'],
                    'completed_at' => $sessionData['ended_at'],
                    'errors' => isset($sessionData['errors']) ? json_encode($sessionData['errors']) : null,
                    'correct_answers' => $sessionData['correct_answers'] ?? null
                ]
            );
            $sessionId = $existingSession['id'];
        } else {
            // Insert new session
            db()->execute(
                'INSERT INTO student_sessions
                 (id, student_id, assignment_id, tenant_id, status, score, time_spent,
                  started_at, completed_at, errors, correct_answers, created_at)
                 VALUES (:id, :student_id, :assignment_id, :tenant_id, :status, :score,
                         :time_spent, :started_at, :completed_at, :errors, :correct_answers, NOW())',
                [
                    'id' => $sessionId,
                    'student_id' => $studentId,
                    'assignment_id' => $assignmentId,
                    'tenant_id' => $tenantId,
                    'status' => 'terminee',
                    'score' => $sessionData['score'],
                    'time_spent' => $sessionData['time_spent'],
                    'started_at' => $sessionData['started_at'] ?? $sessionData['ended_at'],
                    'completed_at' => $sessionData['ended_at'],
                    'errors' => isset($sessionData['errors']) ? json_encode($sessionData['errors']) : null,
                    'correct_answers' => $sessionData['correct_answers'] ?? null
                ]
            );
        }

        // Update or insert stats
        $existingStats = db()->queryOne(
            'SELECT id FROM stats
             WHERE student_id = :student_id AND theme_id = :theme_id',
            ['student_id' => $studentId, 'theme_id' => $assignment['theme_id']]
        );

        if ($existingStats) {
            // Update stats - increment attempts, update score/mastery
            db()->execute(
                'UPDATE stats
                 SET attempts = attempts + 1,
                     score = (score * attempts + :new_score) / (attempts + 1),
                     mastery = GREATEST(mastery, :new_mastery),
                     time_spent = time_spent + :time_spent,
                     last_activity_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => $existingStats['id'],
                    'new_score' => $sessionData['score'],
                    'new_mastery' => $sessionData['mastery'] ?? ($sessionData['score'] / 100),
                    'time_spent' => $sessionData['time_spent']
                ]
            );
        } else {
            // Insert new stats
            $statsId = generateId('stat');
            db()->execute(
                'INSERT INTO stats
                 (id, student_id, theme_id, tenant_id, attempts, score, mastery,
                  time_spent, last_activity_at, created_at)
                 VALUES (:id, :student_id, :theme_id, :tenant_id, 1, :score, :mastery,
                         :time_spent, NOW(), NOW())',
                [
                    'id' => $statsId,
                    'student_id' => $studentId,
                    'theme_id' => $assignment['theme_id'],
                    'tenant_id' => $tenantId,
                    'score' => $sessionData['score'],
                    'mastery' => $sessionData['mastery'] ?? ($sessionData['score'] / 100),
                    'time_spent' => $sessionData['time_spent']
                ]
            );
        }

        // Log sync operation
        db()->execute(
            'INSERT INTO sync_logs
             (id, tenant_id, direction, type, status, payload, created_at)
             VALUES (:id, :tenant_id, :direction, :type, :status, :payload, NOW())',
            [
                'id' => $syncLogId,
                'tenant_id' => $tenantId,
                'direction' => 'push',
                'type' => 'stats',
                'status' => 'ok',
                'payload' => json_encode([
                    'session_id' => $sessionId,
                    'student_id' => $studentId,
                    'assignment_id' => $assignmentId,
                    'score' => $sessionData['score']
                ])
            ]
        );

        // Check and award badges
        require_once __DIR__ . '/../../lib/badges.php';
        $badgeService = new BadgeService();
        $newBadges = $badgeService->checkAndAwardBadges($studentId, $tenantId);

        db()->commit();

        logInfo('Student results pushed successfully', [
            'session_id' => $sessionId,
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
            'score' => $sessionData['score']
        ]);

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/student/sync/push', 202, $duration);

        jsonResponse([
            'status' => 'accepted',
            'session_id' => $sessionId,
            'sync_log_id' => $syncLogId,
            'new_badges' => $newBadges ?? [],
            'message' => 'Results synchronized successfully'
        ], 202);

    } catch (Exception $e) {
        db()->rollback();

        // Log failed sync for retry
        $syncLogId = generateId('sync');
        try {
            db()->execute(
                'INSERT INTO sync_logs
                 (id, tenant_id, direction, type, status, error_message, payload, created_at)
                 VALUES (:id, :tenant_id, :direction, :type, :status, :error_message, :payload, NOW())',
                [
                    'id' => $syncLogId,
                    'tenant_id' => $tenantId,
                    'direction' => 'push',
                    'type' => 'stats',
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                    'payload' => json_encode($body)
                ]
            );
        } catch (Exception $logError) {
            // Ignore log errors
        }

        logError('Failed to push student results', [
            'error' => $e->getMessage(),
            'student_id' => $studentId,
            'assignment_id' => $assignmentId
        ]);

        errorResponse('SERVER_ERROR', 'Failed to push results: ' . $e->getMessage(), 500);
    }
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
