# ADCreation - Gestion des Comptes Active Directory

Application web de gestion des comptes utilisateurs dans Active Directory pour ESIGELEC.

## Fonctionnalités

### 1. Créer un Compte Utilisateur
- Création de comptes individuels dans AD
- Génération automatique du login et du mot de passe
- Génération optionnelle de fiches utilisateur (DOCX)
- Envoi optionnel d'emails de bienvenue
- Gestion des groupes (CPII, Google Apps, Intervenants, IRSEEM)

### 2. Création en Masse
- Import depuis XML Aurion
- Import depuis fichier CSV
- Aperçu avant création
- Création batch avec gestion des erreurs
- Génération de documents en masse

### 3. Modifier un Compte
- Recherche de comptes existants
- Modification des informations (email, téléphone, description)
- Mise à jour en masse

### 4. Logs d'Activité
- Consultation des logs d'activité
- Filtrage par niveau (INFO, WARNING, ERROR)
- Statistiques d'utilisation

## Architecture

```
adcreation/
├── index.php                      # Page d'accueil
├── creercompte.php               # Création de compte unique
├── creationenmasse.php           # Création en masse
├── modifiercompte.php            # Modification de compte
├── logs.php                      # Consultation des logs
├── AdConnection.php               # Classe ADConnection (LDAP)
├── DBConfig.php                   # Classe DBConfig (Base de données)
├── Logging.php                   # Classe de logging
├── CompteUtilisateur.php         # Classe de gestion des comptes (salarié/stagiaire/vacataire...)
├── EnvoiMail.php                 # Classe d'envoi d'emails
├── LecteurXml.php                # Classe de lecture XML (Aurion)
├── GenereDOCX.php                # Classe de génération DOCX
├── LogUtils.php                  # Utilitaires de logging
├── maintenance.php               # Script de maintenance
├── composer.json                 # Dépendances PHP
├── templates/                    # Templates Twig
│   ├── base.html.twig
│   ├── index.html.twig
│   ├── creercompte.html.twig
│   ├── creationenmasse.html.twig
│   ├── modifiercompte.html.twig
│   └── logs.html.twig
├── css/                          # Feuilles de style
│   ├── style.css
│   └── stylecreercompte.css
├── logs/                         # Fichiers de logs
│   └── logs.json
└── documents/                    # Documents générés
```

## Installation

1. **Installer les dépendances PHP:**
```bash
cd adcreation
composer install
```

2. **Configurer la connexion LDAP/AD:**
   - Modifier `ADConfig` dans `config_ldap.php`
   - Paramètres à configurer:
     - `$SERVER_IP`: Adresse du serveur LDAP
     - `$BASE_DN`: Base DN du domaine
     - `$ADMIN_USER`: Compte administrateur LDAP
     - `$ADMIN_PASSWORD`: Mot de passe administrateur

3. **Configurer la connexion Base de Données (optionnelle):**
   - Modifier les constantes DB_* dans `config.php`

4. **Configurer l'envoi d'emails:**
   - Modifier les paramètres SMTP dans `EnvoiMail`
   - `$mailHost`: Serveur mail
   - `$mailFrom`: Adresse d'expédition

5. **Créer les répertoires nécessaires:**
```bash
mkdir -p logs documents exports
chmod 755 logs documents exports
```

6. **Configurer le serveur web:**
   - Pointer le document root vers le dossier `adcreation`
   - S'assurer que `mod_rewrite` est activé (pour `.htaccess`)

## Utilisation

### Via Interface Web
1. Accéder à `http://localhost/adcreation`
2. Utiliser le menu de navigation pour accéder aux différentes fonctionnalités

### Créer un Compte
1. Aller à "Créer"
2. Remplir les informations du compte
3. Sélectionner les options (groupes, génération de document, envoi d'email)
4. Cliquer sur "Créer le Compte"

### Création en Masse
1. Aller à "Création en Masse"
2. Entrer l'ID du favori Aurion
3. Cliquer sur "Aperçu" pour valider les données
4. Sélectionner les apprenants à créer
5. Cliquer sur "Créer les Comptes"

## API et Classes

### ADConnection
```php
$ad = new ADConnection();

// Créer un utilisateur
$user = $ad->createNewUser($nomCommun, $nom, $prenom, $promo);

// Chercher un utilisateur
$user = $ad->searchUser($username);

// Ajouter à un groupe
$ad->addUserToGroup($userDn, $groupDn);

// Mettre à jour le mot de passe
$ad->updatePassword($userDn, $password);
```

### CompteUtilisateur
```php
$compte = new CompteUtilisateur();

// Créer un compte
$user = $compte->creerCompte($nom, $prenom, $promo, $options);

// Récupérer les informations
$info = $compte->getCompte($login);

// Mettre à jour
$compte->updateCompte($login, $updates);

// Lister par promotion
$users = $compte->getComptesByPromo($promo);
```

### EnvoiMail
```php
// Envoyer un email
EnvoiMail::sendWelcomeEmail($toEmail, $login, $password, $nom, $prenom);

// Envoyer en masse
$results = EnvoiMail::sendBatchEmails($users);
```

### LecteurXml
```php
$lecteur = new LecteurXml();

// Récupérer les étudiants depuis Aurion
$students = $lecteur->fetchNewStudents();

// Exporter en CSV
$file = $lecteur->exportToCSV();

// Importer depuis CSV
$students = LecteurXml::importFromCSV($filename);
```

### GenereDOCX
```php
// Générer un document pour un utilisateur
$file = GenereDOCX::generateUserDoc($nom, $prenom, $login, $password, $promo);

// Générer en masse
$results = GenereDOCX::generateBatchDocs($users);
```

### Logging
```php
// Logger un message
Logging::log("Message d'information");

// Logger une erreur
Logging::log("Erreur!", "ERROR");

// Récupérer les logs
$logs = Logging::getLogs();

// Récupérer les erreurs
$errors = Logging::getErrorLogs();
```

## Maintenance

### Script de Maintenance
```bash
# Nettoyer les logs plus anciens que 30 jours
php maintenance.php clean-logs 30

# Exporter les logs en CSV
php maintenance.php export-logs

# Afficher les statistiques
php maintenance.php stats
```

## Sécurité

- Utilisation de prepared statements (si base de données)
- Validation des entrées utilisateur
- Protection contre les injections LDAP
- Logs d'activité pour audit
- Fichiers `.htaccess` pour protéger les répertoires sensibles
- Headers de sécurité HTTP

## Dépannage

### Erreur de Connexion LDAP
- Vérifier l'adresse IP du serveur LDAP
- Vérifier le port (389 par défaut)
- Vérifier les identifiants d'authentification
- Consulter les logs pour plus de détails

### Erreur d'Envoi d'Email
- Vérifier la configuration SMTP
- Vérifier que le serveur mail est accessible
- Consulter les logs

### Erreur de Génération DOCX
- Vérifier que le dossier `documents` existe et est accessible
- Vérifier que PHPWord est installé (via Composer)

## Support

Pour toute question ou problème, consulter les logs d'activité ou contacter l'équipe informatique.

## Licence

© 2024 ESIGELEC - École d'ingénieurs généralistes
