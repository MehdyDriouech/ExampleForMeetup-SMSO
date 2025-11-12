# Middleware Integration Guide

## Sprint 3: Multi-tenant & RBAC Implementation

This guide explains how to integrate the tenant isolation and RBAC middleware into API endpoints.

## Overview

The security middleware consists of two layers:

1. **Tenant Isolation** (`_middleware_tenant.php`): Ensures strict data separation between tenants
2. **RBAC** (`_middleware_rbac.php`): Enforces role-based permissions

## Integration Pattern

### Basic Pattern (Replaces direct requireAuth())

**Before (Sprint 2):**
```php
$auth = requireAuth();
$tenantId = $auth->getTenantId();
```

**After (Sprint 3):**
```php
// Load middleware
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rbac.php';

// 1. Enforce tenant isolation
$tenantContext = enforceTenantIsolation();

// 2. Authenticate user
$auth = requireAuth();

// 3. Verify tenant match between auth and request
enforceTenantAuthMatch($tenantContext, $auth);

// 4. Setup RBAC
$rbac = enforceRBAC($auth);

// 5. Check permissions
$rbac->requirePermission('assignments', 'read');
```

## Complete Examples

### Example 1: List Assignments (GET)

```php
// GET /api/assignments - Liste des assignments
if ($method === 'GET' && !$assignmentId) {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);
    $rbac = enforceRBAC($auth);

    // Permission check
    $rbac->requirePermission('assignments', 'read');

    $tenantId = $tenantContext->getTenantId();

    // Build query with ownership filter
    $ownershipWhere = $rbac->ownershipWhere('assignments', 'teacher_id', 'a');

    $sql = "SELECT a.*, t.title as theme_title
            FROM assignments a
            JOIN themes t ON a.theme_id = t.id
            WHERE a.tenant_id = :tenant_id
            AND ({$ownershipWhere})
            ORDER BY a.created_at DESC";

    // ... rest of implementation
}
```

### Example 2: Create Assignment (POST)

```php
// POST /api/assignments - Créer un assignment
if ($method === 'POST' && !$assignmentId) {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);
    $rbac = enforceRBAC($auth);

    // Permission check
    $rbac->requirePermission('assignments', 'create');

    $tenantId = $tenantContext->getTenantId();
    $userId = $rbac->getUserId();

    $body = getRequestBody();

    // Validate theme belongs to same tenant
    $theme = db()->queryOne(
        'SELECT id, tenant_id FROM themes WHERE id = ?',
        [$body['theme_id']]
    );

    if (!$theme) {
        errorResponse('NOT_FOUND', 'Theme not found', 404);
    }

    // Verify theme ownership
    $tenantContext->verifyOwnership(
        $theme['tenant_id'],
        'theme',
        $theme['id']
    );

    // Create assignment with tenant_id
    $assignmentId = generateId('ASSIGN');
    db()->insert('assignments', [
        'id' => $assignmentId,
        'tenant_id' => $tenantId,
        'teacher_id' => $userId,
        'theme_id' => $body['theme_id'],
        // ... other fields
    ]);

    // Log sensitive access
    logSensitiveAccess('assignments', $assignmentId, 'create', [
        'user_id' => $userId,
        'role' => $rbac->getRole()
    ]);

    jsonResponse(['assignment_id' => $assignmentId], 201);
}
```

### Example 3: Update Assignment (PATCH)

```php
// PATCH /api/assignments/{id} - Modifier un assignment
if ($method === 'PATCH' && $assignmentId) {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);
    $rbac = enforceRBAC($auth);

    $tenantId = $tenantContext->getTenantId();

    // Load assignment
    $assignment = db()->queryOne(
        'SELECT * FROM assignments WHERE id = ? AND tenant_id = ?',
        [$assignmentId, $tenantId]
    );

    if (!$assignment) {
        errorResponse('NOT_FOUND', 'Assignment not found', 404);
    }

    // Check ownership or update_any permission
    $rbac->requireOwnership('assignments', $assignment, 'update_any');

    $body = getRequestBody();

    // Update only allowed fields
    $updates = [];
    if (isset($body['title'])) $updates['title'] = $body['title'];
    if (isset($body['instructions'])) $updates['instructions'] = $body['instructions'];

    if (!empty($updates)) {
        $updates['updated_at'] = date('Y-m-d H:i:s');
        db()->update('assignments', $updates, ['id' => $assignmentId]);
    }

    logSensitiveAccess('assignments', $assignmentId, 'update', [
        'user_id' => $rbac->getUserId(),
        'role' => $rbac->getRole(),
        'updated_fields' => array_keys($updates)
    ]);

    jsonResponse(['success' => true, 'updated' => count($updates)]);
}
```

### Example 4: Delete Assignment (DELETE)

