# 🎓 StudyMate School Orchestrator

> Plateforme institutionnelle qui synchronise **ErgoMate** et les équipes pédagogiques pour piloter, créer et qualifier les contenus d'apprentissage.

- **Version produit** : `Alpha-01`
- **Dernière mise à jour** : 2025-11-14
- **Auteur / Mainteneur** : Mehdy Driouech – DAWP Engineering  

---

## 🧭 1. Vue d’ensemble

**StudyMate School Orchestrator** est le cœur **administratif**, **pédagogique** et **IA** qui se connecte à **ErgoMate** (app étudiants) pour :

- Centraliser la **donnée scolaire** (tenants, classes, élèves, enseignants, licences).
- Orchestrer la **création**, la **validation** et la **publication** des contenus (quiz, flashcards, fiches, bibliothèques internes).
- Offrir des **dashboards de pilotage** à la direction, aux référents et aux inspecteurs.
- Assurer une **gouvernance IA** (politiques, budgets, audit) et la **conformité RGPD**.
- Synchroniser **ErgoMate** (assignments, analytics, social) et piloter les exports académiques.

### 1.1 Objectifs produit

- 📊 **Piloter** la réussite, la charge et les risques des élèves via des analytics actionnables.
- 🧑‍🏫 **Accompagner** le corps enseignant avec un copilot IA et des workflows sécurisés.
- 🪄 **Industrialiser** la création de contenus pédagogiques validés et versionnés.
- 🔄 **Synchroniser** la donnée ErgoMate (classes, étudiants, analytics, missions).
- 🛡️ **Garantir** la sécurité, le RBAC multi-tenant, la traçabilité, la gouvernance IA et la conformité RGPD.

### 1.2 Public cible

- Directions d’établissement & responsables pédagogiques (**multi-tenant**).
- Enseignants, référents pédagogiques, inspecteurs académiques.
- Équipes Ops / IT en charge du déploiement, de l’exploitation et de la sécurité.

---

## 🧱 2. Architecture & Stack

### 2.1 Stack technique

- **Backend** : PHP ≥ 8.0  
  - Extensions : `pdo`, `pdo_mysql`, `json`, `mbstring`.
- **Base de données** : MySQL ≥ 5.7 ou MariaDB ≥ 10.3  
  - Schéma de base dans `orchestrator/sql/schema.sql`.
- **Front-end** : HTML/CSS/JS vanilla  
  - SPA enseignants + vues admin dans `public/` et `orchestrator/ui/`.
- **Intégrations** :
  - API REST JSON (`orchestrator/api/*.php`)
  - Webhooks ErgoMate (`realtime/`, `api/ingest.php`, `api/publish.php`)
  - Moteurs IA (Mistral par défaut, BYOK possible via Sprint 15)

### 2.2 Arborescence principale

```text
.
├── orchestrator/
│   ├── api/                    # Endpoints REST (auth, élèves, analytics, IA, admin, etc.)
│   │   ├── _middleware_*.php   # Rate limit, tenant, RBAC, télémétrie
│   │   ├── analytics/          # Heatmaps, teacher KPI, risques élèves
│   │   ├── telemetry/          # Collecte temps réel & webhooks
│   │   ├── ingest.php          # Upload/extraction PDF/audio
│   │   ├── insights.php        # Insights de classe
│   │   ├── coach.php           # Coach IA enseignant
│   │   ├── publish.php         # Publication vers ErgoMate
│   │   ├── catalog.php         # Catalogue pédagogique interne (Sprint 12)
│   │   ├── admin/              # Admin users/classes/licences/roles/audit/students
│   │   └── ...
│   ├── lib/                    # Services transverses (DB, logger, auth, IA, content_extractor, ia_audit...)
│   ├── services/               # Domain services (Thèmes, Workflow, Versioning, Audit, Mailing...)
│   ├── jobs/                   # CRON (backup, export, synchro)
│   ├── realtime/               # Bridge évènementiel (webhooks ErgoMate, SSE)
│   ├── sql/                    # Schéma, seeds, migrations sprint
│   ├── migrations/             # Migrations additionnelles (ex: 015_sprint15_...)
│   ├── tests/                  # Scripts QA/Smoke & tests d’intégration
│   └── ui/                     # Modules front (AI creator, catalogue, admin users, IA view, dashboards...)
├── public/                     # SPA enseignants + assets
├── docs/                       # Architecture, OpenAPI, README Sprints, schémas JSON, RGPD
└── INSTALLATION.md             # Procédure d’installation détaillée
```

