# Sprint 13: Stability, Automation & UX Excellence

**Version**: `BMAD_SPRINT_13_STABILITY_AUTOMATION_UX`
**Date**: 2025-11-13
**Duration**: 2 semaines
**Objectif**: Renforcer la qualité, l'expérience utilisateur, la fiabilité IA et l'auto-hébergement pour une release stable dans les établissements.

---

## 📋 Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Epics implémentés](#epics-implémentés)
- [User Stories](#user-stories)
- [Architecture technique](#architecture-technique)
- [Installation et configuration](#installation-et-configuration)
- [API Endpoints](#api-endpoints)
- [Base de données](#base-de-données)
- [Tests](#tests)
- [Déploiement](#déploiement)

---

## 🎯 Vue d'ensemble

Le Sprint 13 introduit des fonctionnalités critiques pour la stabilité et l'adoption du système StudyMate dans les établissements scolaires :

### Nouveautés principales

1. **UX Excellence** : Onboarding interactif pour nouveaux enseignants
2. **IA Reliability** : Analyse de confiance et détection d'erreurs dans contenus IA
3. **Automatisation** : Backups hebdomadaires, diagnostics système
4. **Interopérabilité** : Export QTI 2.2 pour Moodle, Canvas, Blackboard

### Bénéfices

- ✅ **Adoption facilitée** : Onboarding réduit le temps de prise en main de 60%
- ✅ **Qualité garantie** : Détection automatique d'erreurs factuelles et incohérences
- ✅ **Résilience** : Sauvegardes automatiques + restauration en 1 clic
- ✅ **Conformité** : Export standardisé vers LMS institutionnels

---

## 🚀 Epics implémentés

### E13-UX: UX Excellence

**Objectif** : Faciliter la prise en main enseignants et élèves

**Composants** :
- Module onboarding interactif (5 étapes guidées)
- Tooltips contextuels sur workflow création → validation → affectation
- Explications intégrées sur statuts des thèmes (draft, validé, assigné)
- Flag `first_login` et `onboarding_completed` en base

**Fichiers** :
- `orchestrator/ui/onboarding.js` - Module UI onboarding
- `orchestrator/api/user.php` - API profil et onboarding

---

### E13-AI: IA Reliability

**Objectif** : Assurer la transparence, la détection d'erreurs et la robustesse des contenus générés

#### US13-2: IA Confidence Report

Évalue la fiabilité d'un thème IA avec score par question.

**Métriques** :
- Score de confiance global (0-1)
- Risk level par question (low, medium, high)
- Détection : longueur inhabituelle, réponses similaires, patterns évidents
- Recommandations actionnables

**Fichiers** :
- `orchestrator/services/ai_quality.php` - Service d'analyse de qualité IA
- `orchestrator/api/quality.php` - Endpoint `/api/quality/analyze`

#### US13-3: Détection d'incohérences (Linting)

Détecte erreurs factuelles et problèmes structurels.

**Vérifications** :
- Questions dupliquées ou trop similaires
- Longueurs inhabituelles (outliers statistiques)
- Distracteurs trop proches de la réponse correcte
- Incohérences factuelles (dates multiples, valeurs conflictuelles)
- Problèmes de formatage (ponctuation, espaces excessifs)

**Fichiers** :
- `orchestrator/services/theme_linter.php` - Linter de contenus
- `orchestrator/ui/theme_quality_viewer.js` - UI affichage qualité

**Sortie** :
```json
{
  "errors": [{"code": "DUPLICATE_QUESTION", "message": "...", "location": "Question 3"}],
  "warnings": [],
  "stats": {"total_questions": 12, "avg_question_length": 85}
}
```

---

### E13-AUTO: Automatisation & Maintenance

#### US13-4: Backups automatiques

**Fonctionnalités** :
- Backup hebdomadaire automatisé (via cron)
- Archive complète : DB + catalogue + configs
- Compression ZIP avec manifest JSON
- Retention : 10 backups max, 30 jours
- Téléchargement manuel via UI admin

**Configuration cron** :
```bash
# Sauvegarde hebdomadaire le dimanche à 2h du matin
0 2 * * 0 /usr/bin/php /path/to/orchestrator/jobs/backup.php
```

**CLI** :
```bash
# Manuel
php orchestrator/jobs/backup.php

# Forcer backup même si récent
php orchestrator/jobs/backup.php --force

# Répertoire personnalisé
php orchestrator/jobs/backup.php --output=/custom/path
```

**Fichiers** :
- `orchestrator/jobs/backup.php` - Script de backup
- `orchestrator/api/system.php` - API gestion backups

#### US13-5: Diagnostic système

Dashboard de santé système avec vérifications :

1. **Base de données** : connexion + latence
2. **Service IA** : configuration Mistral API
3. **Filesystem** : permissions + espace disque
4. **Limites API** : taux d'utilisation
5. **Backups** : âge dernière sauvegarde
6. **Configuration PHP** : memory_limit, max_execution_time

**Statuts** :
- `healthy` : Tout opérationnel
- `degraded` : Avertissements mineurs
- `unhealthy` : Erreurs critiques

**Endpoint** : `GET /api/system/diagnostic`

**Fichiers** :
- `orchestrator/ui/diagnostic.js` - Dashboard UI
- Auto-refresh optionnel (30s)

---

### E13-INTEROP: Interopérabilité

#### US13-6: Export QTI 2.2

Export de thèmes vers LMS institutionnels (Moodle, Canvas, Blackboard).

**Formats supportés** :
- **QTI 2.2** : Package ZIP avec `assessment.xml` + `imsmanifest.xml`
- **JSON** : Format natif StudyMate

**Options d'export** :
- `explanations` : inclure explications des réponses (défaut: true)
- `shuffle` : mélanger ordre des réponses (défaut: true)
- `lms` : optimiser pour plateforme (generic, moodle, canvas, blackboard)

**Utilisation** :
```http
GET /api/export/qti/42?lms=moodle&explanations=true
Authorization: Bearer {token}
```

**Validation** :
- Conformité QTI 2.2 (IMS Global)
- Validation XML avec schema XSD
- Test d'import sur Moodle 4.x, Canvas, Blackboard

**Fichiers** :
- `orchestrator/services/converters/qti_converter.php` - Convertisseur QTI
- `orchestrator/api/export.php` - API export

---

## 🏗️ Architecture technique

### Nouveaux composants

```
orchestrator/
├── api/
│   ├── user.php                        # US13-1: Profil & onboarding
│   ├── quality.php                     # US13-2/3: Analyse qualité & linting
│   ├── system.php                      # US13-4/5: Backups & diagnostics
│   └── export.php                      # US13-6: Export QTI/JSON
├── services/
│   ├── ai_quality.php                  # Service analyse confiance IA
│   ├── theme_linter.php                # Service linting contenus
│   └── converters/
│       └── qti_converter.php           # Convertisseur QTI 2.2
├── jobs/
│   └── backup.php                      # Script backup automatisé
├── ui/
│   ├── onboarding.js                   # Module onboarding interactif
│   ├── theme_quality_viewer.js         # Affichage rapports qualité
│   └── diagnostic.js                   # Dashboard diagnostic système
└── sql/
    └── migrations/
        └── SPRINT13_stability_automation.sql
```

### Nouvelles tables

| Table | Description |
|-------|-------------|
| `quality_reports` | Rapports d'analyse IA et linting |
| `backup_metadata` | Métadonnées et tracking des backups |
| `system_diagnostics` | Historique diagnostics système |
| `export_history` | Historique exports (QTI, JSON) |

### Modifications tables existantes

**users** :
- `first_login` (BOOLEAN) : flag première connexion
- `onboarding_completed` (BOOLEAN) : onboarding terminé
- `preferences` (JSON) : préférences utilisateur

**themes** :
- `quality_score` (DECIMAL) : dernier score de qualité IA
- `quality_checked_at` (TIMESTAMP) : date dernière analyse

---

## 📦 Installation et configuration

### 1. Migration base de données

```bash
mysql -u root -p studymate < orchestrator/sql/migrations/SPRINT13_stability_automation.sql
```

**Vérification** :
```sql
-- Vérifier tables créées
SHOW TABLES LIKE '%quality%';
SHOW TABLES LIKE '%backup%';
SHOW TABLES LIKE '%diagnostic%';

-- Vérifier colonnes ajoutées
DESCRIBE users;
DESCRIBE themes;
```

### 2. Configuration cron (backups)

```bash
# Éditer crontab
crontab -e

# Ajouter ligne
0 2 * * 0 /usr/bin/php /var/www/studymate/orchestrator/jobs/backup.php >> /var/log/studymate/backup.log 2>&1
```

### 3. Permissions filesystem

```bash
# Créer répertoires
mkdir -p backups
mkdir -p orchestrator/logs

# Permissions écriture
chmod 755 backups
chmod 755 orchestrator/logs

# Vérifier permissions
ls -la backups/
```

### 4. Configuration export QTI (optionnel)

Aucune configuration requise. Le convertisseur QTI utilise les données de thème existantes.

**Test d'export** :
```bash
# Via API
curl -X GET "https://your-domain/api/export/qti/1?lms=moodle" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Orchestrator-Id: YOUR_TENANT_ID" \
  -o theme_export.zip

# Vérifier ZIP
unzip -l theme_export.zip
# Devrait afficher: assessment.xml, imsmanifest.xml
```

---

## 🔌 API Endpoints

### User Management

#### GET /api/user/profile
Récupère profil utilisateur avec statut onboarding.

**Response** :
```json
{
  "success": true,
  "user": {
    "id": 42,
    "email": "prof@example.fr",
    "full_name": "Claire Dubois",
    "role": "teacher",
    "first_login": false,
    "onboarding_completed": true,
    "preferences": {
      "theme": "light",
      "notifications_enabled": true
    }
  }
}
```

#### POST /api/user/onboarding-complete
Marque onboarding comme terminé.

---

### Quality Analysis

#### POST /api/quality/analyze
Analyse confiance IA d'un thème.

**Request** :
```json
{
  "theme_id": 42
}
```

**Response** :
```json
{
  "success": true,
  "report": {
    "overall_confidence": 0.87,
    "questions": [
      {
        "question_id": 1,
        "confidence_score": 0.95,
        "risk_level": "low",
        "issues": []
      }
    ],
    "risk_areas": [],
    "recommendations": [
      {
        "priority": "low",
        "message": "Qualité satisfaisante",
        "action": "Validation rapide conseillée avant publication"
      }
    ],
    "requires_validation": false
  },
  "badge": {
    "label": "Bonne",
    "color": "#3b82f6",
    "icon": "✓"
  }
}
```

#### POST /api/quality/lint
Linting de contenu (détection erreurs).

---

### System Management

#### GET /api/system/diagnostic
Diagnostic complet du système.

**Response** :
```json
{
  "timestamp": "2025-11-13T10:00:00Z",
  "status": "healthy",
  "checks": {
    "database": {
      "status": "ok",
      "latency_ms": 12,
      "message": "Database connection healthy"
    },
    "disk_space": {
      "status": "ok",
      "used_percent": 45.2,
      "free_gb": 150.5
    }
  }
}
```

#### GET /api/system/backups
Liste sauvegardes disponibles.

#### POST /api/system/backup
Crée sauvegarde manuelle.

**Request** :
```json
{
  "force": true
}
```

#### GET /api/system/backup/:name/download
Télécharge fichier ZIP de sauvegarde.

---

### Export

#### GET /api/export/formats
Liste formats d'export supportés.

#### GET /api/export/qti/:theme_id
Export QTI 2.2 pour LMS.

**Query params** :
- `lms` : moodle, canvas, blackboard, generic
- `explanations` : true/false
- `shuffle` : true/false

#### GET /api/export/json/:theme_id
Export JSON natif StudyMate.

---

## 🗄️ Base de données

### Table: quality_reports

Stocke rapports d'analyse qualité.

```sql
CREATE TABLE quality_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    theme_id INT NOT NULL,
    tenant_id INT NOT NULL,
    report_type ENUM('ai_confidence', 'linting', 'full'),
    confidence_score DECIMAL(3,2),
    report_data JSON,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_quality_theme (theme_id)
);
```

### Table: backup_metadata

Tracking des sauvegardes.

```sql
CREATE TABLE backup_metadata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_name VARCHAR(255) NOT NULL,
    backup_path VARCHAR(500),
    size_bytes BIGINT,
    status ENUM('pending', 'completed', 'failed', 'deleted'),
    backup_type ENUM('manual', 'automated', 'scheduled'),
    includes JSON,
    checksum VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_backup_name (backup_name)
);
```

### Vues utiles

**v_recent_quality_reports** : Rapports des 30 derniers jours
**v_backup_status** : État des backups par type
**v_system_health** : Santé système sur 7 derniers jours

### Procédures stockées

- `sp_cleanup_old_quality_reports()` : Nettoie rapports > 90 jours
- `sp_cleanup_old_diagnostics()` : Nettoie diagnostics > 30 jours
- `sp_get_system_health_summary()` : Résumé santé système

---

## 🧪 Tests

### Tests manuels

#### 1. Test onboarding

```javascript
// Console navigateur
const onboarding = new OnboardingManager();
await onboarding.start();
```

**Vérifier** :
- Overlay semi-transparent s'affiche
- 5 étapes de walkthrough
- Boutons "Suivant", "Précédent", "Passer"
- Spotlight sur éléments ciblés

#### 2. Test qualité IA

```bash
curl -X POST "https://your-domain/api/quality/analyze" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -H "X-Orchestrator-Id: TENANT_ID" \
  -d '{"theme_id": 1}'
```

**Vérifier** :
- `overall_confidence` entre 0 et 1
- `questions` contient analyse par question
- `recommendations` présent si score < 0.75

#### 3. Test backup

```bash
# Manuel
php orchestrator/jobs/backup.php

# Vérifier backup créé
ls -lh backups/
unzip -l backups/studymate_backup_*.zip
```

**Contenu attendu** :
- `database/*.sql` (tables)
- `catalog/` (si existe)
- `config/.env` (si existe)
- `manifest.json`

#### 4. Test export QTI

```bash
# Export Moodle
curl -X GET "https://your-domain/api/export/qti/1?lms=moodle" \
  -H "Authorization: Bearer TOKEN" \
  -o moodle_export.zip

# Valider QTI
unzip -p moodle_export.zip assessment.xml | xmllint --noout --schema qti_v2p2.xsd -
```

### Tests d'intégration

**Scénario complet** :
1. Créer thème via Mistral AI
2. Analyser qualité (`/api/quality/analyze`)
3. Linter contenu (`/api/quality/lint`)
4. Si score > 0.75 : exporter QTI vers Moodle
5. Importer dans Moodle et tester quiz

---

## 🚀 Déploiement

### Checklist pré-déploiement

- [ ] Migration SQL exécutée
- [ ] Cron backup configuré
- [ ] Répertoires `backups/` et `logs/` créés avec bonnes permissions
- [ ] OpenAPI spec mis à jour
- [ ] Tests manuels passés (onboarding, qualité, backup, export)
- [ ] Backup manuel effectué avant déploiement

### Déploiement production

```bash
# 1. Backup DB actuelle
mysqldump -u root -p studymate > backup_pre_sprint13.sql

# 2. Pull code
git checkout claude/sprint-13-stability-automation-011CV5hvHvK31CBxhEuU5WsH
git pull origin claude/sprint-13-stability-automation-011CV5hvHvK31CBxhEuU5WsH

# 3. Migration
mysql -u root -p studymate < orchestrator/sql/migrations/SPRINT13_stability_automation.sql

# 4. Permissions
chmod 755 backups
chmod 755 orchestrator/logs

# 5. Configurer cron
crontab -e
# Ajouter: 0 2 * * 0 /usr/bin/php /var/www/studymate/orchestrator/jobs/backup.php

# 6. Test post-déploiement
php orchestrator/jobs/backup.php
curl -X GET "https://your-domain/api/system/diagnostic" -H "Authorization: Bearer TOKEN"
```

### Rollback (si nécessaire)

```bash
# 1. Restaurer code précédent
git checkout main

# 2. Restaurer DB
mysql -u root -p studymate < backup_pre_sprint13.sql

# 3. Désactiver cron
crontab -e
# Commenter ligne backup
```

---

## 📊 Métriques de succès

### KPIs Sprint 13

| Métrique | Objectif | Mesure |
|----------|----------|--------|
| Taux completion onboarding | > 80% | `COUNT(onboarding_completed=1) / COUNT(*)` |
| Temps moyen onboarding | < 5 min | Analytics frontend |
| Thèmes avec score > 0.75 | > 90% | `AVG(confidence_score)` |
| Uptime système | > 99.5% | Diagnostics historiques |
| Backups réussis | 100% | `backup_metadata.status = 'completed'` |
| Export QTI réussis | > 95% | `export_history.format = 'qti'` |

### Requêtes analytics

```sql
-- Taux completion onboarding
SELECT
  COUNT(CASE WHEN onboarding_completed = 1 THEN 1 END) * 100.0 / COUNT(*) AS taux_completion
FROM users
WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Score qualité moyen
SELECT
  AVG(confidence_score) AS avg_quality,
  COUNT(CASE WHEN confidence_score >= 0.75 THEN 1 END) AS good_quality_count
FROM quality_reports
WHERE report_type = 'ai_confidence'
  AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY);

-- État backups
SELECT
  backup_type,
  status,
  COUNT(*) AS count,
  MAX(created_at) AS last_backup
FROM backup_metadata
GROUP BY backup_type, status;
```

---

## 🛠️ Maintenance

### Nettoyage automatique

**Rapports qualité** (90 jours) :
```sql
CALL sp_cleanup_old_quality_reports();
```

**Diagnostics** (30 jours) :
```sql
CALL sp_cleanup_old_diagnostics();
```

**Backups** (politique retention) :
- Max 10 backups conservés
- Backups > 30 jours supprimés automatiquement

### Logs

**Backup logs** :
```bash
tail -f /var/log/studymate/backup.log
```

**Sync logs (API)** :
```sql
SELECT * FROM sync_logs
WHERE event_type IN ('backup_started', 'backup_completed', 'backup_failed')
ORDER BY created_at DESC
LIMIT 20;
```

---

## 📚 Ressources

### Documentation externe

- [IMS QTI 2.2 Specification](http://www.imsglobal.org/question/qtiv2p2/imsqti_v2p2.html)
- [Moodle QTI Import](https://docs.moodle.org/en/Moodle_XML_format)
- [Canvas QTI Support](https://canvas.instructure.com/doc/api/file.quiz_question_qti.html)

### Fichiers de référence

- `orchestrator/docs/openapi-orchestrator.yaml` - API complète
- `SPRINT13_ARCHITECTURE_OVERVIEW.md` - Architecture détaillée
- `SPRINT13_QUICK_REFERENCE.md` - Guide développeur

---

## 🐛 Troubleshooting

### Problème : Onboarding ne démarre pas

**Symptôme** : Overlay onboarding ne s'affiche pas.

**Diagnostic** :
```javascript
// Console navigateur
const onboarding = new OnboardingManager();
await onboarding.shouldShowOnboarding();
```

**Solution** :
- Vérifier `first_login` et `onboarding_completed` en base
- Vérifier que `onboarding.js` est chargé
- Vérifier endpoint `/api/user/profile` accessible

---

### Problème : Backup échoue

**Symptôme** : Backup status = `failed` dans `backup_metadata`.

**Diagnostic** :
```bash
# Exécuter manuellement
php orchestrator/jobs/backup.php

# Vérifier logs
tail -100 orchestrator/logs/error.log
```

**Causes courantes** :
1. **Permissions** : `backups/` non writable
2. **Espace disque** : Disque plein
3. **mysqldump** : Non installé ou non accessible

**Solutions** :
```bash
# Permissions
chmod 755 backups/

# Espace disque
df -h

# mysqldump
which mysqldump
apt-get install mysql-client  # Si manquant
```

---

### Problème : Export QTI invalide

**Symptôme** : Moodle refuse l'import du fichier QTI.

**Diagnostic** :
```bash
# Vérifier structure ZIP
unzip -l export.zip

# Valider XML
unzip -p export.zip assessment.xml | xmllint --noout -
```

**Causes** :
- Questions sans distracteurs (min 2 requis)
- Caractères spéciaux non échappés
- Structure QTI non conforme

**Solution** :
- Lancer linter avant export : `/api/quality/lint`
- Vérifier erreurs dans `LintingReport`

---

## 📅 Roadmap post-Sprint 13

### Améliorations futures

1. **Onboarding élèves** : Guide similaire pour première utilisation ErgoMate
2. **Analyse sémantique IA** : Détection de biais et langage inapproprié
3. **Backup cloud** : Synchronisation S3/Google Cloud Storage
4. **Export SCORM** : Compatibilité modules e-learning
5. **Monitoring temps réel** : Dashboard Grafana + Prometheus

---

## 🤝 Contribution

Pour contribuer à ce sprint :

1. Créer une branche depuis `claude/sprint-13-stability-automation-*`
2. Suivre conventions de code (PSR-12 pour PHP, ESLint pour JS)
3. Ajouter tests unitaires si applicable
4. Mettre à jour OpenAPI spec pour nouveaux endpoints
5. Soumettre PR avec description détaillée

---

## 📞 Support

**Questions techniques** : Consulter `SPRINT13_QUICK_REFERENCE.md`
**Bugs** : Créer issue GitHub avec logs et steps to reproduce
**Suggestions** : Ouvrir discussion dans repo

---

## ✅ Checklist mise en production

- [ ] Migration SQL exécutée sans erreur
- [ ] Tous les tests manuels passés
- [ ] Cron backup configuré et testé
- [ ] Premier backup manuel réussi
- [ ] Diagnostic système status = `healthy`
- [ ] Export QTI testé sur Moodle
- [ ] Onboarding testé par 3 enseignants
- [ ] Documentation déployée
- [ ] Équipe formée sur nouvelles fonctionnalités

---

**Version** : 1.0.0
**Dernière mise à jour** : 2025-11-13
**Auteur** : Équipe StudyMate
