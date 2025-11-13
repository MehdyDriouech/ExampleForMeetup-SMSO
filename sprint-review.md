# Sprint Review Checklist - StudyMate School Orchestrator

**Generated:** 2025-11-13
**Purpose:** Vérifier que tous les éléments de chaque sprint ont été développés et sont fonctionnels.

---

## SPRINT 0: Foundation & Bootstrapping
*Duration: 1 week | Goal: Socle cPanel-ready, diagnostics sans SSH, auth MIXED (UrlEncoded+JWT), BDD multi-tenant, OpenAPI unique.*

### Definition of Ready
- [ ] Accès cPanel & phpMyAdmin disponibles
- [ ] Domaine/SSL prêts
- [ ] `.env.php` paramétrable (variables listées)

### Definition of Done
- [ ] Scripts SQL importés (schema + seed) sans erreur
- [ ] OpenAPI unique valide (3.0.3) avec 2 securitySchemes
- [ ] `diag.html`/`diag.php` opérationnels (tous voyants au vert)
- [ ] Logs écrivent/rotent, purge 30j effective
- [ ] Endpoints S0 répondent avec codes attendus

### Environment Variables
- [ ] DB_HOST configuré
- [ ] DB_NAME configuré
- [ ] DB_USER configuré
- [ ] DB_PASS configuré
- [ ] AUTH_MODE configuré (URLENCODED|JWT|MIXED)
- [ ] JWT_SECRET configuré (min 32 chars)
- [ ] ADMIN_KEY configuré
- [ ] LOG_RETENTION_DAYS=30
- [ ] LOG_ROTATE_MAX_FILES=5
- [ ] LOG_ROTATE_MAX_SIZE=5242880

### Database Schema Constraints
- [ ] students: UNIQUE(tenant_id, student_ref)
- [ ] students: INDEX(tenant_id, class_id)
- [ ] assignments: INDEX(tenant_id, status)
- [ ] assignments: INDEX(tenant_id, due_at)
- [ ] assignment_targets: UNIQUE(tenant_id, assignment_id, student_id)
- [ ] assignment_targets: INDEX(tenant_id, status)
- [ ] stats: INDEX(tenant_id, student_id)
- [ ] stats: INDEX(tenant_id, created_at)
- [ ] sync_logs: INDEX(tenant_id, endpoint, status, created_at)
- [ ] sync_logs: INDEX(tenant_id, request_id)

### Logging Setup
- [ ] Log file at `/orchestrator/logs/app.log`
- [ ] Rotation max_size: 5242880 bytes
- [ ] Rotation max_files: 5
- [ ] Retention: 30 days
- [ ] Purge strategy implémentée

### Diagnostics
- [ ] `public/diag.html` présent
- [ ] `/orchestrator/diag.php` présent
- [ ] Protection par ADMIN_KEY (UrlEncoded)
- [ ] Check PHP version
- [ ] Check extensions: pdo_mysql, curl, mbstring, json
- [ ] Check filesystem: write perms `/orchestrator/logs/`, `/orchestrator/var/`
- [ ] Check DB: connect + SELECT 1 + latency
- [ ] Check routes: GET /api/health
- [ ] Check routes: GET /api/students
- [ ] Check routes: POST /api/sync/pull-stats
- [ ] Check routes: POST /api/assignments
- [ ] Export rapport TXT (sans secrets)

### Frontend Scaffold
- [ ] `public/index.html` créé
- [ ] `public/diag.html` créé
- [ ] `public/js/app.js` créé
- [ ] `public/js/view/view-dashboard.js` créé
- [ ] `public/js/features/feature-dashboard.js` créé
- [ ] `public/js/features/feature-sync.js` créé
- [ ] `public/js/features/feature-assignments.js` créé
- [ ] Chart.js (assets locaux)
- [ ] Palette ErgoMate appliquée

### OpenAPI Document
- [ ] Fichier `openapi-orchestrator.yaml` créé
- [ ] Format OAS 3.0.3
- [ ] SecuritySchemes: UrlEncodedKey + BearerJWT
- [ ] GET /api/health documenté
- [ ] GET /api/students documenté

