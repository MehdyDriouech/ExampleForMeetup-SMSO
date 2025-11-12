<?php
/**
 * Study-mate School Orchestrator - Authentication Middleware
 * 
 * Support AUTH_MODE: URLENCODED, JWT, MIXED
 */

class Auth {
    private $mode;
    private $user = null;
    private $tenantId = null;
    private $scope = null;
    
    public function __construct() {
        $this->mode = AUTH_MODE;
    }
    
    /**
     * Vérifier l'authentification
     */
    public function check() {
        if ($this->mode === 'URLENCODED') {
            return $this->checkUrlEncoded();
        }
        
        if ($this->mode === 'JWT') {
            return $this->checkJwt();
        }
        
        if ($this->mode === 'MIXED') {
            // Priorité à UrlEncoded, fallback sur JWT
            if ($this->hasUrlEncodedCredentials()) {
                return $this->checkUrlEncoded();
            }
            return $this->checkJwt();
        }
        
        $this->unauthorized('Invalid AUTH_MODE');
    }
    
    /**
     * Authentification UrlEncoded
     */
    private function checkUrlEncoded() {
        $body = getRequestBody();
        
        $apiKey = $body['api_key'] ?? null;
        $tenantId = $body['tenant_id'] ?? null;
        $scope = $body['scope'] ?? null;
        
        if (!$apiKey || !$tenantId || !$scope) {
            $this->unauthorized('Missing api_key, tenant_id or scope');
        }
        
        // Vérifier la clé API
        if (!isset($GLOBALS['API_KEYS'][$scope]) || $GLOBALS['API_KEYS'][$scope] !== $apiKey) {
            logWarn('Invalid API key', [
                'scope' => $scope,
                'tenant_id' => $tenantId
            ]);
            $this->forbidden('Invalid API key');
        }
        
        // Vérifier le tenant
        $tenant = db()->queryOne(
            'SELECT id, name, status FROM tenants WHERE id = :id',
            ['id' => $tenantId]
        );
        
        if (!$tenant) {
            $this->forbidden('Tenant not found');
        }
        
        if ($tenant['status'] !== 'active') {
            $this->forbidden('Tenant suspended');
        }
        
        // Stocker les infos d'auth
        $this->tenantId = $tenantId;
        $this->scope = $scope;
        $this->user = [
            'auth_method' => 'urlencoded',
            'scope' => $scope,
            'tenant_id' => $tenantId
        ];
        
        // Vérifier le header X-Orchestrator-Id si présent
        $headerTenantId = getHeader('X-Orchestrator-Id');
        if ($headerTenantId && $headerTenantId !== $tenantId) {
            $this->forbidden('Tenant ID mismatch');
        }
        
        return true;
    }
    
