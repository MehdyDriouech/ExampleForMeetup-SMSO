# Mode Démo Global - Sprint 20B

## Vue d'ensemble

Le Mode Démo Global permet de démontrer toutes les fonctionnalités de StudyMate Orchestrator **sans backend réel**, en utilisant des données mock réalistes.

### Sprints supportés

- ✅ **Sprint 17** : Mode démo de base (Dashboard, Students, Assignments, Sync)
- ✅ **Sprint 18** : Curriculum Builder - Parcours pédagogiques personnalisés
- ✅ **Sprint 19** : Workflow Multi-acteurs - Validation collaborative & annotations
- ✅ **Sprint 20** : Tenant Onboarding - Configuration d'établissements & import CSV

## Activation du mode démo

### Méthode 1 : Via localStorage (recommandée)

```javascript
// Activer le mode démo
localStorage.setItem('DEMO_SESSION', 'true');
window.location.reload();

// Désactiver le mode démo
localStorage.removeItem('DEMO_SESSION');
window.location.reload();
```

### Méthode 2 : Via l'interface

1. Accéder à la page de connexion
2. Cliquer sur "Essayer le mode démo"
3. Le bandeau orange apparaît en haut de l'écran
4. Cliquer sur "Quitter le mode démo" pour sortir

## Architecture

### Composants principaux

```
public/js/demo/
├── FakeRouter.js          # Intercepteur d'API (fetch & XMLHttpRequest)
├── demo_tour.js           # Parcours guidé interactif
├── DemoBadge.js           # Badges "DEMO DATA" sur les écrans
├── README_DEMO_MODE.md    # Cette documentation
└── mock/                  # Données mock JSON
    ├── mock_curriculum.json
    ├── mock_curriculum_sequences.json
    ├── mock_student_path.json
    ├── mock_theme_versions.json
    ├── mock_annotations.json
    ├── mock_tenant_config.json
    ├── mock_import_preview.json
    └── mock_import_apply.json
```

### FakeRouter.js

**Rôle** : Intercepte tous les appels API et retourne des données mock

**Fonctionnalités** :
- Interception de `window.fetch` et `XMLHttpRequest`
- Simulation de délais réseau (100-300ms)
- Logs détaillés en mode debug
- Fallback universel pour endpoints non mockés
- Support des méthodes GET, POST, PATCH, PUT, DELETE

**Activation automatique** :
```javascript
if (localStorage.getItem('DEMO_SESSION') === 'true') {
    window.fakeRouter.enable();
}
```

**API publique** :
```javascript
// Activer/désactiver
window.fakeRouter.enable();
window.fakeRouter.disable();

// Mode debug
window.fakeRouter.setDebugMode(true);
window.fakeRouter.setDebugMode(false);

// Logs
console.log(window.fakeRouter.getRequestLog());
window.fakeRouter.clearLog();
```

### Endpoints mockés

#### Sprint 18 - Curriculum Builder

| Méthode | Endpoint | Mock File | Description |
|---------|----------|-----------|-------------|
| GET | `/api/curriculum` | `mock_curriculum.json` | Liste des curriculums |
| GET | `/api/curriculum/:id` | `mock_curriculum_sequences.json` | Détails + séquences |
| GET | `/api/curriculum/student/:uuid` | `mock_student_path.json` | Parcours élève |
| POST | `/api/curriculum` | - | Création (mock success) |
| PATCH | `/api/curriculum/sequence/:id/link-assignment` | - | Lier assignment |

#### Sprint 19 - Workflow Multi-acteurs

| Méthode | Endpoint | Mock File | Description |
|---------|----------|-----------|-------------|
| PATCH | `/api/themes/:id/status` | - | Changer statut thème |
| GET | `/api/annotations/:theme_id` | `mock_annotations.json` | Liste annotations |
| POST | `/api/annotations` | - | Créer annotation |
| GET | `/api/themes/:id/versions` | `mock_theme_versions.json` | Historique versions |
| POST | `/api/themes/:id/version/rollback` | - | Restaurer version |

#### Sprint 20 - Tenant Onboarding

| Méthode | Endpoint | Mock File | Description |
|---------|----------|-----------|-------------|
| POST | `/api/admin/tenant/create` | - | Créer tenant |
| PATCH | `/api/admin/tenant/:id/config` | - | Config tenant |
| POST | `/api/admin/tenant/import-preview` | `mock_import_preview.json` | Prévisualiser CSV |
| POST | `/api/admin/tenant/import-apply` | `mock_import_apply.json` | Importer CSV |

