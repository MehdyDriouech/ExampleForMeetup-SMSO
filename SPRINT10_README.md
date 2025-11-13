# Sprint 10 - Teacher-AI Copilot

## 🎯 Vue d'ensemble

Le Sprint 10 introduit un **Assistant IA complet pour enseignants** permettant de :
- ✨ Générer automatiquement des quiz, flashcards et fiches depuis des PDF ou audios
- 📊 Analyser les difficultés de classe en temps réel
- 🤖 Recevoir des conseils pédagogiques personnalisés
- 📤 Publier directement vers Ergo-Mate (catalogue ou affectations)

## 📦 Composants Implémentés

### 1. Schéma JSON Ergo-Mate
**Fichier:** `docs/schema/ergomate_theme.schema.json`

Schéma de validation JSON complet compatible avec Ergo-Mate :
- Validation stricte des questions (min 2 choix, max 6)
- Support des flashcards avec images
- Fiches de révision structurées (max 20 sections)
- Métadonnées de génération IA

### 2. Migration SQL
**Fichier:** `orchestrator/sql/sprint10_ai_copilot.sql`

Nouvelles tables :
- `ai_coach_sessions` - Sessions de coaching pédagogique
- `ai_coach_messages` - Historique des conversations
- `class_insights` - Insights et analytics par classe
- `ergomate_publications` - Journal des publications
- `ai_content_extractions` - Tracking des extractions PDF/audio

Vues matérialisées :
- `v_class_difficulty_insights` - Top difficultés par classe
- `v_teacher_publications` - Historique des publications

Triggers :
- `trg_detect_class_difficulties` - Détection automatique des difficultés

### 3. Service d'Extraction de Contenu
**Fichier:** `orchestrator/lib/content_extractor.php`

Extraction de texte depuis :
- **PDF** : `pdftotext` (rapide) ou Tesseract OCR (pour PDF scannés)
- **Audio** : Whisper API (OpenAI) pour transcription

Fonctionnalités :
- Gestion automatique du fallback PDF → OCR
- Calcul de métadonnées (pages, durée, mots)
- Tracking complet dans `ai_content_extractions`

### 4. AIService Amélioré
**Fichier:** `orchestrator/lib/ai_service.php`

Améliorations :
- Validation stricte contre schéma Ergo-Mate
- Suggestions d'images automatiques (Unsplash, Pexels)
- Extraction de mots-clés pour illustrations
- Support des fiches illustrées

Validation étendue :
```php
$validator->validateTheme($data, $strictErgoMate = true);
```

### 5. APIs REST

#### 5.1 Ingest API
**Fichier:** `orchestrator/api/ingest.php`

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/api/ingest/upload` | POST | Upload PDF/audio et extraction automatique |
| `/api/ingest/generate` | POST | Générer contenu IA depuis une extraction |
| `/api/ingest/extractions` | GET | Liste des extractions |
| `/api/ingest/extractions/{id}` | GET | Détails d'une extraction |

**Exemple - Upload PDF :**
```bash
curl -X POST https://smso.mehdydriouech.fr/api/ingest/upload \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@cours.pdf"
```

**Réponse :**
```json
{
  "extraction_id": "extract_abc123",
  "source_type": "pdf",
  "text": "Texte extrait du PDF...",
  "metadata": {
    "page_count": 15,
    "character_count": 12543,
    "word_count": 2104
  },
  "processing_time_ms": 2341
}
```

**Exemple - Génération depuis extraction :**
```bash
curl -X POST https://smso.mehdydriouech.fr/api/ingest/generate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "extraction_id=extract_abc123" \
  -d "type=theme" \
  -d "difficulty=intermediate"
```

#### 5.2 Insights API
**Fichier:** `orchestrator/api/insights.php`

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/api/insights/class/{classId}` | GET | Tous les insights d'une classe |
| `/api/insights/difficulties` | GET | Top difficultés (élèves en échec) |
| `/api/insights/mark-read` | POST | Marquer un insight comme lu |
| `/api/insights/{id}` | DELETE | Supprimer un insight |

**Exemple - Top difficultés :**
```bash
curl "https://smso.mehdydriouech.fr/api/insights/difficulties?class_id=class_l1a&limit=5" \
  -H "Authorization: Bearer $TOKEN"
```

**Réponse :**
```json
{
  "class": {
    "id": "class_l1a",
    "name": "L1 Groupe A"
  },
  "difficulties": [
    {
      "theme_id": "theme_algo_001",
      "theme_title": "Algorithmique de base",
      "avg_success_rate": 42.5,
      "struggling_student_count": 12,
      "struggling_students": [
        {
          "id": "student_001",
          "firstname": "Jean",
          "lastname": "Dupont",
          "success_rate": 35.2
        }
      ]
    }
  ]
}
```

