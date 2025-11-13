# Sprint 8: Social & Collaborative Learning

**Date**: 2025-11-13
**Version**: 1.8.0
**Statut**: ✅ Complet

---

## 📋 Vue d'ensemble

Le Sprint 8 introduit des fonctionnalités sociales et collaboratives pour enrichir l'expérience d'apprentissage et favoriser l'entraide entre élèves.

### Epics implémentés

- **E8-LB**: Classements / Leaderboards
- **E8-SHARE**: Partage de contenus créés par élèves
- **E8-PEER**: Feedback entre pairs / Commentaires
- **E8-COLLAB**: Révision collective temps réel
- **E8-MOD**: Modération (IA + Enseignant)

---

## 🗃️ Schéma de base de données

### Nouvelles tables

#### Classements
- `leaderboard_settings` - Paramètres de classement par tenant
- `leaderboard_entries` - Entrées de classement (calculées périodiquement)

#### Partage de contenus
- `shared_content` - Contenus partagés par les élèves

#### Feedback pairs
- `peer_comments` - Commentaires sur contenus partagés (avec threads)

#### Sessions collaboratives
- `collaborative_sessions` - Sessions de révision synchronisées
- `collaborative_session_participants` - Participants des sessions

#### Modération
- `moderation_queue` - File d'attente de modération
- `moderation_actions` - Historique des actions de modération

### Installation

```bash
# Importer le schéma Sprint 8
mysql -u username -p database_name < orchestrator/sql/sprint8_social.sql
```

---

## 🔌 Nouveaux endpoints API

### E8-LB: Classements

```
GET /api/social/leaderboard
  ?theme_id=...&class_id=...&period=weekly|monthly|all_time&anonymize=true|false

GET /api/social/leaderboard/settings
POST /api/social/leaderboard/settings
```

### E8-SHARE: Partage de contenus

```
POST /api/social/content/share
  Body: { student_id, content_type, title, content, is_public, ... }

GET /api/social/content/shared
  ?theme_id=...&class_id=...&content_type=...

GET /api/social/content/shared/{id}
```

### E8-PEER: Commentaires

```
POST /api/social/comments
  Body: { student_id, shared_content_id, parent_comment_id, comment_text }

GET /api/social/content/{id}/comments
```

### E8-COLLAB: Sessions collaboratives

```
POST /api/social/sessions/collaborative
  Body: { creator_student_id, theme_id, title, max_participants, ... }

POST /api/social/sessions/collaborative/join
  Body: { session_code, student_id }

GET /api/social/sessions/collaborative/{id}
POST /api/social/sessions/collaborative/{id}/start
```

### E8-MOD: Modération

```
GET /api/social/moderation/queue
  ?status=pending|in_review|approved|rejected

POST /api/social/moderation/queue/{id}/approve
POST /api/social/moderation/queue/{id}/reject
```

### Temps réel

```
GET /realtime/collaborative/polling
  ?session_id=...&student_id=...&last_poll=...

POST /realtime/session_update
  Body: { session_id, student_id, update_type, ... }
```

Documentation complète : `orchestrator/docs/openapi-orchestrator.yaml`

---

## 🎨 Interface utilisateur

### Nouveaux composants

**Fichier**: `public/js/view/view-social.js`

#### Fonctions principales

- `renderLeaderboard(containerId, options)` - Afficher le classement
- `renderSharedContent(containerId, options)` - Afficher contenus partagés
- `viewSharedContent(contentId)` - Voir un contenu avec commentaires
- `createCollaborativeSession()` - Créer une session collaborative
- `joinCollaborativeSession(sessionId)` - Rejoindre une session
- `renderModerationQueue(containerId)` - File de modération (enseignants)

#### Intégration dashboard

Le dashboard principal (`view-dashboard.js`) inclut maintenant une section sociale avec onglets :

**Pour les élèves** :
- 🏆 Classement
- 📚 Contenus partagés
- 👥 Sessions collectives

**Pour les enseignants** :
- 🏆 Classement
- 📚 Contenus partagés
- ⚖️ Modération

---

## ⚡ Système temps réel

### Polling HTTP (Fallback)