### Endpoints S0
- [ ] GET /api/health → {status: 'ok'}
- [ ] GET /api/students → Student[] avec filtres
- [ ] POST /api/sync/pull-stats → {request_id, status}
- [ ] POST /api/assignments → {assignment_id}
- [ ] POST /api/webhooks/ergo/assignment-ack → {status}

---

## SPRINT 1: Authentification & OpenAPI (+ MVP mock)
*Duration: 1 week | Goal: Authentification hybride, OpenAPI v1.0, MVP demo.*

### Epic E1-AUTH: Authentification hybride UrlEncoded/JWT

#### US1_AUTH_URLENCODED: Auth principale via UrlEncoded
- [ ] AUTH_MODE=URLENCODED ou MIXED actif dans `core/config.php`
- [ ] Champs requis: api_key, tenant_id, scope validés
- [ ] 403 retourné si api_key invalide
- [ ] 403 retourné si scope non autorisé
- [ ] Task T1-1-CONFIG: Paramétrer AUTH_MODE et validation
- [ ] Task T1-1-MW: Middleware auth.php (UrlEncoded) implémenté
- [ ] Fichiers impactés: `orchestrator/core/config.php`, `orchestrator/api/_inc/auth.php`
- [ ] Error contract: JSON {code, message} sur 401/403

#### US1_AUTH_JWT_OPTIONAL: Support JWT optionnel (mode MIXED)
- [ ] AUTH_MODE=MIXED accepte UrlEncoded OU JWT
- [ ] Validation signature JWT
- [ ] Validation token expiry
- [ ] Fallback propre sur UrlEncoded si JWT inopérant
- [ ] Task T1-2-LIB: Lib JWT ajoutée (sans obligation serveur)
- [ ] Task T1-2-VERIFY: Vérification signature + exp implémentée
- [ ] JWT Header: `Authorization: Bearer <token>`
- [ ] JWT Claims min: sub, tenant_id, scope, exp

### Epic E1-OPENAPI: OpenAPI v1.0 initial

#### US1_OPENAPI_INIT: Contrat OpenAPI initial
- [ ] Fichier `docs/openapi-orchestrator.yaml` créé (OAS 3.0.3)
- [ ] Schemas fournis pour /health et /students
- [ ] Examples fournis
- [ ] Validé par un linter OpenAPI
- [ ] Task T1-3-YAML: openapi-orchestrator.yaml rédigé
- [ ] Task T1-3-LINT: Validation via linter OAS

#### US1_API_HEALTH: Endpoint /health
- [ ] GET /api/health retourne {status: 'ok'}
- [ ] Temps de réponse < 100ms
- [ ] Paramètre check=db optionnel
- [ ] Task T1-4-CTRL: Contrôleur health.php implémenté
- [ ] Task T1-4-DB: Ping DB optionnel ajouté
- [ ] Fichier: `orchestrator/api/health.php`

#### US1_API_STUDENTS: Endpoint /students (liste + filtres)
- [ ] GET /api/students retourne Student[]
- [ ] Filtrage par class_id
- [ ] Pagination par limit/offset
- [ ] tenant_id obligatoire
- [ ] Task T1-5-CTRL: Contrôleur students.php implémenté
- [ ] Task T1-5-DAO: Accès DB + pagination implémenté
- [ ] Fichiers: `orchestrator/api/students.php`, `orchestrator/api/_inc/db.php`

#### US1_LOGS_API: Journalisation des appels API
- [ ] Chaque appel écrit dans logs/app.log
- [ ] Format JSON Lines
- [ ] Champs: ts, endpoint, status, duration_ms, tenant_id
- [ ] Task T1-6-LOGMW: Middleware log implémenté
- [ ] Task T1-6-ROTATE: Rotation 5Mo x5 + purge 30j implémentée
- [ ] Fichiers: `orchestrator/api/_inc/log.php`, `logs/app.log`

### Epic E1-MVP: MVP mock (stats & dashboard)

#### US1_MVP_MOCK_STATS: Endpoint mock /api/stats/overview
- [ ] GET /api/stats/overview retourne JSON mock
- [ ] Temps de réponse < 200ms
- [ ] Accessible sans auth stricte ou scope DEMO
- [ ] Task T1-7-API: API stub overview.php implémenté
- [ ] Task T1-7-MOCK: `public/mock/stats.json` créé
- [ ] Fichiers: `app/api/stats/overview.php`, `app/public/mock/stats.json`

