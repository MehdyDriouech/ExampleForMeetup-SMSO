# Sprint 20 - Tenant Onboarding Complet

**Version:** 1.0.0
**Date:** 2025-11-15
**Statut:** ✅ Implémenté

---

## 📋 Vue d'ensemble

Le Sprint 20 implémente un **flux complet d'onboarding** pour les établissements scolaires, incluant :
- Création de tenant avec templates prédéfinis
- Configuration de l'administrateur initial
- Import massif CSV (élèves, classes, profs)
- Configuration SMTP, branding, quotas IA
- Wizard interactif guidé étape par étape

---

## 🎯 Objectifs atteints

### Epic E20-TENANT : Création Tenant
- ✅ API POST `/api/admin/onboarding/tenant` - Création tenant
- ✅ Génération automatique tenant_id
- ✅ Templates prédéfinis (Collège, Lycée, Université)
- ✅ Quotas initiaux et politique IA par défaut
- ✅ Création automatique des licences

### Epic E20-IMPORT : Import Élèves & Classes
- ✅ API POST `/api/admin/import/upload` - Upload CSV
- ✅ API POST `/api/admin/import/validate/:id` - Validation ligne par ligne
- ✅ API POST `/api/admin/import/execute/:id` - Exécution import
- ✅ API GET `/api/admin/import/template/:type` - Templates CSV
- ✅ Génération UUID élèves automatique
- ✅ Gestion des erreurs et doublons
- ✅ Rapport détaillé d'import

### Epic E20-CONFIG : Configuration Établissement
- ✅ API PATCH `/api/admin/tenant/config` - Configuration générale
- ✅ API POST `/api/admin/tenant/logo` - Upload logo
- ✅ API PATCH `/api/admin/tenant/smtp` - Config SMTP avec test
- ✅ API PATCH `/api/admin/tenant/branding` - Couleurs personnalisées
- ✅ API PATCH `/api/admin/tenant/ia-quota` - Quotas IA
- ✅ API PATCH `/api/admin/tenant/ia-policy` - Politique IA

### Epic E20-TOUR : Onboarding Guidé
- ✅ Wizard JavaScript interactif 8 étapes
- ✅ Barre de progression visuelle
- ✅ Validation à chaque étape
- ✅ Étapes optionnelles (SMTP, Branding)
- ✅ Page `/onboarding.html` dédiée
- ✅ Redirection vers dashboard post-onboarding

---

## 🗂️ Architecture

### Base de données

**Tables créées** (`020_sprint20_tenant_onboarding.sql`) :

```sql
-- Extension de la table tenants
ALTER TABLE tenants ADD COLUMN logo VARCHAR(500);
ALTER TABLE tenants ADD COLUMN branding JSON;
ALTER TABLE tenants ADD COLUMN smtp_config JSON;
ALTER TABLE tenants ADD COLUMN ia_policy JSON;
ALTER TABLE tenants ADD COLUMN quota_ia JSON;
ALTER TABLE tenants ADD COLUMN onboarding_completed BOOLEAN DEFAULT FALSE;

-- Nouvelles tables
CREATE TABLE onboarding_progress (...)     -- Suivi étape par étape
CREATE TABLE import_jobs (...)              -- Jobs d'import CSV
CREATE TABLE onboarding_templates (...)     -- Templates de configuration
CREATE TABLE tenant_onboarding_invites (...) -- Invitations équipe

-- Vues et triggers
CREATE VIEW v_onboarding_dashboard;
CREATE TRIGGER trg_tenant_init_onboarding;
CREATE FUNCTION fn_check_onboarding_complete();
```

**Templates prédéfinis** :
- `template_college_standard` - Collège (400 élèves, 30 profs)
- `template_lycee_standard` - Lycée (800 élèves, 60 profs)
- `template_universite` - Université (2000 étudiants, 100 profs)

### Services backend

| Service | Description | Fichier |
|---------|-------------|---------|
| `OnboardingService` | Orchestration du flux d'onboarding | `services/OnboardingService.php` |
| `CSVImportService` | Gestion imports CSV avec validation | `services/CSVImportService.php` |
| `TenantConfigService` | Configuration tenant (SMTP, logo, quotas) | `services/TenantConfigService.php` |

