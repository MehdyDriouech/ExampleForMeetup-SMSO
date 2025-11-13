# StudyMate-SchoolOrchestrator Architecture Overview
## Sprint 10 AI Copilot Implementation Guide

**Date**: 2025-11-13  
**Version**: 1.0 (Architecture Analysis)  
**Status**: Current state analysis for Sprint 10 planning

---

## 1. DIRECTORY STRUCTURE

```
StudyMate-SchoolOrchestrator/
├── orchestrator/                          # Backend PHP application
│   ├── .env.php                          # Configuration (not versioned)
│   ├── api/                              # REST API endpoints (routing via .htaccess)
│   │   ├── auth.php                      # POST /api/auth/login
│   │   ├── health.php                    # GET /api/health
│   │   ├── ai.php                        # POST/GET /api/ai/* (Mistral integration)
│   │   ├── assignments.php               # CRUD /api/assignments
│   │   ├── students.php                  # GET /api/students
│   │   ├── reco.php                      # GET /api/reco (Sprint 7 recommendations)
│   │   ├── social.php                    # /api/social/* (Sprint 8 leaderboards, sharing)
│   │   ├── academic.php                  # /api/academic/* (Sprint 9 data exports)
│   │   ├── _middleware_*.php             # Tenant isolation, RBAC, rate limiting, telemetry
│   │   ├── analytics/                    # KPIs, heatmaps (Sprint 6)
│   │   ├── partners/                     # API usage tracking
│   │   ├── student/                      # Student-side endpoints (missions, badges, etc.)
│   │   └── telemetry/                    # Observability data
│   │
│   ├── lib/                              # Core libraries & services
│   │   ├── db.php                        # PDO singleton, query abstractions
│   │   ├── auth.php                      # Dual auth: UrlEncoded + JWT (HS256)
│   │   ├── ai_service.php                # Mistral AI integration, schema validation
│   │   ├── recommendations.php           # Recommendation engine (Sprint 7)
│   │   ├── logger.php                    # Rotating logs (5MB x 5 files)
│   │   ├── badges.php                    # Badge system (Sprint 5)
│   │   ├── notify.php                    # Notification system
│   │   ├── teacher_validation.php        # Teacher content validation
│   │   └── util.php                      # Helpers, response formatting
│   │
│   ├── sql/                              # Database schemas
│   │   ├── schema.sql                    # Core tables (tenants, users, students, assignments, etc.)
│   │   ├── seeds.sql                     # Test data
│   │   ├── migrations/                   # Numbered migrations
│   │   │   ├── 003_add_tenant_isolation.sql
│   │   │   └── ...
│   │   ├── migration_sprint2.sql         # ai_generations, notifications tables
│   │   ├── sprint8_social.sql            # Leaderboards, sharing, collaboration tables
│   │   └── sprint6_analytics_dashboard.sql
│   │
│   ├── docs/                             # Documentation
│   │   ├── openapi-orchestrator.yaml     # OpenAPI 3.1 spec
│   │   ├── RBAC_SECURITY_GUIDE.md
│   │   ├── MIDDLEWARE_INTEGRATION_GUIDE.md
│   │   └── schema/                       # JSON schema definitions
│   │
│   ├── jobs/                             # Async jobs
│   │   └── export_telemetry.py           # Python telemetry export job
│   │
│   ├── realtime/                         # Real-time features
│   │   ├── session_update.php
│   │   └── collaborative_polling.php
│   │
│   ├── tests/                            # Integration tests
│   │   └── integration/
│   │       ├── TenantIsolationTest.php
│   │       └── RBACTest.php
│   │
│   └── logs/                             # Application logs (auto-rotated)
│
├── public/                               # Frontend (SPA)
│   ├── index.html                        # Main entry point
│   ├── assignments.html                  # Assignments interface
│   ├── js/
│   │   ├── app.js                        # Main app initialization, routing, API client
│   │   ├── assignments.js                # Assignment management features
│   │   ├── features-reco.js              # Recommendation widget (Sprint 7)
│   │   ├── features-difficulty.js        # Adaptive difficulty (Sprint 7)
│   │   ├── features-focus.js             # Focus mode (Sprint 7)
│   │   ├── features-fatigue.js           # Fatigue detection (Sprint 7)
│   │   └── view/
│   │       ├── view-dashboard.js         # Teacher dashboard
│   │       ├── view-social.js            # Leaderboards, sharing (Sprint 8)
│   │       ├── view-student-progress.js  # Student learning journey
│   │       ├── view-student-missions.js  # Mission UI
│   │       ├── view-partners.js
│   │       └── view-telemetry.js
│   ├── assets/
│   │   └── styles.css                    # Styling
│   └── vendor/                           # External libraries
│
├── .htaccess                             # Apache routing (if exists)
├── README.md                             # Main documentation
├── INSTALLATION.md                       # Setup guide
└── SPRINT*_README.md                     # Sprint documentation

```