#### US1_MVP_DASHBOARD: Pseudo-dashboard mock
- [ ] Affichage lisible sans Chart.js
- [ ] Fallback texte implémenté
- [ ] Si Chart.js local présent → bar chart scores par classe
- [ ] Chargement < 2s
- [ ] Task T1-8-HTML: `mock-dashboard.html` créé
- [ ] Task T1-8-VIEW: `view-dashboard-mock.js` implémenté
- [ ] Task T1-8-FEAT: `feature-stats-mock.js` implémenté
- [ ] Fichiers: `app/public/mock-dashboard.html`, `app/public/js/view/view-dashboard-mock.js`, `app/public/js/features/feature-stats-mock.js`

---

## SPRINT 2: Collaboration & IA pédagogique
*Duration: 2 weeks | Goal: Notifications, affectations, IA générative contrôlée, sync Ergo-Mate.*

### Epic E2-ASSIGN: Affectations & notifications

#### US2-1-ASSIGN-CREATE: Créer une affectation et notifier
- [ ] POST /api/assignments retourne assignment_id
- [ ] Email ou bannière Ergo-Mate visible pour chaque élève
- [ ] Ergo-Mate affiche la mission dans 'Mes missions'
- [ ] Task T2-1-API: Endpoint POST /api/assignments implémenté
- [ ] Task T2-1-NOTIF: Service notifications (email + in-app) implémenté
- [ ] Task T2-1-EM: Webhook → Ergo-Mate /ergo/api/v1/assignments/push implémenté
- [ ] DB Change: TABLE assignments créée
- [ ] Fichiers: `orchestrator/api/assignments.php`, `orchestrator/services/notify.php`

### Epic E2-AI-CONTENT: Génération IA validée

#### US2-2-AI-THEME: Générer un thème via IA
- [ ] Upload texte/PDF → IA génère JSON
- [ ] Validation stricte contre `ergomate_theme.schema.json`
- [ ] Échec lisible si schéma invalide
- [ ] Task T2-2-UP: Upload endpoint /api/ai/theme-from-text implémenté
- [ ] Task T2-2-AI: Appel LLM + post-processing implémenté
- [ ] Task T2-2-VAL: Validation JSON Schema implémentée
- [ ] Fichiers: `orchestrator/api/ai.php`, `docs/schema/ergomate_theme.schema.json`

### Epic E2-ACK: Accusés & suivi

#### US2-3-ACK-TRACK: Tracer les accusés et l'état
- [ ] POST /ergo/api/v1/assignments/ack par élève
- [ ] PATCH /ergo/api/v1/assignments/:id/status (en_cours/terminee)
- [ ] Vue enseignant liste ack + status
- [ ] Task T2-3-ACK: Endpoint ack côté Orchestrator implémenté
- [ ] Task T2-3-ERGO: Client ergo → envoi ack/status implémenté
- [ ] Task T2-3-UI: UI de suivi (tableau) implémentée
- [ ] DB Change: TABLE assignment_events créée
- [ ] Fichiers: `orchestrator/api/assignments.php`, `ergomate/services/api.js`

---

## SPRINT 3: Multi-tenant & RBAC
*Duration: 2 weeks | Goal: Isolation entre établissements, rôles avec contrôle d'accès.*

### Epic E3-TENANT: Isolation des tenants

#### US3-1-ISOLATION: Isolation des classes par tenant
- [ ] Header X-Orchestrator-Id obligatoire
- [ ] Cross-tenant retourne 403
- [ ] Logs d'accès avec tenant_id
- [ ] Task T3-1-MW: Middleware tenancy implémenté
- [ ] Task T3-1-TEST: Tests d'intégration cross-tenant
- [ ] Task T3-1-LOG: Journalisation des refus implémentée
- [ ] DB Change: Colonne tenant_id ajoutée sur assignments, stats, reports
- [ ] Fichiers: `orchestrator/api/_middleware_tenant.php`

### Epic E3-RBAC: Rôles & permissions

