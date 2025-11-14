# Sprint 17 : Mode Démo Global

## Vue d'ensemble

Le **Mode Démo** permet de tester l'ensemble de l'application StudyMate School Orchestrator **sans installation backend**, sans base de données, et sans authentification réelle. Toutes les données sont mockées et les appels API sont interceptés localement.

## Objectifs

- ✅ Permettre aux visiteurs de découvrir le produit en 2 minutes
- ✅ Tester tous les écrans avec des données fictives crédibles
- ✅ Aucune installation requise (ni base de données, ni backend)
- ✅ Parcours guidé interactif
- ✅ Compatible Orchestrator + ErgoMate

## Architecture

### 1. Configuration

**Fichier** : `orchestrator/config.php`

```php
define('DEMO_MODE', true); // Activer/désactiver le mode démo
```

L'API `/orchestrator/api/config.php` expose cette configuration au frontend.

### 2. FakeRouter

**Fichier** : `public/js/demo/FakeRouter.js`

Le `FakeRouter` est un système d'interception des appels API :
- Intercepte `fetch()` et `XMLHttpRequest`
- Redirige les appels API vers des fichiers JSON mock
- Simule des délais réseau (100-300ms)
- Log toutes les requêtes pour debug

**Endpoints interceptés** :
- `/api/config` → Configuration de l'app
- `/api/auth/login` → Authentification factice
- `/api/dashboard/summary` → Statistiques du dashboard
- `/api/students` → Liste des élèves
- `/api/classes` → Liste des classes
- `/api/assignments` → Affectations
- `/api/analytics/teacher_kpi` → KPI enseignants
- `/api/analytics/risk` → Détection élèves à risque
- `/api/themes` → Catalogue de thèmes
- `/api/catalog` → Catalogue global
- `/api/quality` → Qualité des contenus
- `/api/ai` → Gouvernance IA
- `/api/telemetry/stats` → Télémétrie

### 3. Données Mock

**Emplacement** : `public/js/demo/mock/*.json`

Fichiers créés :
- `dashboard.json` : KPI, activité récente, performances des classes
- `classes.json` : 4 classes (CM1-A, CM1-B, CM2-A, CM2-B)
- `students.json` : 6 élèves avec statistiques réalistes
- `assignments.json` : 6 affectations (quiz, flashcards)
- `teacher_kpi.json` : KPI enseignant détaillés
- `student_risk.json` : Détection des élèves en difficulté
- `themes.json` : 5 thèmes pédagogiques
- `catalog.json` : Catalogue avec catégories
- `quality.json` : Analyse qualité des contenus
- `ai_governance.json` : Budget IA, audit, conformité RGPD
- `telemetry.json` : Statistiques d'usage

### 4. Interface utilisateur

#### Bouton "Découvrir la démo"

- Visible uniquement si `DEMO_MODE=true`
- Affiché sur la page de login
- Style attractif avec dégradé violet

#### Bandeau Mode Démo

- Affiché en haut de l'écran en mode démo
- Couleur orange avec lien "Quitter la démo"
- Position sticky (toujours visible)

#### Parcours guidé

**Fichier** : `public/js/demo/demo_tour.js`

Classe `DemoTour` avec 7 étapes :
1. Bienvenue
2. Dashboard enseignant
3. Suivi des élèves
4. Gestion des affectations
5. Synchronisation ErgoMate
6. Analytics & Qualité
7. Fin du parcours

Fonctionnalités :
- Navigation : Suivant / Précédent / Passer
- Mise en surbrillance des éléments
- Overlay semi-transparent
- Mémorisation (ne se relance pas si déjà complété)

### 5. Gestion de session

**LocalStorage** :
- `DEMO_SESSION=true` : Indique que le mode démo est actif
- `authToken` : Token fictif (`demo-token-{timestamp}`)
- `currentUser` : Utilisateur démo avec permissions complètes
- `DEMO_TOUR_COMPLETED` : Parcours guidé terminé

## Utilisation

