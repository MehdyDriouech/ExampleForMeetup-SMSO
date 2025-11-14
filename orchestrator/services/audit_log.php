<?php
/**
 * Audit Log Service
 *
 * Traces all critical admin actions for security and compliance.
 * All actions are logged with actor, target, timestamp, and payload.
 *
 * Usage:
 *   require_once __DIR__ . '/../services/audit_log.php';
 *   $auditLog = new AuditLogService();
 *   $auditLog->log('create', 'user', $userId, ['email' => $email]);
 *
 * @version 1.0
 * @date 2025-11-14
 */

// Load core libraries if not already loaded
if (!function_exists('db')) {
    require_once __DIR__ . '/../.env.php';
}

class AuditLogService {
    private $tenantId;
    private $actorUserId;
    private $actorRole;

    /**
     * Constructor
     *
     * @param string $tenantId Tenant ID
     * @param string|null $actorUserId User performing the action
     * @param string|null $actorRole Role of the actor
     */
    public function __construct($tenantId, $actorUserId = null, $actorRole = null) {
        $this->tenantId = $tenantId;
        $this->actorUserId = $actorUserId;
        $this->actorRole = $actorRole;
    }

    /**
     * Log an audit entry
     *
     * @param string $actionType Type of action (create, update, delete, deactivate, etc.)
     * @param string $targetType Type of target (user, class, role, licence, etc.)
     * @param string|null $targetId ID of the target
     * @param array|null $payload Additional data about the action
     * @param string $result Result of action (success|failed)
     * @param string|null $errorMessage Error message if failed
     * @return string Audit log ID
     */
    public function log($actionType, $targetType, $targetId = null, $payload = null, $result = 'success', $errorMessage = null) {
        try {
            $id = $this->generateId();

            $data = [
                'id' => $id,
                'tenant_id' => $this->tenantId,
                'actor_user_id' => $this->actorUserId,
                'action_type' => $actionType,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'payload' => $payload ? json_encode($payload) : null,
                'ip_address' => $this->getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'result' => $result,
                'error_message' => $errorMessage
            ];

            db()->insert('audit_log', $data);

            // Log to system logger as well
            logInfo("Audit: {$actionType} {$targetType}", [
                'audit_id' => $id,
                'tenant_id' => $this->tenantId,
                'actor_user_id' => $this->actorUserId,
                'target_id' => $targetId,
                'result' => $result
            ]);

            return $id;
        } catch (Exception $e) {
            logError('Failed to write audit log', [
                'error' => $e->getMessage(),
                'action_type' => $actionType,
                'target_type' => $targetType
            ]);
            // Don't throw - audit log failure should not break the application
            return null;
        }
    }

    /**
     * Log user creation
     */
    public function logUserCreate($userId, $email, $role) {
        return $this->log('user_create', 'user', $userId, [
            'email' => $email,
            'role' => $role
        ]);
    }

    /**
     * Log user update
     */
    public function logUserUpdate($userId, $changes) {
        return $this->log('user_update', 'user', $userId, [
            'changes' => $changes
        ]);
    }

    /**
     * Log user deactivation
     */
    public function logUserDeactivate($userId, $email, $reason = null) {
        return $this->log('user_deactivate', 'user', $userId, [
            'email' => $email,
            'reason' => $reason
        ]);
    }

    /**
     * Log user activation
     */
    public function logUserActivate($userId, $email) {
        return $this->log('user_activate', 'user', $userId, [
            'email' => $email
        ]);
    }

    /**
     * Log class creation
     */
    public function logClassCreate($classId, $name, $level) {
        return $this->log('class_create', 'class', $classId, [
            'name' => $name,
            'level' => $level
        ]);
    }

    /**
     * Log class update
     */
    public function logClassUpdate($classId, $changes) {
        return $this->log('class_update', 'class', $classId, [
            'changes' => $changes
        ]);
    }

    /**
     * Log class archive
     */
    public function logClassArchive($classId, $name) {
        return $this->log('class_archive', 'class', $classId, [
            'name' => $name
        ]);
    }

    /**
     * Log role configuration change
     */
    public function logRoleConfigUpdate($role, $changes) {
        return $this->log('role_config_update', 'role', $role, [
            'changes' => $changes
        ]);
    }

    /**
     * Log licence quota change
     */
    public function logLicenceUpdate($changes) {
        return $this->log('licence_update', 'licence', $this->tenantId, [
            'changes' => $changes
        ]);
    }

