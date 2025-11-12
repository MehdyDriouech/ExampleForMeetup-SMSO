<?php
/**
 * GET /api/health
 * 
 * Health check endpoint
 */

require_once __DIR__ . '/../.env.php';

setCorsHeaders();

$start = microtime(true);

$response = [
    'status' => 'ok',
    'version' => APP_VERSION,
    'timestamp' => date('c')
];

// Test DB si demandé
$check = $_GET['check'] ?? null;

if ($check === 'db' || $check === 'full') {
    try {
        $dbStatus = db()->testConnection();
        $response['database'] = $dbStatus;
        
        if ($dbStatus['status'] !== 'ok') {
            $response['status'] = 'degraded';
            http_response_code(503);
        }
    } catch (Exception $e) {
        $response['status'] = 'error';
        $response['database'] = [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
        http_response_code(503);
    }
}

$duration = (microtime(true) - $start) * 1000;
logger()->logRequest('/api/health', http_response_code(), $duration);

jsonResponse($response);
