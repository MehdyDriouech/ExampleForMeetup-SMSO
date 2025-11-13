# Sprint 7 — Adaptive Learning & Personnalisation

**Version:** BMAD_SPRINT_7_REVISED
**Date:** 2025-11-10
**Durée:** 2 semaines
**Objectif:** Recommandations IA, difficulté adaptative, mode focus, validation enseignant, détection fatigue

---

## 📋 Vue d'ensemble

Le Sprint 7 introduit un système complet d'apprentissage adaptatif et personnalisé basé sur l'IA. Les fonctionnalités permettent d'optimiser l'expérience d'apprentissage de chaque élève en s'adaptant à son niveau, son rythme et ses besoins spécifiques.

---

## 🎯 Epics

### E7-RECO : Recommandations IA
**Objectif:** Contenus personnalisés basés sur l'historique et les performances

**Fonctionnalités:**
- Moteur de recommandations multi-facteurs (performances, récence, difficulté, complétion)
- Widget "Pour toi" avec 3 suggestions personnalisées
- Explicabilité : chaque recommandation est justifiée
- Feedback loop : les élèves peuvent indiquer si une recommandation est pertinente
- API REST pour intégration

**Fichiers:**
- Backend: `orchestrator/lib/recommendations.php`
- API: `orchestrator/api/reco.php`
- Frontend: `public/js/features-reco.js`
- Dashboard: `public/js/view/view-dashboard.js` (intégré)

**Endpoints:**
- `GET /api/reco?studentId={id}` - Obtenir recommandations
- `POST /api/reco/feedback` - Enregistrer feedback

**Algorithme:**
```
Score = (Performance × 0.35) + (Difficulté × 0.30) + (Récence × 0.20) + (Complétion × 0.15)
```

---

### E7-DIFF : Difficulté Adaptative
**Objectif:** Niveaux dynamiques selon la Zone Proximale de Développement (ZPD)

**Fonctionnalités:**
- 3 niveaux adaptatifs : **Facile 🟢**, **Normal 🟡**, **Expert 🔴**
- Ajustement automatique basé sur :
  - Score moyen et maîtrise
  - Tendance des performances (improving/stable/declining)
  - Taux de réussite récent
- Modificateurs par niveau (temps, indices, nombre de questions)
- Badge de niveau visible dans l'interface
- Ajustement en temps réel pendant les sessions

**Fichiers:**
- Frontend: `public/js/features-difficulty.js`

**API (future):**
- `GET /api/adaptive/difficulty?studentId={id}&themeId={id}` - Niveau recommandé

**Règles d'ajustement:**
```javascript
if (avgScore >= 80 && mastery >= 0.75 && successRate >= 80) → expert
if (avgScore < 50 || mastery < 0.40 || successRate < 40) → easy
if (recentTrend === 'declining' && avgScore < 65) → easy
else → normal (ZPD optimal)
```

---

### E7-FOCUS : Mode Focus
**Objectif:** Mini-sessions ciblées de 5-10 minutes pour révision rapide

**Fonctionnalités:**
- **4 modes disponibles :**
  1. ⚡ **Révision éclair** : 5 questions en 5 minutes
  2. 🎯 **Focus Erreurs** : Rejouer les erreurs récentes
  3. 💪 **Boost Maîtrise** : Renforcer un concept spécifique
  4. 🌟 **Défi du jour** : Challenge quotidien adapté

- Configuration automatique selon le mode
- Timer intégré et objectifs de score
- Sauvegarde locale et sync avec le backend

**Fichiers:**
- Frontend: `public/js/features-focus.js`

**API (future):**
- `POST /api/focus/create` - Créer une session Focus

**Exemple de configuration:**
```json
{
  "mode": "error_focus",
  "duration": 10,
  "questionCount": 10,
  "config": {
    "showHints": true,
    "showExplanationsImmediately": true,
    "showPreviousAttempt": true
  }
}
```

---

### E7-HIL : Validation Enseignant (Human-in-the-Loop)
**Objectif:** Les enseignants valident/ajustent les décisions IA

**Fonctionnalités:**
- Interface de validation pour :
  - Recommandations IA
  - Ajustements de difficulté
  - Sessions Focus suggérées
  - Contenus générés par IA
- Workflow : pending → approved/rejected/modified
- Statistiques de validation
- Application automatique après approbation

**Fichiers:**
- Backend: `orchestrator/lib/teacher_validation.php`