### API Endpoints

#### Onboarding (`/api/admin/onboarding.php`)

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| POST | `/tenant` | Créer un tenant |
| POST | `/admin-user` | Créer admin initial |
| GET | `/progress/:id` | Obtenir le progrès |
| PATCH | `/step` | Mettre à jour une étape |
| POST | `/complete` | Finaliser l'onboarding |
| GET | `/templates` | Lister les templates |
| POST | `/invite` | Inviter un utilisateur |

#### Import CSV (`/api/admin/import.php`)

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| POST | `/upload` | Upload fichier CSV |
| POST | `/validate/:id` | Valider le CSV |
| POST | `/execute/:id` | Exécuter l'import |
| GET | `/status/:id` | Statut du job |
| GET | `/template/:type` | Télécharger template |
| GET | `/jobs` | Lister les jobs |

#### Configuration Tenant (`/api/admin/tenant.php`)

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/config` | Obtenir la config |
| PATCH | `/config` | Mettre à jour config |
| POST | `/logo` | Upload logo |
| PATCH | `/smtp` | Configurer SMTP |
| POST | `/smtp/test` | Tester SMTP |
| PATCH | `/branding` | Configurer branding |
| PATCH | `/ia-quota` | Configurer quotas IA |
| PATCH | `/ia-policy` | Configurer politique IA |

### Interface utilisateur

| Fichier | Description |
|---------|-------------|
| `/public/onboarding.html` | Page d'onboarding |
| `/orchestrator/ui/onboarding_wizard.js` | Wizard JavaScript |

**Étapes du wizard** :
1. Welcome & Template selection
2. Tenant information
3. Admin user creation
4. Import structure (CSV or manual)
5. SMTP configuration (optional)
6. Branding & logo (optional)
7. IA quotas & policy
8. Complete & launch

### Templates CSV

| Fichier | Colonnes requises | Colonnes optionnelles |
|---------|-------------------|---------------------|
| `template_students.csv` | firstname, lastname, email_scolaire, class_name, promo_name | uuid_scolaire, consent_sharing |
| `template_teachers.csv` | firstname, lastname, email, role | class_names |
| `template_classes.csv` | name, promo_name | description, teacher_email |
| `template_promotions.csv` | name, year_start, year_end | level |

---

## 🚀 Guide d'utilisation

### Scénario 1 : Onboarding complet via wizard

```javascript
// 1. Accéder à /onboarding.html
// 2. Sélectionner un template (ex: Collège Standard)
// 3. Remplir les informations établissement
// 4. Créer le compte admin
// 5. Importer la structure via CSV ou passer cette étape
// 6. Configurer SMTP (optionnel)
// 7. Personnaliser le branding (optionnel)
// 8. Configurer les quotas IA
// 9. Finaliser et accéder au dashboard
```

### Scénario 2 : Import CSV massif

#### Étape 1 : Télécharger les templates

```bash
curl -O http://localhost/api/admin/import/template/promotions
curl -O http://localhost/api/admin/import/template/classes
curl -O http://localhost/api/admin/import/template/teachers
curl -O http://localhost/api/admin/import/template/students
```

#### Étape 2 : Remplir les templates

Exemple `promotions.csv` :
```csv
name,year_start,year_end,level
2024-2025,2024,2025,6eme
2024-2025,2024,2025,5eme
```

#### Étape 3 : Upload et import

```bash
# Upload
curl -X POST http://localhost/api/admin/import/upload \
  -F "file=@promotions.csv" \
  -F "import_type=promotions" \
  -H "X-Tenant-Id: tenant_xxx" \
  -H "Authorization: Bearer <token>"

# Réponse : { "job_id": "import_abc123" }

# Validation
curl -X POST http://localhost/api/admin/import/validate/import_abc123 \
  -H "X-Tenant-Id: tenant_xxx" \
  -H "Authorization: Bearer <token>"

# Exécution
curl -X POST http://localhost/api/admin/import/execute/import_abc123 \
  -H "X-Tenant-Id: tenant_xxx" \
  -H "Authorization: Bearer <token>"

