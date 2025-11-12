# 🚀 Guide d'Installation Rapide - Study-mate School Orchestrator

## ⚡ Installation en 10 minutes

### Étape 1 : Préparation (2 min)

1. **Télécharger le ZIP** et extraire sur votre ordinateur
2. **Créer une base MySQL** via cPanel ou phpMyAdmin
3. **Noter les identifiants** : host, nom_db, user, password

### Étape 2 : Base de données (3 min)

**Via phpMyAdmin** :
1. Sélectionner votre base
2. Onglet "Importer"
3. Sélectionner `orchestrator/sql/schema.sql`
4. Cliquer "Exécuter"
5. Répéter avec `orchestrator/sql/seeds.sql` (données de test)

**Via ligne de commande** :
```bash
mysql -u USERNAME -p NOM_BASE < orchestrator/sql/schema.sql
mysql -u USERNAME -p NOM_BASE < orchestrator/sql/seeds.sql
```

### Étape 3 : Configuration (2 min)

1. **Éditer** `orchestrator/.env.php` :

```php
// Base de données
define('DB_HOST', 'localhost');      // Ou l'hôte fourni
define('DB_NAME', 'VOTRE_BASE');
define('DB_USER', 'VOTRE_USER');
define('DB_PASS', 'VOTRE_PASSWORD');

// Sécurité - CHANGER ABSOLUMENT !
define('JWT_SECRET', '<?php echo bin2hex(random_bytes(32)); ?>');
define('ADMIN_KEY', '<?php echo bin2hex(random_bytes(16)); ?>');
```

2. **Générer des clés sécurisées** :

```bash
# JWT Secret (256 bits)
php -r "echo bin2hex(random_bytes(32));"

# Admin Key
php -r "echo bin2hex(random_bytes(16));"
```

3. **Modifier les URLs** (production) :

```php
define('BASE_URL', 'https://smso.mehdydriouech.fr');
define('ERGOMATE_BASE_URL', 'https://ergo-mate.mehdydriouech.fr');
```

### Étape 4 : Upload (2 min)

**Via FTP/SFTP** (FileZilla, WinSCP, etc.) :

1. Connectez-vous à votre hébergeur
2. Naviguez vers `public_html/` ou `www/`
3. Uploadez **TOUT** le contenu du dossier extrait
4. Vérifiez que `.htaccess` est présent à la racine

**Structure finale** :
```
public_html/
├── orchestrator/
│   ├── .env.php       ✅ Configuré
│   ├── api/
│   ├── lib/
│   └── ...
├── public/
│   ├── index.html
│   └── ...
├── .htaccess          ✅ Présent
└── README.md
```

### Étape 5 : Test (1 min)

1. **Health check** :
   ```
   https://votre-domaine.fr/api/health
   ```
   
   Attendu : `{"status":"ok", ...}`

2. **Test DB** :
   ```
   https://votre-domaine.fr/api/health?check=db
   ```
   
   Attendu : `{"database":{"status":"ok", ...}}`

3. **Login** :
   ```
   https://votre-domaine.fr
   ```
   - Email : `claire.dubois@ife-paris.fr`
   - Password : `Ergo2025!`

---

## ✅ Checklist Post-Installation

- [ ] Base de données importée sans erreur
- [ ] `.env.php` configuré avec les bonnes credentials
- [ ] `JWT_SECRET` et `ADMIN_KEY` changés
- [ ] `.htaccess` présent à la racine
- [ ] Health check répond `ok`
- [ ] Test DB répond `ok`
- [ ] Login fonctionne
- [ ] Dashboard accessible

---

## 🔧 Dépannage Rapide

### Erreur 500
- Vérifier `orchestrator/.env.php` (DB credentials)
- Vérifier permissions : `chmod 755 orchestrator/api/`
- Consulter logs : `orchestrator/logs/app.log`

### Erreur 404 sur /api/*
- Vérifier que `.htaccess` est bien uploadé
- Vérifier que mod_rewrite est activé (demander à l'hébergeur)

### Erreur de connexion DB
- Vérifier credentials dans `.env.php`
- Tester : `php -r "new PDO('mysql:host=HOST;dbname=DB', 'USER', 'PASS');"`

### Login échoue
- Vérifier que les seeds sont bien importés
- Vérifier `JWT_SECRET` dans `.env.php`
- Voir les logs : `orchestrator/logs/app.log`

---

## 🔒 Sécurité Production

**Avant de passer en production** :

```php
// Dans orchestrator/.env.php

// 1. Désactiver le debug
define('APP_DEBUG', false);

// 2. Désactiver le mode mock
define('ERGOMATE_MOCK_MODE', false);

// 3. Vérifier les CORS
define('CORS_ALLOWED_ORIGINS', [
    'https://smso.mehdydriouech.fr',
    'https://ergo-mate.mehdydriouech.fr'
]);

// 4. Changer TOUTES les clés
define('JWT_SECRET', 'NOUVELLE_CLE_256_BITS');
define('ADMIN_KEY', 'NOUVELLE_CLE_ADMIN');

// 5. Changer les API_KEYS
$GLOBALS['API_KEYS'] = [
    'teacher' => 'NOUVELLE_CLE_TEACHER',
    'inspector' => 'NOUVELLE_CLE_INSPECTOR',
    'director' => 'NOUVELLE_CLE_DIRECTOR',
    'admin' => 'NOUVELLE_CLE_ADMIN'
];
```

### Forcer HTTPS

Décommenter dans `.htaccess` :
```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

## 📞 Support

Si problème, consulter :
1. `README.md` (documentation complète)
2. `orchestrator/logs/app.log` (logs applicatifs)
3. Logs Apache de votre hébergeur
4. Contact : contact@mehdydriouech.fr

---

**Installation complétée ! 🎉**  
Consultez le `README.md` pour aller plus loin.