---

## 2. EXISTING API PATTERNS & ROUTING

### Request Routing Architecture
- **No Framework**: Direct PHP file routing via `.htaccess` (if present) or Apache mod_rewrite
- **URI Format**: `/api/{resource}/{action}/{id}`
- **Example Routes**:
  - `GET /api/students?classId=X` → `students.php`
  - `POST /api/ai/theme-from-text` → `ai.php`
  - `GET /api/social/leaderboard?theme_id=X` → `social.php`

### Standard API Pattern

**File Structure** (from `assignments.php`):
```php
// 1. Middleware stack
require_once __DIR__ . '/_middleware_tenant.php';    // Tenant isolation
require_once __DIR__ . '/_middleware_rbac.php';      // Role-based access
require_once __DIR__ . '/_middleware_telemetry.php'; // Request logging

// 2. Route parsing
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$resourceId = $pathParts[3] ?? null;
$action = $pathParts[4] ?? null;

// 3. Auth + RBAC enforcement
$tenantContext = enforceTenantIsolation();
$auth = requireAuth();
$rbac = enforceRBAC($auth);
$rbac->requirePermission('assignments', 'read');

// 4. Business logic
if ($method === 'GET' && !$resourceId) {
    // List logic with pagination
}

// 5. Response
jsonResponse($result, 200);
errorResponse('CODE', 'Message', 400);
```

### Authentication Modes (Dual Support)

**UrlEncoded (Prioritized for shared hosting)**:
```bash
curl -X POST /api/assignments \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "api_key=secret&tenant_id=TENANT_ID&scope=teacher"
```

**JWT Bearer**:
```bash
curl /api/students \
  -H "Authorization: Bearer eyJhbGc..."
  -H "X-Orchestrator-Id: TENANT_ID"
```

**Implementation**: `orchestrator/lib/auth.php`
- JWT: HS256 (HMAC-SHA256)
- Token structure: `header.payload.signature`
- Payload includes: `sub` (user_id), `tenant_id`, `scope`, `iat`, `exp`
- Fallback: UrlEncoded → JWT (in MIXED mode)

### Middleware Stack (Request Pipeline)

1. **Tenant Isolation** (`_middleware_tenant.php`)
   - Enforces tenant context from header (`X-Orchestrator-Id`) or body
   - Blocks cross-tenant access with 403
   - Validates tenant status

2. **RBAC** (`_middleware_rbac.php`)
   - Permission matrix: resource + action + role
   - Supports ownership filtering (teachers see own only)
   - Permission definitions at top of file

3. **Rate Limiting** (`_middleware_rate_limit.php`)
   - Per-tenant, per-endpoint limits
   - Sliding window tracking
   - Returns 429 when exceeded

4. **Telemetry** (`_middleware_telemetry.php`)
   - Logs all requests with method, path, duration
   - Tracks success/error rates
   - Used for observability dashboards