### 2.3 Patterns clés

- PHP **sans framework** avec middlewares dédiés :
  - `_middleware_rbac.php`, `_middleware_tenant.php`, `_middleware_telemetry.php`.
- **Services métiers** injectés manuellement (ex : `ThemeService`, `VersionService`, `AuditLogService`, `IAAuditService`).
- Configuration centralisée dans `orchestrator/.env.php` (ou variables d’environnement).
- SPA + modules JS :
  - `public/js/*` (enseignants)
  - `orchestrator/ui/*.js` (AI creator, catalogue, admin, IA view, dashboards).

---

## ✨ 3. Périmètre fonctionnel global

### 3.1 Domaines principaux

| Domaine                         | Capacités principales                                                                                                                              | Localisation principale                                 |
|---------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------|
| **Orchestrateur pédagogique**   | Multi-tenant, RBAC, gestion élèves/enseignants, classes, affectations, dashboards direction/enseignants.                                           | `orchestrator/api/`, `orchestrator/ui/`, `sql/`        |
| **Création & validation IA**    | Upload PDF/audio, extraction, génération IA (quiz, flashcards, fiches), validation ErgoMate schema, publication catalogue/assignments.             | `api/ingest.php`, `api/coach.php`, `lib/ai_service.php`|
| **Bibliothèque pédagogique**    | Catalogue interne, versioning, workflow de validation, publication vers ErgoMate.                                                                  | `api/catalog.php`, `services/WorkflowManager.php`      |
| **Admin & tenants**             | Gestion users, rôles, classes, licences, audit log, matrice de permissions.                                                                        | `api/admin/*`, `services/audit_log.php`                |
| **Gouvernance IA & RGPD**       | Politiques IA, budgets, audit IA, RGPD élèves (UUID, export, pseudonymisation, suppression).                                                       | `migrations/015_*`, `api/admin/students.php`, IA APIs  |
| **Analytics & qualité**         | Teacher KPI, risk analytics élèves, quality feed, inspector view.                                                                                  | `api/analytics/*`, `api/feed/quality.php`              |
| **Mode Démo**                   | Mode démonstration sans backend (FakeRouter, mock JSON, parcours guidé, DEMO_MODE).                                                                | `public/js/demo/*`, `orchestrator/config.php`          |
| **Social & collaboratif**       | Leaderboards, sessions synchro, suivi communautaire (sprints précédents).                                                                          | `api/social.php`, `docs/SPRINT8_SOCIAL_README.md`      |
| **Ops & intégrations**          | Backups, diagnostics, exports QTI/ENT/LMS, API partenaires, télémétrie, webhooks ErgoMate.                                                         | `jobs/`, `api/system.php`, `api/export.php`, `realtime/`|

---

## 🗃️ 4. Modèle de données & migrations

### 4.1 Schéma de base

- **Fichier principal** : `orchestrator/sql/schema.sql`  
  Inclut les tables cœur : `tenants`, `users`, `students`, `classes`, `promotions`, `themes`, `assignments`, `stats`, `sync_logs`, `mistral_queue`, `api_keys`, etc.

- **Seeds de démo** :  
  `orchestrator/sql/seeds.sql` (ex : compte enseignant de test, classes, thèmes).

### 4.2 Extensions par sprint

- **Sprint 10 – AI Copilot**
  - Tables : `ai_coach_sessions`, `ai_coach_messages`, `class_insights`, `ergomate_publications`, `ai_content_extractions`.
  - Vues : `v_class_difficulty_insights`, `v_teacher_publications`.
  - Fichier : `orchestrator/sql/sprint10_ai_copilot.sql`.

- **Sprint 12 – Bibliothèque pédagogique**
  - Tables : `catalog_entries`, `catalog_versions`, `catalog_workflow_history`, `catalog_assignments`, `catalog_collaborators` (future), `notifications`.
  - Côté ErgoMate : `themes`, `theme_assignments`, `theme_questions`, `theme_flashcards`, `theme_fiches`.
  - Fichier : `orchestrator/migrations/sprint12_catalog.sql`.

