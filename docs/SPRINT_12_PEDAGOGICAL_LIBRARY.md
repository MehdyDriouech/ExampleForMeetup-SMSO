# Sprint 12 - Bibliothèque Pédagogique Interne

**Version:** BMAD_SPRINT_12_PEDAGOGICAL_LIBRARY
**Date:** 2025-11-13
**Durée:** 2 semaines

## 🎯 Objectif

Mettre en place un **catalogue interne de thèmes pédagogiques**, validés et versionnés, accessible aux enseignants d'un même établissement. Ce catalogue permet de mutualiser les contenus pédagogiques de qualité entre enseignants, avec un workflow de validation supervisé.

---

## 📋 Épics

### E12-CATALOG - Catalogue interne
Stockage, recherche, consultation et attribution des thèmes internes.

### E12-VALIDATION - Workflow de validation
Proposition, validation, publication et archivage sous supervision pédagogique.

### E12-VERSION - Versioning structuré
Gestion des versions (draft, v1, v2…), rollback, historique des modifications.

### E12-ROLES - Rôles & permissions
Droits enseignants, référents et direction sur le catalogue interne.

### E12-INTEGRATION - Intégration Ergo-Mate
Connexion au catalogue pour affectation, révisions et missions.

---

## 🏗️ Architecture

### Backend (API)

#### Fichiers créés/modifiés

**Services:**
- `orchestrator/services/WorkflowManager.php` - Gestion du workflow de validation
- `orchestrator/services/VersionService.php` - Gestion des versions (existant, réutilisé)

**API:**
- `orchestrator/api/catalog.php` - Endpoints du catalogue
- `orchestrator/api/_middleware_rbac.php` - Permissions catalogue (modifié)

**Intégration Ergo-Mate:**
- `ergomate/importer/themes.php` - Webhook pour import de thèmes

**Migration:**
- `orchestrator/migrations/sprint12_catalog.sql` - Schéma des tables

#### Endpoints API

| Endpoint | Méthode | Description | Permissions |
|----------|---------|-------------|-------------|
| `/api/catalog/list` | GET | Liste des thèmes du catalogue | catalog:read |
| `/api/catalog/{id}` | GET | Détails d'un thème | catalog:read |
| `/api/catalog/submit` | POST | Proposer un thème pour validation | catalog:submit |
| `/api/catalog/validate` | PATCH | Valider ou rejeter un thème | catalog:validate |
| `/api/catalog/publish` | POST | Publier un thème validé | catalog:publish |
| `/api/catalog/{id}/archive` | DELETE | Archiver un thème | catalog:archive |
| `/api/catalog/{id}/versions` | GET | Historique des versions | catalog:read |
| `/api/catalog/{id}/versions/{versionId}/rollback` | POST | Restaurer une version | catalog:update |
| `/api/catalog/publish-to-ergo` | POST | Pousser vers Ergo-Mate | catalog:publish_to_ergo |
| `/api/catalog/stats` | GET | Statistiques du catalogue | catalog:read |

### Frontend (UI)

#### Composants créés

**Interfaces utilisateur:**
- `orchestrator/ui/catalog_view.js` - Navigation dans le catalogue
- `orchestrator/ui/theme_viewer.js` - Consultation d'un thème en lecture seule
- `orchestrator/ui/catalog_validation.js` - Interface de validation (référents)

#### Fonctionnalités UI

**catalog_view.js:**
- Recherche et filtres (matière, niveau, difficulté, tags)
- Vue grille/liste
- Statistiques du catalogue
- Bouton "Mes contributions" (enseignants)
- Bouton "À valider" (référents)

**theme_viewer.js:**
- Affichage complet du contenu (questions, flashcards, fiches, annales)
- Onglets: Contenu / Versions / Historique workflow
- Export JSON
- Affectation à une classe

**catalog_validation.js:**
- File d'attente des thèmes proposés
- Aperçu du thème et statistiques
- Actions: Valider / Rejeter (avec commentaire obligatoire)
- Historique des actions

### Base de données

#### Tables créées

**Tables Orchestrator:**
```sql
catalog_entries              -- Entrées du catalogue
catalog_versions             -- Historique des versions
catalog_workflow_history     -- Historique des transitions workflow
catalog_assignments          -- Affectations aux classes
catalog_collaborators        -- Co-édition (future feature)
notifications                -- Notifications workflow
```

