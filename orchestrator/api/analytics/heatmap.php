<?php
/**
 * GET /api/analytics/heatmap - Learning Analytics Heatmap
 *
 * Sprint 6: Heatmap difficultés - Visualiser difficultés par compétence
 * Returns aggregated difficulty data by theme/skill for heatmap visualization
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

    // Check permissions
    requirePermission($auth, 'analytics', 'read');

    $tenantId = $tenantContext->getTenantId();
    $currentUser = $auth->getUser();

    // Get query parameters for filtering
    $classId = $_GET['class_id'] ?? null;
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');

    $db = db();

    // Check cache first (5 min TTL)
    $cacheKey = "analytics_heatmap_{$tenantId}_{$classId}_{$startDate}_{$endDate}";
    $cachedData = getCached($cacheKey);

    if ($cachedData !== null) {
        logInfo("Analytics Heatmap cache hit", ['cache_key' => $cacheKey, 'tenant_id' => $tenantId]);
        $telemetry->end(200);
        jsonResponse([
            'tenant_id' => $tenantId,
            'filters' => [
                'class_id' => $classId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'heatmap' => $cachedData,
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

    if ($startDate && $endDate) {
        $whereConditions[] = "DATE(st.synced_at) BETWEEN :start_date AND :end_date";
        $params['start_date'] = $startDate;
        $params['end_date'] = $endDate;
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Get heatmap data: theme difficulty metrics
    $heatmapData = $db->query(
        "SELECT
            t.id as theme_id,
            t.title as theme_title,
            t.difficulty as theme_difficulty,
            COUNT(DISTINCT s.id) as student_count,
            COUNT(st.id) as total_attempts,
            AVG(st.score) as avg_score,
            AVG(st.mastery) as avg_mastery,
            SUM(CASE WHEN st.score < 50 THEN 1 ELSE 0 END) as failed_attempts,
            SUM(CASE WHEN st.score >= 50 THEN 1 ELSE 0 END) as passed_attempts,
            MIN(st.score) as min_score,
            MAX(st.score) as max_score,
            STDDEV(st.score) as score_stddev
         FROM themes t
         INNER JOIN stats st ON st.theme_id = t.id
         INNER JOIN students s ON s.id = st.student_id
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE {$whereClause} AND t.status = 'active'
         GROUP BY t.id, t.title, t.difficulty
         ORDER BY failed_attempts DESC, avg_score ASC",
        $params
    );

    // Calculate difficulty indicators for each theme
    $heatmap = array_map(function($row) {
        $failureRate = $row['total_attempts'] > 0
            ? ($row['failed_attempts'] / $row['total_attempts']) * 100
            : 0;

        // Difficulty level: low, medium, high, critical
        $difficultyLevel = 'low';
        if ($failureRate >= 70) {
            $difficultyLevel = 'critical'; // 70%+ failure rate
        } elseif ($failureRate >= 50) {
            $difficultyLevel = 'high'; // 50-70% failure rate
        } elseif ($failureRate >= 30) {
            $difficultyLevel = 'medium'; // 30-50% failure rate
        }

        return [
            'theme_id' => $row['theme_id'],
            'theme_title' => $row['theme_title'],
            'theme_difficulty' => $row['theme_difficulty'],
            'metrics' => [
                'student_count' => (int)$row['student_count'],
                'total_attempts' => (int)$row['total_attempts'],
                'avg_score' => round($row['avg_score'], 1),
                'avg_mastery' => round($row['avg_mastery'] * 100, 1), // 0-1 to 0-100
                'failure_rate' => round($failureRate, 1),
                'success_rate' => round(100 - $failureRate, 1),
                'min_score' => round($row['min_score'], 1),
                'max_score' => round($row['max_score'], 1),
                'score_stddev' => round($row['score_stddev'] ?? 0, 1)
            ],
            'difficulty_level' => $difficultyLevel,
            'needs_remediation' => $failureRate >= 50, // Flag themes needing intervention
            'color' => getDifficultyColor($failureRate) // For heatmap visualization
        ];
    }, $heatmapData);

    // Also get granular session-level errors for detailed tooltips
    $errorBreakdown = $db->query(
        "SELECT
            a.theme_id,
            ss.errors,
            COUNT(*) as occurrence_count
         FROM student_sessions ss
         INNER JOIN assignments a ON a.id = ss.assignment_id
         INNER JOIN students s ON s.id = ss.student_id
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE ss.tenant_id = :tenant_id
           AND ss.errors IS NOT NULL
           AND ss.errors != ''
           AND ss.errors != '[]'
           AND DATE(ss.created_at) BETWEEN :start_date AND :end_date
           " . ($currentUser['role'] === 'teacher' ? "AND c.teacher_id = :teacher_id" : "") . "
           " . ($classId ? "AND s.class_id = :class_id" : "") . "
         GROUP BY a.theme_id, ss.errors
         ORDER BY occurrence_count DESC
         LIMIT 100",
        $params
    );

    // Parse error breakdown and attach to themes
    $errorsByTheme = [];
    foreach ($errorBreakdown as $error) {
        $themeId = $error['theme_id'];
        if (!isset($errorsByTheme[$themeId])) {
            $errorsByTheme[$themeId] = [];
        }

        $errors = json_decode($error['errors'], true);
        if (is_array($errors)) {
            $errorsByTheme[$themeId][] = [
                'errors' => $errors,
                'count' => (int)$error['occurrence_count']
            ];
        }
    }

    // Attach error details to heatmap
    foreach ($heatmap as &$item) {
        $item['common_errors'] = $errorsByTheme[$item['theme_id']] ?? [];
    }

    // Cache the result for 5 minutes
    setCached($cacheKey, $heatmap, 300);

    // Log for observability
    logInfo("Analytics Heatmap generated", [
        'tenant_id' => $tenantId,
        'user_id' => $currentUser['id'],
        'filters' => compact('classId', 'startDate', 'endDate'),
        'themes_count' => count($heatmap)
    ]);

    $telemetry->end(200);
    jsonResponse([
        'tenant_id' => $tenantId,
        'filters' => [
            'class_id' => $classId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ],
        'heatmap' => $heatmap,
        'cached' => false,
        'generated_at' => date('c')
    ]);

} catch (Exception $e) {
    $telemetry->end(500, $e->getMessage());
    logError("Analytics Heatmap error", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    errorResponse('INTERNAL_ERROR', $e->getMessage(), 500);
}

/**
 * Get color code for heatmap visualization based on failure rate
 */
function getDifficultyColor($failureRate) {
    if ($failureRate >= 70) {
        return '#dc3545'; // Critical - Red
    } elseif ($failureRate >= 50) {
        return '#fd7e14'; // High - Orange
    } elseif ($failureRate >= 30) {
        return '#ffc107'; // Medium - Yellow
    } else {
        return '#28a745'; // Low - Green
    }
}