#### US3-2-PERM: Enforcer RBAC sur endpoints
- [ ] prof: CRUD assignments au sein de son tenant
- [ ] inspecteur: lecture seule sur stats
- [ ] direction: accès dashboards agrégés
- [ ] Task T3-2-MW: Middleware RBAC implémenté (teacher, inspector, director)
- [ ] Task T3-2-DOC: Documentation scopes par endpoint
- [ ] Task T3-2-QA: Jeux de tests tokens factices
- [ ] Fichiers: `orchestrator/api/_middleware_auth.php`, `docs/openapi.yaml`

---

## SPRINT 4: Écosystème & Intégrations
*Duration: 2 weeks | Goal: Rate limiting, quotas, télémétrie API.*

### Epic E4-RATE: Rate limiting & quotas

#### US4-1-RATE: Limiter les appels partenaires
- [ ] 429 retourné quand quota dépassé
- [ ] Headers X-RateLimit-* présents
- [ ] Dashboard usage par clé implémenté
- [ ] Task T4-1-GW: Gateway rate limit implémenté
- [ ] Task T4-1-UI: UI usage par clé implémentée
- [ ] Task T4-1-DOC: Doc partenaires rédigée
- [ ] DB Change: TABLE api_keys créée
- [ ] Fichiers: `orchestrator/api/_gateway_rate.php`, `orchestrator/ui/partners_usage.js`

### Epic E4-TELEMETRY: Télémétrie API

#### US4-2-TELEM: Journaliser la télémétrie
- [ ] Événements api.request et api.response
- [ ] Corrélation par request_id
- [ ] Exports journaliers
- [ ] Task T4-2-MW: Middleware telemetry + request_id implémenté
- [ ] Task T4-2-EXP: Job export quotidien implémenté
- [ ] Task T4-2-DASH: Vue télémétrie implémentée
- [ ] Fichiers: `orchestrator/api/_middleware_telemetry.php`, `orchestrator/jobs/export_telemetry.py`

---

## SPRINT 5: Learning Cycle — Pédagogie côté élève
*Duration: 2 weeks | Goal: Missions, exécution, sync, progression, badges.*

### Epic E5-MISSIONS: Missions reçues

#### US5-1-INBOX: Afficher et lancer mes missions
- [ ] Liste missions pull depuis Orchestrator
- [ ] Statut local: a_faire/en_cours/terminee
- [ ] Démarrage module selon type
- [ ] Task T5-1-UI: UI liste missions implémentée
- [ ] Task T5-1-API: GET /api/assignments/pull implémenté
- [ ] Task T5-1-STATE: Persistance statut local implémentée
- [ ] Fichiers: `ergomate/view-dashboard.js`, `ergomate/features-dashboard.js`

### Epic E5-SYNC: Sync auto

#### US5-2-PUSH: Pousser mes résultats automatiquement
- [ ] POST /ergo/api/v1/stats/push après session
- [ ] Retry/backoff si échec
- [ ] Journalisation locale
- [ ] Task T5-2-HOOK: Hook fin de session implémenté
- [ ] Task T5-2-API: Client push + retry implémenté
- [ ] Task T5-2-LOG: Trace locale implémentée
- [ ] Fichiers: `ergomate/features-quiz.js`, `ergomate/services/api.js`

---

## SPRINT 6: Learning Analytics — Measure & Analyze
*Duration: 2 weeks | Goal: KPIs prof/direction, heatmap, rapports IA, alertes, exports.*

### Epic E6-DASH: Dashboard Profs

#### US6-1: KPIs classe
- [ ] GET /api/analytics/kpis implémenté
- [ ] Filtres classe/thème/période
- [ ] Persistance filtres
- [ ] Latence < 1s sur 10k lignes agrégées
- [ ] Task T6-1-API: GET /api/analytics/kpis implémenté
- [ ] Task T6-1-UI: Cartes KPI + filtres implémentées
- [ ] Task T6-1-CACHE: Cache 5 min implémenté
- [ ] Fichiers: `orchestrator/api/analytics.php`, `orchestrator/ui/dashboard_prof.js`

### Epic E6-HEAT: Heatmap difficultés

