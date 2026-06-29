<?php
/**
 * AdConnection — Connexion et gestion des utilisateurs Active Directory (via LDAP port 389)
 *
 * Technique mot de passe identique au code Java d'origine :
 *   1. ldap_add() avec userAccountControl=544 (désactivé, password non requis)
 *   2. Script PowerShell (équivalent du VBS) : SetPassword + activation du compte
 *
 * Le script PS est généré dans /tmp/adcreation_setpwd_<uniqid>.ps1
 * puis exécuté via exec() et supprimé immédiatement.
 *
 * Prérequis côté serveur WAMP :
 *   - PHP exec() activé
 *   - Le process PHP doit pouvoir accéder à l'AD (même réseau que la VM)
 *   - PowerShell installé sur le poste hôte Windows
 *   - RSAT (ActiveDirectory module) installé  OU  accès ADSI via WinNT/LDAP
 */

require_once __DIR__ . '/config_ldap.php';
require_once __DIR__ . '/Logging.php';

class ADConnection
{
    private $conn;

    // ----------------------------------------------------------------
    // CONNEXION
    // ----------------------------------------------------------------

    public function __construct()
    {
        $uri = 'ldap://' . AD_SERVER_IP . ':' . AD_SERVER_PORT;

        $conn = @ldap_connect($uri);
        if (!$conn) {
            throw new Exception("Impossible de créer la connexion LDAP : $uri");
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

        if (!@ldap_bind($conn, AD_ADMIN_UPN, AD_ADMIN_PASSWORD)) {
            throw new Exception('Echec bind LDAP : ' . ldap_error($conn));
        }

        $this->conn = $conn;
        Logging::log('Connexion AD réussie (' . AD_SERVER_IP . ')');
    }

    public function __destruct()
    {
        if ($this->conn) {
            @ldap_close($this->conn);
        }
    }

    public function getConnection()
    {
        return $this->conn;
    }

    // ----------------------------------------------------------------
    // CRÉATION D'UN UTILISATEUR
    // ----------------------------------------------------------------

    /**
     * Crée un utilisateur AD dans la bonne OU selon son type,
     * puis définit son mot de passe via PowerShell (comme le VBS Java).
     *
     * @param string $nom       Nom de famille
     * @param string $prenom    Prénom
     * @param string $userType  USER_TYPE_PERMANENT | USER_TYPE_STAGIAIRE | USER_TYPE_VACATAIRE
     * @param array  $extra     Champs optionnels : email, telephone, login, password, expiration,
     *                          titre, departement, societe, bureau, adresse, ville, code_postal,
     *                          pays, mobile, home_drive, home_dir, profile_path, logon_script,
     *                          description, promo
     * @return array            ['login', 'password', 'dn', 'email', 'type']
     */
    public function createNewUser(string $nom, string $prenom, string $userType, array $extra = []): array
    {
        LDAPConfig::validateUserType($userType) || throw new InvalidArgumentException("Type inconnu : $userType");

        $login    = !empty($extra['login'])    ? $extra['login']    : $this->generateLogin($nom, $prenom);
        $password = !empty($extra['password']) ? $extra['password'] : $this->generatePassword();
        $email    = !empty($extra['email'])    ? $extra['email']    : ($prenom . '.' . $this->normalize($nom) . '@' . AD_DOMAIN);
        $phone    = !empty($extra['telephone']) ? $extra['telephone'] : '02.02.02.02';
        $expiry   = $extra['expiration'] ?? '';

        // DN dans la bonne OU  (ex: CN=Thomas Dupont,OU=permanent,OU=esigelec,DC=test,DC=local)
        $ouDn  = LDAPConfig::getOUDn($userType);
        $cn    = $this->sanitizeCN("$prenom $nom");
        $dn    = "CN={$cn},{$ouDn}";

        // Unicité du CN
        $base = $cn; $i = 1;
        while ($this->dnExists($dn)) {
            $cn = $base . $i++;
            $dn = "CN={$cn},{$ouDn}";
        }

        // Attributs de base — userAccountControl=544 (désactivé + pwdNotReqd)
        // Le mot de passe sera défini juste après via PowerShell
        $attrs = [
            'objectClass'        => ['top', 'person', 'organizationalPerson', 'user'],
            'cn'                 => $cn,
            'sAMAccountName'     => $login,
            'userPrincipalName'  => $login . '@' . AD_DOMAIN,
            'sn'                 => $nom,
            'givenName'          => $prenom,
            'displayName'        => "$nom $prenom",
            'telephoneNumber'    => $phone,
            'mail'               => $email,
            'userAccountControl' => '544',   // 0x220 = désactivé + pwdNotReqd
        ];

        // ── Attributs optionnels (champs avancés) ──────────────────────
        if (!empty($extra['titre']))        $attrs['title']                      = $extra['titre'];
        if (!empty($extra['description']))  $attrs['description']                = $extra['description'];
        if (!empty($extra['departement']))  $attrs['department']                 = $extra['departement'];
        if (!empty($extra['societe']))      $attrs['company']                    = $extra['societe'];
        if (!empty($extra['bureau']))       $attrs['physicalDeliveryOfficeName'] = $extra['bureau'];
        if (!empty($extra['adresse']))      $attrs['streetAddress']              = $extra['adresse'];
        if (!empty($extra['ville']))        $attrs['l']                          = $extra['ville'];
        if (!empty($extra['code_postal']))  $attrs['postalCode']                 = $extra['code_postal'];
        if (!empty($extra['pays']))         $attrs['co']                         = $extra['pays'];
        if (!empty($extra['mobile']))       $attrs['mobile']                     = $extra['mobile'];
        if (!empty($extra['home_drive']))   $attrs['homeDrive']                  = $extra['home_drive'];
        if (!empty($extra['home_dir']))     $attrs['homeDirectory']              = $extra['home_dir'];
        if (!empty($extra['profile_path'])) $attrs['profilePath']               = $extra['profile_path'];
        if (!empty($extra['logon_script'])) $attrs['scriptPath']                = $extra['logon_script'];

        // Supprimer tout attribut vide ou null — l'AD rejette les valeurs vides (Invalid syntax)
        $attrs = $this->filterAttrs($attrs);

        if (!@ldap_add($this->conn, $dn, $attrs)) {
            throw new Exception("ldap_add() échoué ($dn) : " . ldap_error($this->conn));
        }

        Logging::log("Compte créé (désactivé) : $login dans $ouDn");

        // Définit le mot de passe et active le compte via PowerShell
        $this->setPasswordAndEnable($dn, $login, $password, $expiry);

        Logging::log("Compte activé : $login (type=$userType)");

        return [
            'login'    => $login,
            'password' => $password,
            'dn'       => $dn,
            'email'    => $email,
            'type'     => $userType,
        ];
    }

    // ----------------------------------------------------------------
    // MOT DE PASSE VIA POWERSHELL (équivalent du VBS Java)
    // ----------------------------------------------------------------

    /**
     * Génère et exécute un script PowerShell pour :
     *   1. Définir le mot de passe (SetPassword via ADSI — fonctionne sur port 389)
     *   2. Activer le compte (AccountDisabled = $false)
     *   3. Optionnellement définir une date d'expiration
     */
    private function setPasswordAndEnable(string $dn, string $login, string $password, string $expiry = ''): void
    {
        $pwdEscaped = str_replace('"', '`"', $password);
        $adminPwEsc = str_replace('"', '`"', AD_ADMIN_PASSWORD);

        $lines   = [];
        $lines[] = '$ErrorActionPreference = "Stop"';
        $lines[] = '';
        $lines[] = '$objUser = New-Object System.DirectoryServices.DirectoryEntry(';
        $lines[] = '    "LDAP://' . AD_SERVER_IP . '/' . $dn . '",';
        $lines[] = '    "' . AD_ADMIN_UPN . '",';
        $lines[] = '    "' . $adminPwEsc . '"';
        $lines[] = ')';
        $lines[] = '$objUser.SetPassword("' . $pwdEscaped . '")';
        $lines[] = '$objUser.AccountDisabled = $false';

        if (!empty($expiry)) {
            $lines[] = '$objUser.AccountExpirationDate = [DateTime]::Parse("' . $expiry . '")';
        }

        $lines[] = '$objUser.SetInfo()';
        $lines[] = 'Write-Host "OK: $($objUser.sAMAccountName)"';

        $script  = implode("\r\n", $lines);
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adcreation_pwd_' . uniqid() . '.ps1';
        file_put_contents($tmpFile, "\xEF\xBB\xBF" . $script); // BOM UTF-8

        $cmd    = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "' . $tmpFile . '" 2>&1';
        $output = [];
        $retval = 0;
        exec($cmd, $output, $retval);

        @unlink($tmpFile);

        $outputStr = implode("\n", $output);

        if ($retval !== 0) {
            Logging::log("PowerShell erreur (code $retval) : $outputStr", 'ERROR');
            throw new Exception("Erreur activation compte ($login) : $outputStr");
        }

        Logging::log("PowerShell OK : $outputStr");
    }

    // ----------------------------------------------------------------
    // RECHERCHE
    // ----------------------------------------------------------------

    public function searchUser(string $login): ?array
    {
        $filter = sprintf(
            '(&(objectClass=user)(sAMAccountName=%s))',
            ldap_escape($login, '', LDAP_ESCAPE_FILTER)
        );

        $search = @ldap_search($this->conn, AD_BASE_DN, $filter);
        if (!$search) return null;

        $result = @ldap_get_entries($this->conn, $search);
        return ($result && $result['count'] > 0) ? $result[0] : null;
    }

    /**
     * Liste tous les utilisateurs d'un type (par OU).
     */
    public function getUsersByType(string $userType): array
    {
        $ouDn   = LDAPConfig::getOUDn($userType);
        $search = @ldap_search($this->conn, $ouDn, '(objectClass=user)');
        if (!$search) return [];

        $result = @ldap_get_entries($this->conn, $search);
        return $result ?: [];
    }

    /**
     * Récupère TOUS les utilisateurs de l'AD (toutes les OUs gérées).
     * Retourne un tableau indexé par sAMAccountName.
     */
    public function getAllUsers(): array
    {
        $allUsers = [];
        foreach (LDAPConfig::getAllTypes() as $type) {
            $entries = $this->getUsersByType($type);
            for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
                $entry  = $entries[$i];
                $login  = $entry['samaccountname'][0] ?? null;
                if ($login) {
                    $allUsers[$login] = [
                        'login'      => $login,
                        'nom'        => $entry['sn'][0]          ?? '',
                        'prenom'     => $entry['givenname'][0]   ?? '',
                        'email'      => $entry['mail'][0]        ?? '',
                        'telephone'  => $entry['telephonenumber'][0] ?? '',
                        'dn'         => $entry['dn'],
                        'user_type'  => $type,
                        'upn'        => $entry['userprincipalname'][0] ?? '',
                        'titre'      => $entry['title'][0]       ?? '',
                        'departement'=> $entry['department'][0]  ?? '',
                        'societe'    => $entry['company'][0]     ?? '',
                        'bureau'     => $entry['physicaldeliveryofficename'][0] ?? '',
                        'mobile'     => $entry['mobile'][0]      ?? '',
                        'enabled'    => isset($entry['useraccountcontrol'][0])
                                        ? (((int)$entry['useraccountcontrol'][0] & 2) === 0)
                                        : true,
                    ];
                }
            }
        }
        return $allUsers;
    }

