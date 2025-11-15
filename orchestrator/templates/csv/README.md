# CSV Import Templates - Sprint 20

Ce répertoire contient les templates CSV pour l'import massif de données lors de l'onboarding d'un établissement.

## Templates disponibles

### 1. `template_students.csv` - Import d'élèves

**Colonnes requises :**
- `firstname` : Prénom de l'élève
- `lastname` : Nom de famille
- `email_scolaire` : Email scolaire (doit être unique)
- `class_name` : Nom de la classe (doit exister)
- `promo_name` : Nom de la promotion (doit exister)

**Colonnes optionnelles :**
- `uuid_scolaire` : UUID ErgoMate de l'élève (généré automatiquement si absent)
- `consent_sharing` : Consentement partage de données (true/false, défaut: false)

### 2. `template_teachers.csv` - Import d'enseignants

**Colonnes requises :**
- `firstname` : Prénom
- `lastname` : Nom
- `email` : Email professionnel (doit être unique)
- `role` : Rôle (`admin`, `direction`, `teacher`, `inspector`, `referent`, `intervenant`)

**Colonnes optionnelles :**
- `class_names` : Classes assignées, séparées par virgules (ex: "6emeA,6emeB")

**Note :** Un mot de passe temporaire est généré automatiquement et envoyé par email.

### 3. `template_classes.csv` - Import de classes

**Colonnes requises :**
- `name` : Nom de la classe (ex: "6emeA")
- `promo_name` : Nom de la promotion associée (doit exister)

**Colonnes optionnelles :**
- `description` : Description de la classe
- `teacher_email` : Email de l'enseignant principal (doit exister)

### 4. `template_promotions.csv` - Import de promotions

**Colonnes requises :**
- `name` : Nom de la promotion (ex: "2024-2025")
- `year_start` : Année de début (ex: 2024)
- `year_end` : Année de fin (ex: 2025)

**Colonnes optionnelles :**
- `level` : Niveau scolaire (ex: "6eme", "5eme", "L1", "M2")

## Ordre d'import recommandé

Pour un import complet lors de l'onboarding, respectez cet ordre :

1. **Promotions** (`template_promotions.csv`) - Créer les années scolaires
2. **Classes** (`template_classes.csv`) - Créer les classes (nécessite promotions)
3. **Enseignants** (`template_teachers.csv`) - Créer les utilisateurs enseignants
4. **Élèves** (`template_students.csv`) - Créer les élèves (nécessite classes)

## Format CSV

- **Encodage :** UTF-8
- **Séparateur :** Virgule (`,`)
- **Délimiteur de texte :** Guillemets doubles (`"`) pour les champs contenant des virgules
- **Taille max :** 10 MB par fichier
- **Ligne d'en-tête :** Obligatoire (première ligne du fichier)

## Utilisation via l'API

### 1. Télécharger un template

```bash
GET /api/admin/import/template/{type}
```

Types disponibles : `students`, `teachers`, `classes`, `promotions`

### 2. Upload du CSV rempli

```bash
POST /api/admin/import/upload
Content-Type: multipart/form-data

file: [fichier CSV]
import_type: students|teachers|classes|promotions
```

### 3. Valider le CSV

```bash
POST /api/admin/import/validate/{job_id}
```

Retourne un rapport détaillé avec les erreurs ligne par ligne.

### 4. Exécuter l'import

```bash
POST /api/admin/import/execute/{job_id}
```

### 5. Vérifier le statut

```bash
GET /api/admin/import/status/{job_id}
```

## Gestion des erreurs

Le système valide :
- Format des emails
- Existence des entités référencées (classes, promotions)
- Doublons (emails, noms de classes)
- Types de données (années, booléens)
- Contraintes de taille et format

**Les lignes en erreur sont ignorées.** Un rapport détaillé est fourni avec :
- Numéro de ligne
- Nature de l'erreur
- Valeur problématique

## Quotas et limites

Les imports respectent automatiquement les quotas de licences de l'établissement :
- Nombre maximum d'enseignants
- Nombre maximum d'élèves
- Nombre maximum de classes

Si un quota est atteint, l'import s'arrête et retourne une erreur.

## Exemple de workflow complet

```bash
# 1. Télécharger les templates
curl -O http://localhost/api/admin/import/template/promotions
curl -O http://localhost/api/admin/import/template/classes
curl -O http://localhost/api/admin/import/template/teachers
curl -O http://localhost/api/admin/import/template/students

# 2. Remplir les templates avec vos données

# 3. Upload promotions
curl -X POST http://localhost/api/admin/import/upload \
  -F "file=@promotions.csv" \
  -F "import_type=promotions"
# => { "job_id": "import_xxx" }

# 4. Valider
curl -X POST http://localhost/api/admin/import/validate/import_xxx

# 5. Exécuter
curl -X POST http://localhost/api/admin/import/execute/import_xxx

# 6. Répéter pour classes, teachers, students
```

## Support

En cas de problème avec l'import CSV :
1. Vérifiez l'encodage UTF-8
2. Consultez le rapport de validation
3. Assurez-vous que les dépendances existent (promotions avant classes, etc.)
4. Vérifiez les quotas de licences

Pour toute assistance : support@studymate.fr