### Demo Tour

**Parcours guidé interactif** qui fait découvrir les fonctionnalités principales.

**Étapes** :
1. Bienvenue
2. Dashboard Enseignant
3. Suivi des élèves
4. Gestion des affectations
5. Synchronisation ErgoMate
6. **Curriculum Builder** (Sprint 18)
7. **Workflow Multi-acteurs** (Sprint 19)
8. **Onboarding Tenant** (Sprint 20)
9. Analytics & Qualité
10. Fin du tour

**API** :
```javascript
// Démarrer le tour
window.demoTour.start();

// Navigation
window.demoTour.next();
window.demoTour.previous();
window.demoTour.skip();

// Réinitialiser
DemoTour.reset();
```

### DemoBadge

**Composant** pour afficher des badges "DEMO DATA" sur les écrans.

**API** :
```javascript
// Ajouter un badge
window.demoBadge.addBadge('curriculum-content', {
    text: 'CURRICULUM DÉMO',
    position: 'top-right',
    size: 'small',
    color: '#f59e0b'
});

// Supprimer un badge
window.demoBadge.removeBadge('curriculum-content');

// Badge automatiques
window.demoBadge.autoAddBadges();

// Bandeau global
window.demoBadge.showGlobalBanner();
```

**Positions** : `top-right`, `top-left`, `bottom-right`, `bottom-left`, `inline`
**Tailles** : `small`, `medium`, `large`
**Styles** : `badge`, `banner`, `corner`

## Utilisation

### 1. Activer le mode démo

```javascript
localStorage.setItem('DEMO_SESSION', 'true');
location.reload();
```

### 2. Vérifier l'activation

- Un bandeau **orange** apparaît en haut : "MODE DÉMONSTRATION"
- Les badges **"DEMO DATA"** s'affichent sur les nouveaux écrans
- La console affiche : `[FakeRouter] Mode démo activé`

### 3. Naviguer dans l'application

Toutes les requêtes API sont interceptées automatiquement :

```javascript
// Cette requête sera interceptée
fetch('/api/curriculum')
    .then(res => res.json())
    .then(data => console.log(data));
// Retourne mock_curriculum.json
```

### 4. Déboguer

```javascript
// Activer les logs détaillés
window.fakeRouter.setDebugMode(true);

// Voir toutes les requêtes interceptées
console.table(window.fakeRouter.getRequestLog());
```

### 5. Désactiver le mode démo

```javascript
localStorage.removeItem('DEMO_SESSION');
location.reload();
```

## Données Mock

### Structure des fichiers mock

Tous les fichiers suivent cette structure :

```json
{
  "success": true,
  "data": { ... },
  "meta": {
    "demo_mode": true,
    "generated_at": "2025-11-14T17:30:00Z"
  }
}
```

### Personnalisation

Pour modifier les données mock :

1. Éditer le fichier JSON dans `public/js/demo/mock/`
2. Recharger la page (pas besoin de redémarrer le serveur)
3. Les nouvelles données apparaissent immédiatement

### Créer un nouveau mock

```javascript
// 1. Créer le fichier
// public/js/demo/mock/mon_nouveau_mock.json
{
  "success": true,
  "data": { "message": "Hello" },
  "meta": { "demo_mode": true }
}

// 2. Ajouter le mapping dans FakeRouter.js
if (endpoint.startsWith('/api/mon-endpoint')) {
    return this.loadMockFile('mon_nouveau_mock.json');
}
```

## Fallback Universel

Si un endpoint n'est **pas mocké**, le FakeRouter retourne automatiquement :

**Pour GET** :
```json
{
  "success": true,
  "data": [],
  "message": "Mock data for /api/endpoint",
  "demo_mode": true,
  "fallback": true
}
```

**Pour POST/PATCH/PUT/DELETE** :
```json
{
  "success": true,
  "message": "Opération effectuée (mode démo)",
  "data": {
    "demo_mode": true,
    "fallback": true
  }
}
```

## Logs et observabilité

### Console du navigateur

```javascript
// Tous les appels interceptés sont loggés
[FakeRouter] Intercepté: GET /api/curriculum
[FakeRouter DEBUG] Creating new curriculum (mock) {...}
```

