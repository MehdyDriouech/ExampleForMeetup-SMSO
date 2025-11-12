<?php
/**
 * GET /api/students - Lister les élèves d'une classe
 * GET /api/students/{id} - Détails d'un élève
 */

require_once __DIR__ . '/../.env.php';
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rbac.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

if ($method === 'GET') {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);
    $rbac = enforceRBAC($auth);

    // Permission check
    $rbac->requirePermission('students', 'read');

    $tenantId = $tenantContext->getTenantId();
    
    // GET /api/students/{id}
    if (isset($_GET['id']) && $_GET['id']) {
        $student = db()->queryOne(
            'SELECT s.*, c.name as class_name, p.name as promo_name
             FROM students s
             LEFT JOIN classes c ON s.class_id = c.id
             LEFT JOIN promotions p ON s.promo_id = p.id
             WHERE s.id = :id AND s.tenant_id = :tenant_id',
            ['id' => $_GET['id'], 'tenant_id' => $tenantId]
        );
        
        if (!$student) {
            errorResponse('NOT_FOUND', 'Student not found', 404);
        }
        
        $duration = (microtime(true) - $start) * 1000;
        logger()->logRequest('/api/students/' . $_GET['id'], 200, $duration);
        
        jsonResponse(['data' => $student]);
    }
    
    // GET /api/students (liste)
    $classId = $_GET['classId'] ?? null;
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);
    
    if (!$classId) {
        errorResponse('VALIDATION_ERROR', 'classId parameter is required', 400);
    }
    
    // Vérifier que la classe appartient au tenant
    $class = db()->queryOne(
        'SELECT id FROM classes WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $classId, 'tenant_id' => $tenantId]
    );
    
    if (!$class) {
        errorResponse('FORBIDDEN', 'Class not found or access denied', 403);
    }
    
    $sql = 'SELECT s.*, c.name as class_name, p.name as promo_name
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            LEFT JOIN promotions p ON s.promo_id = p.id
            WHERE s.class_id = :class_id AND s.tenant_id = :tenant_id
            ORDER BY s.lastname, s.firstname';
    
    $students = db()->query($sql, [
        'class_id' => $classId,
        'tenant_id' => $tenantId
    ]);
    
    $total = count($students);
    $studentsPage = array_slice($students, $offset, $limit);
    
    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/students', 200, $duration, [
        'class_id' => $classId,
        'count' => count($studentsPage)
    ]);
    
    jsonResponse([
        'data' => $studentsPage,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]
    ]);
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
