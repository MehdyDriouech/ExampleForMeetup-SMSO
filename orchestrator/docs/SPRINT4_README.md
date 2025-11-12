# Sprint 4 — API Rate Limiting & Télémétrie

**Date:** 2025-11-12
**Nom:** Écosystème & Intégrations
**Durée:** 2 semaines
**Objectif:** Ouvrir des intégrations partenaires avec quotas, télémétrie et rate limiting

## 📋 Vue d'ensemble

Ce sprint implémente un système complet de rate limiting par clé API et de télémétrie pour l'observabilité et le monitoring des performances. Il permet d'ouvrir l'API à des partenaires externes tout en protégeant les ressources et en assurant la traçabilité.

## 🎯 Épiques Développées

### E4-RATE: Rate Limiting & Quotas
✅ **Complété**

Système de limitation de taux multi-niveaux (minute, heure, jour) basé sur l'algorithme Token Bucket.

#### Fonctionnalités:
- ✅ Clés API avec quotas configurables (minute/heure/jour)
- ✅ Headers X-RateLimit-* standards (compatibles RFC 6585)
- ✅ Réponse 429 avec Retry-After quand quota dépassé
- ✅ Scopes et permissions par clé API
- ✅ Dashboard UI pour surveiller l'usage par partenaire

### E4-TELEMETRY: Télémétrie API
✅ **Complété**

Système de collecte et d'analyse de télémétrie pour la performance et l'observabilité.

#### Fonctionnalités:
- ✅ Journalisation automatique de chaque requête API
- ✅ Corrélation par X-Request-ID
- ✅ Métriques: latence, erreurs, requêtes DB
- ✅ Agrégation quotidienne avec percentiles (P50, P95, P99)
- ✅ Export JSON quotidien compressé
- ✅ Dashboard UI avec graphiques Chart.js

## 📁 Structure des Fichiers

```
orchestrator/
├── migrations/
│   └── 004_sprint4_rate_limiting_telemetry.sql    # Schéma DB
├── api/
│   ├── _middleware_rate_limit.php                 # Middleware rate limiting
│   ├── _middleware_telemetry.php                  # Middleware télémétrie
│   ├── partners/
│   │   └── usage.php                              # API usage partenaires
│   └── telemetry/
│       └── stats.php                              # API stats télémétrie
├── jobs/
│   └── export_telemetry.py                        # Job export quotidien
└── docs/
    ├── openapi-orchestrator.yaml                  # Spec OpenAPI mise à jour
    └── SPRINT4_README.md                          # Cette documentation

public/js/view/
├── view-partners.js                               # UI usage partenaires
└── view-telemetry.js                              # UI télémétrie
```

## 🗄️ Schéma de Base de Données

### Tables créées:

#### `api_keys`
Gestion des clés API avec quotas et scopes.

```sql
CREATE TABLE api_keys (
    id VARCHAR(64) PRIMARY KEY,
    tenant_id VARCHAR(64) NOT NULL,
    owner VARCHAR(255) NOT NULL,
    key_hash VARCHAR(128) NOT NULL,          -- SHA256 hash
    scopes JSON NOT NULL,                     -- ["students:read", "assignments:write"]
    quota_daily INT NOT NULL DEFAULT 10000,
    quota_per_minute INT NOT NULL DEFAULT 60,
    quota_per_hour INT NOT NULL DEFAULT 1000,
    status ENUM('active', 'suspended', 'revoked'),
    created_at TIMESTAMP,
    last_used_at TIMESTAMP,
    expires_at TIMESTAMP NULL
);
```

#### `rate_limit_buckets`
Compteurs Token Bucket pour rate limiting.

```sql
CREATE TABLE rate_limit_buckets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id VARCHAR(64) NOT NULL,
    bucket_type ENUM('minute', 'hour', 'day'),
    window_start TIMESTAMP NOT NULL,
    request_count INT NOT NULL DEFAULT 0,
    UNIQUE KEY unique_bucket (api_key_id, bucket_type, window_start)
);
```