    /**
     * Authentification JWT
     */
    private function checkJwt() {
        $authHeader = getHeader('Authorization');
        
        if (!$authHeader || !preg_match('/Bearer\s+(.*)/', $authHeader, $matches)) {
            $this->unauthorized('Missing or invalid Authorization header');
        }
        
        $token = $matches[1];
        
        try {
            $payload = $this->verifyJwt($token);
            
            // Vérifier l'expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                $this->unauthorized('Token expired');
            }
            
            // Vérifier le tenant
            $tenantId = $payload['tenant_id'] ?? null;
            if (!$tenantId) {
                $this->forbidden('Missing tenant_id in token');
            }
            
            $tenant = db()->queryOne(
                'SELECT id, name, status FROM tenants WHERE id = :id',
                ['id' => $tenantId]
            );
            
            if (!$tenant) {
                $this->forbidden('Tenant not found');
            }
            
            if ($tenant['status'] !== 'active') {
                $this->forbidden('Tenant suspended');
            }
            
            // Stocker les infos d'auth
            $this->tenantId = $tenantId;
            $this->scope = $payload['scope'] ?? 'user';
            $this->user = [
                'auth_method' => 'jwt',
                'user_id' => $payload['sub'] ?? null,
                'scope' => $this->scope,
                'tenant_id' => $tenantId,
                'payload' => $payload
            ];
            
            // Vérifier le header X-Orchestrator-Id si présent
            $headerTenantId = getHeader('X-Orchestrator-Id');
            if ($headerTenantId && $headerTenantId !== $tenantId) {
                $this->forbidden('Tenant ID mismatch');
            }
            
            return true;
            
        } catch (Exception $e) {
            logError('JWT verification failed', ['error' => $e->getMessage()]);
            $this->unauthorized('Invalid token');
        }
    }
    
    /**
     * Vérifier un JWT (simple HS256)
     */
    private function verifyJwt($token) {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            throw new Exception('Invalid token structure');
        }
        
        list($headerB64, $payloadB64, $signatureB64) = $parts;
        
        // Vérifier la signature
        $signature = $this->base64UrlDecode($signatureB64);
        $expectedSignature = hash_hmac('sha256', "$headerB64.$payloadB64", JWT_SECRET, true);
        
        if (!hash_equals($expectedSignature, $signature)) {
            throw new Exception('Invalid signature');
        }
        
        // Décoder le payload
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        
        if (!$payload) {
            throw new Exception('Invalid payload');
        }
        
        return $payload;
    }
    
    /**
     * Générer un JWT
     */
    public static function generateJwt($userId, $tenantId, $scope, $expiresIn = null) {
        $expiresIn = $expiresIn ?? JWT_EXPIRY_SECONDS;
        
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];
        
        $payload = [
            'sub' => $userId,
            'tenant_id' => $tenantId,
            'scope' => $scope,
            'iat' => time(),
            'exp' => time() + $expiresIn
        ];
        
        $headerB64 = self::base64UrlEncode(json_encode($header));
        $payloadB64 = self::base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', "$headerB64.$payloadB64", JWT_SECRET, true);
        $signatureB64 = self::base64UrlEncode($signature);
        
        return "$headerB64.$payloadB64.$signatureB64";
    }
    
    /**
     * Base64 URL-safe encode
     */
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL-safe decode
     */
    private function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Vérifier si les credentials UrlEncoded sont présentes
     */
    private function hasUrlEncodedCredentials() {
        $body = getRequestBody();
        return isset($body['api_key']) && isset($body['tenant_id']) && isset($body['scope']);
    }
    
    /**
     * Vérifier les scopes
     */
    public function requireScope($requiredScope) {
        if (!$this->scope) {
            $this->forbidden('No scope defined');
        }
        
        // Admin a tous les droits
        if ($this->scope === 'admin') {
            return true;
        }
        
        // Vérifier le scope exact
        if ($this->scope !== $requiredScope) {
            $this->forbidden("Insufficient permissions. Required: $requiredScope");
        }
        
        return true;
    }
    
    /**
     * Vérifier que le tenant correspond
     */
    public function requireTenant($tenantId) {
        if ($this->tenantId !== $tenantId) {
            $this->forbidden('Tenant mismatch');
        }
        return true;
    }
    
    /**
     * Récupérer l'utilisateur authentifié
     */
    public function getUser() {
        return $this->user;
    }
    
    /**
     * Récupérer le tenant ID
     */
    public function getTenantId() {
        return $this->tenantId;
    }
    
    /**
     * Récupérer le scope
     */
    public function getScope() {
        return $this->scope;
    }
    
    /**
     * Erreur 401
     */
    private function unauthorized($message = 'Unauthorized') {
        logWarn('Unauthorized access attempt', [
            'message' => $message,
            'ip' => getClientIp()
        ]);
        errorResponse('UNAUTHORIZED', $message, 401);
    }
    
    /**
     * Erreur 403
     */
    private function forbidden($message = 'Forbidden') {
        logWarn('Forbidden access attempt', [
            'message' => $message,
            'ip' => getClientIp(),
            'tenant_id' => $this->tenantId
        ]);
        errorResponse('FORBIDDEN', $message, 403);
    }
}

/**
 * Middleware global
 */
function requireAuth() {
    $auth = new Auth();
    $auth->check();
    return $auth;
}

/**
 * Login et génération de JWT
 */
function login($email, $password) {
    $user = db()->queryOne(
        'SELECT u.*, t.status as tenant_status 
         FROM users u 
         JOIN tenants t ON u.tenant_id = t.id 
         WHERE u.email = :email',
        ['email' => $email]
    );
    
    if (!$user) {
        logWarn('Login failed - user not found', ['email' => $email]);
        return null;
    }
    
    if (!verifyPassword($password, $user['password_hash'])) {
        logWarn('Login failed - invalid password', ['email' => $email]);
        return null;
    }
    
    if ($user['status'] !== 'active') {
        logWarn('Login failed - user inactive', ['email' => $email]);
        return null;
    }
    
    if ($user['tenant_status'] !== 'active') {
        logWarn('Login failed - tenant suspended', ['email' => $email]);
        return null;
    }
    
    // Mettre à jour last_login
    db()->update('users', 
        ['last_login_at' => date('Y-m-d H:i:s')],
        'id = :id',
        ['id' => $user['id']]
    );
    
    // Générer JWT
    $token = Auth::generateJwt(
        $user['id'],
        $user['tenant_id'],
        $user['role']
    );
    
    logInfo('User logged in', [
        'user_id' => $user['id'],
        'tenant_id' => $user['tenant_id']
    ]);
    
    return [
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'tenantId' => $user['tenant_id'],
            'email' => $user['email'],
            'firstname' => $user['firstname'],
            'lastname' => $user['lastname'],
            'role' => $user['role']
        ],
        'expiresAt' => date('c', time() + JWT_EXPIRY_SECONDS)
    ];
}