### CORS & Response Headers

```php
setCorsHeaders();  // Utility function
header('Content-Type: application/json; charset=utf-8');
```

---

## 3. DATABASE SCHEMA & DATA MODELS

### Core Tables (from `orchestrator/sql/schema.sql`)

**Multi-Tenant Foundation**:
```sql
tenants (id VARCHAR(50) PK)
  ├─ name, type (public/private), email, phone, address
  ├─ settings JSON
  └─ status ENUM(active, suspended, archived)

users (id VARCHAR(50) PK, tenant_id FK)
  ├─ email (UNIQUE), password_hash (bcrypt)
  ├─ firstname, lastname
  ├─ role ENUM(admin, direction, teacher, intervenant)
  ├─ status ENUM(active, inactive, pending)
  └─ last_login_at TIMESTAMP
```

**Learning Structure**:
```sql
promotions (id, tenant_id FK)      -- Academic years/cohorts
├─ year_start, year_end, level (L1, L2, M1, etc.)
└─ status ENUM(active, archived)

classes (id, tenant_id FK, promo_id FK, teacher_id FK)
├─ name, description
└─ status ENUM(active, archived)

students (id, tenant_id FK, class_id FK, promo_id FK)
├─ uuid_scolaire (ErgoMate identifier)
├─ email_scolaire
├─ firstname, lastname
├─ consent_sharing BOOLEAN
└─ status ENUM(active, graduated, withdrawn)
```

**Content & Assignments**:
```sql
themes (id, tenant_id FK, created_by FK)
├─ title, description
├─ content JSON (questions, flashcards, sections)
├─ difficulty ENUM(beginner, intermediate, advanced)
├─ source ENUM(manual, pdf_mistral, import)
├─ is_public BOOLEAN
└─ status ENUM(draft, active, archived)

assignments (id, tenant_id FK, teacher_id FK, theme_id FK)
├─ title, type ENUM(quiz, flashcards, fiche, annales)
├─ mode ENUM(post-cours, pre-examen, revision-generale)
├─ due_at TIMESTAMP
├─ status ENUM(draft, queued, pushed, ack, error)
├─ ergo_push_at, ergo_ack_at TIMESTAMP
└─ target_count, received_count, completed_count INT

assignment_targets (id, assignment_id FK)
├─ target_type ENUM(student, class, promo)
└─ target_id VARCHAR(50)
```

**Statistics & Sync**:
```sql
stats (id, student_id FK, theme_id FK)
├─ attempts INT
├─ score DECIMAL(5,2) [0-100]
├─ mastery DECIMAL(3,2) [0-1]
├─ time_spent INT (seconds)
├─ last_activity_at TIMESTAMP
└─ synced_at TIMESTAMP

sync_logs (id, tenant_id FK, triggered_by FK)
├─ direction ENUM(pull, push)
├─ type ENUM(stats, assignment, webhook)
├─ status ENUM(queued, running, ok, error)
├─ payload JSON
└─ error_message TEXT
```

### Sprint 2+ Tables (AI & Notifications)

**AI Generation Tracking** (`migration_sprint2.sql`):
```sql
ai_generations (id, tenant_id FK, user_id FK)
├─ generation_type ENUM(theme, quiz, flashcards, fiche)
├─ source_type ENUM(text, pdf, audio, url)
├─ source_hash VARCHAR(64)  -- Deduplication
├─ result_json JSON  -- Generated content
├─ validation_status ENUM(pending, valid, invalid, error)
├─ validation_errors JSON
├─ theme_id FK  -- Created theme (if valid)
├─ status ENUM(queued, processing, completed, error)
├─ processing_time_ms INT
└─ created_at, updated_at TIMESTAMP

notifications (id, tenant_id FK)
├─ recipient_type ENUM(student, teacher, class, promo)
├─ recipient_id VARCHAR(50)
├─ notification_type ENUM(assignment, reminder, result, info)
├─ title, message
├─ delivery_method ENUM(in-app, email, both)
├─ status ENUM(pending, sent, failed)
└─ read_at TIMESTAMP

assignment_events (id, assignment_id FK, student_id FK)
├─ event_type ENUM(received, opened, started, in_progress, completed, error)
└─ metadata JSON
```