# Vérifier le statut
curl http://localhost/api/admin/import/status/import_abc123 \
  -H "X-Tenant-Id: tenant_xxx" \
  -H "Authorization: Bearer <token>"
```

### Scénario 3 : Configuration post-onboarding

#### Mettre à jour le branding

```bash
# Upload logo
curl -X POST http://localhost/api/admin/tenant/logo \
  -F "logo=@logo.png" \
  -H "X-Tenant-Id: tenant_xxx" \
  -H "Authorization: Bearer <token>"

# Configurer les couleurs
curl -X PATCH http://localhost/api/admin/tenant/branding \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Id: tenant_xxx" \
  -H "Authorization: Bearer <token>" \
  -d '{
    "primary_color": "#3B82F6",
    "secondary_color": "#10B981",
    "accent_color": "#F59E0B"
  }'
```

#### Configurer SMTP

```bash
# Tester la connexion SMTP
curl -X POST http://localhost/api/admin/tenant/smtp/test \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Id: tenant_xxx" \
  -H "Authorization: Bearer <token>" \
  -d '{
    "host": "smtp.gmail.com",
    "port": 587,
    "encryption": "tls",
    "from_email": "noreply@ecole.fr",
    "username": "user@gmail.com",
    "password": "password"
  }'

# Sauvegarder la config
curl -X PATCH http://localhost/api/admin/tenant/smtp \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Id: tenant_xxx" \
  -H "Authorization: Bearer <token>" \
  -d '{
    "host": "smtp.gmail.com",
    "port": 587,
    "encryption": "tls",
    "from_email": "noreply@ecole.fr",
    "from_name": "Mon École",
    "username": "user@gmail.com",
    "password": "password",
    "test_connection": true
  }'
```

#### Configurer les quotas IA

```bash
curl -X PATCH http://localhost/api/admin/tenant/ia-quota \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Id: tenant_xxx" \
  -H "Authorization: Bearer <token>" \
  -d '{
    "monthly_quota": 2000,
    "warning_threshold": 85
  }'
```

---

## 🔒 Sécurité

### Routes publiques (sans authentification)
- `POST /api/admin/onboarding/tenant` - Création initiale tenant
- `POST /api/admin/onboarding/admin-user` - Création admin initial
- `GET /api/admin/onboarding/templates` - Liste templates

### Routes protégées (authentification requise)
- Toutes les autres routes nécessitent un JWT valide
- Isolation tenant stricte via middleware
- RBAC avec permission `tenant:update` pour configuration
- Permission `imports:create` pour les imports CSV

### Validation des données
- Validation des emails (format et unicité)
- Vérification des quotas avant import
- Sanitization des inputs utilisateur
- Limite taille fichiers CSV : 10 MB
- Types MIME autorisés pour logos : PNG, JPEG, SVG

---

## 📊 Métriques et observabilité

### Audit logging

Toutes les actions d'onboarding sont loggées dans `audit_log` :
- `action_type: onboarding:start`
- `action_type: onboarding:complete_step`
- `action_type: import:csv`
- `action_type: tenant:configure`

### Dashboard onboarding

Vue `v_onboarding_dashboard` pour suivre :
- Nombre de tenants créés
- Taux de complétion d'onboarding
- Imports réussis/échoués
- Quotas utilisés vs max

---

## 🧪 Tests

### Tests manuels recommandés

1. **Onboarding complet** :
   - Créer un tenant avec chaque template
   - Vérifier que les quotas sont corrects
   - Créer l'admin et se connecter
   - Vérifier l'isolation tenant

2. **Import CSV** :
   - Upload avec erreurs → vérifier rapport validation
   - Upload valide → vérifier import complet
   - Vérifier incrémentation quotas licences
   - Vérifier gestion des doublons

3. **Configuration** :
   - Upload logo invalide (taille, type) → erreur
   - Config SMTP invalide → erreur
   - Test SMTP avec serveur inaccessible → erreur
   - Couleurs branding invalides → erreur

### Tests d'intégration

```bash
# Test création tenant + admin
curl -X POST http://localhost/api/admin/onboarding/tenant \
  -H "Content-Type: application/json" \
  -d '{"name": "Test School", "type": "public", "template_id": "template_college_standard"}'

