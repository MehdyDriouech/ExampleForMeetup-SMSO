<?php
/**
 * API Student Review - E5-REVIEW
 * Sprint 5: Learning Cycle - Targeted review (replay errors)
 *
 * GET /api/student/{id}/review - Get questions/concepts to review
 * POST /api/student/{id}/review/session - Create a review session
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
$studentId = $pathParts[3] ?? null; // /api/student/{id}/review

// ============================================================
// GET /api/student/{id}/review - Get items to review
// ============================================================
if ($method === 'GET' && $studentId && ($pathParts[4] ?? null) === 'review') {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);

    $tenantId = $tenantContext->getTenantId();

    // Query parameters
    $themeId = $_GET['theme_id'] ?? null;
    $limit = intval($_GET['limit'] ?? 20);

    // Verify student exists and belongs to tenant
    $student = db()->queryOne(
        'SELECT id, firstname, lastname FROM students
         WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $studentId, 'tenant_id' => $tenantId]
    );

    if (!$student) {
        errorResponse('NOT_FOUND', 'Student not found', 404);
    }

    // Get errors from student sessions
    $whereClauses = ['ss.student_id = :student_id', 'ss.errors IS NOT NULL'];
    $params = ['student_id' => $studentId, 'limit' => $limit];

    if ($themeId) {
        $whereClauses[] = 'a.theme_id = :theme_id';
        $params['theme_id'] = $themeId;
    }

    $sessions = db()->query(
        'SELECT
            ss.id as session_id,
            ss.errors,
            ss.score,
            ss.completed_at,
            a.id as assignment_id,
            a.title as assignment_title,
            a.type as assignment_type,
            t.id as theme_id,
            t.title as theme_title,
            t.content as theme_content
         FROM student_sessions ss
         JOIN assignments a ON ss.assignment_id = a.id
         JOIN themes t ON a.theme_id = t.id
         WHERE ' . implode(' AND ', $whereClauses) . '
         ORDER BY ss.completed_at DESC
         LIMIT :limit',
        $params
    );

    // Parse errors and extract review items
    $reviewItems = [];
    $errorsByTheme = [];

    foreach ($sessions as $session) {
        if (empty($session['errors'])) {
            continue;
        }

        $errors = json_decode($session['errors'], true);
        if (!is_array($errors)) {
            continue;
        }

        foreach ($errors as $error) {
            $reviewItem = [
                'session_id' => $session['session_id'],
                'assignment_id' => $session['assignment_id'],
                'assignment_title' => $session['assignment_title'],
                'assignment_type' => $session['assignment_type'],
                'theme_id' => $session['theme_id'],
                'theme_title' => $session['theme_title'],
                'error' => $error,
                'completed_at' => $session['completed_at']
            ];

            $reviewItems[] = $reviewItem;

            // Count errors by theme
            if (!isset($errorsByTheme[$session['theme_id']])) {
                $errorsByTheme[$session['theme_id']] = [
                    'theme_id' => $session['theme_id'],
                    'theme_title' => $session['theme_title'],
                    'error_count' => 0
                ];
            }
            $errorsByTheme[$session['theme_id']]['error_count']++;
        }
    }

    // Get themes that need review (low mastery)
    $weakThemes = db()->query(
        'SELECT
            t.id as theme_id,
            t.title as theme_title,
            t.difficulty,
            s.mastery,
            s.score,
            s.attempts
         FROM stats s
         JOIN themes t ON s.theme_id = t.id
         WHERE s.student_id = :student_id
           AND s.mastery < 0.7
         ORDER BY s.mastery ASC
         LIMIT 5',
        ['student_id' => $studentId]
    );

    // Recommendations
    $recommendations = [];

    // Recommend review for low-mastery themes
    foreach ($weakThemes as $theme) {
        $recommendations[] = [
            'type' => 'low_mastery',
            'theme_id' => $theme['theme_id'],
            'theme_title' => $theme['theme_title'],
            'mastery' => round($theme['mastery'] * 100, 1),
            'reason' => 'Maîtrise inférieure à 70%',
            'priority' => 'high'
        ];
    }

    // Recommend review for themes with many errors
    foreach ($errorsByTheme as $themeData) {
        if ($themeData['error_count'] >= 3) {
            $recommendations[] = [
                'type' => 'frequent_errors',
                'theme_id' => $themeData['theme_id'],
                'theme_title' => $themeData['theme_title'],
                'error_count' => $themeData['error_count'],
                'reason' => 'Erreurs fréquentes détectées',
                'priority' => 'medium'
            ];
        }
    }

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/student/' . $studentId . '/review', 200, $duration);

    jsonResponse([
        'student' => [
            'id' => $student['id'],
            'firstname' => $student['firstname'],
            'lastname' => $student['lastname']
        ],
        'review_items' => $reviewItems,
        'count' => count($reviewItems),
        'errors_by_theme' => array_values($errorsByTheme),
        'recommendations' => $recommendations,
        'generated_at' => date('c')
    ]);
}

// ============================================================
// POST /api/student/{id}/review/session - Create review session
// ============================================================
if ($method === 'POST' && $studentId && ($pathParts[4] ?? null) === 'review' && ($pathParts[5] ?? null) === 'session') {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);

    $tenantId = $tenantContext->getTenantId();
    $body = getRequestBody();

    validateRequired($body, ['theme_id', 'items']);

    $themeId = $body['theme_id'];
    $items = $body['items']; // Array of question IDs or error references

    // Verify student exists
    $student = db()->queryOne(
        'SELECT id FROM students WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $studentId, 'tenant_id' => $tenantId]
    );

    if (!$student) {
        errorResponse('NOT_FOUND', 'Student not found', 404);
    }

    // Verify theme exists
    $theme = db()->queryOne(
        'SELECT id FROM themes WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $themeId, 'tenant_id' => $tenantId]
    );

    if (!$theme) {
        errorResponse('NOT_FOUND', 'Theme not found', 404);
    }

    // Create review session
    $sessionId = generateId('rev');

    db()->execute(
        'INSERT INTO review_sessions
         (id, student_id, theme_id, tenant_id, items, status, created_at)
         VALUES (:id, :student_id, :theme_id, :tenant_id, :items, :status, NOW())',
        [
            'id' => $sessionId,
            'student_id' => $studentId,
            'theme_id' => $themeId,
            'tenant_id' => $tenantId,
            'items' => json_encode($items),
            'status' => 'en_cours'
        ]
    );

    logInfo('Review session created', [
        'session_id' => $sessionId,
        'student_id' => $studentId,
        'theme_id' => $themeId,
        'items_count' => count($items)
    ]);

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/student/' . $studentId . '/review/session', 201, $duration);

    jsonResponse([
        'session_id' => $sessionId,
        'status' => 'created',
        'items_count' => count($items)
    ], 201);
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