### Inspecter les requêtes

```javascript
// Obtenir toutes les requêtes
const requests = window.fakeRouter.getRequestLog();
console.table(requests);

// Structure d'une requête loggée
{
  timestamp: "2025-11-14T17:30:00.123Z",
  method: "GET",
  url: "/api/curriculum",
  body: null
}
```

## Tests

### Test manuel

1. Activer le mode démo
2. Ouvrir la console du navigateur
3. Naviguer vers chaque écran :
   - `/curriculum` → Curriculum Builder
   - `/theme-validation` → Workflow
   - `/admin-onboarding` → Onboarding
4. Vérifier :
   - ✅ Pas d'erreurs JS
   - ✅ Données affichées
   - ✅ Badges "DEMO DATA" présents
   - ✅ Logs FakeRouter dans la console

### Test des endpoints

```javascript
// Test d'un endpoint
async function testEndpoint(url) {
    const response = await fetch(url);
    const data = await response.json();
    console.log('✅ Success:', data.success);
    console.log('📦 Data:', data.data);
    console.log('🎭 Demo mode:', data.meta?.demo_mode);
}

// Tests
await testEndpoint('/api/curriculum');
await testEndpoint('/api/themes/123/versions');
await testEndpoint('/api/admin/tenant/import-preview');
```

## Bonnes pratiques

### Pour les développeurs

1. ✅ **Toujours** utiliser `window.fakeRouter` (singleton)
2. ✅ **Toujours** inclure `demo_mode: true` dans les mocks
3. ✅ **Toujours** retourner `success: true/false`
4. ✅ Simuler des délais réalistes (100-300ms)
5. ✅ Logger les mutations (POST/PATCH/DELETE) en mode debug

### Pour les démos commerciales

1. ✅ Activer le tour guidé au premier lancement
2. ✅ Afficher le bandeau global
3. ✅ Utiliser des données **réalistes** et **cohérentes**
4. ✅ Prévoir des cas d'erreur (import CSV avec erreurs)
5. ✅ Désactiver le mode debug en production

## Dépannage

### Problème : Le mode démo ne s'active pas

```javascript
// Vérifier
console.log(localStorage.getItem('DEMO_SESSION')); // doit être 'true'
console.log(window.fakeRouter.enabled); // doit être true

// Forcer l'activation
window.fakeRouter.enable();
```

### Problème : Certains endpoints ne sont pas interceptés

```javascript
// Activer le mode debug
window.fakeRouter.setDebugMode(true);

// Vérifier les logs
// Si vous voyez "⚠️ Endpoint non mocké", ajouter le mapping
```

### Problème : Erreur lors du chargement d'un mock

```javascript
// Vérifier que le fichier existe
fetch('/js/demo/mock/mon_fichier.json')
    .then(r => r.ok ? '✅ OK' : '❌ 404')
    .then(console.log);

// Vérifier la syntaxe JSON
// Utiliser https://jsonlint.com/
```

### Problème : Les badges ne s'affichent pas

```javascript
// Vérifier l'activation
console.log(window.demoBadge.isActive()); // doit être true

// Forcer l'ajout
window.demoBadge.addBadge('mon-element-id', {
    text: 'TEST',
    position: 'inline'
});
```

## Évolution future

### Sprint 21+ : Nouvelles fonctionnalités

Pour ajouter le support d'un nouveau sprint :

1. **Créer les mocks** dans `public/js/demo/mock/`
2. **Ajouter les mappings** dans `FakeRouter.js` (section dédiée)
3. **Étendre le tour** dans `demo_tour.js`
4. **Documenter** dans `openapi-sprintXX-demo.yaml`

### Exemple

```javascript
// FakeRouter.js
// ========================================
// SPRINT 21 - MA NOUVELLE FEATURE
// ========================================
if (endpoint === '/api/ma-feature' && method === 'GET') {
    return this.loadMockFile('mock_ma_feature.json');
}
```

## Support

Pour toute question ou bug :
- 📚 Documentation complète : `orchestrator/docs/openapi-sprint20b-demo.yaml`
- 🐛 Rapporter un bug : Issues GitHub
- 💬 Slack : #studymate-demo

---

**Version** : Sprint 20B - v2.0.0
**Date** : 2025-11-14
**Auteur** : Équipe StudyMate
