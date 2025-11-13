<?php
/**
 * Sprint 13 - Quality Analysis API
 * Provides AI confidence scoring and content linting
 *
 * Endpoints:
 * - POST /api/quality/analyze - Analyze theme quality
 * - POST /api/quality/lint - Lint theme content
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/logging.php';
require_once __DIR__ . '/../services/ai_quality.php';
require_once __DIR__ . '/../services/theme_linter.php';
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rbac.php';

header('Content-Type: application/json; charset=utf-8');

$tenantContext = tenantMiddleware();
$user = rbacMiddleware(['teacher', 'admin', 'direction', 'intervenant', 'inspector']);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

try {
    // POST /api/quality/analyze - AI Confidence Analysis
    if ($method === 'POST' && preg_match('#/api/quality/analyze$#', $path)) {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['theme_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'theme_id required']);
            exit;
        }

        // Fetch theme
        $stmt = db()->prepare("
            SELECT id, title, content_json, status
            FROM themes
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$input['theme_id'], $tenantContext['tenant_id']]);
        $theme = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            exit;
        }

        // Check permissions
        if (!in_array($user['role'], ['admin', 'direction', 'teacher'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        // Parse content
        $themeData = json_decode($theme['content_json'], true);
        if (!$themeData) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid theme content']);
            exit;
        }

        // Run analysis
        $report = AIQualityService::analyzeTheme($themeData);
        $badge = AIQualityService::getQualityBadge($report['overall_confidence']);

        // Store analysis result
        $stmt = db()->prepare("
            INSERT INTO quality_reports (theme_id, tenant_id, report_type, confidence_score, report_data, created_by, created_at)
            VALUES (?, ?, 'ai_confidence', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $theme['id'],
            $tenantContext['tenant_id'],
            $report['overall_confidence'],
            json_encode($report),
            $user['user_id']
        ]);

        logEvent('quality_analysis_performed', [
            'theme_id' => $theme['id'],
            'confidence' => $report['overall_confidence'],
            'user_id' => $user['user_id']
        ]);

        echo json_encode([
            'success' => true,
            'report' => $report,
            'badge' => $badge
        ]);
        exit;
    }

    // POST /api/quality/lint - Content Linting
    if ($method === 'POST' && preg_match('#/api/quality/lint$#', $path)) {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['theme_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'theme_id required']);
            exit;
        }

        // Fetch theme
        $stmt = db()->prepare("
            SELECT id, title, content_json, status
            FROM themes
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$input['theme_id'], $tenantContext['tenant_id']]);
        $theme = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$theme) {
            http_response_code(404);
            echo json_encode(['error' => 'Theme not found']);
            exit;
        }

        // Parse content
        $themeData = json_decode($theme['content_json'], true);
        if (!$themeData) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid theme content']);
            exit;
        }

        // Run linter
        $report = ThemeLinter::lint($themeData);
        $summary = ThemeLinter::getSummary($report);

        // Store lint result
        $stmt = db()->prepare("
            INSERT INTO quality_reports (theme_id, tenant_id, report_type, confidence_score, report_data, created_by, created_at)
            VALUES (?, ?, 'linting', ?, ?, ?, NOW())
        ");
        $errorScore = 1.0 - (count($report['errors']) * 0.2 + count($report['warnings']) * 0.05);
        $errorScore = max(0, min(1, $errorScore));

        $stmt->execute([
            $theme['id'],
            $tenantContext['tenant_id'],
            $errorScore,
            json_encode($report),
            $user['user_id']
        ]);

        logEvent('quality_lint_performed', [
            'theme_id' => $theme['id'],
            'errors' => count($report['errors']),
            'warnings' => count($report['warnings']),
            'user_id' => $user['user_id']
        ]);

        echo json_encode([
            'success' => true,
            'report' => $report,
            'summary' => $summary
        ]);
        exit;
    }

    // Method not allowed
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Exception $e) {
    logError('Quality API error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