    // ----------------------------------------------------------------
    // DÉSACTIVATION / SUPPRESSION
    // ----------------------------------------------------------------

    public function desactiverUtilisateur(string $dn): void
    {
        if (!@ldap_mod_replace($this->conn, $dn, ['userAccountControl' => '2'])) {
            throw new Exception('Désactivation échouée : ' . ldap_error($this->conn));
        }
        Logging::log("Compte désactivé : $dn");
    }

    public function supprimerUtilisateur(string $dn): void
    {
        if (!@ldap_delete($this->conn, $dn)) {
            throw new Exception('Suppression échouée : ' . ldap_error($this->conn));
        }
        Logging::log("Compte supprimé : $dn");
    }

    // ----------------------------------------------------------------
    // GROUPES
    // ----------------------------------------------------------------

    public function addUserToGroup(string $userDn, string $groupDn): void
    {
        if (!@ldap_mod_add($this->conn, $groupDn, ['member' => $userDn])) {
            throw new Exception('Ajout groupe échoué : ' . ldap_error($this->conn));
        }
        Logging::log("$userDn → groupe $groupDn");
    }

    // ----------------------------------------------------------------
    // HELPERS PRIVÉS
    // ----------------------------------------------------------------

    /**
     * Supprime du tableau d'attributs LDAP toute valeur vide, null ou tableau vide.
     * L'AD retourne "Invalid syntax" si un attribut a une valeur vide string.
     */
    private function filterAttrs(array $attrs): array
    {
        $clean = [];
        foreach ($attrs as $key => $value) {
            if (is_array($value)) {
                $value = array_filter($value, fn($v) => $v !== null && $v !== '');
                if (!empty($value)) {
                    $clean[$key] = array_values($value);
                }
            } elseif ($value !== null && $value !== '') {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    private function dnExists(string $dn): bool
    {
        $r = @ldap_read($this->conn, $dn, '(objectClass=*)');
        return $r && ldap_count_entries($this->conn, $r) > 0;
    }

    private function generateLogin(string $nom, string $prenom): string
    {
        $nomTrunc = substr($this->normalize(str_replace(' ', '', $nom)), 0, 10);
        $base     = strtolower(substr($prenom, 0, 1)) . '.' . $nomTrunc;
        $login    = $base;
        $i        = 2;

        while ($this->searchUser($login)) {
            $prefix = strtolower(substr($prenom, 0, $i++));
            $login  = $prefix . '.' . $nomTrunc;
            if ($i > 10) break;
        }

        return $login;
    }

    private function normalize(string $str): string
    {
        $map = ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a',
                'ç'=>'c','ù'=>'u','û'=>'u','ü'=>'u','ô'=>'o','ö'=>'o','î'=>'i','ï'=>'i'];
        return preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower($str, 'UTF-8'), $map));
    }

    private function sanitizeCN(string $cn): string
    {
        return trim(preg_replace('/[#,;+<>=\\"\\\\\/]/', '', $cn));
    }

    private function generatePassword(): string
    {
        $upper  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower  = 'abcdefghijklmnopqrstuvwxyz';
        $digits = '123456789';
        $spec   = ':;<=>?';

        $pwd  = $upper[random_int(0, 25)];
        $pwd .= $lower[random_int(0, 25)];
        $pwd .= $lower[random_int(0, 25)];
        $pwd .= $digits[random_int(0, 8)];
        $pwd .= $digits[random_int(0, 8)];
        $pwd .= $spec[random_int(0, 5)];
        $pwd .= $digits[random_int(0, 8)];
        $pwd .= $upper[random_int(0, 25)];

        $all = $upper . $lower . $digits . $spec;
        while (strlen($pwd) < 16) {
            $pwd .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($pwd);
    }

    // Wrappers publics
    public function generateLoginPublic(string $nom, string $prenom): string
    {
        return $this->generateLogin($nom, $prenom);
    }

    public function generatePasswordPublic(): string
    {
        return $this->generatePassword();
    }
}
?>
