# Sprint 13 - Quick Reference Guide

## Critical Files for Sprint 13

### Backend Architecture
- **API Pattern**: `/orchestrator/api/assignments.php` (template for new endpoints)
- **Auth System**: `/orchestrator/lib/auth.php` (JWT + UrlEncoded)
- **RBAC**: `/orchestrator/api/_middleware_rbac.php` (permission matrix)
- **AI Service**: `/orchestrator/lib/ai_service.php` (Mistral integration)
- **Database**: `/orchestrator/sql/schema.sql` (all table definitions)
- **Config**: `/orchestrator/.env.php` (environment & credentials)

### Frontend
- **Main App**: `/public/js/app.js` (routing, API client)
- **API Client**: All calls use `apiCall(endpoint, options)` with auth headers
- **Views**: `/public/js/view/*.js` (components)
- **UI Components**: `/orchestrator/ui/*.js` (content-specific UI)

### Documentation
- **OpenAPI Spec**: `/orchestrator/docs/openapi-orchestrator.yaml` (must be updated)
- **Architecture**: `/SPRINT13_ARCHITECTURE_OVERVIEW.md` (this guide's long form)
- **Previous Sprints**: `/docs/SPRINT_12_PEDAGOGICAL_LIBRARY.md`, `/SPRINT_11_SUMMARY.md`

---

## API Endpoint Template (Copy-Paste)

```php
<?php
/**
 * API {Feature} - {Description}
 *
 * Endpoints:
 * - GET /api/{resource}          List {resources}
 * - POST /api/{resource}         Create {resource}
 * - GET /api/{resource}/{id}     Get {resource} details
 * - PATCH /api/{resource}/{id}   Update {resource}
 * - DELETE /api/{resource}/{id}  Delete {resource}
 */

require_once __DIR__ . '/../.env.php';
require_once __DIR__ . '/_middleware_tenant.php';
require_once __DIR__ . '/_middleware_rbac.php';
require_once __DIR__ . '/_middleware_rate_limit.php';
require_once __DIR__ . '/_middleware_telemetry.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);
$uri = $_SERVER['REQUEST_URI'];

// Parse URI
$path = parse_url($uri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$resourceId = $pathParts[3] ?? null;      // /api/{resource}/{id}
$action = $pathParts[4] ?? null;          // /api/{resource}/{id}/{action}

// Security middleware
$tenantContext = enforceTenantIsolation();
$auth = requireAuth();
enforceTenantAuthMatch($tenantContext, $auth);
$rbac = enforceRBAC($auth);

$tenantId = $tenantContext->getTenantId();

// ============================================================
// GET /api/{resource} - List
// ============================================================
if ($method === 'GET' && !$resourceId) {
    $rbac->requirePermission('{resource}', 'read');
    
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    
    // YOUR LOGIC HERE
    
    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/{resource}', 200, $duration);
    jsonResponse(['data' => [], 'pagination' => ['total' => 0, 'limit' => $limit, 'offset' => $offset]]);
}

// ============================================================
// POST /api/{resource} - Create
// ============================================================
if ($method === 'POST' && !$resourceId) {
    $rbac->requirePermission('{resource}', 'create');
    
    $body = getRequestBody();
    validateRequired($body, ['field1', 'field2']);
    
    // YOUR LOGIC HERE
    
    $duration = (microtime(true) - start) * 1000;
    logger()->logRequest('/api/{resource}', 201, $duration);
    jsonResponse($result, 201);
}

// ============================================================
// GET /api/{resource}/{id} - Detail
// ============================================================
if ($method === 'GET' && $resourceId && !$action) {
    $rbac->requirePermission('{resource}', 'read');
    
    // YOUR LOGIC HERE
    
    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/{resource}/' . $resourceId, 200, $duration);
    jsonResponse($result);
}

// ============================================================
// PATCH /api/{resource}/{id} - Update
// ============================================================
if ($method === 'PATCH' && $resourceId && !$action) {
    $rbac->requirePermission('{resource}', 'update');
    
    $body = getRequestBody();
    
    // YOUR LOGIC HERE
    
    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/{resource}/' . $resourceId, 200, $duration);
    jsonResponse($result);
}

// ============================================================
// DELETE /api/{resource}/{id} - Delete
// ============================================================
if ($method === 'DELETE' && $resourceId && !$action) {
    $rbac->requirePermission('{resource}', 'delete');
    
    // YOUR LOGIC HERE (soft delete recommended)
    
    $duration = (microtime(true) - $start) * 1000;
    logger()->logRequest('/api/{resource}/' . $resourceId, 204, $duration);
    http_response_code(204);
    exit;
}

errorResponse('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
?>
```

---

## Database Query Pattern (Using db() service)

```php
// Query single row
$result = db()->queryOne(
    'SELECT * FROM {table} WHERE tenant_id = :tenant_id AND id = :id',
    ['tenant_id' => $tenantId, 'id' => $resourceId]
);

// Query multiple rows
$results = db()->query(
    'SELECT * FROM {table} WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
    ['tenant_id' => $tenantId, 'limit' => $limit, 'offset' => $offset]
);

// Execute (INSERT/UPDATE/DELETE)
db()->execute(
    'INSERT INTO {table} (id, tenant_id, field1, created_at) VALUES (:id, :tenant_id, :field1, NOW())',
    ['id' => generateId('{prefix}'), 'tenant_id' => $tenantId, 'field1' => $value]
);

// Get PDO directly for complex queries
$pdo = db()->getPdo();
$stmt = $pdo->prepare('...');
```

---

## Frontend API Call Pattern (Using apiCall)

```javascript
// GET request
const data = await apiCall('/api/resource?limit=50&offset=0', {
    method: 'GET'
});

// POST request
const result = await apiCall('/api/resource', {
    method: 'POST',
    body: JSON.stringify({ field1: 'value' })
});

// PATCH request
const updated = await apiCall('/api/resource/id', {
    method: 'PATCH',
    body: JSON.stringify({ field1: 'new_value' })
});

// DELETE request
await apiCall('/api/resource/id', {
    method: 'DELETE'
});

// Error handling
try {
    const data = await apiCall('/api/resource');
} catch (error) {
    console.error('API Error:', error);
    // error is already parsed JSON from response.json()
}
```

---

## RBAC Permission Check Pattern

```php
// In middleware_rbac.php, permission matrix looks like:
const RBAC_PERMISSIONS = [
    '{resource}' => [
        'create' => ['admin', 'teacher', 'direction'],
        'read' => ['admin', 'teacher', 'direction', 'inspector', 'intervenant'],
        'read_all' => ['admin', 'direction', 'inspector'],  // Read all, not just own
        'update' => ['admin', 'teacher'],  // Teachers can only update own
        'update_any' => ['admin', 'direction'],  // Can update any
        'delete' => ['admin', 'teacher'],  // Teachers can only delete own
        'delete_any' => ['admin', 'direction'],  // Can delete any
    ],
];

// In API endpoint:
$rbac->requirePermission('{resource}', 'read');  // Throws 403 if denied

// For ownership-based filtering:
$ownershipWhere = $rbac->ownershipWhere('{resource}', 'field_name', 'table_alias');
// Returns: "t.field_name = 'user_id'" for teachers, "1=1" for admin/direction
```

---

## Add New OpenAPI Endpoints

In `/orchestrator/docs/openapi-orchestrator.yaml`:

```yaml
paths:
  /api/{resource}:
    get:
      tags: ["{Resource}"]
      summary: List {resources}
      parameters:
        - name: limit
          in: query
          schema: { type: integer, default: 50 }
        - name: offset
          in: query
          schema: { type: integer, default: 0 }
      responses:
        '200':
          description: List of {resources}
          content:
            application/json:
              schema:
                type: object
                properties:
                  data:
                    type: array
                    items:
                      $ref: '#/components/schemas/{Resource}'
                  pagination:
                    $ref: '#/components/schemas/Pagination'
        '401':
          $ref: '#/components/responses/UnauthorizedError'
        '403':
          $ref: '#/components/responses/ForbiddenError'
      security:
        - BearerAuth: []
        - ApiKeyUrlEncoded: []
      
    post:
      tags: ["{Resource}"]
      summary: Create {resource}
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Create{Resource}Request'
      responses:
        '201':
          description: {Resource} created
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/{Resource}'
        '400':
          $ref: '#/components/responses/ValidationError'
        '401':
          $ref: '#/components/responses/UnauthorizedError'
        '403':
          $ref: '#/components/responses/ForbiddenError'

  /api/{resource}/{id}:
    get:
      tags: ["{Resource}"]
      summary: Get {resource} details
      parameters:
        - name: id
          in: path
          required: true
          schema: { type: string }
      responses:
        '200':
          description: {Resource} details
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/{Resource}'
        '404':
          $ref: '#/components/responses/NotFoundError'
```

---

## Database Migration Template

Create file: `/orchestrator/sql/migrations/SPRINT13_your_feature.sql`

```sql
-- Sprint 13: Your Feature
-- Date: 2025-11-13

-- ============================================================
-- New Table: {table_name}
-- ============================================================
CREATE TABLE {table_name} (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    field1 VARCHAR(255),
    field2 TEXT,
    field3 JSON DEFAULT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_tenant (tenant_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Then include it in `/orchestrator/sql/schema.sql` for baseline setup.

---

## Common Issues & Solutions

### Issue: 403 Forbidden on valid API call
**Solution**: Check RBAC_PERMISSIONS matrix. Ensure:
1. Role exists (admin, direction, teacher, etc.)
2. Resource + action combo is permitted for that role
3. `enforceTenantAuthMatch()` passes (tenant in auth matches request)

### Issue: Database connection failed
**Solution**: Verify in `.env.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'studymate_orchestrator');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### Issue: JWT token invalid
**Solution**: Ensure:
1. JWT_SECRET is set correctly (min 32 chars)
2. Token not expired (JWT_EXPIRY_SECONDS)
3. Authorization header format: `Bearer {token}`
4. X-Orchestrator-Id header matches user's tenant_id

