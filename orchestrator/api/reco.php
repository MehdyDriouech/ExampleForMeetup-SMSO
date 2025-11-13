<?php
/**
 * Recommendations API - Sprint 7 E7-RECO
 *
 * Endpoints:
 * - GET /api/reco?studentId={id} : Récupérer 3 recommandations personnalisées
 * - POST /api/reco/feedback : Enregistrer le feedback de l'élève
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/recommendations.php';

// Apply middleware
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rbac.php';
require_once __DIR__ . '/_middleware_telemetry.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

try {
    // Parse request
    $user = $GLOBALS['auth_user'] ?? null;
    $tenantId = $GLOBALS['tenant_id'] ?? null;

    if (!$user || !$tenantId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $recommendationEngine = new RecommendationEngine();

    // GET /api/reco?studentId={id}
    if ($method === 'GET') {
        // Check permission: student can view own, teachers can view their students
        requirePermission('recommendations:read');

        $studentId = $_GET['studentId'] ?? null;

        if (!$studentId) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Bad Request',
                'message' => 'studentId parameter is required'
            ]);
            exit;
        }

        // Verify access rights
        if ($user['role'] === 'student' && $user['id'] !== $studentId) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Forbidden',
                'message' => 'You can only view your own recommendations'
            ]);
            exit;
        }

        // For teachers: verify student belongs to their tenant
        $db = db();
        $student = $db->queryOne(
            'SELECT id, tenant_id FROM students WHERE id = :id AND tenant_id = :tenant_id',
            ['id' => $studentId, 'tenant_id' => $tenantId]
        );

        if (!$student) {
            http_response_code(404);
            echo json_encode([
                'error' => 'Not Found',
                'message' => 'Student not found or access denied'
            ]);
            exit;
        }

        // Generate recommendations
        $result = $recommendationEngine->generateRecommendations($studentId, $tenantId);

        logInfo('Recommendations generated', [
            'student_id' => $studentId,
            'user_id' => $user['id'],
            'recommendation_count' => count($result['recommendations'])
        ]);

        echo json_encode($result);
        exit;
    }

    // POST /api/reco/feedback
    if ($method === 'POST' && strpos($requestUri, '/feedback') !== false) {
        requirePermission('recommendations:feedback');

        $input = json_decode(file_get_contents('php://input'), true);

        $studentId = $input['studentId'] ?? null;
        $themeId = $input['themeId'] ?? null;
        $feedback = $input['feedback'] ?? null;

        if (!$studentId || !$themeId || !$feedback) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Bad Request',
                'message' => 'studentId, themeId, and feedback are required'
            ]);
            exit;
        }

        // Validate feedback value
        if (!in_array($feedback, ['relevant', 'not_relevant', 'completed', 'too_hard', 'too_easy'])) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Bad Request',
                'message' => 'Invalid feedback value'
            ]);
            exit;
        }

        // Students can only provide feedback for themselves
        if ($user['role'] === 'student' && $user['id'] !== $studentId) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Forbidden',
                'message' => 'You can only provide feedback for yourself'
            ]);
            exit;
        }

        // Record feedback
        $result = $recommendationEngine->recordFeedback($studentId, $tenantId, $themeId, $feedback);

        logInfo('Recommendation feedback recorded', [
            'student_id' => $studentId,
            'theme_id' => $themeId,
            'feedback' => $feedback,
            'user_id' => $user['id']
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Feedback recorded successfully'
        ]);
        exit;
    }

    // Method not allowed
    http_response_code(405);
    echo json_encode([
        'error' => 'Method Not Allowed',
        'allowed_methods' => ['GET', 'POST']
    ]);

} catch (Exception $e) {
    logError('Recommendations API error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
}