### Sprint 5+ Tables (Gamification & Learning Cycle)

**Badges & Missions**:
```sql
badges (id, tenant_id FK, created_by FK)
├─ title, description, icon_url
├─ criteria JSON
├─ is_active BOOLEAN
└─ created_at TIMESTAMP

student_badges (id, student_id FK, badge_id FK)
├─ earned_at TIMESTAMP
└─ metadata JSON

missions (id, tenant_id FK, created_by FK)
├─ title, description
├─ objectives JSON
├─ due_at TIMESTAMP
└─ reward_type ENUM(points, badge, certificate)
```

### Sprint 8 Tables (Social & Collaboration)

**Leaderboards & Sharing**:
```sql
leaderboard_settings (id, tenant_id FK)
├─ period ENUM(weekly, monthly, all_time)
├─ anonymize BOOLEAN
└─ settings JSON

shared_content (id, student_id FK, theme_id FK)
├─ content_type ENUM(summary, note, alternative_explanation)
├─ title, content
├─ is_public BOOLEAN
└─ created_at TIMESTAMP

peer_comments (id, shared_content_id FK, author_id FK)
├─ parent_comment_id FK  -- Threading
├─ content TEXT
├─ moderation_status ENUM(pending, approved, rejected)
└─ created_at TIMESTAMP

collaborative_sessions (id, tenant_id FK, created_by FK)
├─ theme_id FK
├─ participants_count INT
├─ status ENUM(active, completed)
└─ created_at TIMESTAMP
```

### API Keys (for Mistral BYOK)

```sql
api_keys (id, tenant_id FK, user_id FK)
├─ provider ENUM(mistral)
├─ key_encrypted TEXT  -- Encrypted Mistral API key
├─ label VARCHAR(255)
├─ status ENUM(active, invalid, expired)
└─ last_used_at TIMESTAMP
```

---

## 4. FRONTEND STRUCTURE & COMPONENTS

### SPA Architecture

**Entry Point**: `public/index.html`
- Single HTML file with all views
- CSS: `public/assets/styles.css`

**JavaScript Organization**: `public/js/`

```javascript
// Core app initialization & routing
app.js
├─ const API_BASE_URL, currentView, authToken, currentUser
├─ navigateTo(view)         // Switch active view
├─ loadViewData(view)       // Async data loading
├─ apiCall(endpoint, opts)  // HTTP client with auth headers
├─ login(email, password)   // JWT acquisition
└─ logout()

// Feature modules
assignments.js             -- Assignment creation, targeting
features-reco.js          -- Recommendation widget (Sprint 7)
features-difficulty.js    -- Difficulty level selector (Sprint 7)
features-focus.js         -- Mini-session modes (Sprint 7)
features-fatigue.js       -- Fatigue detection UI (Sprint 7)

// View components
view/
├─ view-dashboard.js      -- Teacher overview with KPIs, charts
├─ view-social.js         -- Leaderboards, content sharing (Sprint 8)
├─ view-student-progress.js -- Learning curve visualization
├─ view-student-missions.js -- Mission tracker & badges
├─ view-partners.js       -- API usage stats
└─ view-telemetry.js      -- System health monitoring
```

### API Client Pattern

```javascript
// Standard pattern in all features
async function apiCall(endpoint, options = {}) {
  const headers = {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${authToken}`,
    'X-Orchestrator-Id': currentUser?.tenantId
  };
  
  const response = await fetch(`${API_BASE_URL}${endpoint}`, {
    ...options, headers
  });
  
  if (!response.ok) throw new Error(await response.json());
  return response.json();
}
```

### Auth Flow

```
Login Page
  ↓ (email + password)
