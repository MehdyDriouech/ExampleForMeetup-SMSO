# Mode Démo - StudyMate School Orchestrator

## 🎯 Quick Start

### Activer le mode démo

1. Ouvrir la page de login
2. Cliquer sur "🎯 Découvrir la démo"
3. Explorer l'application avec des données fictives

### Désactiver le mode démo globalement

Éditer `orchestrator/config.php` :
```php
define('DEMO_MODE', false); // Le bouton disparaîtra
```

---

## 📁 Structure

```
public/js/demo/
├── FakeRouter.js         # Intercepteur d'appels API
├── demo_tour.js          # Parcours guidé interactif
├── mock/                 # Données fictives
│   ├── dashboard.json
│   ├── students.json
│   ├── classes.json
│   ├── assignments.json
│   ├── teacher_kpi.json
│   ├── student_risk.json
│   ├── themes.json
│   ├── catalog.json
│   ├── quality.json
│   ├── ai_governance.json
│   └── telemetry.json
└── README.md            # Ce fichier
```

---

## 🔧 Comment ça marche ?

### 1. FakeRouter

Le `FakeRouter` intercepte tous les appels `fetch()` et `XMLHttpRequest` qui ciblent `/api/*`.

**Exemple** :
```javascript
// L'application fait :
fetch('/api/dashboard/summary')

// Le FakeRouter intercepte et retourne :
fetch('/js/demo/mock/dashboard.json')
```

### 2. Données Mock

Chaque fichier JSON contient des données fictives crédibles :

- **156 élèves** répartis dans 4 classes
- **48 affectations** (quiz, flashcards)
- **24 thèmes** pédagogiques
- KPI enseignants, analytics, qualité, IA

### 3. Session Demo

Quand l'utilisateur clique sur "Découvrir la démo" :

```javascript
localStorage.setItem('DEMO_SESSION', 'true')
window.fakeRouter.enable()
authToken = 'demo-token-...'
currentUser = { role: 'teacher', ... }
```

---

## 🛠️ Ajouter un endpoint mock

### Étape 1 : Créer le fichier JSON

`public/js/demo/mock/mon_endpoint.json` :
```json
{
  "success": true,
  "data": {
    "message": "Hello from mock!"
  }
}
```

### Étape 2 : Mapper dans FakeRouter.js

Dans `getMockResponse()` :
```javascript
if (endpoint.startsWith('/api/mon-endpoint')) {
    return this.loadMockFile('mon_endpoint.json');
}
```

### Étape 3 : Tester

```javascript
// En mode démo :
fetch('/api/mon-endpoint').then(r => r.json()).then(console.log)
// → { success: true, data: { message: "Hello from mock!" } }
```

---

## 🎨 Personnaliser le parcours guidé

Éditer `demo_tour.js` :

```javascript
this.steps = [
    {
        title: "Ma nouvelle étape",
        content: "Description...",
        target: "#mon-element",  // Élément à mettre en surbrillance
        action: () => window.navigateTo('ma-vue')
    },
    // ... autres étapes
]
```

---

## 🐛 Debug

### Vérifier si le mode démo est actif

```javascript
window.isDemoMode()
// → true ou false
```

### Voir les requêtes interceptées

```javascript
window.fakeRouter.getRequestLog()
// → [{ timestamp, method, url, body }, ...]
```

### Relancer le parcours guidé

```javascript
DemoTour.reset()
window.demoTour.start()
```

### Désactiver temporairement l'interception

```javascript
window.fakeRouter.disable()
// ... faire des tests ...
window.fakeRouter.enable()
```

---

## ⚠️ Limitations

- Pas de sauvegarde (rechargement = perte des modifications)
- Graphiques statiques
- Pas d'envoi d'emails
- Pas de génération IA réelle
- Pas de synchronisation ErgoMate réelle

---

## 📚 Documentation complète

Voir `docs/SPRINT_17_DEMO_MODE.md` pour :
- Architecture détaillée
- Tous les endpoints mockés
- Spécifications des fichiers JSON
- Tests et validation
- Évolutions futures

---

## 🚀 Production

**⚠️ IMPORTANT** : Ne JAMAIS activer le mode démo en production !

```php
// Production :
define('DEMO_MODE', false);

// Développement / Démo :
define('DEMO_MODE', true);
```

---

**Version** : 1.0 (Sprint 17)
**Dernière mise à jour** : 2025-11-14