**Endpoints:**
- `GET /api/teacher/validations` - Liste des validations
- `POST /api/teacher/validations` - Soumettre pour validation
- `POST /api/teacher/validations/{id}/approve` - Approuver
- `POST /api/teacher/validations/{id}/reject` - Rejeter

**Statuts:**
- `pending` : En attente
- `approved` : Approuvé sans modification
- `modified` : Approuvé avec modifications
- `rejected` : Rejeté

---

### E7-FAT : Détection de Fatigue
**Objectif:** Charge cognitive et recommandations de pause

**Fonctionnalités:**
- **Détection multi-critères :**
  - Temps de session total
  - Taux d'erreurs en hausse
  - Erreurs consécutives
  - Ralentissement des réponses
  - Temps depuis dernière pause

- **4 niveaux de fatigue :**
  - 😊 **Frais** (score < 25) : Continue !
  - 😐 **Légère fatigue** (25-44) : Attention
  - 😓 **Fatigue modérée** (45-69) : Pause recommandée
  - 😴 **Très fatigué** (70+) : Pause obligatoire

- **Actions adaptées :**
  - Suggestion de pause (légère)
  - Encouragement pause (modérée)
  - Pause forcée de 10 minutes (haute)

- Indicateur visuel permanent
- Timer de pause avec conseils

**Fichiers:**
- Frontend: `public/js/features-fatigue.js`

**Formule du score de fatigue (0-100) :**
```
Score = (Facteur temps × 30)
      + (Taux erreurs × 25)
      + (Erreurs consécutives × 20)
      + (Réponses lentes × 15)
      + (Temps depuis pause × 10)
```

---

## 🔧 Architecture Technique

### Backend (PHP)

```
orchestrator/
├── lib/
│   ├── recommendations.php      # Moteur de recommandations
│   └── teacher_validation.php   # Système de validation
└── api/
    └── reco.php                 # API recommandations
```

**Classes principales:**
- `RecommendationEngine` : Génération de recommandations personnalisées
- `TeacherValidationService` : Gestion du workflow de validation

### Frontend (JavaScript)

```
public/js/
├── features-reco.js         # Widget recommandations
├── features-difficulty.js   # Système adaptatif
├── features-focus.js        # Mode Focus
└── features-fatigue.js      # Détection fatigue
```

**Intégration:**
```javascript
// Dashboard élève
initDashboardView() {
  // ...
  if (currentUser.role === 'student') {
    promises.push(loadStudentRecommendations(currentUser.id));
  }
}

// Session d'apprentissage
const detector = initFatigueDetection();
const difficulty = await initAdaptiveDifficulty(studentId, themeId);
```

---

## 📊 Bases de données

### Tables créées/modifiées

```sql
-- Logs de recommandations
CREATE TABLE recommendation_logs (
  id VARCHAR(50) PRIMARY KEY,
  tenant_id VARCHAR(50),
  student_id VARCHAR(50),
  theme_id VARCHAR(50),
  recommendation_score FLOAT,
  reasons JSON,
  feedback ENUM('relevant', 'not_relevant', 'completed', 'too_hard', 'too_easy'),
  feedback_at DATETIME,
  created_at DATETIME
);

-- Validations enseignant
CREATE TABLE teacher_validations (
  id VARCHAR(50) PRIMARY KEY,
  tenant_id VARCHAR(50),
  teacher_id VARCHAR(50),
  validation_type ENUM('recommendation', 'difficulty_adjustment', 'focus_session', 'ai_content'),
  item_data JSON,
  metadata JSON,
  status ENUM('pending', 'approved', 'rejected', 'modified'),
  validated_by VARCHAR(50),
  validated_at DATETIME,
  modifications JSON,
  rejection_reason TEXT,
  created_at DATETIME,
  updated_at DATETIME
);

-- Préférences élèves
CREATE TABLE student_preferences (
  student_id VARCHAR(50),
  preference_key VARCHAR(100),
  preference_value TEXT,
  set_by ENUM('student', 'teacher', 'system'),
  created_at DATETIME,
  updated_at DATETIME,
  PRIMARY KEY (student_id, preference_key)
);
```

---

## 🚀 Utilisation

### Pour les élèves

**1. Voir les recommandations personnalisées**
```
Dashboard → Widget "Pour toi" → 3 suggestions avec explications
```

