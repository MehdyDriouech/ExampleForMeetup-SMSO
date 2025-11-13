# 🎓 Study-mate School Orchestrator

> Plateforme institutionnelle qui synchronise ErgoMate et les équipes pédagogiques pour piloter, créer et qualifier les contenus d'apprentissage.

**Version** : 1.0.0  
**Dernière mise à jour** : 2025-11-13  
**Auteur** : Mehdy Driouech

---

## 📋 Vue d'ensemble

**StudyMate School Orchestrator** est le cœur administratif et pédagogique connecté à **ErgoMate**. Il centralise la donnée scolaire, orchestre la diffusion des activités générées (manuel ou IA), et expose une suite d'outils pour la direction, les enseignants et les référents qualité.

### Objectifs produit
- 📊 **Piloter** la réussite et la charge des étudiants avec des indicateurs actionnables.
- 🧑‍🏫 **Accompagner** le corps enseignant grâce à des workflows sécurisés et assistés par l'IA.
- 🪄 **Industrialiser** la création de contenus (quiz, flashcards, fiches) et leur validation multi-acteurs.
- 🔄 **Synchroniser** la donnée ErgoMate (classes, étudiants, analytics) et automatiser les exports académiques.
- 🛡️ **Garantir** la conformité (RBAC, traçabilité, sécurité des échanges et des fichiers).

### Public cible
- Directions d'établissement et responsables pédagogiques multi-tenant.
- Enseignants, inspecteurs, référents qualité ou innovation.
- Équipes Ops / IT en charge du déploiement et de l'exploitation.

---

## ✨ Périmètre fonctionnel

| Domaine | Capacités principales | Localisation code |
| --- | --- | --- |
| **Orchestrateur pédagogique** | Multi-tenant, RBAC, gestion élèves/enseignants, affectations, dashboards enseignants/direction. | `orchestrator/api/` (`students.php`, `assignments.php`, `analytics/`, `user.php`), `orchestrator/ui/`, `public/` |
| **Suite de création & validation** | Génération IA Mistral, import/export (Quizlet, Kahoot, QTI), versioning, linter pédagogique, workflows de publication. | `orchestrator/services/ThemeService.php`, `ThemeLinterService.php`, `WorkflowManager.php`, `services/converters/`, `api/themes.php`, `api/publish.php`, `api/preview.php`, UI `theme_editor.js`, `ai_creator.js` |
| **Analytics & IA** | KPIs, heatmaps, alertes, copilote enseignant, recommandations adaptatives, feedback qualité IA. | `api/analytics/`, `api/insights.php`, `api/coach.php`, `api/reco.php`, `api/improve.php`, `services/ai_quality.php`, `lib/ai_service.php` |
| **Social & collaboratif** | Leaderboards, sessions de révision synchrones, suivi communautaire. | `api/social.php`, `api/student/`, `docs/SPRINT8_SOCIAL_README.md` |
| **Ops & intégrations** | Backups, diagnostics, export QTI/ENT/LMS, API partenaires, télémétrie temps réel, webhooks ErgoMate. | `jobs/`, `api/system.php`, `api/export.php`, `api/partners/`, `api/telemetry/`, `realtime/`, `sql/` |

---

## 🧱 Architecture technique

### Stack recommandée
- **Backend** : PHP 8.0+ (extensions `pdo`, `pdo_mysql`, `json`, `mbstring`).
- **Base de données** : MySQL 5.7+ ou MariaDB 10.3+ (`orchestrator/sql/schema.sql`).
- **Front-end** : UI statique (HTML/CSS/JS vanilla) servie depuis `public/` et `orchestrator/ui/`.
- **Intégrations** : API REST JSON, webhooks ErgoMate, moteur IA Mistral (HTTP).

### Arborescence principale
```
.
├── orchestrator/
│   ├── api/                    # Endpoints REST (auth, élèves, analytics, IA...)
│   │   ├── _middleware_*.php   # Rate limit, tenant, RBAC, télémétrie
│   │   ├── analytics/          # Heatmaps, indicateurs, rapports
│   │   ├── telemetry/          # Collecte temps réel & webhooks
│   │   └── ...
│   ├── lib/                    # Services transverses (auth, DB, logger, IA...)
│   ├── services/               # Domain services (Thèmes, Workflow, Qualité IA)
│   ├── jobs/                   # Scripts CRON (backup, export, synchro)
│   ├── realtime/               # Bridge évènementiel (webhooks ErgoMate)
│   ├── sql/                    # Schéma, seeds et migrations sprint
│   ├── tests/                  # Scripts QA/Smoke & rapports
│   └── ui/                     # Modules front riches (éditeur, catalogue, diagnostic)
├── public/                     # SPA enseignante + assets
├── docs/                       # Notes de sprint, architecture, schémas JSON
├── ergomate/                   # Outils d'import ErgoMate et mocks
├── migrations/                 # SQL complémentaire (Sprint 11)
└── INSTALLATION.md             # Procédure pas-à-pas (FTP + shared hosting)
```