- **Sprint 14 – Admin & tenants**
  - Extensions `users` : `deactivated_at`, `deactivated_by`, nouveaux rôles (`inspector`, `referent`).
  - Tables : `user_class_assignments`, `roles_matrix`, `tenant_licences`, `audit_log`.
  - Schéma intégré dans `orchestrator/sql/schema.sql` + doc `SPRINT_14_README.md`.

- **Sprint 15 – IA & RGPD**
  - Extensions `students` : `uuid_student`, `uuid_social`, `rgpd_status`, `rgpd_pseudonymized_at`, `rgpd_deleted_at`, `rgpd_export_count`.
  - Tables : `ia_policies`, `ia_budgets`, `audit_ia_log`, `rgpd_export_requests`.
  - Fichier : `migrations/015_sprint15_ia_governance_students_rgpd.sql`.

- **Sprint 16 – Teacher & Risk analytics**
  - Tables : `teacher_kpi`, `risk_student`, `quality_feed`, `class_risk_aggregate`.
  - Fichier : `orchestrator/sql/migrations/SPRINT16_teacher_quality_analytics.sql`.

- **Sprint 17 – Mode Démo**
  - Pas d’impact DB : tout est mock côté front (JSON + FakeRouter).
  - Docs : `docs/SPRINT_17_DEMO_MODE.md`, `CHANGELOG_SPRINT_17.md`.

---

## ⚙️ 5. Configuration (orchestrator/.env.php)

Principales constantes :