**2. Démarrer une session Focus**
```javascript
renderFocusModeSelector(studentId, 'focus-mode-selector');
// L'élève sélectionne un mode (⚡🎯💪🌟)
```

**3. Ajuster la difficulté**
```javascript
// Automatique ou manuel
showDifficultySelector();
selectDifficulty('expert');
```

### Pour les enseignants

**1. Valider des recommandations**
```
GET /api/teacher/validations?type=recommendation
POST /api/teacher/validations/{id}/approve
```

**2. Suivre les performances adaptatives**
```
Dashboard Analytics → Voir les ajustements de difficulté par élève
```

---

## 📈 Métriques & KPIs

### Recommandations
- Taux de clic sur recommandations
- Taux de feedback "pertinent"
- Taux de complétion après recommandation

### Difficulté Adaptative
- Distribution des niveaux par élève
- Temps passé à chaque niveau
- Impact sur les scores (avant/après ajustement)

### Mode Focus
- Sessions Focus par élève/jour
- Taux de complétion des sessions Focus
- Score moyen en mode Focus vs sessions normales

### Fatigue
- Fréquence des pauses obligatoires
- Corrélation fatigue ↔ erreurs
- Durée moyenne avant détection de fatigue

---

## 🔐 Sécurité & RBAC

### Permissions

| Resource | Student | Teacher | Direction | Admin |
|----------|---------|---------|-----------|-------|
| View own recommendations | ✅ | ❌ | ❌ | ❌ |
| View student recommendations | ❌ | ✅ (own students) | ✅ | ✅ |
| Provide feedback | ✅ | ❌ | ❌ | ❌ |
| Submit for validation | ❌ | ✅ | ✅ | ✅ |
| Validate items | ❌ | ✅ | ✅ | ✅ |
| Adjust difficulty manually | ✅ (own) | ✅ (students) | ✅ | ✅ |

### Isolation Tenant
Toutes les API incluent la vérification stricte du `tenant_id` via middleware.

---

## 🧪 Tests

### Tests manuels recommandés

**1. Recommandations**
```bash
# Générer recommandations pour un élève
curl -H "Authorization: Bearer $TOKEN" \
     -H "X-Orchestrator-Id: TENANT_ID" \
     "https://smso.mehdydriouech.fr/api/reco?studentId=STU_001"

# Feedback
curl -X POST -H "Authorization: Bearer $TOKEN" \
     -H "X-Orchestrator-Id: TENANT_ID" \
     -d '{"studentId":"STU_001","themeId":"THEME_001","feedback":"relevant"}' \
     "https://smso.mehdydriouech.fr/api/reco/feedback"
```

**2. Validation enseignant**
```bash
# Liste des validations
curl -H "Authorization: Bearer $TOKEN" \
     "https://smso.mehdydriouech.fr/api/teacher/validations?type=recommendation"

# Approuver
curl -X POST -H "Authorization: Bearer $TOKEN" \
     "https://smso.mehdydriouech.fr/api/teacher/validations/VAL_001/approve"
```

---

## 📝 TODO & Améliorations Futures

- [ ] Intégration complète des sessions Focus avec ErgoMate
- [ ] Machine Learning pour améliorer les recommandations
- [ ] Dashboard enseignant pour visualiser les patterns de fatigue
- [ ] A/B testing sur les stratégies de recommandation
- [ ] Export des données de fatigue pour recherche pédagogique
- [ ] Notifications push pour recommandations et pauses
- [ ] Mode "Challenge entre élèves" basé sur le système adaptatif

---

## 🤝 Contributeurs

- **Système de recommandations** : Algorithme multi-facteurs avec explicabilité
- **Difficulté adaptative** : Basé sur la recherche en ZPD (Vygotsky)
- **Détection de fatigue** : Inspiré des études en charge cognitive (Sweller, Paas)

---

## 📚 Références

- **Zone Proximale de Développement (ZPD)** : Vygotsky, L. (1978)
- **Courbe de l'oubli** : Ebbinghaus, H. (1885)
- **Charge cognitive** : Sweller, J. (1988)
- **Systèmes adaptatifs** : VanLehn, K. (2011) - The Relative Effectiveness of Human Tutoring, Intelligent Tutoring Systems, and Other Tutoring Systems

---

## ⚖️ Licence

Projet StudyMate School Orchestrator - Sprint 7
© 2025 - Mehdy Driouech

---

**🎉 Sprint 7 - Adaptive Learning Complet !**
