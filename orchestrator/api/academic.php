<?php
/**
 * Sprint 9: Academic API for ENT/LMS Integration
 *
 * Read-only endpoints for academic data exports
 * Routes: /api/academic/*
 *
 * Features:
 * - Read-only access to students, classes, promotions, stats, themes, assignments
 * - API key authentication with rate limiting
 * - Tenant isolation
 * - Full observability logging
 * - OpenAPI documented
 *
 * @version 1.0
 * @date 2025-11-13
 */

require_once __DIR__ . '/../.env.php';
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rate_limit.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// Extract resource from path
$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($uri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));

// Expected: api/academic/{resource}
$resource = $pathParts[2] ?? null;

/**
 * Only GET methods allowed for read-only API
 */
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'error' => 'method_not_allowed',
        'message' => 'Only GET requests are allowed on academic endpoints. This is a read-only API.',
        'allowed_methods' => ['GET']
    ]);
    exit;
}

/**
 * Enforce rate limiting with API key (required for academic API)
 */
$rateLimitInfo = enforceRateLimit(true);

/**
 * Enforce tenant isolation
 * Extract tenant from API key or X-Orchestrator-Id header
 */
$tenantId = $rateLimitInfo->getTenantId();
if (!$tenantId) {
    // Fallback to header-based tenant extraction
    $tenantContext = enforceTenantIsolation();
    $tenantId = $tenantContext->getTenantId();
} else {
    // Validate tenant from API key
    $tenant = validateTenant($tenantId);
    if (!$tenant || $tenant['status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'error' => 'invalid_tenant',
            'message' => 'Tenant not found or inactive.'
        ]);
        exit;
    }
    $tenantContext = new TenantContext($tenantId, $tenant);
}

/**
 * Log API call for observability
 */
function logAcademicApiCall($endpoint, $statusCode, $duration, $metadata = []) {
    global $rateLimitInfo, $tenantId;

    $logData = array_merge([
        'endpoint' => $endpoint,
        'api_key_id' => $rateLimitInfo->apiKeyId,
        'tenant_id' => $tenantId,
        'status_code' => $statusCode,
        'duration_ms' => round($duration, 2),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ], $metadata);

    // Log to sync_logs for observability
    try {
        db()->execute(
            "INSERT INTO sync_logs (tenant_id, direction, type, status, metadata, created_at)
             VALUES (:tenant_id, 'pull', 'academic_api', :status, :metadata, NOW())",
            [
                'tenant_id' => $tenantId,
                'status' => $statusCode >= 200 && $statusCode < 300 ? 'ok' : 'error',
                'metadata' => json_encode($logData)
            ]
        );
    } catch (Exception $e) {
        logError("Failed to log academic API call", ['error' => $e->getMessage()]);
    }

    logger()->logRequest($endpoint, $statusCode, $duration * 1000, $metadata);
}

/**
 * Route handling
 */
