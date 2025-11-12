<?php
/**
 * Role-Based Access Control (RBAC) Middleware
 *
 * Enforces granular permissions based on user roles and scopes.
 * Defines what actions each role can perform on resources.
 *
 * Roles:
 * - admin: Full access within tenant
 * - direction: Aggregated views, reports, user management
 * - teacher: CRUD on own assignments, themes, view own students
 * - intervenant: Limited access to specific classes
 *
 * Usage:
 *   require_once __DIR__ . '/_middleware_rbac.php';
 *   $rbac = enforceRBAC($auth);
 *   $rbac->requirePermission('assignments', 'create');
 *   $rbac->requirePermission('stats', 'read');
 *
 * @version 1.0
 * @date 2025-11-12
 */

// Load core libraries if not already loaded
if (!function_exists('db')) {
    require_once __DIR__ . '/../.env.php';
}

/**
 * Permission definitions for each role
 *
 * Format: [resource => [action => [roles with permission]]]
 */
const RBAC_PERMISSIONS = [
    // Assignments
    'assignments' => [
        'create' => ['admin', 'teacher', 'direction'],
        'read' => ['admin', 'teacher', 'direction', 'inspector', 'intervenant'],
        'read_all' => ['admin', 'direction', 'inspector'],  // Read all, not just own
        'update' => ['admin', 'teacher'],  // Teachers can only update own
        'update_any' => ['admin', 'direction'],  // Can update any
        'delete' => ['admin', 'teacher'],  // Teachers can only delete own
        'delete_any' => ['admin', 'direction'],  // Can delete any
        'push' => ['admin', 'teacher', 'direction'],  // Push to ErgoMate
    ],

    // Themes (pedagogical content)
    'themes' => [
        'create' => ['admin', 'teacher', 'direction'],
        'read' => ['admin', 'teacher', 'direction', 'inspector', 'intervenant'],
        'read_all' => ['admin', 'direction', 'inspector'],
        'update' => ['admin', 'teacher'],  // Teachers can only update own
        'update_any' => ['admin', 'direction'],
        'delete' => ['admin', 'teacher'],  // Teachers can only delete own
        'delete_any' => ['admin', 'direction'],
    ],

    // Students
    'students' => [
        'create' => ['admin', 'direction'],
        'read' => ['admin', 'teacher', 'direction', 'inspector', 'intervenant'],
        'read_all' => ['admin', 'direction', 'inspector'],  // All students in tenant
        'update' => ['admin', 'direction'],
        'delete' => ['admin', 'direction'],
    ],

    // Classes
    'classes' => [
        'create' => ['admin', 'direction'],
        'read' => ['admin', 'teacher', 'direction', 'inspector', 'intervenant'],
        'read_all' => ['admin', 'direction', 'inspector'],
        'update' => ['admin', 'direction'],
        'delete' => ['admin', 'direction'],
    ],

    // Promotions (cohorts)
    'promotions' => [
        'create' => ['admin', 'direction'],
        'read' => ['admin', 'teacher', 'direction', 'inspector', 'intervenant'],
        'update' => ['admin', 'direction'],
        'delete' => ['admin', 'direction'],
    ],

    // Stats (student performance)
    'stats' => [
        'read' => ['admin', 'teacher', 'direction', 'inspector'],
        'read_all' => ['admin', 'direction', 'inspector'],  // All stats in tenant
        'sync' => ['admin', 'teacher', 'direction'],  // Trigger sync from ErgoMate
    ],

    // Users (teachers, admins)
    'users' => [
        'create' => ['admin', 'direction'],
        'read' => ['admin', 'direction'],
        'update' => ['admin', 'direction'],
        'update_self' => ['admin', 'teacher', 'direction', 'inspector', 'intervenant'],
        'delete' => ['admin'],
    ],

    // Dashboard & Reports
    'dashboard' => [
        'view_summary' => ['admin', 'teacher', 'direction', 'inspector'],
        'view_aggregated' => ['admin', 'direction', 'inspector'],  // Full tenant view
        'export_reports' => ['admin', 'direction', 'inspector'],
    ],

    // AI/Mistral
    'ai' => [
        'generate' => ['admin', 'teacher', 'direction'],
        'manage_queue' => ['admin', 'direction'],
        'manage_keys' => ['admin', 'direction'],
    ],

    // Sync operations
    'sync' => [
        'pull_stats' => ['admin', 'teacher', 'direction'],
        'push_assignments' => ['admin', 'teacher', 'direction'],
        'view_logs' => ['admin', 'direction', 'inspector'],
    ],
];