#### 5.3 Coach API
**Fichier:** `orchestrator/api/coach.php`

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/api/coach/session/start` | POST | Démarrer une session de coaching |
| `/api/coach/session/{id}/message` | POST | Envoyer un message au coach |
| `/api/coach/session/{id}` | GET | Récupérer une session |
| `/api/coach/sessions` | GET | Liste des sessions |
| `/api/coach/suggestions` | POST | Obtenir des suggestions |

**Exemple - Démarrer session :**
```bash
curl -X POST https://smso.mehdydriouech.fr/api/coach/session/start \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "context_type=class" \
  -d "context_id=class_l1a" \
  -d "goal=Améliorer performances en algorithmique"
```

**Exemple - Envoyer message :**
```bash
curl -X POST https://smso.mehdydriouech.fr/api/coach/session/{sessionId}/message \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "message=Quels exercices recommandes-tu pour les élèves en difficulté?"
```

#### 5.4 Publish API
**Fichier:** `orchestrator/api/publish.php`

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/api/publish/theme` | POST | Publier un thème vers Ergo-Mate |
| `/api/publish/acknowledge` | POST | Webhook accusé de réception |
| `/api/publish/publications` | GET | Liste des publications |
| `/api/publish/publications/{id}` | GET | Détails d'une publication |

**Exemple - Publication catalogue :**
```bash
curl -X POST https://smso.mehdydriouech.fr/api/publish/theme \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "theme_id=theme_001" \
  -d "generation_id=aigen_abc123" \
  -d "publication_type=catalog"
```

**Exemple - Publication avec affectation :**
```bash
curl -X POST https://smso.mehdydriouech.fr/api/publish/theme \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "theme_id=theme_001" \
  -d "publication_type=assignment" \
  -d "target_classes[]=class_l1a" \
  -d "target_classes[]=class_l1b"
```

**Réponse :**
```json
{
  "publication_id": "pub_xyz789",
  "status": "published",
  "ergomate_theme_id": "ergo_theme_456",
  "ergomate_assignment_id": "ergo_assign_789"
}
```

### 6. Interface Utilisateur
**Fichiers:**
- `orchestrator/ui/ai_creator.js` - Logique
- `orchestrator/ui/ai_creator.css` - Styles

Interface en 4 étapes :
1. **Upload** - Glisser-déposer ou sélectionner un fichier
2. **Extraction** - Visualisation du texte extrait
3. **Génération** - Configuration et génération IA
4. **Publication** - Choix catalogue/affectation et publication

**Intégration dans le dashboard :**
```html
<link rel="stylesheet" href="/orchestrator/ui/ai_creator.css">
<script src="/orchestrator/ui/ai_creator.js"></script>

<div id="aiCreatorContainer"></div>
<script>
  document.getElementById('aiCreatorContainer').innerHTML = aiCreator.render();
</script>
```

## 🔧 Installation

### 1. Prérequis Système

#### Pour extraction PDF :
```bash
# Ubuntu/Debian
sudo apt-get install poppler-utils tesseract-ocr tesseract-ocr-fra

# macOS
brew install poppler tesseract tesseract-lang
```

#### Pour extraction audio :
```bash
# FFmpeg (pour durée audio)
sudo apt-get install ffmpeg
```

### 2. Migration Base de Données

```bash
mysql -u root -p studymate < orchestrator/sql/sprint10_ai_copilot.sql
```

### 3. Configuration Clés API

Les enseignants doivent configurer leurs clés API :

**Mistral AI (génération de contenu) :**
```sql
INSERT INTO api_keys (id, tenant_id, user_id, provider, key_encrypted, status)
VALUES ('apikey_mistral_001', 'ife-paris', 'user_001', 'mistral', 'sk-...', 'active');
```

**OpenAI Whisper (transcription audio) :**
```sql
INSERT INTO api_keys (id, tenant_id, user_id, provider, key_encrypted, status)
VALUES ('apikey_openai_001', 'ife-paris', 'user_001', 'openai', 'sk-...', 'active');
```

### 4. Configuration Ergo-Mate

Variables d'environnement requises :
```bash
export ERGOMATE_API_URL="https://ergomate.fr/api/v1"
export ERGOMATE_API_KEY="your-ergomate-api-key"
```

## 📊 Métriques et Observabilité

Toutes les opérations sont journalisées dans :
- `sync_logs` - Journal des appels API
- `ai_generations` - Historique des générations
- `ai_content_extractions` - Historique des extractions
- `ergomate_publications` - Journal des publications

**Exemple - Requête d'audit :**
```sql
SELECT
    DATE(p.created_at) as date,
    COUNT(*) as total_publications,
    SUM(CASE WHEN p.status = 'published' THEN 1 ELSE 0 END) as successful,
    AVG(TIMESTAMPDIFF(SECOND, p.created_at, p.ack_received_at)) as avg_ack_delay_seconds
FROM ergomate_publications p
WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(p.created_at)
ORDER BY date DESC;
```

## 🔐 Sécurité

### RBAC - Permissions Sprint 10

| Ressource | Action | Admin | Direction | Teacher | Inspector |
|-----------|--------|-------|-----------|---------|-----------|
| ingest | upload | ✅ | ✅ | ✅ | ❌ |
| ingest | generate | ✅ | ✅ | ✅ | ❌ |
| insights | read | ✅ | ✅ | ✅ (own classes) | ✅ |
| coach | create_session | ✅ | ✅ | ✅ | ❌ |
| publish | catalog | ✅ | ✅ | ✅ | ❌ |
| publish | assignment | ✅ | ✅ | ✅ | ❌ |