#### `api_telemetry`
Télémétrie détaillée de chaque requête API.

```sql
CREATE TABLE api_telemetry (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(36) NOT NULL,         -- UUID pour corrélation
    tenant_id VARCHAR(64) NOT NULL,
    api_key_id VARCHAR(64) NULL,
    user_id VARCHAR(64) NULL,
    method VARCHAR(10) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    status_code INT NOT NULL,
    duration_ms DECIMAL(10, 2) NOT NULL,
    db_queries INT DEFAULT 0,
    db_time_ms DECIMAL(10, 2) DEFAULT 0,
    user_agent TEXT,
    ip_address VARCHAR(45),
    error_message TEXT NULL,
    error_code VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) PARTITION BY RANGE (TO_DAYS(created_at));
```

#### `telemetry_daily_summary`
Agrégations pré-calculées pour dashboards rapides.

```sql
CREATE TABLE telemetry_daily_summary (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(64) NOT NULL,
    api_key_id VARCHAR(64) NULL,
    endpoint VARCHAR(255) NOT NULL,
    date DATE NOT NULL,
    total_requests INT NOT NULL,
    successful_requests INT NOT NULL,
    failed_requests INT NOT NULL,
    avg_duration_ms DECIMAL(10, 2),
    p50_duration_ms DECIMAL(10, 2),
    p95_duration_ms DECIMAL(10, 2),
    p99_duration_ms DECIMAL(10, 2),
    max_duration_ms DECIMAL(10, 2),
    UNIQUE KEY (tenant_id, api_key_id, endpoint, date)
);
```

### Stored Procedures:

- `check_rate_limit()`: Vérification atomique et incrémentation du compteur
- `cleanup_rate_limit_buckets()`: Nettoyage quotidien des buckets expirés
- `cleanup_old_telemetry()`: Purge des anciennes données (rétention 90 jours)

## 🔧 API Endpoints

### Rate Limiting

