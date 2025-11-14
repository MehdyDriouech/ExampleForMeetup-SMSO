# Sprint 15 - Gouvernance IA + Élèves + RGPD

## 📋 Vue d'ensemble

Le Sprint 15 introduit une gouvernance complète de l'IA, la gestion des identités élèves avec UUID, et la conformité RGPD.

### Objectifs principaux

1. **Gestion des élèves avec UUID** : Identité pédagogique et sociale séparée
2. **Politiques IA par tenant** : Kill switch, BYOK, configuration des modèles
3. **Budgets IA** : Contrôle des coûts par tenant et enseignant
4. **Journal d'audit IA** : Traçabilité complète des interactions
5. **Conformité RGPD** : Export, pseudonymisation, suppression

---

## 🗄️ Modifications de la base de données

### Migration

```bash
mysql -u root -p studymate < migrations/015_sprint15_ia_governance_students_rgpd.sql
```

### Nouvelles tables

#### 1. `students` (modifications)
- `uuid_student` : UUID pédagogique unique
- `uuid_social` : UUID pour le suivi social anonymisé
- `rgpd_status` : 'active', 'pseudonymized', 'deleted'
- `rgpd_pseudonymized_at` : Date de pseudonymisation
- `rgpd_deleted_at` : Date de suppression logique
- `rgpd_export_count` : Nombre d'exports RGPD

#### 2. `ia_policies` (nouvelle)
Configuration IA par tenant :
- Kill switch (activation/désactivation globale)
- BYOK (Bring Your Own Key)
- Modèles autorisés
- Niveau de filtrage de contenu
- Durée de conservation des logs

#### 3. `ia_budgets` (nouvelle)
Budgets par tenant et enseignant :
- Type : 'tenant' ou 'teacher'
- Tokens max/utilisés
- Requêtes max/utilisées
- Période budgétaire
- Alertes de dépassement

#### 4. `audit_ia_log` (nouvelle)
Journal complet des interactions IA :
- Prompt et réponse
- Modèle utilisé
- Tokens consommés
- Latence
- Statut (success, error, filtered, budget_exceeded)

#### 5. `rgpd_export_requests` (nouvelle)
Historique des demandes d'export RGPD

---

## 🔌 Nouveaux endpoints API

### Gestion des élèves

#### POST `/api/admin/students`
Créer un élève avec génération automatique d'UUID

```bash
curl -X POST https://api.studymate.fr/api/admin/students \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Orchestrator-Id: $TENANT_ID" \
  -H "Content-Type: application/json" \
  -d '{
    "firstname": "Jean",
    "lastname": "Dupont",
    "class_id": "CLS_123"
  }'
```

**Réponse :**
```json
{
  "success": true,
  "data": {
    "id": "STU_ABC123",
    "uuid_student": "550e8400-e29b-41d4-a716-446655440000",
    "uuid_social": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
    "firstname": "Jean",
    "lastname": "Dupont",
    "class_id": "CLS_123",
    "rgpd_status": "active",
    "created_at": "2025-11-14T10:00:00Z"
  }
}
```

#### GET `/api/admin/students/{uuid}/export`
Export RGPD complet des données d'un élève

```bash
curl https://api.studymate.fr/api/admin/students/550e8400-e29b-41d4-a716-446655440000/export \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Orchestrator-Id: $TENANT_ID"
```

**Réponse :** JSON complet avec missions, scores, badges, interactions sociales.

#### PATCH `/api/admin/students/{uuid}/pseudonymize`
Pseudonymiser les données d'un élève

```bash
curl -X PATCH https://api.studymate.fr/api/admin/students/550e8400-e29b-41d4-a716-446655440000/pseudonymize \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Orchestrator-Id: $TENANT_ID"
```

⚠️ **Opération irréversible** : Les données personnelles sont remplacées par "ANONYME".

#### DELETE `/api/admin/students/{uuid}`
Suppression logique d'un élève

---

### Politiques IA

#### GET `/api/admin/ia-policy`
Récupérer la politique IA du tenant

