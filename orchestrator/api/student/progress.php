<?php
/**
 * API Student Progress - E5-PROGRESS
 * Sprint 5: Learning Cycle - Student progress dashboard
 *
 * GET /api/student/{id}/progress - Get student progress (scores, time, radar)
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
$studentId = $pathParts[3] ?? null; // /api/student/{id}/progress

// ============================================================
// GET /api/student/{id}/progress - Get student progress
// ============================================================
if ($method === 'GET' && $studentId && ($pathParts[4] ?? null) === 'progress') {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);

    $tenantId = $tenantContext->getTenantId();

    // Verify student exists and belongs to tenant
    $student = db()->queryOne(
        'SELECT s.id, s.uuid_scolaire, s.email_scolaire, s.firstname, s.lastname,
                s.class_id, c.name as class_name
         FROM students s
         JOIN classes c ON s.class_id = c.id
         WHERE s.id = :id AND s.tenant_id = :tenant_id',
        ['id' => $studentId, 'tenant_id' => $tenantId]
    );

    if (!$student) {
        errorResponse('NOT_FOUND', 'Student not found', 404);
    }

    // ===== KPIs =====
    $kpis = db()->queryOne(
        'SELECT
            COUNT(DISTINCT ss.id) as total_sessions,
            COUNT(DISTINCT ss.assignment_id) as completed_assignments,
            COALESCE(AVG(ss.score), 0) as avg_score,
            COALESCE(SUM(ss.time_spent), 0) as total_time_spent,
            COUNT(DISTINCT CASE WHEN ss.completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN ss.id END) as sessions_last_7_days
         FROM student_sessions ss
         WHERE ss.student_id = :student_id AND ss.status = "terminee"',
        ['student_id' => $studentId]
    );

    // ===== Overall mastery =====
    $overallMastery = db()->queryOne(
        'SELECT COALESCE(AVG(mastery), 0) as avg_mastery
         FROM stats
         WHERE student_id = :student_id',
        ['student_id' => $studentId]
    );

    $kpis['overall_mastery'] = $overallMastery['avg_mastery'];

    // ===== Score trend (last 30 days) =====
    $scoreTrend = db()->query(
        'SELECT
            DATE(ss.completed_at) as date,
            AVG(ss.score) as avg_score,
            COUNT(ss.id) as session_count
         FROM student_sessions ss
         WHERE ss.student_id = :student_id
           AND ss.status = "terminee"
           AND ss.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(ss.completed_at)
         ORDER BY date ASC',
        ['student_id' => $studentId]
    );

    // ===== Time distribution by theme =====
    $timeByTheme = db()->query(
        'SELECT
            t.id as theme_id,
            t.title as theme_title,
            SUM(ss.time_spent) as total_time,
            COUNT(ss.id) as session_count
         FROM student_sessions ss
         JOIN assignments a ON ss.assignment_id = a.id
         JOIN themes t ON a.theme_id = t.id
         WHERE ss.student_id = :student_id AND ss.status = "terminee"
         GROUP BY t.id, t.title
         ORDER BY total_time DESC
         LIMIT 10',
        ['student_id' => $studentId]
    );

    // ===== Mastery radar by theme (for radar chart) =====
    $masteryRadar = db()->query(
        'SELECT
            t.id as theme_id,
            t.title as theme_title,
            COALESCE(s.mastery, 0) as mastery,
            COALESCE(s.attempts, 0) as attempts,
            COALESCE(s.score, 0) as avg_score
         FROM themes t
         LEFT JOIN stats s ON t.id = s.theme_id AND s.student_id = :student_id
         WHERE t.tenant_id = :tenant_id
           AND t.status = "active"
         ORDER BY t.title
         LIMIT 8',
        ['student_id' => $studentId, 'tenant_id' => $tenantId]
    );

    // ===== Recent activity =====
    $recentActivity = db()->query(
        'SELECT
            ss.id,
            ss.score,
            ss.time_spent,
            ss.completed_at,
            a.title as assignment_title,
            a.type as assignment_type,
            t.title as theme_title
         FROM student_sessions ss
         JOIN assignments a ON ss.assignment_id = a.id
         JOIN themes t ON a.theme_id = t.id
         WHERE ss.student_id = :student_id AND ss.status = "terminee"
         ORDER BY ss.completed_at DESC
         LIMIT 10',
        ['student_id' => $studentId]
    );

    // ===== Strengths and weaknesses =====
    $strengths = db()->query(
        'SELECT
            t.title as theme_title,
            s.mastery,
            s.score,
            s.attempts
         FROM stats s
         JOIN themes t ON s.theme_id = t.id
         WHERE s.student_id = :student_id
           AND s.mastery >= 0.8
         ORDER BY s.mastery DESC
         LIMIT 5',
        ['student_id' => $studentId]
    );

    $weaknesses = db()->query(
        'SELECT
            t.title as theme_title,
            s.mastery,
            s.score,
            s.attempts
         FROM stats s
         JOIN themes t ON s.theme_id = t.id
         WHERE s.student_id = :student_id
           AND s.mastery < 0.6
         ORDER BY s.mastery ASC
         LIMIT 5',
        ['student_id' => $studentId]
    );

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/student/' . $studentId . '/progress', 200, $duration);

    jsonResponse([
        'student' => [
            'id' => $student['id'],
            'firstname' => $student['firstname'],
            'lastname' => $student['lastname'],
            'email_scolaire' => $student['email_scolaire'],
            'class_name' => $student['class_name']
        ],
        'kpis' => [
            'total_sessions' => (int)$kpis['total_sessions'],
            'completed_assignments' => (int)$kpis['completed_assignments'],
            'avg_score' => round($kpis['avg_score'], 1),
            'total_time_spent' => (int)$kpis['total_time_spent'],
            'sessions_last_7_days' => (int)$kpis['sessions_last_7_days'],
            'overall_mastery' => round($kpis['overall_mastery'] * 100, 1)
        ],
        'charts' => [
            'score_trend' => [
                'labels' => array_column($scoreTrend, 'date'),
                'values' => array_map('floatval', array_column($scoreTrend, 'avg_score'))
            ],
            'time_by_theme' => [
                'labels' => array_column($timeByTheme, 'theme_title'),
                'values' => array_map('intval', array_column($timeByTheme, 'total_time'))
            ],
            'mastery_radar' => [
                'labels' => array_column($masteryRadar, 'theme_title'),
                'values' => array_map(function($v) {
                    return round($v * 100, 1);
                }, array_column($masteryRadar, 'mastery'))
            ]
        ],
        'recent_activity' => $recentActivity,
        'analysis' => [
            'strengths' => $strengths,
            'weaknesses' => $weaknesses
        ],
        'generated_at' => date('c')
    ]);
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