/**
 * RBAC Context
 */
class RBACContext {
    private $auth;
    private $user;
    private $role;
    private $tenantId;

    public function __construct($auth) {
        $this->auth = $auth;
        $this->user = $auth->getUser();
        $this->role = $this->user['role'] ?? null;
        $this->tenantId = $auth->getTenantId();
    }

    /**
     * Get user role
     */
    public function getRole() {
        return $this->role;
    }

    /**
     * Get user ID
     */
    public function getUserId() {
        return $this->user['id'] ?? null;
    }

    /**
     * Get tenant ID
     */
    public function getTenantId() {
        return $this->tenantId;
    }

    /**
     * Check if user has permission for action on resource
     *
     * @param string $resource Resource name (e.g., 'assignments')
     * @param string $action Action name (e.g., 'create', 'read')
     * @return bool True if permitted
     */
    public function can($resource, $action) {
        if (!isset(RBAC_PERMISSIONS[$resource])) {
            logWarn("Unknown resource in RBAC check", [
                'resource' => $resource,
                'action' => $action,
                'role' => $this->role
            ]);
            return false;
        }

        if (!isset(RBAC_PERMISSIONS[$resource][$action])) {
            logWarn("Unknown action in RBAC check", [
                'resource' => $resource,
                'action' => $action,
                'role' => $this->role
            ]);
            return false;
        }

        $allowedRoles = RBAC_PERMISSIONS[$resource][$action];
        $hasPermission = in_array($this->role, $allowedRoles);

        if (!$hasPermission) {
            logDebug("RBAC permission denied", [
                'resource' => $resource,
                'action' => $action,
                'role' => $this->role,
                'user_id' => $this->getUserId(),
                'allowed_roles' => $allowedRoles
            ]);
        }

        return $hasPermission;
    }