### Activer le mode démo

1. **Configuration** : Vérifier que `DEMO_MODE=true` dans `orchestrator/config.php`
2. **Accès** : Ouvrir la page de login
3. **Activation** : Cliquer sur "Découvrir la démo"

### Navigation en mode démo

Une fois le mode démo activé :
- Tous les écrans sont accessibles
- Toutes les données affichées sont fictives
- Les appels API sont interceptés par le FakeRouter
- Le bandeau orange rappelle que vous êtes en mode démo

### Quitter le mode démo

- Cliquer sur "Quitter la démo" dans le bandeau orange
- Ou utiliser le bouton "Déconnexion"
- Le localStorage est nettoyé
- La page est rechargée

## API Mock

### Exemple : Dashboard

**Endpoint** : `/api/dashboard/summary`

**Réponse mock** :
```json
{
  "success": true,
  "data": {
    "kpis": {
      "totalStudents": 156,
      "activeStudents": 142,
      "avgScore": 78.5,
      "avgMastery": 0.72
    },
    "recentActivity": [...],
    "classPerformance": [...],
    "topPerformers": [...]
  }
}
```

### Ajouter un nouvel endpoint mock

1. Créer le fichier JSON dans `public/js/demo/mock/`
2. Ajouter le mapping dans `FakeRouter.js` :

```javascript
if (endpoint.startsWith('/api/mon-endpoint')) {
    return this.loadMockFile('mon_fichier.json');
}
```

## Styles CSS

**Fichier** : `public/assets/demo-styles.css`

Classes disponibles :
- `.demo-banner` : Bandeau en haut
- `.demo-btn` : Bouton "Découvrir la démo"
- `.divider` : Séparateur entre login et démo
- `.demo-badge` : Badge "DÉMO" pour les cartes
- `.demo-watermark` : Watermark sur les graphiques
- `.demo-highlight` : Animation pulse

## Sécurité

- ⚠️ Le mode démo **NE DOIT PAS** être activé en production
- ⚠️ Aucune donnée réelle n'est exposée
- ✅ Le FakeRouter n'intercepte que les appels API locaux
- ✅ Les données mock sont statiques et anonymes
- ✅ Pas de collecte de données utilisateur

## Limitations

- Pas de sauvegarde (toutes les modifications sont perdues au rechargement)
- Pas de synchronisation réelle avec ErgoMate
- Les graphiques sont statiques
- Pas d'envoi d'emails
- Pas de génération IA (réponses pre-mockées)

## Tests

### Checklist de validation

- [ ] Le bouton "Découvrir la démo" apparaît sur la page login
- [ ] Cliquer active le mode démo sans erreur
- [ ] Le bandeau orange s'affiche
- [ ] Le dashboard affiche les données mock
- [ ] La page "Élèves" affiche les 4 classes
- [ ] Sélectionner une classe affiche les élèves
- [ ] Les affectations s'affichent correctement
- [ ] Le parcours guidé se lance et fonctionne
- [ ] "Quitter la démo" recharge la page normale
- [ ] Le FakeRouter logue les requêtes dans la console

### Debug

Vérifier dans la console :
```javascript
// Est-on en mode démo ?
window.isDemoMode()

// Voir les requêtes interceptées
window.fakeRouter.getRequestLog()

// Relancer le tour guidé
DemoTour.reset()
window.demoTour.start()
```

## Évolutions futures

- [ ] Mode démo pour ErgoMate (côté élève)
- [ ] Personnalisation des données mock
- [ ] Export des données démo
- [ ] Mode "sandbox" avec sauvegarde temporaire
- [ ] Traduction multilingue du parcours guidé
- [ ] Analytics sur l'usage du mode démo

## Contributeurs

- **Sprint 17** : Mode Démo Global
- **Développeur** : Claude (Anthropic)
- **Date** : Novembre 2025
- **Version** : 1.0

---

**Documentation complète** : Voir `orchestrator/docs/openapi-orchestrator.yaml` pour les spécifications API.
