# Sprint 5 - Learning Cycle: Pédagogie côté élève

**Date**: 2025-11-13
**Version**: BMAD_SPRINT_5_REVISED
**Durée**: 2 semaines

---

## 📋 Vue d'ensemble

Ce sprint implémente la boucle pédagogique complète côté élève dans StudyMate School Orchestrator, en connexion avec ErgoMate. L'objectif est de permettre aux élèves de :

- 📥 Recevoir et suivre leurs missions
- 📊 Visualiser leur progression
- 🔄 Réviser leurs erreurs de manière ciblée
- 🏆 Gagner des badges de réussite
- 🔄 Synchroniser automatiquement leurs résultats

---

## ✨ Epics Implémentées

### E5-MISSIONS : Inbox missions côté élève
**Objectif** : Permettre aux élèves de voir et gérer leurs missions assignées par les professeurs.

**Endpoints créés** :
- `GET /api/student/missions/pull?student_id={id}` - Récupérer les missions d'un élève
- `PATCH /api/student/missions/{id}/status` - Mettre à jour le statut local (a_faire, en_cours, terminee)

**Frontend** :
- `public/js/view/view-student-missions.js` - Interface de gestion des missions

**Fonctionnalités** :
- Statut local des missions (à faire, en cours, terminée)
- Filtrage par statut
- Indicateur de retard
- Progression visuelle
- Actions contextuelles (démarrer, continuer, revoir)

---

### E5-SYNC : Synchronisation automatique
**Objectif** : Pousser automatiquement les résultats des élèves après chaque session ErgoMate.

**Endpoints créés** :
- `POST /api/student/sync/push` - Push des résultats d'une session

**Fonctionnalités** :
- Synchronisation automatique après session
- Retry/backoff en cas d'échec
- Journalisation dans sync_logs
- Mise à jour des stats élève
- Attribution automatique des badges

**Contrat API** :
```json
POST /api/student/sync/push
{
  "student_id": "STU_PARIS_001",
  "assignment_id": "assign_xxx",
  "session_data": {
    "score": 85,
    "time_spent": 450,
    "started_at": "2025-11-13T10:00:00Z",
    "ended_at": "2025-11-13T10:07:30Z",
    "errors": [...],
    "correct_answers": 17,
    "mastery": 0.85
  }
}
```

---

### E5-PROGRESS : Dashboard de progression élève
**Objectif** : Afficher un tableau de bord complet de progression avec KPIs et graphiques.

**Endpoints créés** :
- `GET /api/student/{id}/progress` - Dashboard complet de progression

**Frontend** :
- `public/js/view/view-student-progress.js` - Dashboard interactif avec Chart.js

**KPIs affichés** :
- Sessions totales
- Missions complétées
- Score moyen
- Maîtrise globale
- Temps total passé
- Activité des 7 derniers jours

**Graphiques** :
- 📈 **Score Trend** : Évolution du score dans le temps (line chart)
- ⏱️ **Time by Theme** : Temps passé par thème (bar chart)
- 🎯 **Mastery Radar** : Niveau de maîtrise par thème (radar chart)

**Analyses** :
- Points forts (maîtrise ≥ 80%)
- Points à améliorer (maîtrise < 60%)
- Activité récente

---

### E5-REVIEW : Révisions ciblées
**Objectif** : Permettre aux élèves de rejouer les questions où ils ont fait des erreurs.

**Endpoints créés** :
- `GET /api/student/{id}/review?theme_id={id}&limit={n}` - Items à réviser
- `POST /api/student/{id}/review/session` - Créer une session de révision

**Fonctionnalités** :
- Liste des erreurs par session
- Groupement par thème
- Recommandations basées sur :
  - Maîtrise faible (< 70%)
  - Erreurs fréquentes (≥ 3)
- Priorisation (high, medium, low)

---

### E5-BADGES : Système de badges
**Objectif** : Gamifier l'apprentissage avec des badges à débloquer.

**Endpoints créés** :
- `GET /api/student/{id}/badges` - Badges gagnés et disponibles

**Bibliothèque** :
- `orchestrator/lib/badges.php` - Service de gestion des badges

**Fonctionnalités** :
- Évaluation automatique des critères
- Attribution automatique après chaque session
- Progression vers chaque badge
- Badges par catégorie (débutant, progression, excellence, régularité, maîtrise)
- Badges par tier (bronze, silver, gold, platinum)

**Badges par défaut** :
- 🎯 **Premier Pas** (bronze) : Complétez votre première session
- 💪 **Persévérant** (bronze) : Complétez 10 sessions
- 📚 **Expert en Herbe** (silver) : Atteignez un score moyen de 80%
- ⭐ **Perfectionniste** (gold) : Obtenez 5 scores parfaits (100%)
- 🔥 **Régulier** (silver) : Travaillez 7 jours d'affilée
- 👑 **Maître** (gold) : Maîtrisez 5 thèmes (80%+)

