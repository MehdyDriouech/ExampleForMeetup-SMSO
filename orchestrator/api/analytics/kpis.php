<?php
/**
 * GET /api/analytics/kpis - Learning Analytics KPIs
 *
 * Sprint 6: Dashboard Profs - KPIs consolidés
 * Returns aggregated learning analytics with filters
 * Requires teacher, direction, or admin role
 */

require_once __DIR__ . '/../../.env.php';
require_once __DIR__ . '/../_middleware_telemetry.php';
require_once __DIR__ . '/../_middleware_tenant.php';
require_once __DIR__ . '/../_middleware_rbac.php';

setCorsHeaders();

$telemetry = startTelemetry();
$method = $_SERVER['REQUEST_METHOD'];

// Only GET method allowed
if ($method !== 'GET') {
    $telemetry->end(405);
    errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
}

try {
    // Enforce authentication and RBAC
    $auth = requireAuth();
    $telemetry->setUser($auth->getUser()['id'] ?? null);

    // Enforce tenant isolation
    $tenantContext = enforceTenantIsolation();
    $telemetry->setTenant($tenantContext->getTenantId());

    // Check permissions - teachers, direction, and admin can view analytics
    requirePermission($auth, 'analytics', 'read');

    $tenantId = $tenantContext->getTenantId();
    $currentUser = $auth->getUser();

    // Get query parameters for filtering
    $classId = $_GET['class_id'] ?? null;
    $themeId = $_GET['theme_id'] ?? null;
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');

    $db = db();

    // Check cache first (5 min TTL as per requirements)
    $cacheKey = "analytics_kpis_{$tenantId}_{$classId}_{$themeId}_{$startDate}_{$endDate}";
    $cachedData = getCached($cacheKey);

    if ($cachedData !== null) {
        logInfo("Analytics KPIs cache hit", ['cache_key' => $cacheKey, 'tenant_id' => $tenantId]);
        $telemetry->end(200);
        jsonResponse([
            'tenant_id' => $tenantId,
            'filters' => [
                'class_id' => $classId,
                'theme_id' => $themeId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'kpis' => $cachedData,
            'cached' => true
        ]);
    }

    // Build WHERE clause for filters
    $whereConditions = ["s.tenant_id = :tenant_id"];
    $params = ['tenant_id' => $tenantId];

    // Teacher ownership: teachers can only see their own classes
    if ($currentUser['role'] === 'teacher') {
        $whereConditions[] = "c.teacher_id = :teacher_id";
        $params['teacher_id'] = $currentUser['id'];
    }

    if ($classId) {
        $whereConditions[] = "s.class_id = :class_id";
        $params['class_id'] = $classId;
    }

    if ($themeId) {
        $whereConditions[] = "st.theme_id = :theme_id";
        $params['theme_id'] = $themeId;
    }

    if ($startDate && $endDate) {
        $whereConditions[] = "DATE(st.synced_at) BETWEEN :start_date AND :end_date";
        $params['start_date'] = $startDate;
        $params['end_date'] = $endDate;
    }

    $whereClause = implode(' AND ', $whereConditions);

    // KPI 1: Total Students & Active Students
    $studentKpis = $db->queryOne(
        "SELECT
            COUNT(DISTINCT s.id) as total_students,
            COUNT(DISTINCT CASE WHEN st.last_activity_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN s.id END) as active_students
         FROM students s
         LEFT JOIN stats st ON st.student_id = s.id
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE {$whereClause}",
        $params
    );

    // KPI 2: Average Score & Success Rate
    $scoreKpis = $db->queryOne(
        "SELECT
            AVG(st.score) as avg_score,
            AVG(CASE WHEN st.score >= 50 THEN 1 ELSE 0 END) * 100 as success_rate,
            AVG(st.mastery) as avg_mastery
         FROM stats st
         INNER JOIN students s ON s.id = st.student_id
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE {$whereClause} AND st.theme_id IS NOT NULL",
        $params
    );

    // KPI 3: Sessions Statistics
    $sessionKpis = $db->queryOne(
        "SELECT
            COUNT(*) as total_sessions,
            COUNT(CASE WHEN ss.status = 'terminee' THEN 1 END) as completed_sessions,
            AVG(ss.score) as avg_session_score,
            SUM(ss.time_spent) as total_time_spent
         FROM student_sessions ss
         INNER JOIN students s ON s.id = ss.student_id
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE ss.tenant_id = :tenant_id
           AND DATE(ss.created_at) BETWEEN :start_date AND :end_date
           " . ($currentUser['role'] === 'teacher' ? "AND c.teacher_id = :teacher_id" : "") . "
           " . ($classId ? "AND s.class_id = :class_id" : "") . "",
        $params
    );

    // KPI 4: Assignments Statistics
    $assignmentKpis = $db->queryOne(
        "SELECT
            COUNT(*) as total_assignments,
            COUNT(CASE WHEN a.status = 'pushed' OR a.status = 'ack' THEN 1 END) as active_assignments,
            COUNT(CASE WHEN a.status = 'queued' THEN 1 END) as pending_assignments,
            AVG(a.received_count) as avg_received_count,
            AVG(a.completed_count) as avg_completed_count
         FROM assignments a
         WHERE a.tenant_id = :tenant_id
           " . ($currentUser['role'] === 'teacher' ? "AND a.teacher_id = :teacher_id" : "") . "
           " . ($themeId ? "AND a.theme_id = :theme_id" : "") . "",
        $params
    );

    // Build final KPIs response
    $kpis = [
        'students' => [
            'total' => (int)($studentKpis['total_students'] ?? 0),
            'active' => (int)($studentKpis['active_students'] ?? 0),
            'active_rate' => $studentKpis['total_students'] > 0
                ? round(($studentKpis['active_students'] / $studentKpis['total_students']) * 100, 1)
                : 0
        ],
        'performance' => [
            'avg_score' => round($scoreKpis['avg_score'] ?? 0, 1),
            'success_rate' => round($scoreKpis['success_rate'] ?? 0, 1),
            'avg_mastery' => round(($scoreKpis['avg_mastery'] ?? 0) * 100, 1) // Convert 0-1 to 0-100
        ],
        'sessions' => [
            'total' => (int)($sessionKpis['total_sessions'] ?? 0),
            'completed' => (int)($sessionKpis['completed_sessions'] ?? 0),
            'completion_rate' => $sessionKpis['total_sessions'] > 0
                ? round(($sessionKpis['completed_sessions'] / $sessionKpis['total_sessions']) * 100, 1)
                : 0,
            'avg_score' => round($sessionKpis['avg_session_score'] ?? 0, 1),
            'total_time_hours' => round(($sessionKpis['total_time_spent'] ?? 0) / 3600, 1)
        ],
        'assignments' => [
            'total' => (int)($assignmentKpis['total_assignments'] ?? 0),
            'active' => (int)($assignmentKpis['active_assignments'] ?? 0),
            'pending' => (int)($assignmentKpis['pending_assignments'] ?? 0),
            'avg_received' => round($assignmentKpis['avg_received_count'] ?? 0, 1),
            'avg_completed' => round($assignmentKpis['avg_completed_count'] ?? 0, 1)
        ]
    ];

    // Cache the result for 5 minutes
    setCached($cacheKey, $kpis, 300);

    // Log sync_logs for observability
    logInfo("Analytics KPIs generated", [
        'tenant_id' => $tenantId,
        'user_id' => $currentUser['id'],
        'filters' => compact('classId', 'themeId', 'startDate', 'endDate'),
        'kpis_count' => count($kpis)
    ]);

    $telemetry->end(200);
    jsonResponse([
        'tenant_id' => $tenantId,
        'filters' => [
            'class_id' => $classId,
            'theme_id' => $themeId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ],
        'kpis' => $kpis,
        'cached' => false,
        'generated_at' => date('c')
    ]);

} catch (Exception $e) {
    $telemetry->end(500, $e->getMessage());
    logError("Analytics KPIs error", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    errorResponse('INTERNAL_ERROR', $e->getMessage(), 500);
}