Le système utilise un **polling HTTP** avec intervalle de 15-30 secondes comme fallback pour la compatibilité avec l'hébergement mutualisé.

**Endpoints** :
- `GET /realtime/collaborative/polling` - Récupérer l'état de la session
- `POST /realtime/session_update` - Envoyer une mise à jour

### Types de mises à jour

- `ready` - Marquer comme prêt
- `answer` - Soumettre une réponse
- `score` - Mettre à jour le score
- `leave` - Quitter la session

### Optimisations

- Long-polling supporté via paramètre `last_poll` (timestamp)
- Retourne seulement les changements depuis le dernier poll
- Calcul automatique du score collectif
- Détection des nouveaux participants

---

## 🔒 Sécurité & Modération

### Modération automatique (IA)

Tous les contenus partagés passent par une **file de modération** :

1. **Détection IA** (simulée) - Génère un score de suspicion
2. **Flags automatiques** - Stockés en JSON dans `ai_flags`
3. **Priorité** - low, medium, high, critical
4. **Validation enseignant** - Approuver ou rejeter

### Statuts de contenu

- `pending` - En attente de modération
- `approved` - Approuvé par enseignant
- `rejected` - Rejeté par enseignant
- `flagged` - Signalé par IA ou élèves

### Permissions

- **Élèves** : Partager, commenter, participer
- **Enseignants** : Modération complète (approuver/rejeter)
- **Admin/Direction** : Modération + paramètres

---

## 📊 Fonctionnalités détaillées

### E8-LB: Classements

**Caractéristiques** :
- Classements par **thème** ou **global**
- Périodes : **hebdo**, **mensuel**, **all-time**
- **Anonymisation** optionnelle (paramètre ON/OFF)
- Calcul basé sur : score total, sessions complétées, maîtrise moyenne
- Médailles 🥇🥈🥉 pour les 3 premiers

**Calcul du rang** :
```sql
RANK() OVER (PARTITION BY tenant_id, period_type, theme_id ORDER BY total_score DESC)
```

### E8-SHARE: Partage de contenus

**Types de contenus** :
- `flashcard` - Cartes mémoire
- `note` - Notes de cours
- `summary` - Résumés
- `mnemo` - Moyens mnémotechniques
- `quiz` - Quiz créés par élèves

**Visibilité** :
- Public (tout le tenant)
- Limité à une classe (`target_class_id`)

**Métriques** :
- Vues (`views_count`)
- J'aime (`likes_count`)

### E8-PEER: Commentaires

**Fonctionnalités** :
- Commentaires sur contenus partagés
- **Threads** (réponses via `parent_comment_id`)
- Marquage "utile" (`is_helpful`, `helpful_count`)
- Modération automatique des commentaires

### E8-COLLAB: Sessions collaboratives

**Types de sessions** :
- `quiz_battle` - Quiz compétitif
- `flashcard_review` - Révision flashcards
- `study_group` - Groupe d'étude

**Workflow** :
1. Un élève **crée** la session → Code généré (8 caractères)
2. Partage le **code** avec ses camarades
3. Les participants **rejoignent** avec le code
4. Tous se marquent **prêt**
5. Le créateur **démarre** la session
6. Score **collectif** calculé en temps réel

**Paramètres** :
- `max_participants` (défaut: 10)
- `duration_minutes` (défaut: 30)
- `questions` - Questions communes (JSON)

### E8-MOD: Modération

**File d'attente** :
- Tri par **priorité** + date
- **Assignment** aux enseignants
- Historique des actions (`moderation_actions`)

**Actions** :
- `approve` - Approuver
- `reject` - Rejeter
- `flag` - Signaler
- `edit` - Modifier
- `delete` - Supprimer

**IA Score** :
- 0-1 (suspicion)
- Basé sur analyse de contenu (à implémenter)
- Flags : language, spam, inappropriate, etc.

---

## 🎯 User Stories complétées

### US8-1: Classement par thème ✅

**En tant qu'** élève
**Je veux** voir ma position dans le classement
**Afin de** me motiver à progresser

**Critères** :
- [x] Classements hebdo + all-time
- [x] Filtre par thème
- [x] Anonymisation ON/OFF