**Types de critères** :
- `total_sessions` : Nombre de sessions complétées
- `avg_score` : Score moyen
- `mastery_threshold` : Niveau de maîtrise global
- `perfect_score_count` : Nombre de scores parfaits
- `consecutive_days` : Jours consécutifs d'activité
- `theme_mastery` : Nombre de thèmes maîtrisés
- `time_spent` : Temps total passé
- `streak_days` : Série de jours actifs

---

## 🗄️ Base de données

### Nouvelles tables créées

**`student_sessions`** : Suivi des sessions élèves sur les missions
```sql
- id, student_id, assignment_id, tenant_id
- status (a_faire, en_cours, terminee)
- score, time_spent, started_at, completed_at
- errors (JSON), correct_answers, metadata
```

**`badges`** : Définitions des badges
```sql
- id, tenant_id, name, description, icon
- category, tier (bronze, silver, gold, platinum)
- criteria (JSON)
```

**`student_badges`** : Badges gagnés par les élèves
```sql
- id, student_id, badge_id, tenant_id
- earned_at, metadata
```

**`review_sessions`** : Sessions de révision
```sql
- id, student_id, theme_id, tenant_id
- items (JSON), status, score, time_spent
- completed_at
```

**Colonnes ajoutées à `assignments`** :
- `received_count` : Nombre d'élèves ayant reçu
- `completed_count` : Nombre d'élèves ayant terminé
- `ergo_ack_at` : Dernière acknowledgement ErgoMate

---

## 📁 Structure des fichiers

### Backend (PHP)
```
orchestrator/
├── api/
│   └── student/
│       ├── missions.php       # E5-MISSIONS endpoints
│       ├── sync.php            # E5-SYNC endpoints
│       ├── progress.php        # E5-PROGRESS endpoints
│       ├── review.php          # E5-REVIEW endpoints
│       └── badges.php          # E5-BADGES endpoints
├── lib/
│   └── badges.php              # Service de gestion des badges
├── migrations/
│   └── sprint5_learning_cycle.sql  # Migration SQL
└── docs/
    └── openapi-orchestrator.yaml  # Documentation API mise à jour
```

### Frontend (JavaScript)
```
public/js/view/
├── view-student-missions.js   # Vue inbox missions
└── view-student-progress.js   # Vue dashboard progression
```

---

## 🔐 Sécurité et Authentification

### Multi-tenant
- Tous les endpoints vérifient l'isolation tenant via `enforceTenantIsolation()`
- Les élèves ne peuvent accéder qu'à leurs propres données
- Validation stricte des IDs élève et tenant

### Authentification
- Support JWT Bearer token
- Support UrlEncoded (api_key + tenant_id)
- Header `X-Orchestrator-Id` requis

### RBAC
- Permissions appropriées pour chaque endpoint
- Les élèves peuvent consulter leurs propres données
- Les professeurs peuvent consulter les données de leurs élèves

---

## 📊 Observabilité

### Logging
- Tous les appels API sont journalisés via `logger()->logRequest()`
- Les sync sont tracés dans `sync_logs`
- Les erreurs sont loggées avec contexte complet

### Retry/Backoff
- Le push de stats implémente un système de retry
- Les erreurs sont stockées dans `sync_logs` pour rejeu ultérieur
- Journalisation locale des échecs

---

## 🔄 Synchronisation ErgoMate

### Mode de fonctionnement
1. **Fin de session ErgoMate** :
   - Hook déclenché automatiquement
   - Collecte des données (score, temps, erreurs)
   - Appel `POST /api/student/sync/push`

2. **Côté Orchestrator** :
   - Validation des données
   - Mise à jour de `student_sessions`
   - Mise à jour des `stats` agrégées
   - Évaluation et attribution des badges
   - Journalisation dans `sync_logs`

3. **Fallback** :
   - Polling 15-30s si WebSocket indisponible
   - Retry avec backoff exponentiel (2s, 4s, 8s, 16s)
   - Stockage local pour rejeu

---

## 🎨 Frontend

### Technologies utilisées
- Vanilla JavaScript (pas de framework)
- Chart.js pour les graphiques (local)
- CSS Grid/Flexbox pour le layout
- Fetch API pour les appels AJAX

### Nomenclature ErgoMate
- Utilisation de la terminologie "missions" côté élève
- Interface simple et intuitive
- Design responsive
- Feedback visuel clair

---

## 🧪 Tests suggérés