### Patterns clés
- PHP sans framework avec middlewares dédiés (rate limiting, RBAC, tenant) par fichier `_middleware_*.php`.
- Services métiers injectés manuellement (ex. `ThemeService`, `VersionService`).
- Configuration centralisée dans `orchestrator/.env.php` (constantes + bootstrap utilitaires).
- Front dynamique géré par modules JS `orchestrator/ui/*.js` et `public/js/*` pour la SPA enseignante.

---

## 🔌 APIs & services

### Endpoints majeurs
- `/api/health.php` : health check + diagnostics DB.
- `/api/auth.php` : authentification (JWT + API key URL-encoded).
- `/api/students.php`, `/api/assignments.php`, `/api/user.php` : gestion des entités pédagogiques et du personnel.
- `/api/analytics/*.php` : indicateurs multi-dimensionnels (progression, risques, heatmaps).
- `/api/ai.php`, `/api/improve.php`, `/api/coach.php` : interactions Mistral (génération, co-pilotage, feedback).
- `/api/themes.php`, `/api/publish.php`, `/api/preview.php` : création, validation, publication catalogue.
- `/api/telemetry/` : ingestion logs (webhooks, instrumentation front) + export temps réel.
- `/api/system.php` : diagnostics infrastructure (PHP, permissions, filesystems).

Chaque endpoint charge `orchestrator/.env.php`, ce qui initialise : connexion DB (`lib/db.php`), logger (`lib/logger.php`), auth (`lib/auth.php`), configuration CORS et gestion d'erreurs centralisée.

### Services transverses
- `lib/ai_service.php` : client Mistral + post-traitement (`services/ai_quality.php`, `services/theme_linter.php`).
- `services/WorkflowManager.php` : étapes de validation (enseignant → référent → direction → publication).
- `services/converters/` : import/export (Quizlet, Kahoot, QTI v2.2) + script `api/export.php`.
- `jobs/` : `backup.php`, `export_telemetry.py` (cron, export CSV/JSON des métriques).
- `realtime/` : orchestrateur d'évènements (webhooks, SSE prototypes).

---

## 🗃️ Modèle de données
- Schéma complet : `orchestrator/sql/schema.sql` (tenants, users, students, classes, promotions, themes, assignments, stats, sync_logs, mistral_queue, api_keys...).
- Seeds de démo : `orchestrator/sql/seeds.sql` (compte enseignant `claire.dubois@ife-paris.fr / Ergo2025!`).
- Migrations incrémentales : `orchestrator/sql/migrations/` & `migrations/011_sprint11_content_creation_suite.sql`.
- Schéma JSON ErgoMate : `docs/schema/ergomate_theme.schema.json` (validation contenus IA).

---

## 🚀 Installation rapide
1. **Préparer l'environnement** : PHP 8.0+, MySQL 5.7+, extensions `pdo_mysql`, accès FTP/SFTP.
2. **Cloner / déployer** le dépôt sur l'hébergement (`public/` pour la SPA, `orchestrator/` pour les APIs).
3. **Importer la base** : `mysql -u <user> -p <db> < orchestrator/sql/schema.sql` puis `seeds.sql`.
4. **Configurer** `orchestrator/.env.php` (voir section suivante) ou utiliser des variables d'environnement.
5. **Tester** : `https://<domaine>/api/health.php` et `/api/health.php?check=db` doivent renvoyer `status=ok`.
6. **Connexion de recette** : `https://<domaine>/` puis login seeds.

Détails FTP/shared hosting : se référer à `INSTALLATION.md` pour les captures, checklists et correctifs courants.

---

## ⚙️ Configuration applicative (`orchestrator/.env.php`)
- **Base de données** : `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`.
- **Auth & sécurité** : `AUTH_MODE` (`MIXED` par défaut), `JWT_SECRET`, `JWT_EXPIRY_SECONDS`, `$GLOBALS['API_KEYS']` (teacher/admin/director/inspector).
- **Logs & observabilité** : `LOG_FILE`, `LOG_LEVEL`, rotation (`LOG_ROTATE_*`), `logger()` central.
- **Cache & rate limiting** : `CACHE_DIR`, `CACHE_DEFAULT_TTL`, `RATE_LIMIT_MAX_REQUESTS`.
- **CORS** : `CORS_ALLOWED_ORIGINS`, `CORS_ALLOWED_HEADERS`, `CORS_MAX_AGE`.
- **Intégration ErgoMate** : `ERGO_MATE_WEBHOOK_URL`, `ERGO_MATE_API_KEY`, `MOCK_MODE`.
- **Uploads** : `UPLOADS_DIR`, `UPLOADS_MAX_SIZE`, `UPLOADS_ALLOWED_TYPES`.
- **IA Mistral** : `MISTRAL_API_ENDPOINT`, `MISTRAL_DEFAULT_MODEL`, `MISTRAL_TIMEOUT`.
- **Runtime** : `APP_ENV`, `APP_DEBUG` (automatique via `APP_ENV`), hooks d'erreurs personnalisés.

