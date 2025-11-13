# Sprint 2 - Implémentation Complete

**Date**: 2025-11-12
**Version**: 1.0
**Objectif**: Collaboration & IA pédagogique

---

## 📋 Vue d'ensemble

Ce sprint introduit les fonctionnalités suivantes :
1. **Affectations & Notifications** - Créer et notifier des missions aux élèves
2. **Génération IA validée** - Créer des thèmes depuis du texte avec validation schéma
3. **Accusés & Suivi** - Tracer l'ACK de réception et l'état d'exécution

---

## 🗄️ Base de données

### Nouvelles tables créées

#### 1. `assignment_events`
Tracking des événements d'assignments (réception, ouverture, progression, complétion).

```sql
CREATE TABLE assignment_events (
    id VARCHAR(50) PRIMARY KEY,
    assignment_id VARCHAR(50) NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    event_type ENUM('received', 'opened', 'started', 'in_progress', 'completed', 'error'),
    metadata JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);
```

#### 2. `ai_generations`
Stockage des générations IA avec validation.

```sql
CREATE TABLE ai_generations (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    generation_type ENUM('theme', 'quiz', 'flashcards', 'fiche'),
    source_type ENUM('text', 'pdf', 'audio', 'url'),
    source_hash VARCHAR(64) NULL,
    result_json JSON DEFAULT NULL,
    validation_status ENUM('pending', 'valid', 'invalid', 'error'),
    validation_errors JSON DEFAULT NULL,
    theme_id VARCHAR(50) NULL,
    status ENUM('queued', 'processing', 'completed', 'error'),
    processing_time_ms INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### 3. `notifications`
Gestion des notifications (in-app et email).

```sql
CREATE TABLE notifications (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    recipient_type ENUM('student', 'teacher', 'class', 'promo'),
    recipient_id VARCHAR(50) NOT NULL,
    notification_type ENUM('assignment', 'reminder', 'result', 'info'),
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    link_url VARCHAR(500) NULL,
    delivery_method ENUM('in-app', 'email', 'both'),
    status ENUM('pending', 'sent', 'failed'),
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Migration

Pour appliquer les changements :

```bash
mysql -u root -p studymate_orchestrator < orchestrator/sql/migration_sprint2.sql
```

---

## 🔌 API Endpoints

### 1. Assignments API (`/api/assignments`)

#### GET /api/assignments
Liste les assignments avec filtrage.

**Paramètres Query**:
- `limit` (int, défaut: 50)
- `offset` (int, défaut: 0)
- `status` (string, optionnel)
- `teacher_id` (string, optionnel)
- `class_id` (string, optionnel)

**Réponse**:
```json
{
  "assignments": [
    {
      "id": "assign_XXX",
      "title": "Quiz Chapitre 1",
      "type": "quiz",
      "status": "pushed",
      "theme_title": "Mathématiques",
      "teacher_name": "Jean Dupont",
      "target_count": 25,
      "received_count": 20,
      "completed_count": 15,
      "due_at": "2025-11-20T23:59:59Z"
    }
  ],
  "pagination": {
    "total": 42,
    "limit": 50,
    "offset": 0
  }
}
```

#### POST /api/assignments
Crée un nouvel assignment.

**Body**:
```json
{
  "title": "Quiz Chapitre 1",
  "type": "quiz",
  "theme_id": "theme_XXX",
  "mode": "post-cours",
  "instructions": "À faire pour demain",
  "due_at": "2025-11-20T23:59:59Z",
  "targets": [
    {"type": "class", "id": "class_XXX"},
    {"type": "student", "id": "student_YYY"}
  ]
}
```

**Réponse**:
```json
{
  "assignment_id": "assign_XXX",
  "status": "created",
  "sync_log_id": "sync_YYY"
}
```

#### GET /api/assignments/{id}
Détails d'un assignment.

#### GET /api/assignments/{id}/events
Événements de tracking pour un assignment.

**Réponse**:
```json
{
  "events": [
    {
      "id": "evt_XXX",
      "assignment_id": "assign_YYY",
      "student_id": "student_ZZZ",
      "student_name": "Marie Martin",
      "event_type": "completed",
      "created_at": "2025-11-12T14:30:00Z"
    }
  ]
}
```

#### POST /api/assignments/ack
Enregistrer un accusé de réception depuis Ergo-Mate.

**Body**:
```json
{
  "assignment_id": "assign_XXX",
  "student_id": "student_YYY",
  "event_type": "received",
  "metadata": {}
}
```

#### PATCH /api/assignments/{id}/status
Mettre à jour le statut d'un assignment.

---

### 2. AI API (`/api/ai`)

#### POST /api/ai/theme-from-text
Générer un thème depuis du texte.

**Body**:
```json
{
  "text": "Voici le cours sur les fonctions mathématiques...",
  "type": "theme",
  "difficulty": "intermediate"
}
```

**Réponse**:
```json
{
  "generation_id": "aigen_XXX",
  "theme_id": "theme_YYY",
  "result": {
    "title": "Les fonctions mathématiques",
    "description": "...",
    "difficulty": "intermediate",
    "questions": [...],
    "flashcards": [...],
    "fiche": {...}
  },
  "validation": {
    "valid": true,
    "errors": []
  },
  "processing_time_ms": 1250
}
```

#### GET /api/ai/generations
Liste des générations IA.

#### GET /api/ai/generations/{id}
Détails d'une génération IA.

---

## 📦 Services

### NotificationService

Service de gestion des notifications.

**Méthodes principales**:
- `notifyAssignmentCreated($assignmentId, $targets)` - Notifie la création d'un assignment
- `resolveTargets($targets, $tenantId)` - Résout les cibles en liste d'étudiants
- `pushToErgoMate($assignment, $students)` - Envoie un webhook à Ergo-Mate

**Fichier**: `orchestrator/lib/notify.php`

### AIService

Service de génération de contenu IA.

**Méthodes principales**:
- `generateThemeFromText($text, $userId, $tenantId, $options)` - Génère un thème depuis du texte
- `callMistralAPI($text, $options)` - Appelle l'API Mistral
- `validateTheme($data)` - Valide un thème contre le schéma

**Fichier**: `orchestrator/lib/ai_service.php`

### SchemaValidator

Validateur de schéma JSON.

**Méthodes principales**:
- `validateTheme($data)` - Valide un thème ErgoMate

**Fichier**: `orchestrator/lib/ai_service.php`

---

## 🎨 Interface utilisateur

### Page Assignments

**Fichier**: `public/assignments.html`
**Script**: `public/js/assignments.js`

**Fonctionnalités**:
- Liste des assignments avec filtrage
- Création d'assignments via modal
- Suivi en temps réel des événements
- Statistiques de progression

**Captures d'écran**:
- Grille d'assignments avec badges de statut
- Formulaire de création avec sélection multi-cibles
- Vue de suivi avec tableau des événements par étudiant

---

## 🔧 Configuration

### Variables d'environnement

Fichier `.env.php` créé avec les constantes suivantes :

```php
// Base de données
DB_HOST, DB_NAME, DB_USER, DB_PASS

// Authentification
AUTH_MODE (MIXED par défaut)
JWT_SECRET
API_KEY_TEACHER, API_KEY_ADMIN, etc.

// Ergo-Mate
ERGO_MATE_WEBHOOK_URL
ERGO_MATE_API_KEY

// IA
MISTRAL_API_ENDPOINT

// Mode
MOCK_MODE (true/false)
```

### Mode MOCK

Le mode MOCK permet de tester sans :
- Appeler l'API Mistral réelle
- Envoyer des webhooks à Ergo-Mate

Activer via `MOCK_MODE=true` dans `.env.php`.

---

## 🧪 Tests

### Tests manuels

1. **Créer un assignment**
```bash
curl -X POST http://localhost/api/assignments \
  -H "Authorization: Bearer YOUR_JWT" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Test Quiz",
    "type": "quiz",
    "theme_id": "theme_XXX",
    "targets": [{"type": "class", "id": "class_YYY"}]
  }'
```

2. **Générer un thème IA**
```bash
curl -X POST http://localhost/api/ai/theme-from-text \
  -H "Authorization: Bearer YOUR_JWT" \
  -H "Content-Type: application/json" \
  -d '{
    "text": "Les fonctions en mathématiques...",
    "difficulty": "intermediate"
  }'
```

3. **Envoyer un ACK**
```bash
curl -X POST http://localhost/api/assignments/ack \
  -H "Content-Type: application/json" \
  -d '{
    "assignment_id": "assign_XXX",
    "student_id": "student_YYY",
    "event_type": "completed"
  }'
```

---

## 📊 Flux de données

### Création d'assignment

```
1. Enseignant crée assignment (UI)
   ↓
2. POST /api/assignments
   ↓
3. Insertion DB (assignments + assignment_targets)
   ↓
4. NotificationService.notifyAssignmentCreated()
   ↓
5. Création notifications (DB)
   ↓
6. Push webhook vers Ergo-Mate
   ↓
7. Mise à jour status = 'pushed'
```

### Génération IA

```
1. Enseignant soumet texte (UI)
   ↓
2. POST /api/ai/theme-from-text
   ↓
3. Check cache (source_hash)
   ↓
4. Appel Mistral API
   ↓
5. Validation JSON Schema
   ↓
6. Si valide : création thème (DB)
   ↓
7. Stockage génération (ai_generations)
```

### Tracking assignment

```
1. Étudiant ouvre mission (Ergo-Mate)
   ↓
2. POST /api/assignments/ack (event_type='opened')
   ↓
3. Insertion assignment_events
   ↓
4. Mise à jour compteurs (assignments)
   ↓
5. Enseignant consulte GET /api/assignments/{id}/events
   ↓
6. Affichage suivi (UI)
```

---

## 🚀 Déploiement

### Checklist

- [ ] Exécuter migration SQL
- [ ] Configurer `.env.php` avec vraies valeurs
- [ ] Créer répertoires `logs/`, `cache/`, `uploads/`
- [ ] Configurer permissions (755 pour directories, 644 pour fichiers)
- [ ] Configurer webhook Ergo-Mate
- [ ] Obtenir clé API Mistral (BYOK)
- [ ] Tester les endpoints en mode MOCK
- [ ] Tester les endpoints en mode LIVE
- [ ] Configurer rate limiting
- [ ] Configurer CORS

### Mode production

```php
// .env.php
define('APP_ENV', 'production');
define('APP_DEBUG', false);
define('MOCK_MODE', false);
define('JWT_SECRET', 'votre-secret-fort-et-aleatoire');
```

---

## 📚 Ressources

### Schéma ErgoMate

Fichier de référence : `orchestrator/docs/schema/ergomate_theme.schema.json`

### Logs

- Application : `logs/app.log`
- PHP errors : `logs/php-errors.log`

### Documentation API complète

TODO: Générer OpenAPI spec à partir des endpoints

---

## 🐛 Debugging

### Activer mode debug

```php
// .env.php
define('APP_DEBUG', true);
define('LOG_LEVEL', 'DEBUG');
```

### Consulter les logs

```bash
tail -f orchestrator/logs/app.log | jq
```

### Vérifier sync_logs

```sql
SELECT * FROM sync_logs ORDER BY created_at DESC LIMIT 10;
```

---

## ✅ Statut d'implémentation

| Feature | Statut | Notes |
|---------|--------|-------|
| DB Migration | ✅ Terminé | 3 nouvelles tables |
| API Assignments | ✅ Terminé | CRUD + events + ack |
| API AI | ✅ Terminé | theme-from-text + validation |
| NotificationService | ✅ Terminé | In-app + webhook |
| AIService | ✅ Terminé | Mock + Mistral intégration |
| SchemaValidator | ✅ Terminé | Validation complète |
| UI Assignments | ✅ Terminé | Création + suivi |
| Documentation | ✅ Terminé | Ce fichier |

---

## 🔜 Prochaines étapes (Sprint 3)

- [ ] Implémenter envoi d'emails (SMTP)
- [ ] Ajouter extraction PDF → texte
- [ ] Améliorer validation schéma (JSON Schema library)
- [ ] Ajouter tests automatisés (PHPUnit)
- [ ] Implémenter cache Redis (optionnel)
- [ ] Ajouter monitoring (Prometheus/Grafana)
- [ ] Implémenter webhooks bi-directionnels complets

---

**Auteur**: Mehdy Driouech
**Date**: 2025-11-12
**Version**: 1.0
