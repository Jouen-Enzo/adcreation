# Guide de Configuration LDAP - AD et OpenLDAP

## 🔄 Si votre Active Directory a changé

Si le serveur AD a changé d'IP, de domaine ou de structure, suivez ce guide.

### 1️⃣ Découvrir automatiquement le nouveau serveur

Créez un fichier `discover_ad.php`:

```php
<?php
require_once 'config_ldap.php';

// Testez la nouvelle adresse IP
$newServer = '192.168.X.X'; // À remplacer
$newPort = 389;

$type = LDAPConfig::detectServerType($newServer, $newPort);

if ($type) {
    echo "✅ Serveur LDAP détecté: $type\n";
} else {
    echo "❌ Impossible de déterminer le type\n";
}
?>
```

Exécutez:
```bash
php discover_ad.php
```

### 2️⃣ Configurer le nouvel AD

#### Étape A: Trouver la Base DN

```bash
# Connectez-vous au serveur AD avec une commande comme:
ldapsearch -H ldap://nouveau.serveur.ad:389 -x -s base -b "" "(objectClass=*)"

# Cherchez la valeur "defaultNamingContext" ou "rootDomainNamingContext"
# Exemple: CN=Configuration,DC=nouvelle,DC=entreprise,DC=fr
```

#### Étape B: Éditer `config_ldap.php`

```php
// Ancienne config (ne pas supprimer pour compatibilité)
$AD_CONFIG = array(
    'SERVER_IP' => '192.168.4.33',        // ← À CHANGER
    'SERVER_PORT' => 389,                 // Généralement 389 ou 636 (SSL)
    'BASE_DN' => 'DC=ad,DC=test,DC=esigelec',  // ← À CHANGER
    'USERS_BASE' => 'OU=ELEVES,OU=ESIGELEC,DC=ad,DC=test,DC=esigelec',  // ← À CHANGER
    'GROUPS_BASE' => 'OU=DISTRIBUTION,OU=GROUPES,DC=ad,DC=test,DC=esigelec',  // ← À CHANGER
    'ADMIN_USER' => 'CN=administrateur,CN=Users,DC=ad,DC=test,DC=esigelec',  // ← À CHANGER
    'ADMIN_PASSWORD' => 'Test1234',       // ← À CHANGER
    'USE_SSL' => false,                   // true si port 636
    'USE_TLS' => false,                   // true pour démarrer TLS
);
```

#### Étape C: Exemple pour nouveau AD

Supposons que le nouveau AD est:
- **Serveur:** 10.0.0.50
- **Domaine:** nouvelle-entreprise.fr
- **Base DN:** DC=nouvelle-entreprise,DC=fr
- **Utilisateurs:** OU=Users,DC=nouvelle-entreprise,DC=fr
- **Admin:** CN=admin,CN=Users,DC=nouvelle-entreprise,DC=fr

Configuration:
```php
$AD_CONFIG = array(
    'SERVER_IP' => '10.0.0.50',
    'SERVER_PORT' => 389,
    'BASE_DN' => 'DC=nouvelle-entreprise,DC=fr',
    'USERS_BASE' => 'OU=Users,DC=nouvelle-entreprise,DC=fr',
    'GROUPS_BASE' => 'OU=Groups,DC=nouvelle-entreprise,DC=fr',
    'ADMIN_USER' => 'CN=admin,CN=Users,DC=nouvelle-entreprise,DC=fr',
    'ADMIN_PASSWORD' => 'MotDePasse123',
    'USE_SSL' => false,
    'USE_TLS' => false,
);
```

### 3️⃣ Tester la nouvelle connexion

Exécutez:
```bash
php ldap_test.php
```

Vous devriez voir:
```
✓ Connexion réussie!
✓ Utilisateurs trouvés...
```

---

## 🐧 Si vous utilisez OpenLDAP (au lieu d'AD)

OpenLDAP est un serveur LDAP open-source, compatible avec le code.

### Configuration OpenLDAP

#### Exemple typique:

```php
$OPENLDAP_CONFIG = array(
    'SERVER_IP' => 'localhost',           // ou l'IP du serveur OpenLDAP
    'SERVER_PORT' => 389,
    'BASE_DN' => 'dc=example,dc=com',     // À adapter
    'USERS_BASE' => 'ou=people,dc=example,dc=com',
    'GROUPS_BASE' => 'ou=groups,dc=example,dc=com',
    'ADMIN_USER' => 'cn=admin,dc=example,dc=com',
    'ADMIN_PASSWORD' => 'admin_password',
    'USE_SSL' => false,
    'USE_TLS' => false,
    'USER_FILTER' => '(&(objectClass=inetOrgPerson)(uid=%s))',
    'GROUP_FILTER' => '(&(objectClass=groupOfNames)(cn=%s))',
    'EMAIL_ATTR' => 'mail',
    'PHONE_ATTR' => 'telephoneNumber',
    'DISPLAY_NAME_ATTR' => 'cn',
);
```