switch ($resource) {

    /**
     * GET /api/academic/students
     * List students with optional filters
     */
    case 'students':
        requireScope($rateLimitInfo, 'academic:read');

        $classId = $_GET['class_id'] ?? null;
        $promoId = $_GET['promo_id'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 100), 500);
        $offset = (int)($_GET['offset'] ?? 0);

        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if ($classId) {
            $where[] = 'class_id = :class_id';
            $params['class_id'] = $classId;
        }

        if ($promoId) {
            $where[] = 'promo_id = :promo_id';
            $params['promo_id'] = $promoId;
        }

        $whereClause = implode(' AND ', $where);

        $students = db()->query(
            "SELECT s.id, s.uuid_scolaire, s.email_scolaire, s.firstname, s.lastname,
                    s.class_id, s.promo_id, s.consent_sharing, s.status,
                    c.name as class_name, p.name as promo_name,
                    s.created_at, s.updated_at
             FROM students s
             LEFT JOIN classes c ON s.class_id = c.id
             LEFT JOIN promotions p ON s.promo_id = p.id
             WHERE {$whereClause}
             ORDER BY s.lastname, s.firstname
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset])
        );

        $total = db()->queryOne(
            "SELECT COUNT(*) as count FROM students WHERE {$whereClause}",
            $params
        )['count'];

        $duration = (microtime(true) - $start) * 1000;
        logAcademicApiCall('/api/academic/students', 200, $duration, [
            'count' => count($students),
            'total' => $total,
            'filters' => ['class_id' => $classId, 'promo_id' => $promoId]
        ]);

        jsonResponse([
            'data' => $students,
            'pagination' => [
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total
            ],
            'meta' => [
                'tenant_id' => $tenantId,
                'generated_at' => date('c')
            ]
        ]);
        break;

    /**
     * GET /api/academic/classes
     * List classes with optional filters
     */
    case 'classes':
        requireScope($rateLimitInfo, 'academic:read');

        $promoId = $_GET['promo_id'] ?? null;
        $status = $_GET['status'] ?? 'active';

        $where = ['c.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if ($promoId) {
            $where[] = 'c.promo_id = :promo_id';
            $params['promo_id'] = $promoId;
        }

        if ($status) {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }

        $whereClause = implode(' AND ', $where);

        $classes = db()->query(
            "SELECT c.id, c.name, c.description, c.promo_id, c.teacher_id, c.status,
                    p.name as promo_name,
                    u.firstname as teacher_firstname, u.lastname as teacher_lastname,
                    (SELECT COUNT(*) FROM students WHERE class_id = c.id) as student_count,
                    c.created_at, c.updated_at
             FROM classes c
             LEFT JOIN promotions p ON c.promo_id = p.id
             LEFT JOIN users u ON c.teacher_id = u.id
             WHERE {$whereClause}
             ORDER BY c.name",
            $params
        );

        $duration = (microtime(true) - $start) * 1000;
        logAcademicApiCall('/api/academic/classes', 200, $duration, [
            'count' => count($classes),
            'filters' => ['promo_id' => $promoId, 'status' => $status]
        ]);

        jsonResponse([
            'data' => $classes,
            'meta' => [
                'tenant_id' => $tenantId,
                'count' => count($classes),
                'generated_at' => date('c')
            ]
        ]);
        break;

    /**
     * GET /api/academic/promotions
     * List promotions
     */
    case 'promotions':
        requireScope($rateLimitInfo, 'academic:read');

        $status = $_GET['status'] ?? 'active';

        $promotions = db()->query(
            "SELECT p.id, p.name, p.description, p.year_start, p.year_end, p.status,
                    (SELECT COUNT(*) FROM classes WHERE promo_id = p.id) as class_count,
                    (SELECT COUNT(*) FROM students WHERE promo_id = p.id) as student_count,
                    p.created_at, p.updated_at
             FROM promotions p
             WHERE p.tenant_id = :tenant_id AND p.status = :status
             ORDER BY p.year_start DESC, p.name",
            ['tenant_id' => $tenantId, 'status' => $status]
        );

        $duration = (microtime(true) - $start) * 1000;
        logAcademicApiCall('/api/academic/promotions', 200, $duration, [
            'count' => count($promotions)
        ]);

        jsonResponse([
            'data' => $promotions,
            'meta' => [
                'tenant_id' => $tenantId,
                'count' => count($promotions),
                'generated_at' => date('c')
            ]
        ]);
        break;

    /**
     * GET /api/academic/themes
     * List themes
     */
    case 'themes':
        requireScope($rateLimitInfo, 'academic:read');

        $status = $_GET['status'] ?? 'active';
        $difficulty = $_GET['difficulty'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 100), 500);
        $offset = (int)($_GET['offset'] ?? 0);

        $where = ['tenant_id = :tenant_id', 'status = :status'];
        $params = ['tenant_id' => $tenantId, 'status' => $status];

        if ($difficulty) {
            $where[] = 'difficulty = :difficulty';
            $params['difficulty'] = $difficulty;
        }

        $whereClause = implode(' AND ', $where);

        $themes = db()->query(
            "SELECT id, title, description, tags, difficulty, source, status,
                    created_at, updated_at
             FROM themes
             WHERE {$whereClause}
             ORDER BY title
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset])
        );

        $total = db()->queryOne(
            "SELECT COUNT(*) as count FROM themes WHERE {$whereClause}",
            $params
        )['count'];

        $duration = (microtime(true) - $start) * 1000;
        logAcademicApiCall('/api/academic/themes', 200, $duration, [
            'count' => count($themes),
            'total' => $total
        ]);

        jsonResponse([
            'data' => $themes,
            'pagination' => [
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total
            ],
            'meta' => [
                'tenant_id' => $tenantId,
                'generated_at' => date('c')
            ]
        ]);
        break;

    /**
     * GET /api/academic/assignments
     * List assignments (read-only)
     */
    case 'assignments':
        requireScope($rateLimitInfo, 'academic:read');

        $classId = $_GET['class_id'] ?? null;
        $teacherId = $_GET['teacher_id'] ?? null;
        $status = $_GET['status'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 100), 500);
        $offset = (int)($_GET['offset'] ?? 0);

        $where = ['a.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if ($classId) {
            $where[] = "a.targets LIKE :class_id";
            $params['class_id'] = '%' . $classId . '%';
        }

        if ($teacherId) {
            $where[] = 'a.teacher_id = :teacher_id';
            $params['teacher_id'] = $teacherId;
        }

        if ($status) {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }

        $whereClause = implode(' AND ', $where);

        $assignments = db()->query(
            "SELECT a.id, a.type, a.title, a.mode, a.theme_id, a.status,
                    a.due_at, a.instructions, a.targets, a.teacher_id,
                    t.title as theme_title, t.difficulty as theme_difficulty,
                    u.firstname as teacher_firstname, u.lastname as teacher_lastname,
                    a.created_at, a.updated_at
             FROM assignments a
             LEFT JOIN themes t ON a.theme_id = t.id
             LEFT JOIN users u ON a.teacher_id = u.id
             WHERE {$whereClause}
             ORDER BY a.due_at DESC, a.created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset])
        );

        $total = db()->queryOne(
            "SELECT COUNT(*) as count FROM assignments a WHERE {$whereClause}",
            $params
        )['count'];

        $duration = (microtime(true) - $start) * 1000;
        logAcademicApiCall('/api/academic/assignments', 200, $duration, [
            'count' => count($assignments),
            'total' => $total
        ]);

        jsonResponse([
            'data' => $assignments,
            'pagination' => [
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total
            ],
            'meta' => [
                'tenant_id' => $tenantId,
                'generated_at' => date('c')
            ]
        ]);
        break;

    /**
     * GET /api/academic/stats
     * Aggregated statistics
     */
    case 'stats':
        requireScope($rateLimitInfo, 'academic:read');

        $classId = $_GET['class_id'] ?? null;
        $studentId = $_GET['student_id'] ?? null;
        $themeId = $_GET['theme_id'] ?? null;
        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');

        $where = ['s.tenant_id = :tenant_id'];
        $params = [
            'tenant_id' => $tenantId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ];

        if ($classId) {
            $where[] = 'st.class_id = :class_id';
            $params['class_id'] = $classId;
        }

        if ($studentId) {
            $where[] = 's.student_id = :student_id';
            $params['student_id'] = $studentId;
        }

        if ($themeId) {
            $where[] = 's.theme_id = :theme_id';
            $params['theme_id'] = $themeId;
        }

        $where[] = 'DATE(s.last_activity_at) BETWEEN :start_date AND :end_date';
        $whereClause = implode(' AND ', $where);

        $stats = db()->query(
            "SELECT s.student_id, s.theme_id,
                    st.firstname, st.lastname, st.email_scolaire,
                    t.title as theme_title, t.difficulty as theme_difficulty,
                    s.attempts, s.score, s.mastery, s.time_spent,
                    s.last_activity_at
             FROM stats s
             LEFT JOIN students st ON s.student_id = st.id
             LEFT JOIN themes t ON s.theme_id = t.id
             WHERE {$whereClause}
             ORDER BY s.last_activity_at DESC",
            $params
        );

        // Compute aggregates
        $totalSessions = count($stats);
        $avgScore = $totalSessions > 0 ? array_sum(array_column($stats, 'score')) / $totalSessions : 0;
        $avgMastery = $totalSessions > 0 ? array_sum(array_column($stats, 'mastery')) / $totalSessions : 0;
        $totalTime = array_sum(array_column($stats, 'time_spent'));

        $duration = (microtime(true) - $start) * 1000;
        logAcademicApiCall('/api/academic/stats', 200, $duration, [
            'count' => count($stats),
            'filters' => [
                'class_id' => $classId,
                'student_id' => $studentId,
                'theme_id' => $themeId,
                'date_range' => [$startDate, $endDate]
            ]
        ]);

        jsonResponse([
            'data' => $stats,
            'aggregates' => [
                'total_sessions' => $totalSessions,
                'avg_score' => round($avgScore, 2),
                'avg_mastery' => round($avgMastery, 2),
                'total_time_hours' => round($totalTime / 3600, 2)
            ],
            'filters' => [
                'class_id' => $classId,
                'student_id' => $studentId,
                'theme_id' => $themeId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'meta' => [
                'tenant_id' => $tenantId,
                'generated_at' => date('c')
            ]
        ]);
        break;

    /**
     * GET /api/academic/export
     * Full export for ENT/LMS integration
     */
    case 'export':
        requireScope($rateLimitInfo, 'academic:export');

        $format = $_GET['format'] ?? 'json';
        $include = explode(',', $_GET['include'] ?? 'students,classes,themes,stats');

        $export = [
            'tenant_id' => $tenantId,
            'exported_at' => date('c'),
            'format_version' => '1.0'
        ];

        if (in_array('students', $include)) {
            $export['students'] = db()->query(
                "SELECT s.id, s.uuid_scolaire, s.email_scolaire, s.firstname, s.lastname,
                        s.class_id, s.promo_id, s.consent_sharing, s.status
                 FROM students s
                 WHERE s.tenant_id = :tenant_id AND s.status = 'active'
                 ORDER BY s.lastname, s.firstname",
                ['tenant_id' => $tenantId]
            );
        }

        if (in_array('classes', $include)) {
            $export['classes'] = db()->query(
                "SELECT c.id, c.name, c.description, c.promo_id, c.teacher_id, c.status
                 FROM classes c
                 WHERE c.tenant_id = :tenant_id AND c.status = 'active'
                 ORDER BY c.name",
                ['tenant_id' => $tenantId]
            );
        }

        if (in_array('themes', $include)) {
            $export['themes'] = db()->query(
                "SELECT id, title, description, tags, difficulty, source, status
                 FROM themes
                 WHERE tenant_id = :tenant_id AND status = 'active'
                 ORDER BY title",
                ['tenant_id' => $tenantId]
            );
        }

        if (in_array('stats', $include)) {
            $export['stats'] = db()->query(
                "SELECT s.student_id, s.theme_id, s.attempts, s.score, s.mastery,
                        s.time_spent, s.last_activity_at
                 FROM stats s
                 WHERE s.tenant_id = :tenant_id
                 ORDER BY s.last_activity_at DESC
                 LIMIT 1000",
                ['tenant_id' => $tenantId]
            );
        }

        $duration = (microtime(true) - $start) * 1000;
        logAcademicApiCall('/api/academic/export', 200, $duration, [
            'format' => $format,
            'includes' => $include
        ]);

        if ($format === 'csv') {
            // TODO: Implement CSV export
            http_response_code(501);
            echo json_encode([
                'error' => 'not_implemented',
                'message' => 'CSV export not yet implemented. Use format=json.'
            ]);
            exit;
        }

        jsonResponse($export);
        break;

    default:
        http_response_code(404);
        echo json_encode([
            'error' => 'not_found',
            'message' => 'Academic API resource not found: ' . ($resource ?? 'none'),
            'available_resources' => [
                'students',
                'classes',
                'promotions',
                'themes',
                'assignments',
                'stats',
                'export'
            ],
            'documentation' => '/orchestrator/docs/openapi-orchestrator.yaml'
        ]);
        exit;
}
