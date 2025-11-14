<?php
/**
 * Admin Classes API - Sprint 14
 *
 * Endpoints:
 * - GET    /api/admin/classes        - List all classes in tenant
 * - POST   /api/admin/classes        - Create a new class
 * - GET    /api/admin/classes/:id    - Get class details
 * - PATCH  /api/admin/classes/:id    - Update class
 * - DELETE /api/admin/classes/:id    - Archive class
 *
 * @version 1.0
 * @date 2025-11-14
 */

require_once __DIR__ . '/../../.env.php';
require_once __DIR__ . '/../_middleware_tenant.php';
require_once __DIR__ . '/../_middleware_rbac.php';
require_once __DIR__ . '/../../services/audit_log.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// Parse path to get class ID if present
$requestUri = $_SERVER['REQUEST_URI'];
$pathParts = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));
$classId = null;

// Extract class ID from path: /api/admin/classes/:id
if (count($pathParts) >= 4 && $pathParts[0] === 'api' && $pathParts[1] === 'admin' && $pathParts[2] === 'classes') {
    if (isset($pathParts[3]) && $pathParts[3] !== '') {
        $classId = $pathParts[3];
    }
}

// Authentication & Authorization
$auth = requireAuth();
$tenantContext = enforceTenantIsolation();
enforceTenantAuthMatch($tenantContext, $auth);
$rbac = enforceRBAC($auth);

$tenantId = $tenantContext->getTenantId();
$auditLog = createAuditLog($tenantId, $auth);

// ============================================================
// GET /api/admin/classes - List classes
// ============================================================
if ($method === 'GET' && !$classId) {
    $rbac->requirePermission('classes', 'read');

    try {
        $filters = [];
        $params = ['tenant_id' => $tenantId];

        // Filter by status
        if (isset($_GET['status']) && !empty($_GET['status'])) {
            $filters[] = 'c.status = :status';
            $params['status'] = $_GET['status'];
        }

        // Filter by promotion
        if (isset($_GET['promo_id']) && !empty($_GET['promo_id'])) {
            $filters[] = 'c.promo_id = :promo_id';
            $params['promo_id'] = $_GET['promo_id'];
        }

        // Search by name
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $search = '%' . $_GET['search'] . '%';
            $filters[] = '(c.name LIKE :search OR c.description LIKE :search)';
            $params['search'] = $search;
        }

        $whereClause = 'c.tenant_id = :tenant_id';
        if (count($filters) > 0) {
            $whereClause .= ' AND ' . implode(' AND ', $filters);
        }

        // Get classes with counts
        $sql = "SELECT c.*,
                       p.name as promo_name,
                       p.level as promo_level,
                       COUNT(DISTINCT s.id) as student_count,
                       COUNT(DISTINCT uca.user_id) as teacher_count,
                       GROUP_CONCAT(DISTINCT u.firstname, ' ', u.lastname SEPARATOR ', ') as teacher_names
                FROM classes c
                LEFT JOIN promotions p ON c.promo_id = p.id
                LEFT JOIN students s ON c.id = s.class_id AND s.status = 'active'
                LEFT JOIN user_class_assignments uca ON c.id = uca.class_id
                LEFT JOIN users u ON uca.user_id = u.id AND u.status = 'active'
                WHERE {$whereClause}
                GROUP BY c.id
                ORDER BY c.created_at DESC";

        $classes = db()->query($sql, $params);

        // Format response
        $result = array_map(function($class) {
            return [
                'id' => $class['id'],
                'name' => $class['name'],
                'description' => $class['description'],
                'promoId' => $class['promo_id'],
                'promoName' => $class['promo_name'],
                'level' => $class['promo_level'],
                'status' => $class['status'],
                'studentCount' => (int)$class['student_count'],
                'teacherCount' => (int)$class['teacher_count'],
                'teacherNames' => $class['teacher_names'],
                'createdAt' => $class['created_at'],
                'updatedAt' => $class['updated_at']
            ];
        }, $classes);

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/classes', 200, $duration);

        jsonResponse([
            'classes' => $result,
            'total' => count($result)
        ]);
    } catch (Exception $e) {
        logError('Failed to list classes', ['error' => $e->getMessage()]);
        errorResponse('SERVER_ERROR', 'Failed to retrieve classes', 500);
    }
}