- **Base de données**
  - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`.
- **Auth & sécurité**
  - `AUTH_MODE` (ex : `MIXED`), `JWT_SECRET`, `JWT_EXPIRY_SECONDS`.
  - `$GLOBALS['API_KEYS']` (teacher/admin/director/inspector).
  - `ADMIN_KEY` (clé d’admin out-of-band).
- **Logs & observabilité**
  - `LOG_FILE`, `LOG_LEVEL`, rotation (`LOG_ROTATE_*`), dossier `logs/`.
- **Cache & anti-abus**
  - `CACHE_DIR`, `CACHE_DEFAULT_TTL`, `RATE_LIMIT_MAX_REQUESTS`, `RATE_LIMIT_ENABLED`.
- **CORS**
  - `CORS_ALLOWED_ORIGINS`, `CORS_ALLOWED_HEADERS`, `CORS_MAX_AGE`.
- **Intégration ErgoMate**
  - `ERGO_MATE_WEBHOOK_URL`, `ERGO_MATE_API_KEY`, `ERGOMATE_API_URL`, `MOCK_MODE`.
- **Uploads**
  - `UPLOADS_DIR`, `UPLOADS_MAX_SIZE`, `UPLOADS_ALLOWED_TYPES`.
- **IA & BYOK**
  - `MISTRAL_API_ENDPOINT`, `MISTRAL_DEFAULT_MODEL`, `MISTRAL_TIMEOUT`.
  - `ENCRYPTION_KEY` pour chiffrer les clés BYOK (Sprint 15).
- **Runtime**
  - `APP_ENV`, `APP_DEBUG`, hooks d’erreurs personnalisés.
- **Mode Démo**
  - `DEMO_MODE` dans `orchestrator/config.php` (exposé via `/orchestrator/api/config.php`).

> 🔐 Génération de clés :  
> `php -r "echo bin2hex(random_bytes(32));"` pour `JWT_SECRET` / `ADMIN_KEY`.

---

## 🚀 6. Installation & démarrage

### 6.1 Prérequis système

- PHP ≥ 8.0 + extensions mentionnées.
- MySQL/MariaDB opérationnel.
- Accès FTP/SFTP ou Git sur le serveur web.
- Pour l’extraction :
  - PDF : `poppler-utils` (`pdftotext`), `tesseract-ocr`, `tesseract-ocr-fra`.
  - Audio : `ffmpeg` + clé API Whisper/OpenAI (optionnel).

### 6.2 Installation rapide

1. **Déploiement fichiers**
   - Placer `public/` comme racine web.
   - Placer `orchestrator/` hors racine publique si possible (ou sous-projet séparé).
2. **Base de données**
   ```bash
   mysql -u <user> -p <db> < orchestrator/sql/schema.sql
   mysql -u <user> -p <db> < orchestrator/sql/seeds.sql

   # Migrations sprints majeurs
   mysql -u <user> -p <db> < orchestrator/sql/sprint10_ai_copilot.sql
   mysql -u <user> -p <db> < orchestrator/migrations/sprint12_catalog.sql
   mysql -u <user> -p <db> < migrations/015_sprint15_ia_governance_students_rgpd.sql
   mysql -u <user> -p <db> < orchestrator/sql/migrations/SPRINT16_teacher_quality_analytics.sql
   ```
3. **Configurer `.env.php`** (DB, JWT, API keys, ERGOMATE_URL, ENCRYPTION_KEY, DEMO_MODE, etc.).
4. **Vérifier les permissions** : `orchestrator/uploads`, `logs/`.
5. **Tester en local**
   ```bash
   # APIs
   php -S 0.0.0.0:8080 -t orchestrator/api

   # SPA front
   php -S 0.0.0.0:8081 -t public

   curl http://localhost:8080/health.php
   # macOS : open http://localhost:8081/
   ```

> Pour l’installation FTP/shared hosting : suivre `INSTALLATION.md` (checklist, .htaccess, correctifs).

---

## 🔌 7. APIs majeures

### 7.1 Socle & santé

- `GET /api/health.php`  
  Health check simple + `?check=db` / `?check=full` pour diagnostics.
- Auth : `POST /api/auth.php`  
  JWT + API keys, compatible form-urlencoded.

### 7.2 Pédagogie & IA (Sprint 10)

- **Ingest API** (`orchestrator/api/ingest.php`)
  - `POST /api/ingest/upload` : upload PDF/audio + extraction.
  - `POST /api/ingest/generate` : génération IA (thème, quiz, fiches) à partir d’une extraction.
  - `GET /api/ingest/extractions` / `GET /api/ingest/extractions/{id}`.

- **Insights API** (`insights.php`)
  - `GET /api/insights/class/{classId}`
  - `GET /api/insights/difficulties?class_id=...&limit=...`
  - `POST /api/insights/mark-read`, `DELETE /api/insights/{id}`.

- **Coach API** (`coach.php`)
  - `POST /api/coach/session/start`
  - `POST /api/coach/session/{id}/message`
  - `GET /api/coach/session/{id}`, `GET /api/coach/sessions`
  - `POST /api/coach/suggestions`

- **Publish API** (`publish.php`)
  - `POST /api/publish/theme` (catalogue / assignment + target_classes[])
  - `POST /api/publish/acknowledge`
  - `GET /api/publish/publications` / `{id}`

### 7.3 Catalogue pédagogique (Sprint 12)

- `orchestrator/api/catalog.php` :
  - `GET /api/catalog/list`, `GET /api/catalog/{id}`
  - `POST /api/catalog/submit` (proposition)
  - `PATCH /api/catalog/validate` (valider/rejeter)
  - `POST /api/catalog/publish` (catalogue interne)
  - `DELETE /api/catalog/{id}/archive`
  - `GET /api/catalog/{id}/versions` + rollback
  - `POST /api/catalog/publish-to-ergo` (push vers ErgoMate)
  - `GET /api/catalog/stats`

### 7.4 Admin & tenants (Sprint 14)

- `orchestrator/api/admin/users.php`
  - `GET /api/admin/users`, `POST /api/admin/users`
  - `GET /api/admin/users/:id`, `PATCH /api/admin/users/:id`
  - `PATCH /api/admin/users/:id/status` (activation/désactivation)

- `orchestrator/api/admin/classes.php`
  - `GET /api/admin/classes`, `POST /api/admin/classes`
  - `GET /api/admin/classes/:id`, `PATCH /api/admin/classes/:id`, `DELETE /api/admin/classes/:id` (archivage logique)

- `orchestrator/api/admin/roles.php`
  - `GET /api/admin/roles`, `PUT /api/admin/roles` (matrice de permissions).

- `orchestrator/api/admin/licences.php`
  - `GET /api/admin/licences`, `PUT /api/admin/licences`.

- `orchestrator/api/admin/audit.php`
  - `GET /api/admin/audit` (audit log filtrable / paginé).

### 7.5 IA Governance & RGPD (Sprint 15)

- **Élèves & RGPD** (`admin/students`)
  - `POST /api/admin/students` (création + UUID).
  - `GET /api/admin/students/{uuid}/export` (export RGPD complet).
  - `PATCH /api/admin/students/{uuid}/pseudonymize` (pseudonymisation irréversible).
  - `DELETE /api/admin/students/{uuid}` (suppression logique RGPD).

- **Politiques IA**
  - `GET /api/admin/ia-policy`
  - `PUT /api/admin/ia-policy` (kill switch, BYOK, modèles, conservation logs).

- **Budgets IA**
  - `GET /api/admin/ia-budgets` (tenant, teacher, usage).
  - `POST /api/admin/ia-budgets` (création budgets).
  - `GET /api/admin/ia-budgets/usage`.

- **Audit IA**
  - `GET /api/admin/ia-audit` (logs d’interactions IA + stats).

### 7.6 Analytics & qualité (Sprint 16)

- **Teacher KPI**  
  `GET /api/analytics/teacher-kpi` (global ou par `teacher_id`, export PDF possible).

- **Student Risk**
  - `GET /api/analytics/risk` (heatmap classes, élèves à risque, recommandations).
  - `POST /api/analytics/risk` (mise à jour du statut de risque).

- **Quality Feed**
  - `GET /api/feed/quality` (issues IA/élèves, filtres, severities, pagination).
  - `POST /api/feed/quality` (création d’issue).
  - `PATCH /api/feed/quality` (mise à jour statut, résolution, assignation).

---

## 🧠 8. IA, qualité & performance

### 8.1 AI Copilot (Sprint 10)

- Extraction :
  - PDF : `pdftotext` → fallback `Tesseract OCR`.
  - Audio : Whisper API.
- Génération IA :
  - Modèles Mistral (par défaut), création de thèmes complets :
    - quiz (15+ questions), flashcards, fiches de révision.
  - Validation stricte contre `docs/schema/ergomate_theme.schema.json`.

### 8.2 IA Governance & budgets (Sprint 15)

- **ia_policies** : kill switch, BYOK (`api_provider`, `api_key` chiffrée), modèles autorisés.
- **ia_budgets** : limites tokens & requêtes par tenant/enseignant, alertes.
- **audit_ia_log** : prompts, réponses, modèle, tokens, latence, statut.

### 8.3 Analytics enseignants & risques (Sprint 16)

- **Teacher KPI** : engagement, complétion missions, qualité des thèmes, performance élèves.
- **Risk Analytics** : score de risque par élève, heatmap par classe, recommandations d’actions.
- **Quality Feed** : incohérences IA, retours élèves, problèmes de structure ou contenu.

---

## 🔒 9. Sécurité, RBAC & RGPD

### 9.1 RBAC (extraits)

- Rôles : `admin`, `direction`, `teacher`, `inspector`, `referent`, `intervenant`.
- Matrice stockée dans `roles_matrix` + logique dans `_middleware_rbac.php`.

Exemples :

- Admin/Direction :
  - CRUD utilisateurs, classes, licences, politiques IA, budgets.
- Référent :
  - Validation de thèmes, feed qualité, risk updates.
- Enseignant :
  - Création de contenus, soumission catalogue, usage AI copilot, consultation de ses KPI.
- Inspecteur :
  - Accès **lecture seule** aux analytics, KPIs, heatmaps (niveaux agrégés + anonymisation élèves).

### 9.2 RGPD

- Séparation des identités élèves :
  - `uuid_student` (pédagogique) vs `uuid_social` (suivi social anonymisé).
- Export RGPD complet via `GET /api/admin/students/{uuid}/export`.
- Pseudonymisation irréversible (`PATCH .../pseudonymize`) → données personnelles remplacées.
- Suppression logique + journaux d’export dans `rgpd_export_requests`.

---

## ✅ 10. Tests & QA

- Smoke test complet :  
  `php orchestrator/tests/smoke_test_qa01.php`
- Tests gestion d’erreurs :  
  `orchestrator/tests/qa08_error_handling_test.php`
- Tests d’intégration Sprint 15 :
  - `Sprint15IAPolicyTest.php`
  - `Sprint15StudentRGPDTest.php`
  - `Sprint15BudgetsTest.php`
- Recommandations :
  - Postman/Newman pour API.
  - Cypress/Jest pour UI.
  - OWASP Top 10 pour sécurité.

---

## 🗺️ 11. Roadmap & sprints livrés

- ✅ **Sprint 10 – Teacher-AI Copilot**  
  Extraction PDF/audio, génération IA, Coach enseignant, insights classes, publication ErgoMate.
- ✅ **Sprint 12 – Bibliothèque pédagogique interne**  
  Catalogue interne, workflow validation, versioning, intégration ErgoMate.
- ✅ **Sprint 14 – Admin & Tenant Management**  
  Admin users/classes/licences, audit log, matrice de rôles, quotas.
- ✅ **Sprint 15 – IA Governance & RGPD**  
  UUID élèves, politiques IA, budgets, audit IA, RGPD export/pseudonymisation/suppression.
- ✅ **Sprint 16 – Teacher Quality & Student Risk Analytics**  
  Teacher KPI dashboard, student risk analytics, quality feed, inspector view.
- ✅ **Sprint 17 – Mode Démo Global**  
  Mode démo sans backend, données mock complètes, parcours guidé, DEMO_MODE.

> Détail complet par sprint : `SPRINT_10_README.md`, `SPRINT_12_README.md`, `SPRINT_14_README.md`, `SPRINT_15_README.md`, `SPRINT_16_README.md`, `SPRINT_17_DEMO_MODE.md`.

---

## 📚 12. Documentation & ressources

- **OpenAPI global** : `orchestrator/docs/openapi-orchestrator.yaml`
- **OpenAPI sprints** :
  - `openapi-sprint10-paths.yaml` (AI Copilot)
  - `openapi-sprint12-catalog.yaml` (Catalogue)
  - `openapi-sprint14-admin.yaml` (Admin)
  - `openapi-sprint15-ia-rgpd.yaml` (IA & RGPD)
  - `openapi-sprint16-analytics.yaml` (Analytics)
- **Schémas JSON** :
  - `docs/schema/ergomate_theme.schema.json`
- **Guides** :
  - `SPRINT10_ARCHITECTURE_OVERVIEW.md`
  - `SPRINT13_ARCHITECTURE_OVERVIEW.md`
  - `docs/RBAC_SECURITY_GUIDE.md`
  - `docs/rgpd-guide.md`


---

### 🆕 Nouvelles fonctionnalités


# Demo Mode

### Composants ajoutés

- **Paramètre `DEMO_MODE=true|false`**
  - Défini dans `orchestrator/config.php`
  - Exposé au front via `/orchestrator/api/config.php`
  - `public/index.html` : ajout bandeau + bouton démo + scripts
  - `public/js/app.js` : fonctions `startDemoMode()`, `exitDemoMode()`, `isDemoMode()`

- **UI dédiée mode démo**
  - Bandeau sticky : **« Mode Démo – Données fictives »**
  - Bouton **« Découvrir la démo »** sur la page de login
  - Divider “OU” entre login réel et démo
  - Styles CSS dédiés : `public/assets/demo-styles.css`
  - Loader spécifique au mode démo

### Écrans simulés

- Dashboard enseignant  
- Liste élèves (par classe)  
- Missions / Affectations  
- Synchronisation ErgoMate  
- Analytics (KPI, risques)  
- Catalogue interne  
- Qualité (issues)  
- IA Governance

---


### Critères d’acceptation (tous validés)

- `DEMO_MODE` pilotable en config
- Bouton affiché/masqué selon `DEMO_MODE`
- Aucune requête API réelle en mode démo
- Tous les écrans principaux fonctionnent avec les JSON mock
- Bandeau démo **toujours visible** en haut de l’écran
- Sortie du mode démo → retour au flux normal + nettoyage `localStorage`

---

## 📦 Fichiers créés

| Fichier | Description |
|--------|-------------|
| `orchestrator/config.php` | Configuration globale incluant `DEMO_MODE` |
| `orchestrator/api/config.php` | Endpoint API exposant la config (dont DEMO_MODE) |
| `public/js/demo/FakeRouter.js` | Intercepteur d'appels API côté front |
| `public/js/demo/demo_tour.js` | Parcours guidé interactif du mode démo |
| `public/js/demo/mock/*.json` | 10 fichiers de données mock |
| `public/assets/demo-styles.css` | Styles spécifiques au mode démo |
| `docs/SPRINT_17_DEMO_MODE.md` | Documentation complète du sprint |
| `CHANGELOG_SPRINT_17.md` | Journal détaillé du sprint 17 |

---

## 🔧 Fichiers modifiés

| Fichier | Modifications |
|---------|---------------|
| `public/index.html` | Ajout du bandeau démo, du bouton démo et des scripts associés |
| `public/js/app.js` | Gestion du mode démo, initialisation et logout |

---

## 🎨 Adaptations

### Orchestrator

- Ajout de `DEMO_MODE` dans la config globale
- FakeRouter utilisé uniquement côté front
- JSON mock cohérents avec les structures API existantes


---

## 🧪 Tests

### Tests manuels

- Activation du mode démo depuis la page de login
- Affichage du bandeau orange « Mode Démo »
- Dashboard avec données mock
- Navigation entre tous les écrans simulés
- Sélection d’une classe → affichage des élèves
- Affectations affichées correctement
- Parcours guidé complet (7 étapes)
- Quitter la démo → réinitialisation et retour à la version standard

### Tests de régression

- Mode normal (DEMO_MODE=false) inchangé
- Pas d’impact sur l’API réelle
- `localStorage` correctement nettoyé à la déconnexion 

---

## 🔒 Sécurité

- Mode démo **désactivable** via config
- Aucune donnée réelle exposée
- `FakeRouter` n’intercepte que les appels locaux de la SPA
- Données mock anonymes et fictives
- ⚠️ Recommandation : garder `DEMO_MODE=false` en prod par défaut et l’activer uniquement pour des instances de démonstration contrôlées

---

## 📝 Notes techniques

### LocalStorage utilisé

```javascript
DEMO_SESSION = 'true'        // Indique le mode démo actif
authToken = 'demo-token-...' // Token factice
currentUser = {...}          // Utilisateur démo
DEMO_TOUR_COMPLETED = 'true' // Parcours terminé
```

### Architecture d’interception

```text
Frontend (public/js/app.js)
    ↓
FakeRouter.js (interception appels API)
    ↓
mock/*.json (données fictives)
```

### Endpoints interceptés (exemples)

- `/api/config` → config.json
- `/api/auth/login` → login factice
- `/api/dashboard/summary` → `dashboard.json`
- `/api/students` → `students.json`
- `/api/classes` → `classes.json`
- `/api/assignments` → `assignments.json`
- `/api/analytics/teacher_kpi` → `teacher_kpi.json`
- `/api/analytics/risk` → `student_risk.json`
- `/api/themes` → `themes.json`
- `/api/catalog` → `catalog.json`
- `/api/quality` → `quality.json`

---

## 🐛 Problèmes connus

- Aucun problème connu à ce stade pour la V1 du mode démo.

---

## 🚀 Évolutions futures

- [ ] Mode démo pour ErgoMate (côté élève)
- [ ] Personnalisation des données mock par tenant
- [ ] Mode "sandbox" avec sauvegarde temporaire des actions utilisateur
- [ ] Analytics sur l'usage du mode démo (conversion démo → prod)
- [ ] Traduction multilingue (FR/EN/ES) du parcours guidé

---

## ✅ Validation

**Critères de complétion du sprint** :

- ✅ Paramètre `DEMO_MODE` fonctionnel  
- ✅ FakeRouter intercepte tous les appels ciblés  
- ✅ 10+ fichiers mock avec données crédibles  
- ✅ Bouton « Découvrir la démo » visible et fonctionnel  
- ✅ Bandeau mode démo affiché  
- ✅ Parcours guidé complet (7 étapes)  
- ✅ Tous les écrans principaux testés et fonctionnels  
- ✅ Documentation complète (`SPRINT_17_DEMO_MODE.md`, `CHANGELOG_SPRINT_17.md`)  

**Sprint 17 : ✅ COMPLÉTÉ** 🎉

---

## 🤝 14. Support & contact

- **Produit / Tech** : Mehdy Driouech – DAWP Engineering  
- **Email** : `contact@dawp-engineering.com`  
- **Site** : https://dawp-engineering.com/  
- **Issues GitHub** : `https://github.com/MehdyDriouech/StudyMate-SchoolOrchestrator/issues`

---

## 📄 15. Licence

- **Code** : Licence **AGPL v3.0**  
- **Copyright** : © 2025 – Mehdy Driouech / StudyMate
