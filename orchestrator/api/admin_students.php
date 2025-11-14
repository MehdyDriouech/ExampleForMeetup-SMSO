<?php
/**
 * Sprint 15 - Admin Students Management
 *
 * POST   /api/admin/students                  - Create student with UUID
 * GET    /api/admin/students/:uuid/export     - RGPD export
 * PATCH  /api/admin/students/:uuid/pseudonymize - Pseudonymize student
 * DELETE /api/admin/students/:uuid            - Logical delete
 */

require_once __DIR__ . '/../.env.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rbac.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// Security middleware
$tenantContext = enforceTenantIsolation();
$auth = requireAuth();
enforceTenantAuthMatch($tenantContext, $auth);
$rbac = enforceRBAC($auth);

$tenantId = $tenantContext->getTenantId();

// ============================================
// POST /api/admin/students - Create student
// ============================================
if ($method === 'POST' && !isset($_GET['uuid'])) {
    $rbac->requirePermission('students', 'create');

    $body = getRequestBody();

    // Validate required fields
    validateRequired($body, ['firstname', 'lastname', 'class_id']);

    // Validate class belongs to tenant
    $class = db()->queryOne(
        'SELECT id FROM classes WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $body['class_id'], 'tenant_id' => $tenantId]
    );

    if (!$class) {
        errorResponse('VALIDATION_ERROR', 'Class not found or access denied', 403);
    }

    // Generate UUIDs
    $uuidStudent = generateUUID();
    $uuidSocial = generateUUID();

    // Check licence quota
    $licence = db()->queryOne(
        'SELECT max_students,
                (SELECT COUNT(*) FROM students WHERE tenant_id = :tenant_id AND rgpd_status = "active") as used_students
         FROM tenant_licences WHERE tenant_id = :tenant_id',
        ['tenant_id' => $tenantId]
    );

    if ($licence && $licence['used_students'] >= $licence['max_students']) {
        errorResponse('QUOTA_EXCEEDED', 'Student quota exceeded', 403, [
            'max' => $licence['max_students'],
            'used' => $licence['used_students']
        ]);
    }

    // Create student
    $studentData = [
        'id' => generateId('STU'),
        'tenant_id' => $tenantId,
        'uuid_student' => $uuidStudent,
        'uuid_social' => $uuidSocial,
        'firstname' => sanitize($body['firstname']),
        'lastname' => sanitize($body['lastname']),
        'class_id' => $body['class_id'],
        'promo_id' => $body['promo_id'] ?? null,
        'email' => $body['email'] ?? null,
        'phone' => $body['phone'] ?? null,
        'rgpd_status' => 'active',
        'created_at' => date('Y-m-d H:i:s')
    ];

    try {
        db()->beginTransaction();

        $studentId = db()->insert('students', $studentData);

        // Log audit
        db()->insert('audit_logs', [
            'id' => generateUUID(),
            'tenant_id' => $tenantId,
            'actor_user_id' => $auth->getUserId(),
            'action_type' => 'student_created',
            'target_type' => 'student',
            'target_id' => $uuidStudent,
            'payload' => json_encode([
                'firstname' => $studentData['firstname'],
                'lastname' => $studentData['lastname'],
                'class_id' => $studentData['class_id']
            ]),
            'ip_address' => getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'result' => 'success',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        db()->commit();

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/students', 201, $duration);

        jsonResponse([
            'success' => true,
            'data' => [
                'id' => $studentData['id'],
                'uuid_student' => $uuidStudent,
                'uuid_social' => $uuidSocial,
                'firstname' => $studentData['firstname'],
                'lastname' => $studentData['lastname'],
                'class_id' => $studentData['class_id'],
                'rgpd_status' => 'active',
                'created_at' => $studentData['created_at']
            ]
        ], 201);

    } catch (Exception $e) {
        db()->rollback();
        errorResponse('SERVER_ERROR', 'Failed to create student', 500, $e->getMessage());
    }
}

// ============================================
// GET /api/admin/students/:uuid/export - RGPD Export
// ============================================
if ($method === 'GET' && isset($_GET['uuid']) && strpos($_SERVER['REQUEST_URI'], '/export') !== false) {
    $rbac->requirePermission('students', 'read');

    $uuid = $_GET['uuid'];

    // Get student
    $student = db()->queryOne(
        'SELECT s.*, c.name as class_name, p.name as promo_name
         FROM students s
         LEFT JOIN classes c ON s.class_id = c.id
         LEFT JOIN promotions p ON s.promo_id = p.id
         WHERE s.uuid_student = :uuid AND s.tenant_id = :tenant_id',
        ['uuid' => $uuid, 'tenant_id' => $tenantId]
    );

    if (!$student) {
        errorResponse('NOT_FOUND', 'Student not found', 404);
    }

    // Get mission history
    $missions = db()->query(
        'SELECT mission_id, status, score, completed_at, time_spent_seconds
         FROM student_missions
         WHERE student_uuid = :uuid
         ORDER BY completed_at DESC',
        ['uuid' => $uuid]
    );

    // Get social interactions
    $socialStats = db()->queryOne(
        'SELECT
            COUNT(*) as total_interactions,
            SUM(CASE WHEN interaction_type = "like" THEN 1 ELSE 0 END) as likes_given,
            SUM(CASE WHEN interaction_type = "comment" THEN 1 ELSE 0 END) as comments_made
         FROM social_interactions
         WHERE uuid_social = :uuid_social',
        ['uuid_social' => $student['uuid_social']]
    );

    // Get badges earned
    $badges = db()->query(
        'SELECT badge_id, earned_at, criteria_met
         FROM student_badges
         WHERE student_uuid = :uuid
         ORDER BY earned_at DESC',
        ['uuid' => $uuid]
    );

    // Increment export counter
    db()->execute(
        'UPDATE students SET rgpd_export_count = rgpd_export_count + 1 WHERE uuid_student = :uuid',
        ['uuid' => $uuid]
    );

    // Create export request log
    db()->insert('rgpd_export_requests', [
        'id' => generateUUID(),
        'tenant_id' => $tenantId,
        'student_uuid' => $uuid,
        'requested_by' => $auth->getUserId(),
        'status' => 'completed',
        'export_format' => 'json',
        'completed_at' => date('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // Log audit
    db()->insert('audit_logs', [
        'id' => generateUUID(),
        'tenant_id' => $tenantId,
        'actor_user_id' => $auth->getUserId(),
        'action_type' => 'student_data_exported',
        'target_type' => 'student',
        'target_id' => $uuid,
        'result' => 'success',
        'ip_address' => getClientIp(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/admin/students/export', 200, $duration);

    // Build complete export
    jsonResponse([
        'export_date' => date('c'),
        'export_requested_by' => $auth->getUserEmail(),
        'student' => [
            'uuid_student' => $student['uuid_student'],
            'uuid_social' => $student['uuid_social'],
            'firstname' => $student['firstname'],
            'lastname' => $student['lastname'],
            'email' => $student['email'],
            'phone' => $student['phone'],
            'class_name' => $student['class_name'],
            'promo_name' => $student['promo_name'],
            'rgpd_status' => $student['rgpd_status'],
            'created_at' => $student['created_at'],
            'rgpd_export_count' => $student['rgpd_export_count'] + 1
        ],
        'academic_data' => [
            'missions_completed' => count($missions),
            'missions' => $missions
        ],
        'social_data' => $socialStats,
        'achievements' => [
            'badges_earned' => count($badges),
            'badges' => $badges
        ],
        'metadata' => [
            'data_sources' => ['orchestrator_db', 'ergomate_sync'],
            'format' => 'json',
            'version' => '1.0'
        ]
    ]);
}

// ============================================
// PATCH /api/admin/students/:uuid/pseudonymize
// ============================================
if ($method === 'PATCH' && isset($_GET['uuid']) && strpos($_SERVER['REQUEST_URI'], '/pseudonymize') !== false) {
    $rbac->requirePermission('students', 'update');

    $uuid = $_GET['uuid'];

    // Get student
    $student = db()->queryOne(
        'SELECT * FROM students WHERE uuid_student = :uuid AND tenant_id = :tenant_id',
        ['uuid' => $uuid, 'tenant_id' => $tenantId]
    );

    if (!$student) {
        errorResponse('NOT_FOUND', 'Student not found', 404);
    }

    if ($student['rgpd_status'] === 'pseudonymized') {
        errorResponse('VALIDATION_ERROR', 'Student already pseudonymized', 400);
    }

    // Pseudonymize: remove personal data but keep stats
    try {
        db()->beginTransaction();

        db()->update('students', [
            'firstname' => 'ANONYME',
            'lastname' => 'ANONYME',
            'email' => null,
            'phone' => null,
            'rgpd_status' => 'pseudonymized',
            'rgpd_pseudonymized_at' => date('Y-m-d H:i:s')
        ], 'uuid_student = :uuid AND tenant_id = :tenant_id', [
            'uuid' => $uuid,
            'tenant_id' => $tenantId
        ]);

        // Log audit
        db()->insert('audit_logs', [
            'id' => generateUUID(),
            'tenant_id' => $tenantId,
            'actor_user_id' => $auth->getUserId(),
            'action_type' => 'student_pseudonymized',
            'target_type' => 'student',
            'target_id' => $uuid,
            'payload' => json_encode([
                'original_firstname' => $student['firstname'],
                'original_lastname' => $student['lastname']
            ]),
            'result' => 'success',
            'ip_address' => getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        db()->commit();

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/students/pseudonymize', 200, $duration);

        jsonResponse([
            'success' => true,
            'message' => 'Student data pseudonymized',
            'data' => [
                'uuid_student' => $uuid,
                'rgpd_status' => 'pseudonymized',
                'pseudonymized_at' => date('c'),
                'warning' => 'Personal data has been removed. Statistics are preserved.'
            ]
        ]);

    } catch (Exception $e) {
        db()->rollback();
        errorResponse('SERVER_ERROR', 'Failed to pseudonymize student', 500, $e->getMessage());
    }
}

// ============================================
// DELETE /api/admin/students/:uuid - Logical delete
// ============================================
if ($method === 'DELETE' && isset($_GET['uuid'])) {
    $rbac->requirePermission('students', 'delete');

    $uuid = $_GET['uuid'];

    // Get student
    $student = db()->queryOne(
        'SELECT * FROM students WHERE uuid_student = :uuid AND tenant_id = :tenant_id',
        ['uuid' => $uuid, 'tenant_id' => $tenantId]
    );

    if (!$student) {
        errorResponse('NOT_FOUND', 'Student not found', 404);
    }

    if ($student['rgpd_status'] === 'deleted') {
        errorResponse('VALIDATION_ERROR', 'Student already deleted', 400);
    }

    try {
        db()->beginTransaction();

        // Logical delete - mark as deleted but keep data
        db()->update('students', [
            'rgpd_status' => 'deleted',
            'rgpd_deleted_at' => date('Y-m-d H:i:s')
        ], 'uuid_student = :uuid AND tenant_id = :tenant_id', [
            'uuid' => $uuid,
            'tenant_id' => $tenantId
        ]);

        // Log audit
        db()->insert('audit_logs', [
            'id' => generateUUID(),
            'tenant_id' => $tenantId,
            'actor_user_id' => $auth->getUserId(),
            'action_type' => 'student_deleted',
            'target_type' => 'student',
            'target_id' => $uuid,
            'result' => 'success',
            'ip_address' => getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        db()->commit();

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/students/delete', 200, $duration);

        jsonResponse([
            'success' => true,
            'message' => 'Student marked as deleted',
            'data' => [
                'uuid_student' => $uuid,
                'rgpd_status' => 'deleted',
                'deleted_at' => date('c')
            ]
        ]);

    } catch (Exception $e) {
        db()->rollback();
        errorResponse('SERVER_ERROR', 'Failed to delete student', 500, $e->getMessage());
    }
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
