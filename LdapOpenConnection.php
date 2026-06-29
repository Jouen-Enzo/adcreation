<?php
/**
 * LdapOpenConnection — Connexion singleton vers un serveur OpenLDAP
 *
 * Distinct de AdConnection (qui cible l'Active Directory).
 * Utilisé uniquement si OPENLDAP_SERVER est défini dans config_ldap.php.
 */

require_once __DIR__ . '/config_ldap.php';
require_once __DIR__ . '/Logging.php';

class LdapOpenConnection
{
    private static ?LdapOpenConnection $instance = null;
    private $conn;

    private function __construct()
    {
        $uri  = 'ldap://' . OPENLDAP_SERVER . ':' . OPENLDAP_PORT;
        $conn = @ldap_connect($uri);

        if (!$conn) {
            throw new Exception("Impossible de se connecter à OpenLDAP : $uri");
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

        if (!@ldap_bind($conn, OPENLDAP_ADMIN_DN, OPENLDAP_PASSWORD)) {
            throw new Exception('Bind OpenLDAP échoué : ' . ldap_error($conn));
        }

        $this->conn = $conn;
        Logging::log('Connexion OpenLDAP réussie (' . OPENLDAP_SERVER . ')');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->conn;
    }

    public function __destruct()
    {
        if ($this->conn) {
            @ldap_close($this->conn);
        }
    }
}
?>