```bash
curl https://api.studymate.fr/api/admin/ia-policy \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Orchestrator-Id: $TENANT_ID"
```

#### PUT `/api/admin/ia-policy`
Mettre à jour la politique IA (admin/direction uniquement)

**Kill Switch - Désactiver l'IA :**
```bash
curl -X PUT https://api.studymate.fr/api/admin/ia-policy \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Orchestrator-Id: $TENANT_ID" \
  -H "Content-Type: application/json" \
  -d '{
    "ia_enabled": false,
    "ia_disabled_reason": "Maintenance planifiée"
  }'
```

**BYOK - Configuration :**
```bash
curl -X PUT https://api.studymate.fr/api/admin/ia-policy \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Orchestrator-Id: $TENANT_ID" \
  -H "Content-Type: application/json" \
  -d '{
    "byok_enabled": true,
    "api_provider": "openai",
    "api_key": "sk-..."
  }'
```

**Configuration des modèles :**
```bash
curl -X PUT https://api.studymate.fr/api/admin/ia-policy \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Orchestrator-Id: $TENANT_ID" \
  -H "Content-Type: application/json" \
  -d '{
    "default_model": "gpt-4o-mini",
    "allowed_models": ["gpt-4o-mini", "gpt-4o", "claude-3-5-sonnet"]
  }'
```

---

### Budgets IA

#### GET `/api/admin/ia-budgets`
Lister tous les budgets

```bash
curl https://api.studymate.fr/api/admin/ia-budgets \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Orchestrator-Id: $TENANT_ID"
```

Filtres disponibles :
- `?type=tenant` : Budgets tenant uniquement
- `?type=teacher` : Budgets enseignants uniquement
- `?user_id=USER_123` : Budget d'un enseignant spécifique

#### POST `/api/admin/ia-budgets`
Créer un nouveau budget

**Budget tenant :**
```bash
curl -X POST https://api.studymate.fr/api/admin/ia-budgets \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Orchestrator-Id: $TENANT_ID" \
  -H "Content-Type: application/json" \
  -d '{
    "budget_type": "tenant",
    "max_tokens": 1000000,
    "period_start": "2025-11-01T00:00:00Z",
    "period_end": "2025-11-30T23:59:59Z",
    "alert_threshold_percent": 80
  }'
```

**Budget enseignant :**
```bash
curl -X POST https://api.studymate.fr/api/admin/ia-budgets \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Orchestrator-Id: $TENANT_ID" \
  -H "Content-Type: application/json" \
  -d '{
    "budget_type": "teacher",
    "user_id": "USER_123",
    "max_tokens": 50000,
    "period_start": "2025-11-01T00:00:00Z",
    "period_end": "2025-11-30T23:59:59Z"
  }'
```

#### GET `/api/admin/ia-budgets/usage`
Statistiques d'usage globales

---

### Journal d'audit IA

#### GET `/api/admin/ia-audit`
Récupérer les logs d'audit

```bash
curl "https://api.studymate.fr/api/admin/ia-audit?limit=50&date_from=2025-11-01" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Orchestrator-Id: $TENANT_ID"
```

**Filtres disponibles :**
- `user_id` : Filtrer par utilisateur
- `model` : Filtrer par modèle (gpt-4o-mini, claude-3-5-sonnet, etc.)
- `context_type` : Type de contexte (theme_creation, improvement, etc.)
- `status` : Statut (success, error, filtered, budget_exceeded)
- `date_from` / `date_to` : Période
- `limit` / `offset` : Pagination

**Réponse :**
```json
{
  "success": true,
  "data": [
    {
      "id": "LOG_123",
      "user_email": "prof@ecole.fr",
      "prompt_text": "Créer un exercice sur les fractions",
      "model_used": "gpt-4o-mini",
      "tokens_total": 1234,
      "status": "success",
      "created_at": "2025-11-14T10:00:00Z"
    }
  ],
  "stats": {
    "total_requests": 150,
    "total_tokens": 123456,
    "avg_latency_ms": 1234,
    "successful_requests": 145,
    "failed_requests": 5
  },
  "model_breakdown": [
    {
      "model_used": "gpt-4o-mini",
      "requests": 120,
      "tokens_used": 98765
    }
  ]
}
```