#### US6-2: Heatmap
- [ ] GET /api/analytics/heatmap implémenté
- [ ] Tooltip taux d'échec/tentatives
- [ ] Lien créer révision
- [ ] Task T6-2-API: GET /api/analytics/heatmap implémenté
- [ ] Task T6-2-UI: Composant heatmap implémenté
- [ ] Task T6-2-CTA: CTA planifier implémenté
- [ ] Fichiers: `orchestrator/api/analytics.php`, `orchestrator/ui/dashboard_prof.js`

---

## SPRINT 7: Adaptive Learning — Personnalisation
*Duration: 2 weeks | Goal: Recommandations IA, difficulté adaptative, mode focus, validation, fatigue.*

### Epic E7-RECO: Reco IA

#### US7-1: Suggestions IA côté élève
- [ ] Widget 'Pour toi' implémenté
- [ ] 3 suggestions pertinentes
- [ ] Explicabilité des suggestions
- [ ] Feedback 'Pas pertinent'
- [ ] Task T7-1-ENGINE: features-reco.js implémenté
- [ ] Task T7-1-UI: Widget dashboard élève implémenté
- [ ] Task T7-1-API: /api/reco implémenté
- [ ] Fichiers: `ergomate/view-dashboard.js`, `ergomate/features-reco.js`, `orchestrator/api/reco.php`

### Epic E7-DIFF: Difficulté adaptative

#### US7-2: Niveau adaptatif
- [ ] easy/normal/expert implémenté
- [ ] Badge niveau visible
- [ ] Task T7-2-LOGIC: Moteur règles difficulty_level implémenté
- [ ] Task T7-2-UI: Badge niveau quiz implémenté
- [ ] Fichiers: `ergomate/features-quiz.js`

---

## SPRINT 8: Social & Collaborative Learning
*Duration: 2 weeks | Goal: Classements, partages, commentaires, révision collective, modération.*

### Epic E8-LB: Classements

#### US8-1: Classement par thème
- [ ] GET /api/social/leaderboard implémenté
- [ ] Hebdo + all-time
- [ ] Filtre par thème
- [ ] Anonymisation ON/OFF
- [ ] Task T8-1-API: GET /api/social/leaderboard implémenté
- [ ] Task T8-1-UI: Widget classement implémenté
- [ ] Task T8-1-PRIV: Paramètre anonymisation implémenté
- [ ] Fichiers: `orchestrator/api/social.php`, `ergomate/view-dashboard.js`

### Epic E8-COLLAB: Révision collective temps réel

#### US8-4: Révision collective temps réel
- [ ] Code session
- [ ] Chrono + questions communes
- [ ] Score collectif
- [ ] Task T8-4-RT: WebSocket canal session implémenté
- [ ] Task T8-4-UI: Écran session groupée implémenté
- [ ] Task T8-4-SCORE: Calcul score collectif implémenté
- [ ] Fichiers: `orchestrator/realtime/*`, `ergomate/features-quiz.js`

---

## SPRINT 9: Institutional & Data Hub
*Duration: 2 weeks | Goal: Dashboard direction, comparatifs, RGPD, API académique, benchmarks IA.*

### Epic E9-API: API académique