#### Basculer vers OpenLDAP:

Changez `LDAP_TYPE` dans `config_ldap.php`:

```php
// === TYPE DE SERVEUR LDAP ===
// Options: 'active_directory' ou 'openldap'
define('LDAP_TYPE', 'openldap');  // ← Changez cette ligne
```

#### Différences AD vs OpenLDAP:

| Aspect | Active Directory | OpenLDAP |
|--------|------------------|----------|
| **Format DN** | `CN=user,OU=dept,DC=enterprise,DC=fr` | `uid=user,ou=people,dc=example,dc=com` |
| **Identifiant User** | `sAMAccountName` | `uid` |
| **Email** | `mail` | `mail` |
| **Mot de passe** | `unicodePwd` (UTF-16 LE) | SHA ou MD5 |
| **Groupes** | `member` | `memberUid` |
| **Classe d'objet** | `user`, `group` | `inetOrgPerson`, `groupOfNames` |
| **Numéro UID** | N/A | `uidNumber` (POSIX) |
| **Shell** | N/A | `loginShell` |
| **Home** | `homeDirectory` | `homeDirectory` |

### Trouver votre Base DN OpenLDAP

```bash
# Requête LDAP simple
ldapsearch -H ldap://localhost:389 -x -s base -b "" "(objectClass=*)"

# Cherchez les attributs comme:
# namingContexts: dc=example,dc=com
```

---

## 📋 Comparaison des Structures

### Active Directory Classique

```
DC=ad,DC=test,DC=esigelec
├── OU=ESIGELEC
│   └── OU=ELEVES
│       ├── CN=Thomas Dupont (user)
│       └── CN=Sarah Martin (user)
└── OU=GROUPES
    └── OU=DISTRIBUTION
        └── CN=Promo_A1 (group)
```

### OpenLDAP Typique

```
dc=example,dc=com
├── ou=people
│   ├── uid=t.dupont (inetOrgPerson)
│   └── uid=s.martin (inetOrgPerson)
└── ou=groups
    └── cn=promo_a1 (groupOfNames)
```

---

## 🔧 Utilisation dans le Code

Le code s'adapte automatiquement:

```php
require_once 'config_ldap.php';

$ldap = new LDAPConnection();  // Utilise la config actuelle

// Les mêmes méthodes fonctionnent pour AD et OpenLDAP:
$user = $ldap->createNewUser($nom, $prenom, $promo);
$user_info = $ldap->searchUser($login);
```

---

## 🧪 Tester avant/après Migration

### Script de test complet

Créez `test_migration.php`:

```php
<?php
require_once 'config_ldap.php';
require_once 'Logging.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║         Test de Connexion LDAP                            ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

echo "Type LDAP: " . LDAPConfig::getType() . "\n";
echo "Serveur: " . LDAPConfig::getConfig('SERVER_IP') . ":" . LDAPConfig::getConfig('SERVER_PORT') . "\n";
echo "Base DN: " . LDAPConfig::getConfig('BASE_DN') . "\n\n";

try {
    $ldap = new LDAPConnection();
    echo "✓ Connexion réussie!\n";
    
    // Test recherche
    $user = $ldap->searchUser('t.dupont');
    if ($user) {
        echo "✓ Utilisateur trouvé: " . $user['cn'][0] . "\n";
    } else {
        echo "⚠ Aucun utilisateur trouvé (normal si BD vide)\n";
    }
    
} catch (Exception $e) {
    echo "✗ Erreur: " . $e->getMessage() . "\n";
}
?>
```

Exécutez:
```bash
php test_migration.php
```

---

## ✅ Checklist Migration AD

- [ ] Nouvelle adresse IP du serveur AD
- [ ] Nouveau domaine / Base DN
- [ ] Nouvel utilisateur administrateur
- [ ] Nouveau mot de passe administrateur
- [ ] OU des utilisateurs (Users_Base)
- [ ] OU des groupes (Groups_Base)
- [ ] Port LDAP (389, 636 SSL, ou autre)
- [ ] TLS/SSL nécessaire?
- [ ] Firewall autorise la connexion?
- [ ] Test avec `ldap_test.php` OK?

---

## 🆘 Problèmes Courants

### Erreur: "Impossible de se connecter au serveur LDAP"
- Vérifier l'IP du serveur
- Vérifier le port (389 standard, 636 pour SSL)
- Vérifier la connectivité réseau: `ping 192.168.X.X`

### Erreur: "Erreur d'authentification LDAP"
- Vérifier le DN administrateur (format correct?)
- Vérifier le mot de passe administrateur
- Tester manuellement avec `ldapsearch`

### Erreur: "Base DN invalide"
- Vérifier le format: `DC=...` ou `dc=...`
- Utiliser `ldapsearch` pour lister les DN racine
- Format dépend du type (AD vs OpenLDAP)

---

**Pour questions:** Consulter les logs: `logs/logs.json`