POST /api/auth/login
  ↓ (return JWT token + user)
localStorage.setItem('authToken', token)
  ↓
apiCall() auto-includes Authorization header
  ↓
Dashboard loads with user.tenantId context
```

---

## 5. EXISTING AI & CONTENT GENERATION FEATURES

### Current AI Integration (Sprint 2)

**Service**: `orchestrator/lib/ai_service.php`

**Capabilities**:
```php
class AIService {
  public function generateThemeFromText($text, $userId, $tenantId, $options)
    ├─ Input: Plain text (course material, notes)
    ├─ Output: Full theme with questions, flashcards, fiche
    └─ Mistral API: 'mistral-medium' model via curl

  private function callMistralAPI($text, $options)
    ├─ Prompt engineering for JSON response
    ├─ Temperature: 0.7, max_tokens: 4000
    ├─ Error handling & timeout: 30s
    └─ Mock mode available for testing

  private function generateMockTheme($text, $options)
    └─ Test data generator (2 questions, 2 flashcards, 2 sections)
}

class SchemaValidator {
  public function validateTheme($data)
    ├─ Validate required fields: title, description, difficulty
    ├─ Validate questions: id, text, 2+ choices, correctAnswer
    └─ Validate flashcards: id, front, back
}
```

**API Endpoint**: `orchestrator/api/ai.php`

```
POST /api/ai/theme-from-text
  ├─ Body: { text, type='theme'|'quiz'|'flashcards'|'fiche', difficulty='intermediate' }
  ├─ Auth: Required (JWT or UrlEncoded)
  ├─ Tenant isolation: ✓
  ├─ RBAC: ✓ (teacher/admin only)
  └─ Returns: 
      {
        generation_id, theme_id, result, validation, processing_time_ms,
        cached: true/false  -- Deduplication via source hash
      }

POST /api/ai/theme-from-pdf
  ├─ Multipart file upload (max 10MB)
  ├─ MIME validation: application/pdf only
  ├─ Status: NOT YET IMPLEMENTED (returns 501)
  └─ TODO: PDF text extraction → generateThemeFromText

GET /api/ai/generations
  ├─ List AI generation jobs with pagination
  ├─ Filters: status, validation_status
  └─ Returns: Array of generation records + user/theme info

GET /api/ai/generations/{id}
  ├─ Detail view with decoded JSON results
  └─ Shows validation errors if invalid
```

**Database Tracking**:
- **ai_generations table**: Stores all requests, results, validation status
- **Deduplication**: SHA256 hash of source text prevents duplicate generations
- **Cache duration**: 7 days (valid generations only)
- **Processing metrics**: Stored (time, token count, etc.)

**Mistral Configuration**:
```php
// In orchestrator/lib/ai_service.php
private function callMistralAPI($text, $options) {
    curl_init('https://api.mistral.ai/v1/chat/completions');
    
    $payload = [
        'model' => 'mistral-medium',
        'temperature' => 0.7,
        'max_tokens' => 4000,
        'messages' => [
            ['role' => 'system', 'content' => 'Tu es un assistant pédagogique...'],
            ['role' => 'user', 'content' => $prompt]
        ]
    ];
}