#### US9-4: Exports académiques standardisés
- [ ] Endpoints lecture seule implémentés
- [ ] Rate limit/Quotas appliqués
- [ ] Docs OpenAPI rédigée
- [ ] Task T9-4-API: Routes /api/hub/academic/* implémentées
- [ ] Task T9-4-RATE: Rate limit par clé implémenté
- [ ] Task T9-4-DOC: Doc + exemples rédigée
- [ ] Fichiers: `orchestrator/api/academic.php`, `docs/openapi.yaml`

---

## SPRINT 10: Teacher-AI Copilot
*Duration: 2 weeks | Goal: Générer quiz/fiches, insights, coach pédagogique, publication.*

### Epic E10-QGEN: Génération de quiz

#### US10-1: Générer un quiz depuis PDF
- [ ] Upload + OCR si besoin
- [ ] Validation JSON Schema Ergo-Mate
- [ ] Édition avant publication
- [ ] Task T10-1-UP: Upload /api/ai/ingest implémenté
- [ ] Task T10-1-AI: Pipeline prompts + post-process implémenté
- [ ] Task T10-1-VAL: Validator schéma implémenté
- [ ] Fichiers: `orchestrator/api/ai.php`, `orchestrator/ui/ai_creator.js`

### Epic E10-PUBLISH: Publication Ergo-Mate

#### US10-5: Publier vers Ergo-Mate
- [ ] Choisir catalogue ou affectation
- [ ] Ack de réception
- [ ] Audit trail
- [ ] Task T10-5-API: POST /ergo/api/v1/publish implémenté
- [ ] Task T10-5-ACK: Webhook ack implémenté
- [ ] Task T10-5-LOG: Journal publication implémenté
- [ ] Fichiers: `orchestrator/api/publish.php`, `ergomate/importer/`, `ergomate/assignments/`

---

## SPRINT 11: Content Creation Suite
*Duration: 2 weeks | Goal: Éditeur manuel, prévisualisation, IA d'amélioration, bibliothèque personnelle, co-édition, export/import.*

### Epic E11-EDITOR: Éditeur manuel WYSIWYG

#### US11-1-EDITOR-UI: Créer et éditer un thème manuellement
- [ ] Édition question par question
- [ ] Support flashcards (recto/verso)
- [ ] Support fiches de révision (Markdown limité)
- [ ] Validation JSON instantanée
- [ ] Enregistrement auto
- [ ] Annulation / Rétablissement
- [ ] Task T11-1-UI: UI WYSIWYG theme_editor.js implémenté
- [ ] Task T11-1-SCHEMA: Binding live au schema implémenté
- [ ] Task T11-1-STORAGE: Persistance locale + cloud implémentée
- [ ] Fichiers: `orchestrator/ui/theme_editor.js`, `orchestrator/api/themes.php`

### Epic E11-PREVIEW: Prévisualisation & tests

#### US11-2-PREVIEW-TEST: Tester un thème avant publication
- [ ] Mode 'Test élève' identique à ErgoMate
- [ ] Évaluation IA du niveau (easy/normal/expert)
- [ ] Alertes: questions trop longues / ambiguës
- [ ] Simulation: quiz → flashcards → fiche
- [ ] Détection erreurs de schema
- [ ] Task T11-2-VIEW: Simulator view implémenté
- [ ] Task T11-2-ENGINE: Engine difficulty analyser implémenté
- [ ] Task T11-2-ALERT: Linter pédagogique (IA) implémenté
- [ ] Fichiers: `orchestrator/ui/view-theme-tester.js`, `orchestrator/services/theme_linter.js`

### Epic E11-IA-REWRITE: IA d'amélioration locale

#### US11-3-LOCAL-IA-IMPROVE: Améliorer une partie via IA
- [ ] Sélection d'un élément → bouton 'Améliorer via IA'
- [ ] Retour IA respecte intégralement ergomate_theme.schema.json
- [ ] Choix: 'Simplifier', 'Compliquer', 'Rendre plus concis'
- [ ] Historique des améliorations
- [ ] Task T11-3-API: POST /api/ai/improve-element implémenté
- [ ] Task T11-3-UI: UI inline actions IA implémentée
- [ ] Task T11-3-LOG: Journal améliorations implémenté
- [ ] Fichiers: `orchestrator/api/ai.php`, `orchestrator/ui/theme_editor.js`

### Epic E11-LIB: Bibliothèque personnelle

#### US11-4-LIBRARY: Bibliothèque personnelle de thèmes
- [ ] Recherche par titre/tags
- [ ] Dossiers personnalisés
- [ ] Détection doublons (hash JSON)
- [ ] Versions (v1, v2, v3...)
- [ ] Actions: ouvrir / dupliquer / archiver
- [ ] Task T11-4-UI: UI library library_view.js implémentée
- [ ] Task T11-4-API: /api/themes/list implémenté
- [ ] Task T11-4-VERSION: Service versioning implémenté
- [ ] Fichiers: `orchestrator/api/themes.php`, `orchestrator/ui/library_view.js`

### Epic E11-WORKFLOW: Workflow multi-enseignants

#### US11-5-COLLAB: Co-édition & commentaires
- [ ] Commentaires ancrés sur éléments
- [ ] Mode suggestion (Google Docs style)
- [ ] États: brouillon → proposé → validé → publié
- [ ] Notifications des modifications
- [ ] Task T11-5-RT: Canal realtime WebSocket implémenté
- [ ] Task T11-5-UI: UI commentaires & suggestions implémentée
- [ ] Task T11-5-WF: Workflow manager implémenté
- [ ] Fichiers: `orchestrator/realtime/`, `orchestrator/ui/theme_editor.js`

### Epic E11-EXPORT: Export & import avancés

#### US11-6-EXPORT-IMPORT: Exporter ou importer des thèmes
- [ ] Export JSON / PDF / CSV
- [ ] Export QTI (Moodle/LMS)
- [ ] Import Quizlet, Kahoot, CSV, QTI
- [ ] Validation automatique après import
- [ ] Task T11-6-EXP: Module export implémenté
- [ ] Task T11-6-IMP: Module import + conversion implémenté
- [ ] Task T11-6-VAL: Validation post-import implémentée
- [ ] Fichiers: `orchestrator/api/export.php`, `orchestrator/api/import.php`

---

## SPRINT 12: Internal Pedagogical Library
*Duration: 2 weeks | Goal: Catalogue interne validé et versionné.*

### Epic E12-CATALOG: Catalogue interne

#### US12-1-LIST: Lister les thèmes du catalogue
- [ ] Recherche par titre, tags, matière, niveau
- [ ] Visible uniquement dans le tenant
- [ ] Affichage version actuelle + auteur + date
- [ ] Statut: validé, proposé, archivé
- [ ] Task T12-1-API: GET /api/catalog/list implémenté
- [ ] Task T12-1-UI: UI catalogue catalog_view.js implémentée
- [ ] Fichiers: `orchestrator/api/catalog.php`, `orchestrator/ui/catalog_view.js`

#### US12-2-DETAIL: Consulter un thème
- [ ] Affichage complet (quiz/flashcards/fiches)
- [ ] Mode lecture seule
- [ ] Historique des versions visible
- [ ] Task T12-2-API: GET /api/catalog/:id implémenté
- [ ] Task T12-2-UI: UI theme viewer implémentée
- [ ] Fichiers: `orchestrator/api/catalog.php`, `orchestrator/ui/theme_viewer.js`

### Epic E12-VALIDATION: Workflow de validation

#### US12-3-SUBMIT: Proposer un thème à validation
- [ ] Statut passe à 'proposé'
- [ ] Notification envoyée au référent
- [ ] Historique note l'action
- [ ] Task T12-3-API: POST /api/catalog/submit implémenté
- [ ] Task T12-3-WF: Workflow implémenté
- [ ] Fichiers: `orchestrator/api/catalog.php`, `orchestrator/services/workflow_manager.php`

#### US12-4-VALIDATE: Valider ou rejeter un thème
- [ ] Statuts: validé / rejeté
- [ ] Commentaire obligatoire en cas de rejet
- [ ] Historique enregistré
- [ ] Notification automatique
- [ ] Task T12-4-API: PATCH /api/catalog/validate implémenté
- [ ] Task T12-4-UI: UI rôle référent implémentée
- [ ] Fichiers: `orchestrator/api/catalog.php`, `orchestrator/ui/catalog_validation.js`

### Epic E12-VERSION: Versioning structuré

#### US12-5-VERSIONING: Gérer les versions
- [ ] Liste des versions v1, v2, v3…
- [ ] Rollback possible
- [ ] Diff minimal (ajouts / suppressions clés)
- [ ] Task T12-5-SVC: Service versioning implémenté
- [ ] Task T12-5-DB: stockage versionné implémenté
- [ ] Fichiers: `orchestrator/services/versioner.php`

### Epic E12-ROLES: Rôles & permissions

#### US12-6-RBAC-CATALOG: Droits d'accès catalogue
- [ ] Enseignant: consulter, proposer
- [ ] Référent: valider, commenter
- [ ] Direction: publier, archiver
- [ ] Respect tenant isolation
- [ ] Task T12-6-MW: Middleware catalog_rbac implémenté
- [ ] Fichiers: `orchestrator/api/_middleware_rbac.php`

### Epic E12-INTEGRATION: Intégration Ergo-Mate

#### US12-7-ERGO: Utiliser un thème du catalogue
- [ ] Sélection depuis le catalogue
- [ ] Affectation via /assignments
- [ ] Ergo-Mate reçoit le thème (vérifié)
- [ ] Task T12-7-API: POST /api/catalog/publish-to-ergo implémenté
- [ ] Task T12-7-ERGO: Webhook vers /ergo/api/v1/themes/push implémenté
- [ ] Fichiers: `orchestrator/api/catalog.php`, `ergomate/importer/themes.php`

---

## SPRINT 13: Stability, Automation & UX Excellence
*Duration: 2 weeks | Goal: Renforcer qualité, UX, IA fiabilité, auto-hébergement.*

### Epic E13-UX: UX Excellence

#### US13-1-ONBOARDING: Onboarding enseignant
- [ ] Walkthrough interactif (création thème + validation + affectation)
- [ ] Message de bienvenue contextualisé
- [ ] Explications intégrées sur les statuts des thèmes
- [ ] Task T13-1-UI: Module onboarding UI implémenté
- [ ] Task T13-1-STATE: Flag user_first_login implémenté
- [ ] Fichiers: `orchestrator/ui/onboarding.js`, `ergomate/app.js`

### Epic E13-AI: IA Reliability

#### US13-2-CONFIDENCE: IA Confidence Report
- [ ] Score IA par question
- [ ] Highlight des zones à risque
- [ ] Résumé des doutes IA
- [ ] Task T13-2-AI: Module IA confidence scoring implémenté
- [ ] Task T13-2-UI: UI affichage risques implémentée
- [ ] Fichiers: `orchestrator/services/ai_quality.js`, `orchestrator/ui/theme_viewer.js`

#### US13-3-FACTCHECK: Détection d'incohérences
- [ ] Vérification heuristique offline
- [ ] Alerte sur longueurs inhabituelles
- [ ] Signalement distracteurs trop proches
- [ ] Task T13-3-LOGIC: Heuristics implémentées
- [ ] Task T13-3-UI: UI warnings qualité implémentée
- [ ] Fichiers: `orchestrator/services/theme_linter.js`, `orchestrator/ui/theme_editor.js`

### Epic E13-AUTO: Automatisation & Maintenance

#### US13-4-BACKUP: Backups automatiques
- [ ] Backup hebdomadaire
- [ ] Archive DB + catalogue + configs
- [ ] Téléchargement manuel possible
- [ ] Task T13-4-JOB: Cron backup job implémenté
- [ ] Task T13-4-ZIP: Archive zip implémenté
- [ ] Fichiers: `orchestrator/jobs/backup.php`, `orchestrator/admin/settings.js`

#### US13-5-DIAG: Diagnostic système
- [ ] Check DB
- [ ] Check API
- [ ] Check LLM latency
- [ ] Check quotas
- [ ] Task T13-5-API: GET /api/system/diagnostic implémenté
- [ ] Task T13-5-UI: Diagnostic dashboard implémenté
- [ ] Fichiers: `orchestrator/api/system.php`, `orchestrator/ui/diagnostic.js`

### Epic E13-INTEROP: Interopérabilité

#### US13-6-QTI: Export QTI
- [ ] Génération QTI 2.2
- [ ] Validation offline
- [ ] Téléchargement zip
- [ ] Task T13-6-CONV: Converter JSON -> QTI implémenté
- [ ] Task T13-6-API: GET /api/export/qti implémenté
- [ ] Fichiers: `orchestrator/api/export.php`, `orchestrator/services/converters/qti_converter.php`

---

## Summary & Status

**Total Sprints:** 14 (Sprint 0-13)
**Total Epics:** ~50+
**Total User Stories:** ~100+
**Total Tasks:** ~200+

### Recommended Order of Verification:
1. **Sprint 0** - Foundation (Critical: DB, Auth, OpenAPI)
2. **Sprint 1** - Auth & API Endpoints (Critical: security, contracts)
3. **Sprint 2-3** - Core Features (Multi-tenant, RBAC)
4. **Sprint 4-10** - Features & Analytics
5. **Sprint 11-12** - Content Suite & Library
6. **Sprint 13** - Quality & UX

### Notes:
- Mark each item with `[x]` once verified as developed and tested
- Document any blockers or partial implementations in comments
- Update the date in the header when reviewing
- Use git branches for tracking implementation progress

---

**Last Updated:** 2025-11-13
**Reviewed By:** [Your Name]
**Status:** In Progress