### US8-4: Révision collective temps réel ✅

**En tant qu'** élève
**Je veux** rejoindre une session synchronisée
**Afin d'** apprendre en groupe

**Critères** :
- [x] Code session
- [x] Chrono + questions communes
- [x] Score collectif

---

## 🚀 Installation & Déploiement

### 1. Importer le schéma SQL

```bash
mysql -u your_user -p your_database < orchestrator/sql/sprint8_social.sql
```

### 2. Vérifier les permissions

```bash
chmod 755 orchestrator/api/social.php
chmod 755 orchestrator/realtime/
```

### 3. Tester les endpoints

```bash
# Health check
curl https://smso.mehdydriouech.fr/api/health

# Test leaderboard (nécessite auth)
curl https://smso.mehdydriouech.fr/api/social/leaderboard?period=monthly \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Orchestrator-Id: TENANT_INST_PARIS"
```

### 4. Charger le module JS

Ajouter dans `index.html` :

```html
<script src="/js/view/view-social.js"></script>
```

---

## 📝 Configuration

### Paramètres de classement

Créer des paramètres par défaut via SQL :

```sql
INSERT INTO leaderboard_settings (id, tenant_id, period_type, anonymize_enabled)
VALUES ('LB_DEFAULT', 'TENANT_INST_PARIS', 'monthly', FALSE);
```

### Polling interval

Modifier dans `view-social.js` :

```javascript
// Polling toutes les 15 secondes (défaut)
pollingInterval = setInterval(poll, 15000);

// Ou 30 secondes pour réduire la charge
pollingInterval = setInterval(poll, 30000);
```

---

## 🧪 Tests

### Test de création de session

```javascript
// Dans la console du navigateur
createCollaborativeSession()
// Suivre les prompts pour titre et thème
```

### Test de modération

```bash
# Se connecter en tant qu'enseignant
# Aller dans l'onglet "Modération"
# Vérifier la file d'attente
```

### Test de classement

```bash
# Générer des données de test
curl -X POST https://smso.mehdydriouech.fr/api/social/leaderboard/test-populate \
  -H "Authorization: Bearer ADMIN_TOKEN"
```

---

## 📊 Observabilité

### Logs

Tous les appels API sociaux sont journalisés dans `sync_logs` :

```sql
SELECT * FROM sync_logs
WHERE type IN ('leaderboard', 'shared_content', 'collaborative_session')
ORDER BY created_at DESC
LIMIT 50;
```

### Métriques

Requêtes fréquentes pour monitoring :

```sql
-- Sessions actives
SELECT COUNT(*) FROM collaborative_sessions
WHERE status IN ('waiting', 'active');

-- Contenus en modération
SELECT COUNT(*) FROM moderation_queue
WHERE status = 'pending';

-- Contenus partagés cette semaine
SELECT COUNT(*) FROM shared_content
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);
```

---

## 🔄 Prochaines étapes

### Sprint 9 (suggestions)

- [ ] Notifications push pour sessions collaboratives
- [ ] Système de badges sociaux
- [ ] Statistiques sociales avancées
- [ ] Intégration IA pour modération avancée
- [ ] Export/import de contenus partagés
- [ ] API publique pour partenaires

### Améliorations

- [ ] WebSocket natif (si hébergement le permet)
- [ ] Compression des réponses API
- [ ] Cache Redis pour classements
- [ ] Search full-text sur contenus partagés
- [ ] Système de réputation élèves

---

## 📚 Ressources

- **OpenAPI**: `orchestrator/docs/openapi-orchestrator.yaml`
- **Schéma SQL**: `orchestrator/sql/sprint8_social.sql`
- **API Backend**: `orchestrator/api/social.php`
- **Temps réel**: `orchestrator/realtime/collaborative_polling.php`
- **UI Frontend**: `public/js/view/view-social.js`

---

## 🤝 Support

Pour toute question sur le Sprint 8 :

- **Développeur**: Mehdy Driouech
- **Email**: contact@mehdydriouech.fr
- **Documentation**: Ce fichier + OpenAPI

---

**Version**: 1.8.0
**Sprint**: 8 - Social & Collaborative Learning
**Statut**: ✅ Production Ready