// BYOK: API key stored in api_keys table (encrypted)
// TODO: Implement actual encryption/decryption
```

### Content Generation Patterns

**Prompt templates** (in `AIService::buildPrompt`):
- **theme**: Full pedagogical unit with 10 MCQs + 10 flashcards + structured fiche
- **quiz**: 15 multiple-choice questions
- **flashcards**: 20 front/back card pairs
- **fiche**: Study sheet with key points

**Quality Control**:
- JSON Schema validation
- Mandatory fields check
- Choice count validation (2+ per question)
- Processing time tracking for optimization

---

## 6. AUTHENTICATION & JWT IMPLEMENTATION

### Hybrid Authentication System

**File**: `orchestrator/lib/auth.php`

**Modes**:
1. **URLENCODED** (shared hosting compatible)
   - Priority: 1st checked
   - Params: `api_key`, `tenant_id`, `scope`
   - Use case: Scripts, background jobs, non-interactive clients

2. **JWT** (REST API standard)
   - Priority: Fallback if no UrlEncoded credentials
   - Algorithm: HS256 (HMAC-SHA256)
   - Header: `Authorization: Bearer {token}`

3. **MIXED** (default mode)
   - Tries UrlEncoded first, falls back to JWT
   - Configured via `AUTH_MODE` constant

### JWT Specification

**Token Structure**: `{header}.{payload}.{signature}`

**Header**:
```json
{
  "alg": "HS256",
  "typ": "JWT"
}
```

**Payload**:
```json
{
  "sub": "user_id",           // Subject (user ID)
  "tenant_id": "TENANT_ID",   // Multi-tenant isolation
  "scope": "teacher",          // Role/permission scope
  "iat": 1699348400,          // Issued at (unix timestamp)
  "exp": 1699434800          // Expiration (unix timestamp)
}
```

**Signature**: HMAC-SHA256 of `header.payload` using `JWT_SECRET`

### JWT Flow

```php
// Generation (on login)
Auth::generateJwt($userId, $tenantId, $scope, $expiresIn = null)
  ├─ Default expiry: JWT_EXPIRY_SECONDS (24 hours)
  ├─ Base64URL encode header & payload
  └─ HMAC-SHA256 sign with JWT_SECRET

// Verification (on API request)
Auth::checkJwt()
  ├─ Extract from "Authorization: Bearer" header
  ├─ Split into 3 parts, verify signature
  ├─ Decode payload, check expiration
  ├─ Validate tenant exists and is active
  └─ Store in $this->user, $this->tenantId, $this->scope
```

### Authorization Checks

```php
// Tenant context validation
$auth->requireTenant($expectedTenantId);
  └─ 403 if mismatch

// Scope/Role validation
$auth->requireScope('teacher');
  ├─ Admin can access anything
  ├─ Exact match required for other scopes
  └─ 403 if insufficient
```

### Configuration

```php
// In orchestrator/.env.php
define('AUTH_MODE', 'MIXED');           // URLENCODED, JWT, or MIXED
define('JWT_SECRET', 'your-256-bit-key');  // Min 32 chars (for HS256)
define('JWT_EXPIRY_SECONDS', 86400);    // 24 hours

define('API_KEYS', [
    'teacher' => 'secret_teacher_key',
    'admin' => 'secret_admin_key'
]);
```

### Session Management

**Optional table**: `sessions` (for token revocation)
```sql
sessions (id, user_id FK, token_hash VARCHAR(64))
├─ ip_address, user_agent (tracking)
├─ expires_at, revoked BOOLEAN
└─ created_at
```

Currently: JWT tokens are self-contained, no session table required

---

## 7. OPENAPI DOCUMENTATION LOCATION & STRUCTURE

### Main Documentation File

**Path**: `orchestrator/docs/openapi-orchestrator.yaml`

**Format**: OpenAPI 3.1.0 specification

### Structure Overview

**Metadata**:
```yaml
openapi: 3.1.0
info:
  title: Study-mate School Orchestrator API
  version: 1.0.0
  description: |
    API REST du tableau de bord pédagogique
    - Multi-tenant isolation
    - UrlEncoded + JWT authentication
    - RBAC with 5 roles (admin, direction, teacher, inspector, intervenant)
    - Rate limiting & telemetry

servers:
  - url: https://smso.mehdydriouech.fr  (production)
  - url: http://localhost:8080          (dev)
