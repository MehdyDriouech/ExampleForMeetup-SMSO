<?php
/**
 * API Student Badges - E5-BADGES
 * Sprint 5: Learning Cycle - Badge system
 *
 * GET /api/student/{id}/badges - Get student badges
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
$studentId = $pathParts[3] ?? null; // /api/student/{id}/badges

// ============================================================
// GET /api/student/{id}/badges - Get student badges
// ============================================================
if ($method === 'GET' && $studentId && ($pathParts[4] ?? null) === 'badges') {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);

    $tenantId = $tenantContext->getTenantId();

    // Verify student exists and belongs to tenant
    $student = db()->queryOne(
        'SELECT id, firstname, lastname FROM students
         WHERE id = :id AND tenant_id = :tenant_id',
        ['id' => $studentId, 'tenant_id' => $tenantId]
    );

    if (!$student) {
        errorResponse('NOT_FOUND', 'Student not found', 404);
    }

    // Get earned badges
    $earnedBadges = db()->query(
        'SELECT
            sb.id,
            sb.badge_id,
            sb.earned_at,
            sb.metadata,
            b.name as badge_name,
            b.description as badge_description,
            b.icon as badge_icon,
            b.category as badge_category,
            b.tier as badge_tier
         FROM student_badges sb
         JOIN badges b ON sb.badge_id = b.id
         WHERE sb.student_id = :student_id
         ORDER BY sb.earned_at DESC',
        ['student_id' => $studentId]
    );

    // Parse metadata
    foreach ($earnedBadges as &$badge) {
        if ($badge['metadata']) {
            $badge['metadata'] = json_decode($badge['metadata'], true);
        }
    }

    // Get available badges (not yet earned)
    $availableBadges = db()->query(
        'SELECT
            b.id as badge_id,
            b.name as badge_name,
            b.description as badge_description,
            b.icon as badge_icon,
            b.category as badge_category,
            b.tier as badge_tier,
            b.criteria
         FROM badges b
         WHERE b.tenant_id = :tenant_id
           AND b.id NOT IN (
               SELECT badge_id FROM student_badges WHERE student_id = :student_id
           )
         ORDER BY b.category, b.tier',
        ['tenant_id' => $tenantId, 'student_id' => $studentId]
    );

    // Parse criteria
    foreach ($availableBadges as &$badge) {
        if ($badge['criteria']) {
            $badge['criteria'] = json_decode($badge['criteria'], true);
        }
    }

    // Calculate progress towards available badges
    require_once __DIR__ . '/../../lib/badges.php';
    $badgeService = new BadgeService();

    foreach ($availableBadges as &$badge) {
        $progress = $badgeService->calculateBadgeProgress($studentId, $badge['badge_id']);
        $badge['progress'] = $progress;
    }

    // Badge statistics
    $badgeStats = [
        'total_earned' => count($earnedBadges),
        'total_available' => count($availableBadges),
        'by_category' => [],
        'by_tier' => []
    ];

    // Count by category
    foreach ($earnedBadges as $badge) {
        $category = $badge['badge_category'];
        if (!isset($badgeStats['by_category'][$category])) {
            $badgeStats['by_category'][$category] = 0;
        }
        $badgeStats['by_category'][$category]++;
    }

    // Count by tier
    foreach ($earnedBadges as $badge) {
        $tier = $badge['badge_tier'];
        if (!isset($badgeStats['by_tier'][$tier])) {
            $badgeStats['by_tier'][$tier] = 0;
        }
        $badgeStats['by_tier'][$tier]++;
    }

    // Recent badges (last 5)
    $recentBadges = array_slice($earnedBadges, 0, 5);

    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/student/' . $studentId . '/badges', 200, $duration);

    jsonResponse([
        'student' => [
            'id' => $student['id'],
            'firstname' => $student['firstname'],
            'lastname' => $student['lastname']
        ],
        'earned_badges' => $earnedBadges,
        'available_badges' => $availableBadges,
        'recent_badges' => $recentBadges,
        'statistics' => $badgeStats,
        'generated_at' => date('c')
    ]);
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