### Isolation Multi-tenant

Tous les endpoints vérifient automatiquement :
- Appartenance des ressources au tenant
- Droits d'accès RBAC
- Rate limiting par tenant

### Validation Schéma

Le schéma Ergo-Mate est strictement validé avant publication :
- Types de champs
- Longueurs min/max
- Formats (IDs, URLs)
- Cohérence des données (correctAnswer dans range)

## 🧪 Tests

### Test Complet du Workflow

```bash
# 1. Upload et extraction PDF
EXTRACTION_ID=$(curl -X POST https://smso.mehdydriouech.fr/api/ingest/upload \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@test.pdf" | jq -r '.extraction_id')

# 2. Génération IA
THEME_ID=$(curl -X POST https://smso.mehdydriouech.fr/api/ingest/generate \
  -H "Authorization: Bearer $TOKEN" \
  -d "extraction_id=$EXTRACTION_ID" \
  -d "type=theme" \
  -d "difficulty=intermediate" | jq -r '.theme_id')

# 3. Publication Ergo-Mate
curl -X POST https://smso.mehdydriouech.fr/api/publish/theme \
  -H "Authorization: Bearer $TOKEN" \
  -d "theme_id=$THEME_ID" \
  -d "publication_type=catalog"
```

## 📈 Performance

### Temps de traitement moyens

| Opération | Temps moyen | Notes |
|-----------|-------------|-------|
| Extraction PDF (10 pages) | 2-3s | pdftotext |
| Extraction PDF OCR (10 pages) | 15-20s | Tesseract |
| Transcription audio (5 min) | 8-12s | Whisper API |
| Génération thème complet | 5-8s | Mistral Medium |
| Publication Ergo-Mate | 1-2s | HTTP + webhook |

### Optimisations

1. **Cache de génération** : 7 jours sur hash SHA256 du contenu source
2. **Vue matérialisée** : `v_class_difficulty_insights` pré-calculée
3. **Triggers** : Détection automatique des difficultés
4. **Index** : Sur tous les foreign keys et champs de recherche

## 🐛 Dépannage

### Erreur : "pdftotext not available"
```bash
sudo apt-get install poppler-utils
```

### Erreur : "Tesseract OCR not available"
```bash
sudo apt-get install tesseract-ocr tesseract-ocr-fra
```

### Erreur : "Mistral API key not configured"
Vérifier la présence de la clé dans `api_keys` :
```sql
SELECT * FROM api_keys WHERE provider = 'mistral' AND status = 'active';
```

### Erreur : "Theme is not Ergo-Mate compliant"
Consulter les erreurs de validation :
```json
{
  "error": "VALIDATION_ERROR",
  "validation_errors": [
    "Question 0: id must match pattern 'q[0-9]+'",
    "Fiche section 2: content must be between 10 and 5000 characters"
  ]
}
```

## 🎓 Cas d'usage

### Use Case 1 : Enseignant crée un quiz depuis un PDF de cours

1. Upload du PDF via l'interface
2. Extraction automatique (pdftotext)
3. Génération IA d'un thème complet (15 questions + 20 flashcards + fiche)
4. Validation manuelle dans l'interface
5. Publication vers catalogue Ergo-Mate
6. Les élèves accèdent au quiz dans Ergo-Mate

### Use Case 2 : Direction analyse les difficultés de toutes les classes

1. Accès aux insights via `/api/insights/difficulties`
2. Identification des top 5 thèmes difficiles par classe
3. Liste des élèves en difficulté par thème
4. Démarrage d'une session de coaching pour recommandations
5. Création d'actions correctives (révisions collectives, supports additionnels)

### Use Case 3 : Enseignant génère des fiches depuis un enregistrement audio

1. Upload d'un enregistrement audio de cours (M4A)
2. Transcription automatique via Whisper API
3. Génération de fiches de révision structurées
4. Enrichissement avec suggestions d'images (Unsplash)
5. Publication comme affectation ciblée (classes spécifiques)

## 📚 Ressources

- **Schéma JSON Ergo-Mate** : `docs/schema/ergomate_theme.schema.json`
- **OpenAPI Sprint 10** : `docs/openapi-sprint10-paths.yaml`
- **Migration SQL** : `orchestrator/sql/sprint10_ai_copilot.sql`
- **Guide Architecture** : `SPRINT10_ARCHITECTURE_OVERVIEW.md`

## 🚀 Prochaines Étapes (Sprint 11+)

- [ ] Support génération d'images IA (DALL-E, Stable Diffusion)
- [ ] Mode "batch" pour générer plusieurs thèmes en parallèle
- [ ] Analytics avancées du coach (recommandations ML)
- [ ] Export des insights en PDF/Excel
- [ ] Intégration avec calendrier scolaire (suggestions temporelles)

---

**Sprint 10 - Teacher-AI Copilot** - Développé le 2025-11-13