```

**Tag Organization**:
```yaml
tags:
  - name: Auth
  - name: Health
  - name: Students
  - name: Classes
  - name: Themes
  - name: Assignments
  - name: Stats
  - name: Sync
  - name: Dashboard
  - name: Mistral (AI content generation)
  - name: Webhooks (ErgoMate → Orchestrator)
  - name: Partners (API key management)
  - name: Telemetry (observability)
  - name: Analytics (Sprint 6)
  - name: Student (Sprint 5 - missions, badges)
  - name: Adaptive (Sprint 7 - reco, difficulty, focus)
  - name: Social (Sprint 8 - leaderboards, sharing)
  - name: Academic (Sprint 9 - data exports)
```

**Path Documentation**:
```yaml
paths:
  /api/health:
    get:
      tags: [Health]
      summary: État du service
      parameters:
        - name: check
          enum: [db, full]
      responses:
        '200': 
          schema: {status, version, timestamp, database{status, latency_ms}}

  /api/auth/login:
    post:
      tags: [Auth]
      requestBody:
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/LoginRequest'
      responses:
        '200':
          schema:
            $ref: '#/components/schemas/LoginResponse'
            # Returns: {token, user{id, tenantId, email, role}, expiresAt}

  /api/ai/theme-from-text:
    post:
      tags: [Mistral]
      summary: Générer un thème depuis du texte
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              properties:
                text: {type: string}
                type: {enum: [theme, quiz, flashcards, fiche]}
                difficulty: {enum: [beginner, intermediate, advanced]}
      responses:
        '200':
          schema:
            $ref: '#/components/schemas/AIGenerationResult'

  /api/students:
    get:
      tags: [Students]
      parameters:
        - name: classId
        - name: limit
        - name: offset
      responses:
        '200':
          schema:
            type: object
            properties:
              students: {type: array, items: {$ref: '#/components/schemas/Student'}}
              pagination: {total, limit, offset}

  /api/assignments:
    get:
      tags: [Assignments]
      parameters:
        - name: status
        - name: teacher_id
        - name: class_id
      responses:
        '200':
          schema:
            type: object
            properties:
              assignments: {type: array}
              pagination: {}

    post:
      tags: [Assignments]
      requestBody:
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/CreateAssignmentRequest'

  /api/social/leaderboard:
    get:
      tags: [Social]
      parameters:
        - name: theme_id
        - name: class_id
        - name: period
          enum: [weekly, monthly, all_time]
        - name: anonymize
          type: boolean
      responses:
        '200':
          schema:
            type: object
            properties:
              entries:
                type: array
                items:
                  - rank, student_name, score, badge_count, last_activity_at

  /api/reco:
    get:
      tags: [Adaptive]
      parameters:
        - name: studentId
      responses:
        '200':
          schema:
            type: object
            properties:
              recommendations:
                type: array
                items:
                  - theme_id, title, score, reasoning, difficulty_level
              profile_summary:
                avg_score, avg_mastery, total_sessions, learning_velocity
```

### Component Schemas

Common reusable schemas defined:
```yaml
components:
  schemas:
    ErrorResponse:
      properties:
        code: string
        message: string
        timestamp: string
        details: object

    LoginRequest:
      required: [email, password]
      properties:
        email: string
        password: string

    LoginResponse:
      properties:
        token: string
        user: {id, tenantId, email, firstname, lastname, role}
        expiresAt: string (ISO 8601)

    Student:
      properties:
        id, tenant_id, class_id, uuid_scolaire, email_scolaire
        firstname, lastname, status, created_at

    Assignment:
      properties:
        id, tenant_id, teacher_id, theme_id, title, type, mode
        due_at, status, ergo_push_at, ergo_ack_at, target_count

    Theme:
      properties:
        id, title, description, difficulty, source, is_public
        content: {questions, flashcards, fiche}
