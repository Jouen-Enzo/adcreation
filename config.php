<?php
/**
 * Configuration de l'application ADCreation
 * À configurer selon votre environnement
 */

// (Config LDAP/AD désormais centralisée dans config_ldap.php)
require_once __DIR__ . '/config_ldap.php';

// === CONFIGURATION MAILEUR ===
define('MAIL_HOST', 'mail.esigelec.fr');
define('MAIL_PORT', 25);
define('MAIL_FROM', 'noreply@esigelec.fr');
define('MAIL_FROM_NAME', 'ESIGELEC - Gestion Comptes');

// === CONFIGURATION BASE DE DONNÉES (OPTIONNEL) ===
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'adcreation');

// === CONFIGURATION AURION ===
if (!defined('AURION_SERVER')) {
    define('AURION_SERVER', 'http://srvaurion:5680');
}
define('AURION_FAVORITE_NEW_STUDENTS', '1813866');

// === CONFIGURATION APPLICATION ===
define('APP_NAME', 'ADCreation');
define('APP_VERSION', '2.0');
define('APP_DEBUG', false);
define('APP_TIMEZONE', 'Europe/Paris');

// === CHEMINS ===
define('APP_ROOT', __DIR__);
define('LOGS_DIR', APP_ROOT . '/logs');
define('DOCUMENTS_DIR', APP_ROOT . '/documents');
define('EXPORTS_DIR', APP_ROOT . '/exports');
define('TEMPLATES_DIR', APP_ROOT . '/templates');

// === PARAMÈTRES DE SÉCURITÉ ===
define('PASSWORD_MIN_LENGTH', 12);
define('SESSION_TIMEOUT', 3600); // 1 heure
define('MAX_ATTEMPTS', 5);
define('ATTEMPT_TIMEOUT', 900); // 15 minutes

// === RÉPERTOIRE PERSONNEL — PERMANENTS ===
// Partage UNC racine (sans le sAMAccountName final, il sera ajouté dynamiquement)
define('PERMANENT_HOME_SHARE',  '\\\\192.168.21.131\\partage');
define('PERMANENT_HOME_DRIVE',  'P:');

// Méthode de création du répertoire personnel :
//   'php'        → mkdir() PHP natif (rapide, nécessite que le compte Apache
//                  soit un compte de domaine avec droits sur le partage)
//   'powershell' → script PowerShell via exec() (utilise les credentials AD admin,
//                  définit l'utilisateur comme propriétaire — recommandé sur WAMP/LocalSystem)
define('PERMANENT_HOME_DIR_METHOD', 'powershell');

// === OPTIONS DE CRÉATION DE COMPTE ===
$ACCOUNT_OPTIONS = array(
    'default_mail_domain' => 'ad.test.esigelec',
    'default_file_server' => '\\\\srvfiles2\\',
    'default_home_drive' => 'X:',
    'default_phone' => '02.02.02.02',
    'account_validity_days' => 365,
    'auto_generate_docx' => true,
    'auto_send_email' => true
);

// === GROUPES AD DISPONIBLES ===
$AVAILABLE_GROUPS = array(
    'cpii' => array(
        'name' => 'CPII',
        'dn' => 'CN=CPII,OU=ELEVES,OU=DISTRIBUTION,OU=GROUPES,' . AD_BASE_DN,
        'description' => 'Élèves CPII'
    ),
    'google' => array(
        'name' => 'GOOGLE_APPS',
        'dn' => 'CN=GOOGLE_APPS,OU=ELEVES,OU=DISTRIBUTION,OU=GROUPES,' . AD_BASE_DN,
        'description' => 'Intégration Google Apps'
    ),
    'intervenant' => array(
        'name' => 'INTERVENANTS',
        'dn' => 'CN=INTERVENANTS,OU=ELEVES,OU=DISTRIBUTION,OU=GROUPES,' . AD_BASE_DN,
        'description' => 'Intervenants'
    ),
    'irseem' => array(
        'name' => 'IRSEEM',
        'dn' => 'CN=IRSEEM,OU=ELEVES,OU=DISTRIBUTION,OU=GROUPES,' . AD_BASE_DN,
        'description' => 'IRSEEM'
    )
);

// === PROMOTIONS DISPONIBLES ===
$AVAILABLE_PROMOS = array(
    'A1' => 'Année 1',
    'A2' => 'Année 2',
    'A3' => 'Année 3',
    'CPII' => 'CPII',
    'Alternance' => 'Alternance',
    'Master' => 'Master',
    'Personnel' => 'Personnel'
);

// === CONFIGURATION TWIG ===
$TWIG_CONFIG = array(
    'cache' => APP_ROOT . '/cache',
    'debug' => APP_DEBUG,
    'auto_reload' => APP_DEBUG,
    'strict_variables' => APP_DEBUG
);

// === INITIALISATION DE LA TIMEZONE ===
date_default_timezone_set(APP_TIMEZONE);

// === INITIALISATION DES RÉPERTOIRES ===
foreach (array(LOGS_DIR, DOCUMENTS_DIR, EXPORTS_DIR) as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// === GESTION DES ERREURS ===
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', 0);
}

// === AUTOLOADING ===
require_once APP_ROOT . '/vendor/autoload.php';
?>