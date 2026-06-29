# Guide d'Installation - ADCreation

## 📋 Pré-requis

- **PHP 7.4+**
- **Composer** (gestionnaire de paquets PHP)
- **Serveur LDAP/Active Directory** accessible
- **Serveur SMTP** pour l'envoi d'emails
- **Serveur Web Apache** avec `mod_rewrite` activé
- **Extension PHP**: `ldap`, `curl`, `zip`, `xml`

## 🚀 Installation Rapide

### Étape 1: Télécharger le Projet

```bash
# Option 1: Cloner depuis Git (si disponible)
git clone https://votre-repo/adcreation.git

# Option 2: Copier les fichiers
cp -r adcreation /var/www/html/
cd /var/www/html/adcreation
```

### Étape 2: Installer les Dépendances PHP

```bash
# Installer Composer si nécessaire
curl -sS https://getcomposer.org/installer | php

# Installer les dépendances
composer install
```

### Étape 3: Configurer l'Application

```bash
# Copier le fichier de configuration d'exemple
cp config.example.php config.php

# Éditer la configuration
nano config.php
```

#### Configuration LDAP/AD
Dans `config.php`, remplir les paramètres LDAP:
```php
define('LDAP_SERVER_IP', 'votre.serveur.ad');
define('LDAP_BASE_DN', 'DC=ad,DC=votre,DC=domaine');
define('LDAP_ADMIN_USER', 'CN=administrateur,CN=Users,' . LDAP_BASE_DN);
define('LDAP_ADMIN_PASSWORD', 'mot_de_passe');
```

#### Configuration Email
```php
define('MAIL_HOST', 'mail.votre.domaine');
define('MAIL_PORT', 25);
define('MAIL_FROM', 'noreply@votre.domaine');
```

### Étape 4: Créer les Répertoires Nécessaires

```bash
mkdir -p logs documents exports cache
chmod 755 logs documents exports cache
```

### Étape 5: Configurer le Serveur Web Apache

#### Option A: VirtualHost

Créer un fichier `/etc/apache2/sites-available/adcreation.conf`:
```apache
<VirtualHost *:80>
    ServerName adcreation.votre.domaine
    ServerAlias www.adcreation.votre.domaine
    
    DocumentRoot /var/www/html/adcreation
    
    <Directory /var/www/html/adcreation>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    # Logs
    ErrorLog ${APACHE_LOG_DIR}/adcreation-error.log
    CustomLog ${APACHE_LOG_DIR}/adcreation-access.log combined
</VirtualHost>
```

Activer le site:
```bash
sudo a2ensite adcreation
sudo a2enmod rewrite
sudo systemctl reload apache2
```

#### Option B: Sous-répertoire

Si vous devez installer dans un sous-répertoire:
```bash
mv adcreation /var/www/html/
```

Accéder via: `http://localhost/adcreation`

### Étape 6: Vérifier la Configuration LDAP

```bash
php ldap_test.php
```

Cela affichera:
- ✓ Connexion réussie
- ✓ Utilisateurs trouvés
- ✗ Erreurs détaillées

### Étape 7: Tester l'Application

1. Ouvrir dans un navigateur: `http://localhost/adcreation`
2. Vérifier que la page d'accueil s'affiche
3. Tester la création d'un compte
4. Vérifier les logs d'activité

## 🔧 Configuration Avancée

### Base de Données (Optionnel)

Si vous souhaitez stocker les historiques dans une base de données:

1. Créer la base de données:
```bash
mysql -u root -p -e "CREATE DATABASE adcreation;"
```

2. Importer le schéma SQL (si disponible):
```bash
mysql -u root -p adcreation < schema.sql
```

3. Configurer dans `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'utilisateur');
define('DB_PASSWORD', 'mot_de_passe');
define('DB_NAME', 'adcreation');
```

### Auron XML Integration

Pour importer depuis Aurion:

1. Vérifier l'accès au serveur Aurion
2. Obtenir l'ID du favori pour les nouveaux apprenants
3. Configurer dans `config.php`:
```php
define('AURION_SERVER', 'http://votre.serveur.aurion');
define('AURION_FAVORITE_NEW_STUDENTS', 'ID_FAVORI');
```

### Email SMTP Avancé

Pour utiliser SMTP avec authentification:

Modifier `EnvoiMail.php`:
```php
$mail->SMTPAuth = true;
$mail->Username = 'votre_email@domaine';
$mail->Password = 'votre_password';
$mail->SMTPSecure = 'tls'; // ou 'ssl'
```

## 🔐 Sécurité