**Tables Ergo-Mate:**
```sql
themes                       -- Thèmes importés depuis le catalogue
theme_assignments            -- Affectations aux classes
theme_questions              -- Questions importées
theme_flashcards             -- Flashcards importées
theme_fiches                 -- Fiches importées
```

---

## 🔐 Permissions RBAC

### Nouveau rôle: `referent`

Le rôle **référent pédagogique** a été ajouté avec les permissions suivantes:

| Ressource | Action | Enseignant | Référent | Direction | Admin |
|-----------|--------|-----------|----------|-----------|-------|
| catalog | read | ✅ | ✅ | ✅ | ✅ |
| catalog | submit | ✅ | ❌ | ✅ | ✅ |
| catalog | validate | ❌ | ✅ | ✅ | ✅ |
| catalog | publish | ❌ | ❌ | ✅ | ✅ |
| catalog | archive | ❌ | ❌ | ✅ | ✅ |
| catalog | update | ❌ | ❌ | ✅ | ✅ |
| catalog | publish_to_ergo | ✅ | ❌ | ✅ | ✅ |

---

## 🔄 Workflow de validation

### États possibles

```
draft → proposed → validated → published
          ↓            ↓
       rejected    archived
```

### Transitions autorisées

| De | Vers | Rôles autorisés |
|----|------|----------------|
| draft | proposed | teacher, admin, direction |
| proposed | validated | referent, admin, direction |
| proposed | rejected | referent, admin, direction |
| validated | published | admin, direction |
| * | archived | admin, direction |

### Notifications

- **Thème proposé** → Notification aux référents
- **Thème validé** → Notification à l'auteur
- **Thème rejeté** → Notification à l'auteur + commentaire
- **Thème publié** → Notification à tous les enseignants du tenant

---

## 🔧 Installation

### 1. Migration de la base de données

```bash
mysql -u root -p database_name < orchestrator/migrations/sprint12_catalog.sql
```

### 2. Vérifier les permissions RBAC

Les permissions du catalogue ont été ajoutées à `_middleware_rbac.php`.

### 3. Configuration Ergo-Mate

Configurer l'URL Ergo-Mate dans les variables d'environnement:

```bash
ERGOMATE_URL=http://localhost:8081
```

### 4. Tester les endpoints

```bash
# Liste du catalogue
curl -X GET "http://localhost:8080/api/catalog/list?status=published" \
  -H "Authorization: Bearer YOUR_JWT" \
  -H "X-Tenant-Id: tenant_123"

# Proposer un thème
curl -X POST "http://localhost:8080/api/catalog/submit" \
  -H "Authorization: Bearer YOUR_JWT" \
  -H "X-Tenant-Id: tenant_123" \
  -H "Content-Type: application/json" \
  -d '{"catalog_entry_id": "cat_abc123", "comment": "Prêt pour validation"}'

# Valider un thème (référent)
curl -X PATCH "http://localhost:8080/api/catalog/validate" \
  -H "Authorization: Bearer YOUR_JWT" \
  -H "X-Tenant-Id: tenant_123" \
  -H "Content-Type: application/json" \
  -d '{"catalog_entry_id": "cat_abc123", "action": "validate", "comment": "Excellent contenu"}'
```

---

## 📊 User Stories complètes

### US12-1-LIST: Lister les thèmes du catalogue
**En tant qu'** enseignant
**Je veux** voir les thèmes de mon établissement
**Afin de** réutiliser du contenu existant

**Critères d'acceptation:**
- ✅ Recherche par titre, tags, matière, niveau
- ✅ Visible uniquement dans le tenant
- ✅ Affichage version actuelle + auteur + date
- ✅ Statut: validé, proposé, archivé

### US12-2-DETAIL: Consulter un thème
**En tant qu'** enseignant
**Je veux** ouvrir un thème du catalogue
**Afin de** vérifier son contenu

**Critères d'acceptation:**
- ✅ Affichage complet (quiz/flashcards/fiches)
- ✅ Mode lecture seule
- ✅ Historique des versions visible

### US12-3-SUBMIT: Proposer un thème à validation
**En tant qu'** enseignant
**Je veux** soumettre un thème que j'ai créé pour relecture
**Afin de** garantir la qualité du catalogue

**Critères d'acceptation:**
- ✅ Statut passe à 'proposé'
- ✅ Notification envoyée au référent
- ✅ Historique note l'action

### US12-4-VALIDATE: Valider ou rejeter un thème
**En tant que** référent pédagogique
**Je veux** accepter, commenter, ou refuser un thème
**Afin d'** assurer la cohérence pédagogique