```

### Security Schemes

```yaml
components:
  securitySchemes:
    BearerAuth:
      type: http
      scheme: bearer
      bearerFormat: JWT
      description: "Authorization: Bearer {token}"

    ApiKeyUrlEncoded:
      type: apiKey
      in: query/body
      name: api_key
      description: "api_key + tenant_id + scope in form data"

security:
  - BearerAuth: []
  - ApiKeyUrlEncoded: []
```

### RBAC Matrix in Documentation

Complete permission matrix for all roles × resources included:

| Resource | Action | Admin | Direction | Teacher | Inspector | Intervenant |
|----------|--------|-------|-----------|---------|-----------|-------------|
| assignments | create | ✅ | ✅ | ✅ | ❌ | ❌ |
| assignments | read_own | ✅ | ✅ | ✅ | ❌ | ❌ |
| assignments | read_all | ✅ | ✅ | ❌ | ✅ | ❌ |
| students | read | ✅ | ✅ | ✅ | ✅ | ✅ |
| stats | read_own | ✅ | ✅ | ✅ | ❌ | ❌ |
| stats | read_all | ✅ | ✅ | ❌ | ✅ | ❌ |

---

## SPRINT 10 AI COPILOT - ARCHITECTURE CONSIDERATIONS

Based on the existing infrastructure, Sprint 10 should build on:

### Current Strengths
✅ Mistral AI integration pattern established (lib/ai_service.php)  
✅ JSON schema validation framework in place  
✅ Multi-tenant isolation enforced at middleware level  
✅ RBAC system with granular permissions  
✅ Database schema supports tracking AI generations  
✅ OpenAPI documentation structure ready  
✅ Notification system exists (lib/notify.php)  

### Recommended Implementation Areas

**1. Copilot Conversation Model**
   - Add `ai_conversations` table (id, tenant_id, user_id, student_id FK, context_data)
   - Add `ai_messages` table (id, conversation_id, role='user'|'assistant', content, created_at)
   - Implement stateful conversation context

**2. Intelligent Tutoring Features**
   - Leverage existing `RecommendationEngine` for personalization
   - Build on `features-*.js` pattern for new UI components
   - Use `ai_generations` for tracking copilot-created content

**3. Teacher Assistance**
   - Copilot helps create assignment briefs
   - Real-time content review & suggestions
   - Integrates with existing teacher validation workflow

**4. Student Support**
   - Copilot explains concepts, answers questions
   - Generates study guides from student notes
   - Provides feedback on student work

**5. API Endpoints** (to implement)
   ```
   POST /api/copilot/chat        -- Send message to copilot
   GET /api/copilot/chat/{id}    -- Get conversation history
   POST /api/copilot/explain     -- Explain a concept
   POST /api/copilot/generate-guide -- Generate study guide
   ```

**6. Authentication**
   - Use existing JWT/UrlEncoded system
   - Enforce tenant isolation in middleware
   - RBAC: teacher + direction can initiate sessions

**7. Observability**
   - Track in existing telemetry system
   - Log copilot interactions for audit
   - Monitor Mistral API costs per tenant (BYOK model)

---

## QUICK START CHECKLIST FOR SPRINT 10

- [ ] Review OpenAPI spec (`orchestrator/docs/openapi-orchestrator.yaml`)
- [ ] Study `ai_service.php` for integration patterns
- [ ] Examine `RecommendationEngine` for context-aware logic
- [ ] Check middleware stack (_middleware_*.php)
- [ ] Review RBAC permissions matrix
- [ ] Plan copilot database schema
- [ ] Prototype copilot endpoint using `/api/ai/` pattern
- [ ] Create OpenAPI paths for copilot endpoints
- [ ] Add frontend copilot UI module (public/js/features-copilot.js)
- [ ] Implement conversation context tracking
- [ ] Build prompt engineering for multi-turn chat
- [ ] Add copilot permissions to RBAC matrix
- [ ] Create integration tests
- [ ] Document Sprint 10 features