### Points de Sécurité Importants

1. **Fichiers de Configuration**
   - Ne pas versionner `config.php`
   - Définir les permissions: `chmod 600 config.php`

2. **Répertoires Sensibles**
   - `.htaccess` protège les accès directs
   - Logs et documents ne sont pas accessibles via web

3. **Compte LDAP**
   - Utiliser un compte avec permissions minimales
   - Changer régulièrement le mot de passe

4. **HTTPS (Recommandé)**
   - Installer un certificat SSL
   - Activer HTTPS dans Apache
   - Rediriger HTTP vers HTTPS

### Configuration SSL (Let's Encrypt)

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d adcreation.votre.domaine
```

## 📦 Sauvegardes

### Stratégie de Sauvegarde

Créer un script `backup.sh`:
```bash
#!/bin/bash

# Répertoires à sauvegarder
BACKUP_DIR="/backups/adcreation"
APP_DIR="/var/www/html/adcreation"

# Créer le répertoire de sauvegarde
mkdir -p $BACKUP_DIR

# Sauvegarder les logs et documents
tar -czf $BACKUP_DIR/adcreation_$(date +%Y%m%d_%H%M%S).tar.gz \
    $APP_DIR/logs \
    $APP_DIR/documents \
    $APP_DIR/config.php

# Nettoyer les anciennes sauvegardes (plus de 30 jours)
find $BACKUP_DIR -mtime +30 -delete
```

Planifier avec cron:
```bash
# Sauvegarder quotidiennement à 2h du matin
0 2 * * * /usr/local/bin/backup.sh
```

## 🚨 Dépannage

### Erreurs Communes

#### 1. Erreur: "LDAP Connection Failed"
- Vérifier l'adresse IP du serveur LDAP
- Vérifier le port (389 ou 636 pour LDAPS)
- Vérifier la connectivité réseau: `ping serveur.ldap`

#### 2. Erreur: "Class not found: PHPMailer"
- Réinstaller les dépendances: `composer install`
- Vérifier le fichier `vendor/autoload.php`

#### 3. Erreur: "Permission denied" sur les logs
- Vérifier les permissions: `ls -la logs/`
- Changer les permissions: `chmod 755 logs/`

#### 4. Template Twig non trouvé
- Vérifier que le dossier `templates/` existe
- Vérifier les permissions: `chmod 755 templates/`

### Scripts de Débogage

Exécuter les tests:
```bash
# Test LDAP
php ldap_test.php

# Vérifier les extensions PHP
php -m | grep -E 'ldap|curl|xml'

# Vérifier la configuration PHP
php -i | grep -E 'extension_dir|open_basedir'
```

## 📊 Maintenance

### Nettoyage Régulier

```bash
# Nettoyer les logs plus anciens que 30 jours
php maintenance.php clean-logs 30

# Exporter les logs en CSV
php maintenance.php export-logs

# Afficher les statistiques
php maintenance.php stats
```

### Mise à Jour

```bash
# Mettre à jour les dépendances
composer update

# Vérifier les mises à jour disponibles
composer outdated
```

## 📞 Support

En cas de problème:
1. Consulter les logs: `logs/logs.json`
2. Vérifier la configuration: `php ldap_test.php`
3. Activer le mode debug dans `config.php`: `APP_DEBUG = true`
4. Consulter la documentation

## ✅ Checklist de Déploiement

- [ ] PHP 7.4+ installé
- [ ] Extension LDAP activée
- [ ] Composer installé et dépendances installées
- [ ] Configuration LDAP correcte
- [ ] Test LDAP réussi (`php ldap_test.php`)
- [ ] Répertoires créés (logs, documents, exports)
- [ ] Permissions définies correctement
- [ ] Serveur web configuré
- [ ] VirtualHost Apache activé (ou alias créé)
- [ ] SSL/HTTPS configuré
- [ ] Sauvegarde planifiée
- [ ] Logs d'activité accessibles
- [ ] Page d'accueil accessible
- [ ] Création de compte testée
- [ ] Envoi d'email testé

## 📝 Fichiers Importants

```
adcreation/
├── config.php              ⚠️  À configurer
├── config.example.php      📖 Exemple de configuration
├── composer.json           📦 Dépendances
├── .htaccess              🔐 Sécurité Apache
├── ldap_test.php          🧪 Test LDAP
├── maintenance.php        🔧 Scripts de maintenance
├── README.md              📚 Documentation
└── INSTALL.md             👈 Ce fichier
```

---

**Date de création:** 2024
**Version:** 2.0
**Dernière mise à jour:** 2024-06-11
