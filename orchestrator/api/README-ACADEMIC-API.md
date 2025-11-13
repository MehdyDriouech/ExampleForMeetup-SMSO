# API Académique - Sprint 9

## Vue d'ensemble

API lecture seule pour l'intégration avec les ENT et LMS. Permet d'exporter les données académiques (étudiants, classes, promotions, thèmes, affectations, statistiques) avec rate limiting et observabilité complète.

## Caractéristiques

- ✅ **Read-only**: Endpoints GET uniquement
- ✅ **Rate Limiting**: Quotas par clé API (minute, heure, jour)
- ✅ **Tenant Isolation**: Isolation stricte des données par tenant
- ✅ **Observabilité**: Tous les appels journalisés dans `sync_logs`
- ✅ **OpenAPI**: Documentation complète dans `/orchestrator/docs/openapi-orchestrator.yaml`
- ✅ **Sécurité**: Authentification par clé API + support JWT optionnel

## Endpoints

### `/api/academic/students`
Liste des étudiants avec filtres par classe et promotion.

**Paramètres:**
- `class_id` (optionnel): Filtrer par classe
- `promo_id` (optionnel): Filtrer par promotion
- `limit` (optionnel, défaut 100, max 500): Limite de résultats
- `offset` (optionnel, défaut 0): Pagination offset

**Scope requis:** `academic:read`

### `/api/academic/classes`
Liste des classes avec informations enseignant et comptage d'étudiants.

**Paramètres:**
- `promo_id` (optionnel): Filtrer par promotion
- `status` (optionnel, défaut `active`): Statut des classes

**Scope requis:** `academic:read`

### `/api/academic/promotions`
Liste des promotions avec comptages de classes et étudiants.

**Paramètres:**
- `status` (optionnel, défaut `active`): Statut des promotions

**Scope requis:** `academic:read`

### `/api/academic/themes`
Liste des thèmes pédagogiques.

**Paramètres:**
- `status` (optionnel, défaut `active`): Statut des thèmes
- `difficulty` (optionnel): Niveau de difficulté (`beginner`, `intermediate`, `advanced`)
- `limit` (optionnel, défaut 100, max 500)
- `offset` (optionnel, défaut 0)

**Scope requis:** `academic:read`

### `/api/academic/assignments`
Liste des affectations (read-only).

**Paramètres:**
- `class_id` (optionnel): Filtrer par classe
- `teacher_id` (optionnel): Filtrer par enseignant
- `status` (optionnel): Statut de l'affectation
- `limit` (optionnel, défaut 100, max 500)
- `offset` (optionnel, défaut 0)

**Scope requis:** `academic:read`

### `/api/academic/stats`
Statistiques agrégées avec métriques calculées.