### Tests backend
```bash
# Test pull missions
curl -X GET "http://localhost:8080/api/student/missions/pull?student_id=STU_PARIS_001" \
  -H "X-Orchestrator-Id: TENANT_INST_PARIS" \
  -H "Authorization: Bearer $TOKEN"

# Test push résultats
curl -X POST "http://localhost:8080/api/student/sync/push" \
  -H "Content-Type: application/json" \
  -H "X-Orchestrator-Id: TENANT_INST_PARIS" \
  -d '{"student_id":"STU_PARIS_001","assignment_id":"assign_xxx","session_data":{"score":85,"time_spent":450,"ended_at":"2025-11-13T10:00:00Z"}}'

# Test dashboard progression
curl -X GET "http://localhost:8080/api/student/STU_PARIS_001/progress" \
  -H "X-Orchestrator-Id: TENANT_INST_PARIS" \
  -H "Authorization: Bearer $TOKEN"

# Test badges
curl -X GET "http://localhost:8080/api/student/STU_PARIS_001/badges" \
  -H "X-Orchestrator-Id: TENANT_INST_PARIS" \
  -H "Authorization: Bearer $TOKEN"
```

### Tests frontend
1. Ouvrir la console développeur
2. Appeler `initStudentMissionsView('STU_PARIS_001')`
3. Appeler `initStudentProgressView('STU_PARIS_001')`
4. Vérifier les graphiques Chart.js
5. Tester les interactions (filtres, boutons)

---

## 📝 Migration en production

### Étapes d'installation

1. **Migration DB** :
```bash
mysql -u user -p database < orchestrator/migrations/sprint5_learning_cycle.sql
```

2. **Upload des fichiers** :
- Uploader tous les nouveaux fichiers PHP dans `orchestrator/api/student/`
- Uploader `orchestrator/lib/badges.php`
- Uploader les vues JS dans `public/js/view/`

3. **Vérification** :
```bash
# Health check
curl http://localhost:8080/api/health

# Test endpoint missions
curl http://localhost:8080/api/student/missions/pull?student_id=STU_PARIS_001
```

4. **Initialiser les badges** :
Les badges par défaut sont créés automatiquement via la migration SQL pour les tenants existants.

---

## 🔮 Prochaines étapes

### Améliorations possibles
- [ ] Notifications push pour nouvelles missions
- [ ] Statistiques comparatives (vs classe)
- [ ] Recommendations IA basées sur les erreurs
- [ ] Système de streaks avec rappels
- [ ] Leaderboard optionnel (avec consentement)
- [ ] Export PDF de la progression
- [ ] Intégration calendrier pour les missions
- [ ] Mode hors-ligne avec sync différée

### Optimisations
- [ ] Cache Redis pour les stats fréquemment consultées
- [ ] Agrégation périodique des stats (cron)
- [ ] Index DB optimisés pour les requêtes lourdes
- [ ] Pagination pour grandes listes de missions

---

## 📚 Documentation

### Documentation API complète
Voir `orchestrator/docs/openapi-orchestrator.yaml` - Section "Student" (Sprint 5)

### Documentation technique
- Tous les endpoints sont documentés avec PHPDoc
- Les fonctions JavaScript sont commentées
- Les schémas de données sont détaillés dans OpenAPI

---

## 🤝 Alignement avec l'addenda

### Sécurité ✅
- UrlEncoded par défaut (compatible hébergement mutualisé)
- JWT compatible
- Multi-tenant strict

### Tenant ✅
- `tenant_id` inclus en form-urlencoded
- Header `X-Orchestrator-Id` optionnel (requis pour JWT)

### OpenAPI ✅
- YAML unique étendu avec section "Student"
- Tous les endpoints documentés
- Schémas complets

### Realtime ✅
- Fallback polling 15-30s si WebSocket indisponible
- Retry avec backoff

### Observabilité ✅
- Chaque appel journalisé dans `sync_logs`
- Métriques de performance
- Traçabilité complète

### Front ✅
- Nomenclature ErgoMate
- Chart.js local (pas de CDN)
- Design responsive

---

## ✅ Checklist de complétion

### Backend
- [x] E5-MISSIONS endpoints (missions.php)
- [x] E5-SYNC endpoints (sync.php)
- [x] E5-PROGRESS endpoints (progress.php)
- [x] E5-REVIEW endpoints (review.php)
- [x] E5-BADGES endpoints (badges.php)
- [x] Badge service library (badges.php)
- [x] Migration SQL avec nouvelles tables
- [x] Badges par défaut

### Frontend
- [x] Vue missions (view-student-missions.js)
- [x] Vue progression (view-student-progress.js)
- [x] Intégration Chart.js
- [x] Filtres et interactions
- [x] Design responsive

### Documentation
- [x] OpenAPI étendu avec nouveaux endpoints
- [x] Schémas de données complets
- [x] Tag "Student" ajouté
- [x] Documentation Sprint 5

### Sécurité & Qualité
- [x] Isolation multi-tenant
- [x] Authentification JWT + UrlEncoded
- [x] RBAC permissions
- [x] Validation des entrées
- [x] Logging complet
- [x] Gestion des erreurs

---

**Sprint 5 complété** ✅
**Date de livraison** : 2025-11-13
**Prochaine version** : Sprint 6 (TBD)