```php
// DELETE /api/assignments/{id} - Supprimer un assignment
if ($method === 'DELETE' && $assignmentId) {
    // Security middleware
    $tenantContext = enforceTenantIsolation();
    $auth = requireAuth();
    enforceTenantAuthMatch($tenantContext, $auth);
    $rbac = enforceRBAC($auth);

    $tenantId = $tenantContext->getTenantId();

    // Load assignment
    $assignment = db()->queryOne(
        'SELECT * FROM assignments WHERE id = ? AND tenant_id = ?',
        [$assignmentId, $tenantId]
    );

    if (!$assignment) {
        errorResponse('NOT_FOUND', 'Assignment not found', 404);
    }

    // Check ownership or delete_any permission
    $rbac->requireOwnership('assignments', $assignment, 'delete_any');

    // Soft delete
    db()->update('assignments', [
        'status' => 'deleted',
        'updated_at' => date('Y-m-d H:i:s')
    ], ['id' => $assignmentId]);

    logSensitiveAccess('assignments', $assignmentId, 'delete', [
        'user_id' => $rbac->getUserId(),
        'role' => $rbac->getRole()
    ]);

    jsonResponse(['success' => true], 200);
}
```

## Permission Matrix

### Assignments Resource

| Action | Admin | Direction | Teacher | Inspector | Intervenant |
|--------|-------|-----------|---------|-----------|-------------|
| create | ✅ | ✅ | ✅ | ❌ | ❌ |
| read | ✅ (all) | ✅ (all) | ✅ (own) | ✅ (all, read-only) | ✅ (assigned classes) |
| update | ✅ (any) | ✅ (any) | ✅ (own) | ❌ | ❌ |
| delete | ✅ (any) | ✅ (any) | ✅ (own) | ❌ | ❌ |
| push | ✅ | ✅ | ✅ | ❌ | ❌ |

### Stats Resource

| Action | Admin | Direction | Teacher | Inspector | Intervenant |
|--------|-------|-----------|---------|-----------|-------------|
| read | ✅ (all) | ✅ (all) | ✅ (own students) | ✅ (all, read-only) | ❌ |
| sync | ✅ | ✅ | ✅ | ❌ | ❌ |

### Dashboard Resource

| Action | Admin | Direction | Teacher | Inspector | Intervenant |
|--------|-------|-----------|---------|-----------|-------------|
| view_summary | ✅ | ✅ | ✅ (own data) | ✅ | ❌ |
| view_aggregated | ✅ | ✅ | ❌ | ✅ | ❌ |
| export_reports | ✅ | ✅ | ❌ | ✅ | ❌ |

## Migration Checklist

For each API endpoint file:

- [ ] Add middleware includes at top of file
- [ ] Replace direct `requireAuth()` with full middleware stack
- [ ] Add `enforceTenantIsolation()`
- [ ] Add `enforceTenantAuthMatch()`
- [ ] Add `enforceRBAC()`
- [ ] Add appropriate `requirePermission()` calls
- [ ] Use `ownershipWhere()` for query filtering
- [ ] Use `verifyOwnership()` for resource validation
- [ ] Add `logSensitiveAccess()` for audit trail
- [ ] Update all queries to use `$tenantContext->getTenantId()`
- [ ] Test with different roles

## Testing

Test each endpoint with:

1. **Valid same-tenant access**: Should succeed
2. **Invalid cross-tenant access**: Should return 403
3. **Missing tenant header**: Should return 400
4. **Insufficient permissions**: Should return 403 with role info
5. **Ownership violations**: Should return 403

Example test with curl:

```bash
# Valid request
curl -X GET \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "X-Orchestrator-Id: TENANT_INST_PARIS" \
  http://localhost:8080/api/assignments

# Cross-tenant attempt (should fail)
curl -X GET \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "X-Orchestrator-Id: TENANT_INST_LYON" \
  http://localhost:8080/api/assignments
```

## Common Pitfalls

1. **Forgetting tenant_id in INSERT**: Always include `tenant_id` when creating records
2. **Not verifying related resource ownership**: Check that referenced resources belong to same tenant
3. **Using raw requireAuth() without RBAC**: Always use full middleware stack
4. **Missing tenant filter in queries**: All SELECT queries must filter by `tenant_id`
5. **Not logging sensitive operations**: Use `logSensitiveAccess()` for audit trail

## Helper Functions Reference

### Tenant Context
- `enforceTenantIsolation()`: Validates and loads tenant
- `$tenantContext->getTenantId()`: Get current tenant ID
- `$tenantContext->verifyOwnership()`: Verify resource belongs to tenant
- `enforceTenantAuthMatch()`: Ensure auth tenant matches request tenant

### RBAC Context
- `enforceRBAC($auth)`: Create RBAC context
- `$rbac->can($resource, $action)`: Check permission (returns bool)
- `$rbac->requirePermission($resource, $action)`: Require permission or throw 403
- `$rbac->owns($resourceType, $resource)`: Check ownership (returns bool)
- `$rbac->requireOwnership($resourceType, $resource, $alternate)`: Require ownership or throw 403
- `$rbac->ownershipWhere($resource, $field, $alias)`: Get SQL WHERE clause for ownership
- `$rbac->getUserId()`: Get current user ID
- `$rbac->getRole()`: Get current user role

### Logging
- `logSensitiveAccess($resource, $id, $action, $context)`: Log sensitive operations
- `logTenantViolation($type, $context)`: Log tenant violations
- `logRBACDenial($resource, $action, $role, $context)`: Log permission denials