    /**
     * Require permission or throw 403
     *
     * @param string $resource Resource name
     * @param string $action Action name
     * @param string $customMessage Optional custom error message
     * @throws Exception with 403 status
     */
    public function requirePermission($resource, $action, $customMessage = null) {
        if (!$this->can($resource, $action)) {
            $message = $customMessage ?? "You do not have permission to {$action} {$resource}.";

            logWarn("RBAC permission denied - throwing 403", [
                'resource' => $resource,
                'action' => $action,
                'role' => $this->role,
                'user_id' => $this->getUserId(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);

            http_response_code(403);
            echo json_encode([
                'error' => 'forbidden',
                'message' => $message,
                'required_permission' => "{$resource}:{$action}",
                'your_role' => $this->role
            ]);
            exit;
        }
    }

    /**
     * Check if user owns a resource
     *
     * @param string $resourceType Type of resource
     * @param array|object $resource Resource data with created_by or teacher_id
     * @return bool True if user owns the resource
     */
    public function owns($resourceType, $resource) {
        // Admin owns everything in their tenant
        if ($this->role === 'admin') {
            return true;
        }

        $userId = $this->getUserId();

        // Convert object to array if needed
        if (is_object($resource)) {
            $resource = (array) $resource;
        }

        // Check common ownership fields
        $ownerFields = ['created_by', 'teacher_id', 'user_id', 'assigned_to'];

        foreach ($ownerFields as $field) {
            if (isset($resource[$field]) && $resource[$field] === $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Require ownership of a resource
     *
     * @param string $resourceType Type of resource
     * @param array|object $resource Resource data
     * @param string $alternatePermission Optional alternate permission that grants access
     * @throws Exception with 403 status
     */
    public function requireOwnership($resourceType, $resource, $alternatePermission = null) {
        // Check alternate permission first (e.g., 'update_any')
        if ($alternatePermission && $this->can($resourceType, $alternatePermission)) {
            return;
        }

        if (!$this->owns($resourceType, $resource)) {
            logWarn("Ownership check failed", [
                'resource_type' => $resourceType,
                'user_id' => $this->getUserId(),
                'role' => $this->role,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);

            http_response_code(403);
            echo json_encode([
                'error' => 'forbidden',
                'message' => "You can only access your own {$resourceType}.",
                'your_role' => $this->role
            ]);
            exit;
        }
    }

    /**
     * Check if user can access another user's data
     *
     * @param string $targetUserId User ID to check access for
     * @return bool True if access allowed
     */
    public function canAccessUser($targetUserId) {
        // Can always access own data
        if ($targetUserId === $this->getUserId()) {
            return true;
        }

        // Admin and direction can access any user in their tenant
        if (in_array($this->role, ['admin', 'direction'])) {
            return true;
        }

        return false;
    }

    /**
     * Apply ownership filter to SQL query
     *
     * Returns WHERE condition for ownership filtering.
     * Use when users should only see their own resources.
     *
     * @param string $resourceType Type of resource
     * @param string $ownerField Field name containing owner ID (default: 'created_by')
     * @param string $tableAlias Optional table alias
     * @return string SQL WHERE condition or '1=1' if user can see all
     */
    public function ownershipWhere($resourceType, $ownerField = 'created_by', $tableAlias = null) {
        // Admin and roles with 'read_all' permission see everything
        if ($this->can($resourceType, 'read_all')) {
            return '1=1';
        }

        // Others only see their own
        $field = $tableAlias ? "{$tableAlias}.{$ownerField}" : $ownerField;
        $userId = $this->getUserId();

        return "{$field} = " . db()->quote($userId);
    }

    /**
     * Get role display name
     */
    public function getRoleDisplayName() {
        $names = [
            'admin' => 'Administrator',
            'direction' => 'Director',
            'teacher' => 'Teacher',
            'inspector' => 'Inspector',
            'intervenant' => 'Contractor'
        ];

        return $names[$this->role] ?? $this->role;
    }

    /**
     * Get all permissions for current role
     *
     * @return array List of resource:action permissions
     */
    public function getAllPermissions() {
        $permissions = [];

        foreach (RBAC_PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action => $allowedRoles) {
                if (in_array($this->role, $allowedRoles)) {
                    $permissions[] = "{$resource}:{$action}";
                }
            }
        }

        return $permissions;
    }
}

/**
 * Enforce RBAC
 *
 * Creates an RBAC context from an authenticated user.
 * Should be called after requireAuth().
 *
 * @param Auth $auth Authentication context from requireAuth()
 * @return RBACContext RBAC context for permission checks
 */
function enforceRBAC($auth) {
    if (!$auth || !$auth->getUser()) {
        http_response_code(401);
        echo json_encode([
            'error' => 'unauthorized',
            'message' => 'Authentication required for RBAC.'
        ]);
        exit;
    }

    $rbac = new RBACContext($auth);

    logDebug("RBAC context created", [
        'user_id' => $rbac->getUserId(),
        'role' => $rbac->getRole(),
        'tenant_id' => $rbac->getTenantId()
    ]);

    return $rbac;
}

/**
 * Get permission matrix for documentation
 *
 * @param string|null $resource Optional: get permissions for specific resource
 * @return array Permission matrix
 */
function getRBACMatrix($resource = null) {
    if ($resource) {
        return RBAC_PERMISSIONS[$resource] ?? [];
    }
    return RBAC_PERMISSIONS;
}

/**
 * Helper: Require any of multiple permissions (OR logic)
 *
 * @param RBACContext $rbac
 * @param string $resource
 * @param array $actions List of actions, any of which grants access
 * @param string $customMessage Optional custom error message
 */
function requireAnyPermission($rbac, $resource, $actions, $customMessage = null) {
    foreach ($actions as $action) {
        if ($rbac->can($resource, $action)) {
            return; // Access granted
        }
    }

    // None of the permissions matched
    $message = $customMessage ?? "You do not have permission to access this resource.";

    logWarn("RBAC any-permission denied", [
        'resource' => $resource,
        'required_actions' => $actions,
        'role' => $rbac->getRole(),
        'user_id' => $rbac->getUserId()
    ]);

    http_response_code(403);
    echo json_encode([
        'error' => 'forbidden',
        'message' => $message,
        'required_permissions' => array_map(function($a) use ($resource) {
            return "{$resource}:{$a}";
        }, $actions),
        'your_role' => $rbac->getRole()
    ]);
    exit;
}

/**
 * Helper: Require all of multiple permissions (AND logic)
 *
 * @param RBACContext $rbac
 * @param array $permissions List of [resource, action] pairs
 * @param string $customMessage Optional custom error message
 */
function requireAllPermissions($rbac, $permissions, $customMessage = null) {
    foreach ($permissions as $perm) {
        list($resource, $action) = $perm;
        $rbac->requirePermission($resource, $action, $customMessage);
    }
}
