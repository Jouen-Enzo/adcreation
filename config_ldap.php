<?php
/**
 * config_ldap.php — Configuration LDAP / AD / OpenLDAP / Aurion (v3)
 */

// ── Active Directory ──────────────────────────────────────────────
define('AD_SERVER_IP',      '192.168.21.131');
define('AD_SERVER_PORT',    389);
define('AD_BASE_DN',        'DC=test,DC=local');
// define('AD_ADMIN_DN',       'CN=Administrateur,CN=Users,DC=test,DC=local');
define('AD_ADMIN_UPN',      'admin@test.local');
define('AD_ADMIN_PASSWORD', 'Chell.2502');   // ← À remplir
define('AD_DOMAIN',         'test.local');

// ── OpenLDAP ─────────────────────────────────────────────────────
define('OPENLDAP_SERVER',   '192.168.21.137');   // ex: ldap://192.168.4.40  — vide = sync ignorée
define('OPENLDAP_PORT',     389);
define('OPENLDAP_BASE_DN',  'dc=testopenldap,dc=local');
define('OPENLDAP_ADMIN_DN', 'cn=admin,dc=testopenldap,dc=local');
define('OPENLDAP_PASSWORD', 'Chell.2502');   // ← À remplir

// ── Partage RENATER (boîtes mail ZRR) ────────────────────────────
// Documentation API : https://documentation.partage.renater.fr/
// La clé preauth est fournie par RENATER pour votre domaine.
// Récupérable via GetDomain → zimbraPreAuthKey une fois connecté.
define('PARTAGE_API_URL',     'https://api.partage.renater.fr/service/domain');
define('PARTAGE_DOMAIN',      '');       // ← ex: "zrr.esigelec.fr"
define('PARTAGE_PREAUTH_KEY', '');       // ← clé fournie par RENATER
// define('PARTAGE_COS_ID_ZRR', '');     // ← ID de la classe de service ZRR (optionnel)

// ── Aurion ────────────────────────────────────────────────────────
if (!defined('AURION_SERVER')) {
    define('AURION_SERVER', '');   // ex: http://srvaurion2:5680 — vide = sync ignorée
}
define('AURION_API_TOKEN', '');    // ← Token API Aurion

// URLs Aurion pour la sync badges (favoris Aurion)
define('AURION_URL_ELEVES',    (AURION_SERVER ?: 'http://srvaurion2:5680')
    . '/servlet/Dispatcher?action=executeFavori&data=%3C?xml%20version=%221.0%22%20encoding=%22UTF-8%22?%3E%3CexecuteFavori%3E%3Cfavori%3E%3Cid%3E17782803%3C/id%3E%3C/favori%3E%3Cdatabase%3Eaurion%3C/database%3E%3CresultType%3ExmlAndSchema%3C/resultType%3E%3C/executeFavori%3E');
define('AURION_URL_PERSONNEL', (AURION_SERVER ?: 'http://srvaurion2:5680')
    . '/servlet/Dispatcher?action=executeFavori&data=%3C?xml%20version=%221.0%22%20encoding=%22UTF-8%22?%3E%3CexecuteFavori%3E%3Cfavori%3E%3Cid%3E17785538%3C/id%3E%3C/favori%3E%3Cdatabase%3Eaurion%3C/database%3E%3CresultType%3ExmlAndSchema%3C/resultType%3E%3C/executeFavori%3E');

// OpenLDAP badges (peut être différent du OPENLDAP principal)
define('OPENLDAP_BADGES_HOST',    OPENLDAP_SERVER ?: 'ldap://ldap.esigelec.fr');
define('OPENLDAP_BADGES_PORT',    OPENLDAP_PORT);
define('OPENLDAP_BADGES_BASE_DN', 'dc=sgc,dc=intranet,dc=int');
define('OPENLDAP_BADGES_ADMIN_DN','cn=admin,dc=sgc,dc=intranet,dc=int');
define('OPENLDAP_BADGES_PASSWORD', OPENLDAP_PASSWORD);

// ── Types d'utilisateurs ──────────────────────────────────────────
define('USER_TYPE_PERMANENT', 'permanent');
define('USER_TYPE_STAGIAIRE', 'stagiaires');
define('USER_TYPE_VACATAIRE', 'vacataire');

const USER_TYPES = [
    'permanent'  => 'Personnel permanent',
    'stagiaires' => 'Stagiaire',
    'vacataire'  => 'Vacataire / Intervenant',
];

class LDAPConfig
{
    private static array $ouMap = [
        'permanent'  => 'OU=permanent,OU=esigelec,'  . AD_BASE_DN,
        'stagiaires' => 'OU=stagiaires,OU=esigelec,' . AD_BASE_DN,
        'vacataire'  => 'OU=vacataire,OU=esigelec,'  . AD_BASE_DN,
    ];

    public static function getOUDn(string $userType): string
    {
        if (!isset(self::$ouMap[$userType])) {
            throw new InvalidArgumentException("Type inconnu : $userType");
        }
        return self::$ouMap[$userType];
    }

    public static function validateUserType(string $userType): bool
    {
        return isset(self::$ouMap[$userType]);
    }

    public static function getAllTypes(): array
    {
        return array_keys(self::$ouMap);
    }
}