### Issue: File upload fails
**Solution**: Check permissions:
```bash
chmod 755 orchestrator/uploads
chmod 755 orchestrator/cache
chmod 755 orchestrator/logs
```

---

## Testing Endpoints with cURL

```bash
# Health check
curl https://smso.mehdydriouech.fr/api/health

# Login
TOKEN=$(curl -X POST https://smso.mehdydriouech.fr/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"claire.dubois@ife-paris.fr","password":"Ergo2025!"}' \
  | jq -r '.token')

# API call with token
curl https://smso.mehdydriouech.fr/api/students \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Orchestrator-Id: TENANT_INST_PARIS"

# API call with UrlEncoded auth
curl -X GET "https://smso.mehdydriouech.fr/api/students" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "api_key=teacher-dev-key&tenant_id=TENANT_INST_PARIS&scope=teacher"
```

---

## Key Constants & Enums

**User Roles**:
```
admin, direction, teacher, intervenant, inspector
```

**Assignment Status**:
```
draft, queued, pushed, ack, error
```

**Theme Source**:
```
manual, pdf_mistral, import
```

**Theme Difficulty**:
```
beginner, intermediate, advanced
```

**Assignment Type**:
```
quiz, flashcards, fiche, annales
```

**Tenant Status**:
```
active, suspended, archived
```

---

## Performance Tips for Sprint 13

1. **Use database indexes** - Define for frequently filtered fields
2. **Paginate results** - Always use limit/offset
3. **Cache frequently accessed data** - Use CACHE_DIR with TTL
4. **Log selectively** - Use LOG_LEVEL to reduce I/O
5. **Batch operations** - Insert/update multiple records in single transaction
6. **Validate early** - Fail fast with input validation

---

## Checklist Before Submitting PR

- [ ] API endpoint template applied (middleware included)
- [ ] RBAC permissions added to `_middleware_rbac.php`
- [ ] OpenAPI spec updated with new endpoints
- [ ] Database migration created if needed
- [ ] Sample cURL commands provided in PR
- [ ] Error responses properly formatted
- [ ] Tenant isolation enforced
- [ ] Rate limiting considered
- [ ] Logging added (apiCall logged)
- [ ] UI component created if needed
- [ ] Documentation updated
- [ ] Tests written (integration test if complex)

---

**Last Updated**: 2025-11-13  
**For Questions**: Refer to `/SPRINT13_ARCHITECTURE_OVERVIEW.md`