**Critères d'acceptation:**
- ✅ Statuts: validé / rejeté
- ✅ Commentaire obligatoire en cas de rejet
- ✅ Historique enregistré
- ✅ Notification automatique

### US12-5-VERSIONING: Gérer les versions d'un thème
**En tant qu'** enseignant
**Je veux** voir et restaurer d'anciennes versions
**Afin de** corriger ou adapter un thème sans tout recréer

**Critères d'acceptation:**
- ✅ Liste des versions v1, v2, v3…
- ✅ Rollback possible
- ✅ Diff minimal (ajouts / suppressions clés)

### US12-6-RBAC-CATALOG: Droits d'accès catalogue
**En tant que** direction
**Je veux** définir les rôles et droits exacts
**Afin de** sécuriser le catalogue interne

**Critères d'acceptation:**
- ✅ Enseignant: consulter, proposer
- ✅ Référent: valider, commenter
- ✅ Direction: publier, archiver
- ✅ Respect tenant isolation

### US12-7-ERGO: Utiliser un thème du catalogue dans Ergo-Mate
**En tant qu'** enseignant
**Je veux** affecter un thème validé à une classe
**Afin de** le mettre à disposition des élèves

**Critères d'acceptation:**
- ✅ Sélection depuis le catalogue
- ✅ Affectation via /assignments
- ✅ Ergo-Mate reçoit le thème (vérifié)

---

## 🔗 Intégration Ergo-Mate

### Endpoint Ergo-Mate

**POST** `/ergo/api/v1/themes/push`

Reçoit les thèmes du catalogue Orchestrator et les importe dans Ergo-Mate.

**Payload:**
```json
{
  "tenant_id": "tenant_123",
  "theme_id": "cat_abc123",
  "class_ids": ["class_1", "class_2"],
  "theme": {
    "title": "Chimie organique - Alcools",
    "difficulty": "intermediate",
    "questions": [...],
    "flashcards": [...],
    "fiche": {...}
  },
  "metadata": {
    "title": "Chimie organique - Alcools",
    "description": "...",
    "author": "user_456",
    "version": "v2.1"
  }
}
```

**Réponse:**
```json
{
  "success": true,
  "message": "Theme successfully imported to Ergo-Mate",
  "data": {
    "ergo_theme_id": "ergo_theme_xyz789",
    "catalog_theme_id": "cat_abc123",
    "classes_assigned": 2,
    "questions_imported": 25,
    "flashcards_imported": 50,
    "fiche_imported": true
  }
}
```

---

## 🧪 Tests

### Tests manuels

1. **Créer un thème en draft**
2. **Proposer pour validation** (enseignant)
3. **Valider le thème** (référent)
4. **Publier au catalogue** (direction)
5. **Affecter à une classe** (enseignant)
6. **Vérifier dans Ergo-Mate** que le thème est bien importé

### Tests automatisés (TODO)

- Tests unitaires: WorkflowManager.php
- Tests d'intégration: API catalog.php
- Tests E2E: Workflow complet de validation

---

## 📝 Notes d'alignement (Addenda)

- **Security:** UrlEncoded par défaut + JWT compatible ✅
- **Tenant:** inclure tenant_id en form-urlencoded; header X-Orchestrator-Id optionnel ✅
- **OpenAPI:** étendre le YAML unique ✅ (openapi-sprint12-catalog.yaml)
- **Realtime:** fallback polling 15–30s si WebSocket indisponible ⏳ (futur)
- **Observabilité:** journaliser chaque appel dans sync_logs ✅ (via logInfo/logError)
- **Front:** nomenclature ErgoMate; Chart.js local si graphes ✅

---

## 🚀 Prochaines étapes (Sprint 13+)

- [ ] Système de commentaires sur les thèmes
- [ ] Co-édition en temps réel (collaborateurs)
- [ ] Suggestions automatiques de thèmes similaires (ML)
- [ ] Export vers formats standards (SCORM, IMS QTI)
- [ ] Analytics: thèmes les plus utilisés/appréciés

---

## 📚 Références

- [OpenAPI Sprint 12](../orchestrator/docs/openapi-sprint12-catalog.yaml)
- [Migration SQL](../orchestrator/migrations/sprint12_catalog.sql)
- [Schema ErgoMate Theme](../orchestrator/docs/schema/ergomate_theme.schema.json)
- [RBAC Middleware](../orchestrator/api/_middleware_rbac.php)

---

**Développé par:** Claude AI
**Date de livraison:** 2025-11-13