    /**
     * Log user-class assignment
     */
    public function logUserClassAssign($userId, $classId, $isPrimary = false) {
        return $this->log('user_class_assign', 'user_class', null, [
            'user_id' => $userId,
            'class_id' => $classId,
            'is_primary' => $isPrimary
        ]);
    }

    /**
     * Log user-class unassignment
     */
    public function logUserClassUnassign($userId, $classId) {
        return $this->log('user_class_unassign', 'user_class', null, [
            'user_id' => $userId,
            'class_id' => $classId
        ]);
    }

    /**
     * Log password reset
     */
    public function logPasswordReset($userId, $email) {
        return $this->log('password_reset', 'user', $userId, [
            'email' => $email
        ]);
    }

    /**
     * Log failed action
     */
    public function logFailed($actionType, $targetType, $targetId, $errorMessage, $payload = null) {
        return $this->log($actionType, $targetType, $targetId, $payload, 'failed', $errorMessage);
    }

    /**
     * Get audit logs for a tenant
     *
     * @param array $filters Optional filters (from, to, action_type, target_type, actor_user_id)
     * @param int $limit Max number of results
     * @param int $offset Offset for pagination
     * @return array List of audit log entries
     */
    public function getLogs($filters = [], $limit = 100, $offset = 0) {
        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $this->tenantId];

        if (isset($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params['from'] = $filters['from'];
        }

        if (isset($filters['to'])) {
            $where[] = 'created_at <= :to';
            $params['to'] = $filters['to'];
        }

        if (isset($filters['action_type'])) {
            $where[] = 'action_type = :action_type';
            $params['action_type'] = $filters['action_type'];
        }

        if (isset($filters['target_type'])) {
            $where[] = 'target_type = :target_type';
            $params['target_type'] = $filters['target_type'];
        }

        if (isset($filters['actor_user_id'])) {
            $where[] = 'actor_user_id = :actor_user_id';
            $params['actor_user_id'] = $filters['actor_user_id'];
        }

        if (isset($filters['result'])) {
            $where[] = 'result = :result';
            $params['result'] = $filters['result'];
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT a.*, u.email as actor_email, u.firstname as actor_firstname, u.lastname as actor_lastname
                FROM audit_log a
                LEFT JOIN users u ON a.actor_user_id = u.id
                WHERE {$whereClause}
                ORDER BY a.created_at DESC
                LIMIT :limit OFFSET :offset";

        $params['limit'] = $limit;
        $params['offset'] = $offset;

        return db()->query($sql, $params);
    }

    /**
     * Get total count of audit logs
     */
    public function getCount($filters = []) {
        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $this->tenantId];

        if (isset($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params['from'] = $filters['from'];
        }

        if (isset($filters['to'])) {
            $where[] = 'created_at <= :to';
            $params['to'] = $filters['to'];
        }

        if (isset($filters['action_type'])) {
            $where[] = 'action_type = :action_type';
            $params['action_type'] = $filters['action_type'];
        }

        if (isset($filters['target_type'])) {
            $where[] = 'target_type = :target_type';
            $params['target_type'] = $filters['target_type'];
        }

        if (isset($filters['actor_user_id'])) {
            $where[] = 'actor_user_id = :actor_user_id';
            $params['actor_user_id'] = $filters['actor_user_id'];
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT COUNT(*) as total FROM audit_log WHERE {$whereClause}";

        $result = db()->queryOne($sql, $params);
        return (int)($result['total'] ?? 0);
    }

    /**
     * Generate unique ID
     */
    private function generateId() {
        return 'audit_' . uniqid() . '_' . bin2hex(random_bytes(8));
    }

    /**
     * Get client IP address
     */
    private function getClientIp() {
        $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

        foreach ($keys as $key) {
            if (isset($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Take first IP if multiple
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                return trim($ip);
            }
        }

        return 'unknown';
    }
}

/**
 * Create audit log service from auth context
 *
 * @param string $tenantId Tenant ID
 * @param Auth|null $auth Optional auth context
 * @return AuditLogService
 */
function createAuditLog($tenantId, $auth = null) {
    $actorUserId = null;
    $actorRole = null;

    if ($auth) {
        $user = $auth->getUser();
        $actorUserId = $user['id'] ?? $user['user_id'] ?? null;
        $actorRole = $user['role'] ?? null;
    }

    return new AuditLogService($tenantId, $actorUserId, $actorRole);
}