---

## 🎨 Interface utilisateur

### Admin IA View

Nouvelle interface d'administration accessible via `/admin/ia`

**Onglets :**
1. **Politique IA**
   - Kill switch
   - Configuration BYOK
   - Modèles autorisés
   - Filtrage de contenu

2. **Budgets**
   - Vue d'ensemble tenant
   - Budgets enseignants
   - Création de nouveaux budgets
   - Alertes de dépassement

3. **Journal d'audit**
   - Logs des interactions IA
   - Statistiques
   - Filtres avancés

4. **Statistiques**
   - Graphiques d'utilisation
   - Tendances
   - Breakdown par modèle

### Utilisation

```javascript
// Initialiser la vue admin IA
const adminIAView = new AdminIAView();
adminIAView.init();
```

---

## 🔧 Service d'audit IA

### Utilisation dans le code PHP

```php
require_once __DIR__ . '/lib/ia_audit_service.php';

// Logger une interaction IA
$logId = iaAudit()->logInteraction([
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'user_role' => 'teacher',
    'prompt_text' => 'Créer un exercice sur les fractions',
    'model_used' => 'gpt-4o-mini',
    'tokens_total' => 1234,
    'response_text' => 'Voici un exercice...',
    'context_type' => 'theme_creation',
    'context_id' => 'THEME_123',
    'status' => 'success'
]);

// Vérifier le budget avant une requête
$budgetExceeded = iaAudit()->checkBudget($tenantId, $userId, 1000);
if ($budgetExceeded) {
    errorResponse('BUDGET_EXCEEDED', 'Budget IA dépassé', 403);
}

// Récupérer les stats d'usage
$stats = iaAudit()->getUsageStats($tenantId, 30); // 30 derniers jours
```

---

## 🔄 ErgoMate - Synchronisation des élèves

### Service de synchronisation

```javascript
const StudentSyncService = require('./services/student_sync_service');

const syncService = new StudentSyncService({
    orchestratorUrl: 'https://api.studymate.fr',
    tenantId: 'TENANT_123',
    apiToken: 'your-api-token'
});

// Synchroniser un élève par UUID
const student = await syncService.syncStudent('550e8400-e29b-41d4-a716-446655440000');

// Synchroniser tous les élèves d'une classe
const count = await syncService.syncClassStudents('CLS_123', db);

// Gérer la pseudonymisation
await syncService.handlePseudonymization('550e8400-e29b-41d4-a716-446655440000', db);

// Valider l'accès d'un élève
const canAccess = await syncService.validateStudentAccess('550e8400-e29b-41d4-a716-446655440000', db);
```

---

## 📊 Permissions RBAC

### Nouvelles permissions

| Ressource | Actions | Rôles autorisés |
|-----------|---------|-----------------|
| `students` | create, read, update, delete | admin, direction |
| `ia_policy` | read, update | admin, direction |
| `ia_budgets` | read, create, update | admin, direction |
| `ia_audit` | read | admin, direction |

### Configuration

Les permissions sont gérées via la matrice RBAC existante dans `_middleware_rbac.php`.

---

## 🔒 Sécurité

### Chiffrement des clés API (BYOK)

Les clés API personnalisées sont chiffrées avant stockage. Configuration dans `.env.php` :

```php
define('ENCRYPTION_KEY', 'your-secure-encryption-key-32-chars-min');
```

⚠️ **Important** : Utilisez une clé forte et unique en production !

### Isolation des tenants

Tous les endpoints vérifient l'isolation des tenants via le middleware `_middleware_tenant.php`.

### Logs d'audit

Toutes les actions administratives sont loggées dans `audit_logs` :
- Création/modification d'élèves
- Modifications des politiques IA
- Création/modification de budgets
- Export RGPD
- Pseudonymisation