// ============================================================
// GET /api/admin/classes/:id - Get class details
// ============================================================
if ($method === 'GET' && $classId) {
    $rbac->requirePermission('classes', 'read');

    try {
        $class = db()->queryOne(
            "SELECT c.*, p.name as promo_name, p.level as promo_level
             FROM classes c
             LEFT JOIN promotions p ON c.promo_id = p.id
             WHERE c.id = :id AND c.tenant_id = :tenant_id",
            ['id' => $classId, 'tenant_id' => $tenantId]
        );

        if (!$class) {
            errorResponse('NOT_FOUND', 'Class not found', 404);
        }

        // Get students
        $students = db()->query(
            "SELECT id, uuid_scolaire, email_scolaire, firstname, lastname, status
             FROM students
             WHERE class_id = :class_id
             ORDER BY lastname, firstname",
            ['class_id' => $classId]
        );

        // Get teachers
        $teachers = db()->query(
            "SELECT u.id, u.email, u.firstname, u.lastname, u.role, uca.is_primary
             FROM user_class_assignments uca
             JOIN users u ON uca.user_id = u.id
             WHERE uca.class_id = :class_id AND u.status = 'active'
             ORDER BY uca.is_primary DESC, u.lastname",
            ['class_id' => $classId]
        );

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest("/api/admin/classes/{$classId}", 200, $duration);

        jsonResponse([
            'id' => $class['id'],
            'name' => $class['name'],
            'description' => $class['description'],
            'promoId' => $class['promo_id'],
            'promoName' => $class['promo_name'],
            'level' => $class['promo_level'],
            'status' => $class['status'],
            'createdAt' => $class['created_at'],
            'updatedAt' => $class['updated_at'],
            'students' => $students,
            'teachers' => $teachers
        ]);
    } catch (Exception $e) {
        logError('Failed to get class', ['error' => $e->getMessage(), 'class_id' => $classId]);
        errorResponse('SERVER_ERROR', 'Failed to retrieve class', 500);
    }
}

// ============================================================
// POST /api/admin/classes - Create class
// ============================================================
if ($method === 'POST' && !$classId) {
    $rbac->requirePermission('classes', 'create');

    try {
        $body = getRequestBody();

        // Validate required fields
        $required = ['name', 'promo_id'];
        foreach ($required as $field) {
            if (!isset($body[$field]) || empty($body[$field])) {
                errorResponse('VALIDATION_ERROR', "Missing required field: {$field}", 400);
            }
        }

        $name = trim($body['name']);
        $promoId = $body['promo_id'];
        $description = $body['description'] ?? null;
        $teacherIds = $body['teacher_ids'] ?? [];

        // Verify promotion exists
        $promo = db()->queryOne(
            'SELECT id, name, level FROM promotions WHERE id = :id AND tenant_id = :tenant_id',
            ['id' => $promoId, 'tenant_id' => $tenantId]
        );

        if (!$promo) {
            errorResponse('NOT_FOUND', 'Promotion not found', 404);
        }

        // Check licence quotas
        $licences = db()->queryOne(
            'SELECT * FROM tenant_licences WHERE tenant_id = :tenant_id',
            ['tenant_id' => $tenantId]
        );

        if ($licences && $licences['used_classes'] >= $licences['max_classes']) {
            errorResponse('QUOTA_EXCEEDED', 'Class quota exceeded', 403);
        }

        db()->beginTransaction();

        // Create class
        $newClassId = 'class_' . uniqid() . '_' . bin2hex(random_bytes(8));

        db()->insert('classes', [
            'id' => $newClassId,
            'tenant_id' => $tenantId,
            'promo_id' => $promoId,
            'name' => $name,
            'description' => $description,
            'status' => 'active'
        ]);

        // Assign teachers if provided
        if (!empty($teacherIds) && is_array($teacherIds)) {
            foreach ($teacherIds as $index => $teacherId) {
                db()->insert('user_class_assignments', [
                    'user_id' => $teacherId,
                    'class_id' => $newClassId,
                    'tenant_id' => $tenantId,
                    'is_primary' => ($index === 0) // First teacher is primary
                ]);
            }
        }

        // Update licence usage
        if ($licences) {
            db()->execute(
                'UPDATE tenant_licences SET used_classes = used_classes + 1 WHERE tenant_id = :tenant_id',
                ['tenant_id' => $tenantId]
            );
        }

        // Audit log
        $auditLog->logClassCreate($newClassId, $name, $promo['level']);

        db()->commit();

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/admin/classes', 201, $duration);

        jsonResponse([
            'id' => $newClassId,
            'name' => $name,
            'promoId' => $promoId,
            'promoName' => $promo['name'],
            'level' => $promo['level'],
            'status' => 'active',
            'created' => true
        ], 201);
    } catch (Exception $e) {
        db()->rollback();
        logError('Failed to create class', ['error' => $e->getMessage()]);
        $auditLog->logFailed('class_create', 'class', null, $e->getMessage(), ['name' => $name ?? null]);
        errorResponse('SERVER_ERROR', 'Failed to create class: ' . $e->getMessage(), 500);
    }
}