Tous les endpoints API incluent désormais les headers X-RateLimit-*:

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1699977600
X-RateLimit-Limit-Hour: 1000
X-RateLimit-Remaining-Hour: 856
X-RateLimit-Limit-Day: 10000
X-RateLimit-Remaining-Day: 7234
```

#### Réponse 429 (Quota dépassé):

```json
{
  "error": "rate_limit_exceeded",
  "message": "Rate limit exceeded. Please retry after the reset time.",
  "retry_after": 45,
  "reset_at": "2025-11-12T10:15:00Z",
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

### Nouveaux Endpoints

#### `GET /api/partners/usage`
Retourne les statistiques d'usage des clés API partenaires.

**Paramètres:**
- `api_key_id` (optionnel): Filtrer par clé spécifique
- `start_date`: Date début (YYYY-MM-DD)
- `end_date`: Date fin (YYYY-MM-DD)

**Permissions:** admin, direction

**Exemple de réponse:**
```json
{
  "tenant_id": "TENANT_INST_PARIS",
  "period": {
    "start_date": "2025-10-13",
    "end_date": "2025-11-12"
  },
  "summary": {
    "total_api_keys": 2,
    "active_api_keys": 2,
    "total_requests": 125430,
    "successful_requests": 124891,
    "failed_requests": 539,
    "success_rate": 99.57,
    "avg_duration_ms": 87.3
  },
  "api_keys": [
    {
      "id": "APIKEY_PARIS_PARTNER_001",
      "owner": "External Partner Service",
      "status": "active",
      "quotas": {
        "daily": 50000,
        "per_hour": 2000,
        "per_minute": 100
      },
      "rate_limit_status": {
        "remaining": {
          "minute": 89,
          "hour": 1645,
          "day": 42108
        }
      },
      "usage": {
        "total_requests": 85234,
        "success_rate": 99.8,
        "avg_duration_ms": 92.4
      }
    }
  ]
}
```

#### `GET /api/telemetry/stats`
Retourne les statistiques de télémétrie API.

**Paramètres:**
- `view`: Type de vue (overview, endpoints, errors, performance)
- `start_date`: Date début (YYYY-MM-DD)
- `end_date`: Date fin (YYYY-MM-DD)
- `endpoint` (optionnel): Filtrer par endpoint
- `limit`: Limite de résultats (max 500)

**Permissions:** admin, direction

**Exemple (view=overview):**
```json
{
  "view": "overview",
  "overview": {
    "total_requests": 245678,
    "active_days": 7,
    "successful_requests": 243012,
    "client_errors": 1834,
    "server_errors": 832,
    "avg_duration_ms": 124.5,
    "max_duration_ms": 4567.8,
    "avg_db_queries": 3.2,
    "avg_db_time_ms": 45.6
  },
  "daily_stats": [
    {
      "date": "2025-11-05",
      "requests": 34512,
      "successful": 34234,
      "errors": 278,
      "avg_duration_ms": 118.3
    }
  ]
}
```

## 🚀 Utilisation

### 1. Migration de Base de Données

```bash
mysql -u orchestrator_user -p orchestrator < orchestrator/migrations/004_sprint4_rate_limiting_telemetry.sql
```

### 2. Utilisation du Middleware Rate Limiting

Dans vos endpoints API:

```php
<?php
require_once __DIR__ . '/_middleware_rate_limit.php';
require_once __DIR__ . '/_middleware_telemetry.php';

// Démarrer télémétrie
$telemetry = startTelemetry();

// Enforcer rate limit (optionnel)
$rateLimitInfo = enforceRateLimit(false); // false = non requis

// Si clé API fournie, vérifier les scopes
if ($rateLimitInfo) {
    requireScope($rateLimitInfo, 'students:read');
    $telemetry->setApiKey($rateLimitInfo->apiKeyId);
}

// ... traitement de la requête ...

// Terminer télémétrie
$telemetry->end(200);
jsonResponse($data);
```

### 3. Job d'Export Quotidien

Configuration cron:

```bash
# /etc/cron.d/orchestrator-telemetry
0 2 * * * python3 /path/to/orchestrator/jobs/export_telemetry.py
```

Exécution manuelle:

```bash
# Export pour hier
python3 orchestrator/jobs/export_telemetry.py

# Export pour une date spécifique
python3 orchestrator/jobs/export_telemetry.py --date 2025-11-10

# Skip export JSON
python3 orchestrator/jobs/export_telemetry.py --skip-export

# Personnaliser rétention
python3 orchestrator/jobs/export_telemetry.py --retention-days 60
```

### 4. Dashboard UI

Accéder aux dashboards (rôles admin/direction uniquement):

- **Usage Partenaires:** `/partners` (vue partners usage)
- **Télémétrie:** `/telemetry` (vue télémétrie)

## 📊 Headers de Télémétrie

Chaque réponse API inclut maintenant:

```http
X-Request-ID: 550e8400-e29b-41d4-a716-446655440000
X-Response-Time: 124.56ms
X-DB-Queries: 3
```

## 🔐 Sécurité

### Gestion des Clés API

1. Les clés API sont hashées en SHA256 dans la base
2. Les scopes limitent les permissions par clé
3. Expiration automatique supportée
4. Audit complet de chaque utilisation

### Isolation Tenant

- Toutes les clés API sont liées à un tenant
- Validation stricte du tenant_id dans les requêtes
- Logs de sécurité pour tentatives d'accès inter-tenant

### Protection DDoS

- Rate limiting multi-niveau (minute/heure/jour)
- Réponse 429 avec Retry-After
- Suspension automatique possible des clés abusives

## 📈 Métriques et Observabilité

### KPIs Disponibles

**Performance:**
- Latence moyenne, P50, P95, P99, max
- Nombre de requêtes DB par endpoint
- Temps DB cumulé

**Fiabilité:**
- Taux de succès par endpoint
- Erreurs 4xx vs 5xx
- Taux d'erreur par partenaire

**Usage:**
- Requêtes par jour/heure/minute
- Distribution temporelle
- Top endpoints par volume

### Alerting (Recommandations)

Configurer des alertes sur:
- Taux d'erreur > 5%
- Latence P95 > 1000ms
- Rate limit atteint fréquemment
- Pic de requêtes inhabituel

## 🧪 Tests

### Test Rate Limiting

```bash
# Test avec clé API valide
curl -H "X-API-Key: test_partner_key_paris_001" \
     -H "X-Orchestrator-Id: TENANT_INST_PARIS" \
     https://smso.mehdydriouech.fr/api/students?classId=CLASS_PARIS_L1_A

# Vérifier headers
# X-RateLimit-Limit: 100
# X-RateLimit-Remaining: 99
# X-RateLimit-Reset: 1699977600
```

### Test Télémétrie

```bash
# Vérifier headers de corrélation
curl -H "X-Request-ID: my-custom-id" \
     -H "Authorization: Bearer $JWT_TOKEN" \
     https://smso.mehdydriouech.fr/api/students?classId=CLASS_PARIS_L1_A

# Réponse inclut:
# X-Request-ID: my-custom-id
# X-Response-Time: 87.34ms
# X-DB-Queries: 2
```

## 📖 Conformité Standards

### RFC et Standards

- **RFC 6585**: HTTP 429 Too Many Requests
- **RFC 6648**: Deprecation of X- prefix (mais conservé pour compatibilité)
- **OpenAPI 3.1**: Spécification complète des endpoints
- **JSON:API**: Structure cohérente des erreurs

## 🔄 Maintenance

### Nettoyage Automatique

Les événements MySQL suivants s'exécutent automatiquement:

1. **Quotidien (3h):** Nettoyage des buckets rate limit > 2 jours
2. **Hebdomadaire (4h):** Purge télémétrie > 90 jours

### Monitoring Recommandé

- Taille table `api_telemetry` (peut grandir rapidement)
- Performance des queries sur télémétrie
- Espace disque pour exports JSON
- Latence stored procedure `check_rate_limit`

## 🎨 UI/UX

### Dashboard Partenaires

- Vue globale: KPIs agrégés
- Par clé API: usage détaillé, rate limits, top endpoints
- Graphiques Chart.js pour tendances
- Export CSV (à implémenter)

### Dashboard Télémétrie

4 vues:
1. **Overview**: KPIs globaux + tendances quotidiennes/horaires
2. **Endpoints**: Stats par endpoint (latence, erreurs, DB)
3. **Errors**: Analyse des erreurs avec grouping
4. **Performance**: Slow queries, percentiles

## 🚧 Prochaines Étapes (Post-Sprint 4)

### Améliorations Futures

- [ ] Génération automatique de clés API via UI
- [ ] Rotation de clés API
- [ ] Webhooks sur dépassement de quota
- [ ] Alerting Slack/Email configurable
- [ ] Export CSV/Excel des stats
- [ ] Graphiques temps réel avec WebSocket
- [ ] Cache Redis pour rate limiting (performance)
- [ ] Analyse prédictive des quotas

## 📝 Notes d'Implémentation

### Choix Techniques

**Token Bucket vs Leaky Bucket:**
Choisi Token Bucket pour sa simplicité et compatibilité MySQL.

**Partitionnement `api_telemetry`:**
Par date pour purge efficace et performance queries.

**Python pour Export:**
Flexibilité pour export formats multiples (JSON, Parquet future).

**Chart.js:**
Léger, pas de dépendances lourdes, offline-ready.

### Performance

- Index optimisés sur `tenant_id`, `api_key_id`, `created_at`
- Stored procedures pour atomicité rate limiting
- Sommaires pré-agrégés pour dashboards rapides
- Partitionnement pour gestion efficace du cycle de vie

## 🤝 Contribution

Pour questions ou améliorations:
- Voir `openapi-orchestrator.yaml` pour spec API complète
- Tests d'intégration dans `/orchestrator/tests/integration/`
- Documentation développeur dans `/orchestrator/docs/`

---

**Auteur:** Sprint 4 Team
**Date:** 2025-11-12
**Version:** 1.0.0
