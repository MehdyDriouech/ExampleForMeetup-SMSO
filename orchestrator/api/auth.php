<?php
/**
 * POST /api/auth/login - Authentification et génération JWT
 * GET /api/auth/me - Profil utilisateur connecté
 */

require_once __DIR__ . '/../.env.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// POST /api/auth/login
if ($method === 'POST') {
    $body = getRequestBody();
    
    $email = $body['email'] ?? null;
    $password = $body['password'] ?? null;
    
    if (!$email || !$password) {
        errorResponse('VALIDATION_ERROR', 'Email and password are required', 400);
    }
    
    if (!isValidEmail($email)) {
        errorResponse('VALIDATION_ERROR', 'Invalid email format', 400);
    }
    
    $result = login($email, $password);
    
    if (!$result) {
        errorResponse('UNAUTHORIZED', 'Invalid credentials', 401);
    }
    
    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/auth/login', 200, $duration, [
        'user_id' => $result['user']['id']
    ]);
    
    jsonResponse($result);
}

// GET /api/auth/me
if ($method === 'GET') {
    $auth = requireAuth();
    $user = $auth->getUser();
    
    // Si JWT, récupérer les détails complets de l'utilisateur
    if ($user['auth_method'] === 'jwt' && isset($user['user_id'])) {
        $fullUser = db()->queryOne(
            'SELECT id, tenant_id, email, firstname, lastname, role, status 
             FROM users WHERE id = :id',
            ['id' => $user['user_id']]
        );
        
        if ($fullUser) {
            $user = array_merge($user, $fullUser);
        }
    }
    
    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/auth/me', 200, $duration);
    
    jsonResponse($user);
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