**Paramètres:**
- `class_id` (optionnel): Filtrer par classe
- `student_id` (optionnel): Filtrer par étudiant
- `theme_id` (optionnel): Filtrer par thème
- `start_date` (optionnel, défaut -30 jours): Date de début (YYYY-MM-DD)
- `end_date` (optionnel, défaut aujourd'hui): Date de fin (YYYY-MM-DD)

**Scope requis:** `academic:read`

**Métriques calculées:**
- `total_sessions`: Nombre total de sessions
- `avg_score`: Score moyen (0-100)
- `avg_mastery`: Maîtrise moyenne (0-1)
- `total_time_hours`: Temps total en heures

### `/api/academic/export`
Export complet de toutes les données en une seule requête.

**Paramètres:**
- `format` (optionnel, défaut `json`): Format d'export (`json` ou `csv`)
- `include` (optionnel, défaut `students,classes,themes,stats`): Ressources à inclure (CSV)

**Scope requis:** `academic:export`

⚠️ **Note:** Le scope `academic:export` nécessite des quotas plus élevés.

## Authentification

### Méthode 1: Clé API (Recommandée)

```bash
curl -H "X-API-Key: YOUR_API_KEY" \
     -H "X-Orchestrator-Id: YOUR_TENANT_ID" \
     https://smso.mehdydriouech.fr/api/academic/students
```

### Méthode 2: JWT Bearer

```bash
curl -H "Authorization: Bearer YOUR_JWT_TOKEN" \
     -H "X-Orchestrator-Id: YOUR_TENANT_ID" \
     https://smso.mehdydriouech.fr/api/academic/students
```

## Rate Limiting

Chaque clé API a des quotas configurés:

- **Per-minute**: Ex. 60 req/min
- **Per-hour**: Ex. 1000 req/h
- **Per-day**: Ex. 10000 req/jour

### Headers de réponse

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 42
X-RateLimit-Reset: 1699999999
X-RateLimit-Limit-Hour: 1000
X-RateLimit-Remaining-Hour: 856
X-RateLimit-Limit-Day: 10000
X-RateLimit-Remaining-Day: 7234
```

### En cas de dépassement (429)

```json
{
  "error": "rate_limit_exceeded",
  "message": "Rate limit exceeded. Please retry after the reset time.",
  "retry_after": 45,
  "reset_at": "2025-11-13T10:15:00Z",
  "limits": {
    "minute": 60,
    "hour": 1000,
    "day": 10000
  },
  "remaining": {
    "minute": 0,
    "hour": 234,
    "day": 5678
  }
}
```

## Observabilité

Tous les appels sont journalisés dans la table `sync_logs`:

```sql
SELECT * FROM sync_logs
WHERE type = 'academic_api'
ORDER BY created_at DESC
LIMIT 10;
```

Métadonnées enregistrées:
- `endpoint`: Endpoint appelé
- `api_key_id`: ID de la clé API
- `tenant_id`: ID du tenant
- `status_code`: Code HTTP de la réponse
- `duration_ms`: Durée de la requête en ms
- `ip`: Adresse IP du client
- `user_agent`: User Agent
- Filtres et paramètres de la requête

## Exemples d'utilisation

### 1. Lister tous les étudiants d'une classe

```bash
curl -H "X-API-Key: sk_live_abc123" \
     -H "X-Orchestrator-Id: TENANT_INST_PARIS" \
     "https://smso.mehdydriouech.fr/api/academic/students?class_id=CLASS_PARIS_L1_A&limit=50"
```

### 2. Récupérer les statistiques d'une classe sur 7 jours

```bash
curl -H "X-API-Key: sk_live_abc123" \
     -H "X-Orchestrator-Id: TENANT_INST_PARIS" \
     "https://smso.mehdydriouech.fr/api/academic/stats?class_id=CLASS_PARIS_L1_A&start_date=2025-11-06&end_date=2025-11-13"
```

### 3. Export complet pour ENT

```bash
curl -H "X-API-Key: sk_live_abc123" \
     -H "X-Orchestrator-Id: TENANT_INST_PARIS" \
     "https://smso.mehdydriouech.fr/api/academic/export?include=students,classes,stats&format=json" \
     > export-ent-$(date +%Y%m%d).json
```

### 4. Lister les thèmes par difficulté

```bash
curl -H "X-API-Key: sk_live_abc123" \
     -H "X-Orchestrator-Id: TENANT_INST_PARIS" \
     "https://smso.mehdydriouech.fr/api/academic/themes?difficulty=advanced&status=active"
```

## Scopes disponibles

- `academic:read`: Lecture des données académiques (students, classes, promotions, themes, assignments, stats)
- `academic:export`: Export complet de toutes les données (nécessite quotas élevés)

## Création d'une clé API

```sql
-- Exemple pour créer une clé API avec scope academic:read
INSERT INTO api_keys (
  id,
  tenant_id,
  owner,
  key_hash,
  scopes,
  quota_daily,
  quota_per_hour,
  quota_per_minute,
  status,
  expires_at
) VALUES (
  'APIKEY_ENT_001',
  'TENANT_INST_PARIS',
  'ENT Integration',
  SHA2('sk_live_YOUR_SECRET_KEY', 256),
  '["academic:read"]',
  10000,
  1000,
  60,
  'active',
  '2026-12-31 23:59:59'
);
```

⚠️ **Important:**
- Stockez la clé en clair **uniquement** lors de la création
- Le hash SHA256 est stocké en base
- La clé ne peut pas être récupérée après création

## Gestion des erreurs

### 401 Unauthorized
Clé API manquante ou invalide.

```json
{
  "error": "invalid_api_key",
  "message": "Invalid or expired API key."
}
```

### 403 Forbidden
Tenant invalide ou scope insuffisant.

```json
{
  "error": "insufficient_scope",
  "message": "Your API key does not have the required scope: academic:export",
  "required_scope": "academic:export",
  "your_scopes": ["academic:read"]
}
```

### 404 Not Found
Ressource inexistante.

```json
{
  "error": "not_found",
  "message": "Academic API resource not found: unknown",
  "available_resources": [
    "students",
    "classes",
    "promotions",
    "themes",
    "assignments",
    "stats",
    "export"
  ]
}
```

### 405 Method Not Allowed
Méthode non autorisée (seul GET est accepté).

```json
{
  "error": "method_not_allowed",
  "message": "Only GET requests are allowed on academic endpoints. This is a read-only API.",
  "allowed_methods": ["GET"]
}
```

### 429 Too Many Requests
Quota dépassé. Voir section Rate Limiting ci-dessus.

## Support

Pour toute question ou problème:
1. Consultez la documentation OpenAPI: `/orchestrator/docs/openapi-orchestrator.yaml`
2. Vérifiez les logs dans `sync_logs` pour le debugging
3. Contactez l'équipe technique

## Version

- **Version API**: 1.0
- **Sprint**: Sprint 9 - Institutional & Data Hub
- **Date**: 2025-11-13