---

## 🧪 Tests

### Tests d'intégration

```bash
php orchestrator/tests/integration/Sprint15IAPolicyTest.php
php orchestrator/tests/integration/Sprint15StudentRGPDTest.php
php orchestrator/tests/integration/Sprint15BudgetsTest.php
```

### Tests manuels

1. **Kill Switch**
   - Désactiver l'IA via l'API
   - Vérifier que les requêtes IA sont bloquées
   - Réactiver et vérifier le fonctionnement

2. **Budgets**
   - Créer un budget avec limite basse
   - Effectuer des requêtes IA jusqu'au dépassement
   - Vérifier l'erreur `BUDGET_EXCEEDED`

3. **RGPD**
   - Créer un élève
   - Exporter ses données
   - Pseudonymiser
   - Vérifier que les données sont anonymisées

---

## 📈 Monitoring

### Métriques clés

- Nombre d'interactions IA par jour
- Tokens consommés par tenant/enseignant
- Taux d'erreur IA
- Latence moyenne des requêtes
- Budgets dépassés

### Alertes

- Budget à 80% → Email à l'admin
- Budget dépassé → Email + blocage des requêtes
- Taux d'erreur > 5% → Email d'alerte

---

## 🚀 Déploiement

### Checklist

- [ ] Appliquer la migration SQL
- [ ] Vérifier la configuration `.env.php` (ENCRYPTION_KEY)
- [ ] Créer les politiques IA par défaut pour chaque tenant
- [ ] Créer les budgets tenant initiaux
- [ ] Générer les UUID pour les élèves existants
- [ ] Tester les endpoints avec Postman/Insomnia
- [ ] Vérifier les logs d'audit
- [ ] Former les admins à l'interface

### Rollback

En cas de problème :

```sql
-- Rollback de la migration
DROP TABLE IF EXISTS audit_ia_log;
DROP TABLE IF EXISTS ia_budgets;
DROP TABLE IF EXISTS ia_policies;
DROP TABLE IF EXISTS rgpd_export_requests;

ALTER TABLE students
DROP COLUMN uuid_student,
DROP COLUMN uuid_social,
DROP COLUMN rgpd_status,
DROP COLUMN rgpd_pseudonymized_at,
DROP COLUMN rgpd_deleted_at,
DROP COLUMN rgpd_export_count;
```

---

## 📚 Documentation complémentaire

- [OpenAPI Sprint 15](./orchestrator/docs/openapi-sprint15-ia-rgpd.yaml)
- [Spécifications BMAD](./docs/sprint15-specs.json)
- [Guide RGPD](./docs/rgpd-guide.md)

---

## 🐛 Dépannage

### Problème : Budget non respecté

**Symptôme :** Les requêtes IA passent malgré un budget dépassé.

**Solution :**
1. Vérifier que `ia_audit_service.php` est bien inclus dans `ai.php`
2. Vérifier les logs : `tail -f /var/log/orchestrator/app.log`
3. Vérifier la table `ia_budgets` : tokens utilisés vs max

### Problème : Kill switch ne fonctionne pas

**Symptôme :** L'IA reste accessible malgré `ia_enabled = false`.

**Solution :**
1. Vérifier la politique : `SELECT * FROM ia_policies WHERE tenant_id = 'XXX'`
2. Vérifier que `ai.php` vérifie bien la politique avant de traiter
3. Vider le cache si applicable

### Problème : Export RGPD incomplet

**Symptôme :** Certaines données manquent dans l'export.

**Solution :**
1. Vérifier les tables concernées (missions, badges, social)
2. Vérifier les jointures SQL dans `admin_students.php`
3. Ajouter les tables manquantes si nécessaire

---

## 📞 Support

Pour toute question ou problème :
- Email : support@studymate.fr
- Slack : #sprint15-ia-rgpd
- Issues GitHub : https://github.com/studymate/orchestrator/issues

---

**Version :** 15.0.0
**Date :** 2025-11-14
**Auteur :** Équipe StudyMate
