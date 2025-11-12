<?php
/**
 * Study-mate School Orchestrator - Utilitaires
 */

/**
 * Retourner une réponse JSON
 */
function jsonResponse($data, $statusCode = 200, $headers = []) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    
    foreach ($headers as $key => $value) {
        header("$key: $value");
    }
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Erreur JSON standardisée
 */
function errorResponse($code, $message, $statusCode = 400, $details = null) {
    $response = [
        'code' => $code,
        'message' => $message,
        'timestamp' => date('c')
    ];
    
    if ($details && APP_DEBUG) {
        $response['details'] = $details;
    }
    
    jsonResponse($response, $statusCode);
}

/**
 * Valider les champs requis
 */
function validateRequired($data, $requiredFields) {
    $missing = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        errorResponse(
            'VALIDATION_ERROR',
            'Missing required fields: ' . implode(', ', $missing),
            400
        );
    }
}

/**
 * Générer un UUID v4
 */
function generateUUID() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Générer un ID préfixé
 */
function generateId($prefix) {
    return $prefix . '_' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 12));
}

/**
 * Sanitize string
 */
function sanitize($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * Parser le body de la requête
 */
function getRequestBody() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    // JSON
    if (strpos($contentType, 'application/json') !== false) {
        $body = file_get_contents('php://input');
        return json_decode($body, true) ?: [];
    }
    
    // Form-urlencoded ou multipart
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return $_POST;
    }
    
    // Autres cas : parser le body brut
    parse_str(file_get_contents('php://input'), $data);
    return $data;
}

/**
 * Obtenir un header HTTP
 */
function getHeader($name) {
    $name = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$name] ?? null;
}

/**
 * Vérifier si la requête est en HTTPS
 */
function isHttps() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? 0) == 443;
}

/**
 * Obtenir l'IP du client
 */
function getClientIp() {
    $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = explode(',', $_SERVER[$key])[0];
            return trim($ip);
        }
    }
    
    return '0.0.0.0';
}

/**
 * Hash de payload pour idempotence
 */
function payloadHash($data) {
    return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
}

/**
 * CORS headers
 */
function setCorsHeaders() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (in_array($origin, CORS_ALLOWED_ORIGINS)) {
        header("Access-Control-Allow-Origin: $origin");
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    
    header('Access-Control-Allow-Methods: ' . CORS_ALLOWED_METHODS);
    header('Access-Control-Allow-Headers: ' . CORS_ALLOWED_HEADERS);
    header('Access-Control-Max-Age: ' . CORS_MAX_AGE);
    header('Access-Control-Allow-Credentials: true');
    
    // Gérer les requêtes OPTIONS (preflight)
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Valider un email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Valider une date ISO 8601
 */
function isValidIsoDate($date) {
    $d = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $date);
    return $d && $d->format('Y-m-d\TH:i:s\Z') === $date;
}

/**
 * Pagination helper
 */
function paginate($query, $limit, $offset, $pdo) {
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $total = $stmt->rowCount();
    
    $queryPaginated = $query . " LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($queryPaginated);
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    
    return [
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => [
            'total' => $total,
            'limit' => (int)$limit,
            'offset' => (int)$offset
        ]
    ];
}

/**
 * Calcul de hash bcrypt
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

/**
 * Vérification de hash bcrypt
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Nettoyer un chemin de fichier
 */
function sanitizePath($path) {
    return preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $path);
}

/**
 * Formater les bytes en taille lisible
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Mesurer le temps d'exécution
 */
function benchmark($callback) {
    $start = microtime(true);
    $result = $callback();
    $duration = (microtime(true) - $start) * 1000; // ms
    
    return [
        'result' => $result,
        'duration_ms' => round($duration, 2)
    ];
}

/**
 * Rate limiting simple (basé sur fichiers)
 */
function checkRateLimit($key, $maxRequests, $windowSeconds) {
    if (!RATE_LIMIT_ENABLED) {
        return true;
    }
    
    $cacheFile = CACHE_DIR . '/rate_' . md5($key) . '.json';
    
    if (!file_exists($cacheFile)) {
        $data = ['count' => 1, 'reset' => time() + $windowSeconds];
        file_put_contents($cacheFile, json_encode($data));
        return true;
    }
    
    $data = json_decode(file_get_contents($cacheFile), true);
    
    if (time() > $data['reset']) {
        $data = ['count' => 1, 'reset' => time() + $windowSeconds];
        file_put_contents($cacheFile, json_encode($data));
        return true;
    }
    
    $data['count']++;
    file_put_contents($cacheFile, json_encode($data));
    
    if ($data['count'] > $maxRequests) {
        header('X-RateLimit-Limit: ' . $maxRequests);
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Reset: ' . $data['reset']);
        return false;
    }
    
    header('X-RateLimit-Limit: ' . $maxRequests);
    header('X-RateLimit-Remaining: ' . ($maxRequests - $data['count']));
    header('X-RateLimit-Reset: ' . $data['reset']);
    
    return true;
}