Astuce : exécuter `php -r "echo bin2hex(random_bytes(32));"` pour générer des clés JWT 256 bits, et `random_bytes(16)` pour `ADMIN_KEY`.

---

## ▶️ Démarrer en local
```bash
# 1. Servir les APIs (PHP built-in)
php -S 0.0.0.0:8080 -t orchestrator/api

# 2. Servir la SPA front (dans un second terminal)
php -S 0.0.0.0:8081 -t public

# 3. Vérifier
curl http://localhost:8080/health.php
# macOS : open http://localhost:8081/
# Linux : xdg-open http://localhost:8081/
```
> En production, placer `public/` comme racine web et exposer `/api/*.php` via Apache/Nginx avec `orchestrator/api/`.

---

## ✅ Tests & QA
- `orchestrator/tests/smoke_test_qa01.php` : Smoke test complet (environnement, DB, endpoints critiques, auth). Exécution : `php orchestrator/tests/smoke_test_qa01.php`.
- `orchestrator/tests/qa08_error_handling_test.php` : résilience et gestion d'erreurs API.
- `orchestrator/tests/integration/` : scénarios de bout en bout (assignments, analytics, IA).
- Rapports QA/Bug Hunt : `orchestrator/tests/qa_sprint_s_qa_bug_hunt_01_report.md`, `bugs_found.log`.
- Healthcheck automatisable : `/api/health.php?check=full`.

---

## 🔄 Synchronisation ErgoMate
- **Mode mock (MVP)** : `MOCK_MODE=true`, jeux de données statiques (`public/mock/`, seeds DB), webhooks simulés.
- **Mode production** : `MOCK_MODE=false`, configurer webhooks signés HMAC, remplir `ERGO_MATE_*`, connecter les listeners `realtime/` + `api/ingest.php`.
- **Exports académiques** : API `export.php` (QTI, ENT, LMS) + `services/converters/`.
- **Télémétrie** : middlewares `/_middleware_telemetry.php` + `api/telemetry/ingest.php` pour alimenter `telemetry_events`.

---

## 🔒 Sécurité & exploitation
- Changer toutes les clés (`JWT_SECRET`, `ADMIN_KEY`, `$GLOBALS['API_KEYS']`, `ERGO_MATE_API_KEY`).
- Forcer HTTPS (`.htaccess` fourni) et restreindre CORS.
- Désactiver `APP_DEBUG` en production, activer rotation logs (`logs/`, `php-errors.log`).
- Vérifier permissions (`orchestrator/uploads`, `logs`) : `chmod 755`.
- Activer `RATE_LIMIT_ENABLED` et surveiller `logs/app.log` + `sync_logs`.
- Penser à la rotation des clés JWT (`php -r "echo bin2hex(random_bytes(32));"`).

---

## 🗺️ Roadmap (extraits Sprint)
- **Sprint 2** : notifications temps réel, génération IA avancée, partage inter-écoles, API partenaires.
- **Sprint 3** : isolation stricte des tenants, RBAC hiérarchique, reporting anonymisé.
- **Sprints 4 → 10** : learning analytics avancés, adaptive learning, fonctionnalités sociales, dashboard direction.
- Historique complet dans `docs/SPRINT*_*.md` + `QA_SPRINT_FINAL_REPORT.md`.

---

## 📚 Ressources
- Documentation API : `docs/openapi-orchestrator.yaml` (OpenAPI v3).
- Architecture : `docs/SPRINT10_ARCHITECTURE_OVERVIEW.md`, `docs/SPRINT13_ARCHITECTURE_OVERVIEW.md`.
- Schémas : `docs/schema/ergomate_theme.schema.json`, diagrammes de séquence (`docs/architecture/`).
- Guides produit : `docs/SPRINT13_README.md`, `docs/SPRINT_12_PEDAGOGICAL_LIBRARY.md`.

---

## 🤝 Support
**Développeur** : Mehdy Driouech  
**Email** : contact@mehdydriouech.fr  
**Site** : [www.mehdydriouech.fr](https://www.mehdydriouech.fr)

---

## 📄 Licence
Code propriétaire – Tous droits réservés.  
© 2025 Mehdy Driouech

---

**Version MVP** : Sprint 0 + Sprint 1 complétés ✅  
**Prochaine version** : Sprint 2 (Collaboration & IA)