# Récupérer tenant_id et créer admin
curl -X POST http://localhost/api/admin/onboarding/admin-user \
  -H "Content-Type: application/json" \
  -d '{"tenant_id": "<tenant_id>", "email": "admin@test.fr", "firstname": "Admin", "lastname": "Test", "password": "password123"}'

# Se connecter et obtenir JWT
curl -X POST http://localhost/api/auth \
  -H "Content-Type: application/json" \
  -d '{"email": "admin@test.fr", "password": "password123"}'

# Utiliser le JWT pour les opérations suivantes
```

---

## 📦 Fichiers modifiés/créés

### Migrations SQL
- `orchestrator/sql/migrations/020_sprint20_tenant_onboarding.sql`

### Services
- `orchestrator/services/OnboardingService.php`
- `orchestrator/services/CSVImportService.php`
- `orchestrator/services/TenantConfigService.php`

### API Endpoints
- `orchestrator/api/admin/onboarding.php`
- `orchestrator/api/admin/import.php`
- `orchestrator/api/admin/tenant.php`

### Frontend
- `public/onboarding.html`
- `orchestrator/ui/onboarding_wizard.js`

### Templates CSV
- `orchestrator/templates/csv/template_students.csv`
- `orchestrator/templates/csv/template_teachers.csv`
- `orchestrator/templates/csv/template_classes.csv`
- `orchestrator/templates/csv/template_promotions.csv`
- `orchestrator/templates/csv/README.md`

### Documentation
- `docs/SPRINT_20_TENANT_ONBOARDING.md`

---

## 🐛 Limitations connues

1. **Import CSV** :
   - Pas de support pour les caractères spéciaux dans les noms de fichiers
   - Limite 10 000 lignes par fichier (performance)
   - Pas de reprise en cas d'échec partiel (rollback complet)

2. **SMTP** :
   - Test de connexion basique (socket seulement)
   - Pas de validation complète de l'authentification
   - Recommandé : tester avec un vrai email après config

3. **Wizard** :
   - Pas de sauvegarde intermédiaire (si fermeture navigateur)
   - Pas de mode "brouillon" pour reprendre plus tard

---

## 🔮 Évolutions futures

### Sprint 20.1 (Améliorations)
- [ ] Import CSV asynchrone avec websockets pour suivi temps réel
- [ ] Templates CSV custom par tenant
- [ ] Validation avancée SMTP (envoi email de test)
- [ ] Sauvegarde auto du wizard (localStorage)
- [ ] Reprise d'import après échec partiel

### Sprint 20.2 (Fonctionnalités avancées)
- [ ] Import depuis Google Classroom / Pronote
- [ ] Export données tenant (conformité RGPD)
- [ ] Multi-langue pour le wizard
- [ ] Tour guidé interactif post-onboarding
- [ ] Dashboard analytique d'onboarding

---

## 📞 Support

Pour toute question ou problème :
- **Documentation** : `/docs/SPRINT_20_TENANT_ONBOARDING.md`
- **Templates CSV** : `/orchestrator/templates/csv/README.md`
- **GitHub Issues** : https://github.com/MehdyDriouech/StudyMate-SchoolOrchestrator/issues

---

## ✅ Checklist de déploiement

Avant de déployer en production :

- [ ] Exécuter la migration SQL `020_sprint20_tenant_onboarding.sql`
- [ ] Vérifier que les répertoires uploads existent et sont writables :
  - `/orchestrator/uploads/csv`
  - `/orchestrator/uploads/logos`
- [ ] Configurer les variables d'environnement :
  - `APP_URL` - URL de l'application
- [ ] Tester le workflow complet d'onboarding
- [ ] Vérifier les permissions RBAC pour les rôles
- [ ] Configurer les limites de taille fichiers dans php.ini :
  - `upload_max_filesize = 10M`
  - `post_max_size = 10M`
- [ ] Activer HTTPS pour les uploads de fichiers sensibles
- [ ] Configurer les backups automatiques de la DB

---

**Sprint 20 - TERMINÉ** ✅
Prêt pour merge et déploiement.