// ============================================================
// PATCH /api/admin/classes/:id - Update class
// ============================================================
if ($method === 'PATCH' && $classId) {
    $rbac->requirePermission('classes', 'update');

    try {
        $body = getRequestBody();

        // Check if class exists
        $class = db()->queryOne(
            'SELECT * FROM classes WHERE id = :id AND tenant_id = :tenant_id',
            ['id' => $classId, 'tenant_id' => $tenantId]
        );

        if (!$class) {
            errorResponse('NOT_FOUND', 'Class not found', 404);
        }

        $updates = [];
        $changes = [];

        // Update name
        if (isset($body['name'])) {
            $updates['name'] = trim($body['name']);
            $changes['name'] = $updates['name'];
        }

        // Update description
        if (isset($body['description'])) {
            $updates['description'] = $body['description'];
            $changes['description'] = $updates['description'];
        }

        // Update status (archive)
        if (isset($body['status'])) {
            $validStatuses = ['active', 'archived'];
            if (!in_array($body['status'], $validStatuses)) {
                errorResponse('VALIDATION_ERROR', 'Invalid status', 400);
            }
            $updates['status'] = $body['status'];
            $changes['status'] = ['from' => $class['status'], 'to' => $body['status']];
        }

        db()->beginTransaction();

        // Apply updates
        if (!empty($updates)) {
            db()->update('classes', $updates, 'id = :id', ['id' => $classId]);
        }

        // Update teacher assignments
        if (isset($body['teacher_ids'])) {
            // Remove existing assignments
            db()->delete('user_class_assignments', 'class_id = :class_id', ['class_id' => $classId]);

            // Add new assignments
            if (!empty($body['teacher_ids']) && is_array($body['teacher_ids'])) {
                foreach ($body['teacher_ids'] as $index => $teacherId) {
                    db()->insert('user_class_assignments', [
                        'user_id' => $teacherId,
                        'class_id' => $classId,
                        'tenant_id' => $tenantId,
                        'is_primary' => ($index === 0)
                    ]);
                }
            }

            $changes['teacher_ids'] = $body['teacher_ids'];
        }

        // Audit log
        if (isset($updates['status']) && $updates['status'] === 'archived') {
            $auditLog->logClassArchive($classId, $class['name']);
        } else {
            $auditLog->logClassUpdate($classId, $changes);
        }

        db()->commit();

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest("/api/admin/classes/{$classId}", 200, $duration);

        jsonResponse([
            'id' => $classId,
            'updated' => true,
            'changes' => $changes
        ]);
    } catch (Exception $e) {
        db()->rollback();
        logError('Failed to update class', ['error' => $e->getMessage(), 'class_id' => $classId]);
        $auditLog->logFailed('class_update', 'class', $classId, $e->getMessage());
        errorResponse('SERVER_ERROR', 'Failed to update class', 500);
    }
}

// ============================================================
// DELETE /api/admin/classes/:id - Archive class
// ============================================================
if ($method === 'DELETE' && $classId) {
    $rbac->requirePermission('classes', 'delete');

    try {
        // Check if class exists
        $class = db()->queryOne(
            'SELECT * FROM classes WHERE id = :id AND tenant_id = :tenant_id',
            ['id' => $classId, 'tenant_id' => $tenantId]
        );

        if (!$class) {
            errorResponse('NOT_FOUND', 'Class not found', 404);
        }

        db()->beginTransaction();

        // Archive class (don't delete to preserve stats)
        db()->update('classes',
            ['status' => 'archived'],
            'id = :id',
            ['id' => $classId]
        );

        // Audit log
        $auditLog->logClassArchive($classId, $class['name']);

        db()->commit();

        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest("/api/admin/classes/{$classId}", 200, $duration);

        jsonResponse([
            'id' => $classId,
            'archived' => true
        ]);
    } catch (Exception $e) {
        db()->rollback();
        logError('Failed to archive class', ['error' => $e->getMessage(), 'class_id' => $classId]);
        errorResponse('SERVER_ERROR', 'Failed to archive class', 500);
    }
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
