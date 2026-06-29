<?php
/**
 * AdImportService — Importe dans MySQL les utilisateurs déjà présents dans l'AD
 *
 * Flux :
 *   1. Récupère tous les users AD via AdConnection::getAllUsers()
 *   2. Pour chacun, vérifie s'il existe déjà en base (par login)
 *   3. Si absent → INSERT avec account_enabled=1 et sync AD marquée "synced"
 *   4. Retourne un résumé ['imported'=>N, 'skipped'=>N, 'errors'=>N]
 *
 * Usage :
 *   $service = new AdImportService(new UtilisateurRepository());
 *   $result  = $service->importAll();
 */

require_once __DIR__ . '/AdConnection.php';
require_once __DIR__ . '/UtilisateurRepository.php';
require_once __DIR__ . '/config_ldap.php';
require_once __DIR__ . '/Logging.php';

class AdImportService
{
    private UtilisateurRepository $repo;

    public function __construct(UtilisateurRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Importe tous les utilisateurs AD manquants en MySQL.
     *
     * @return array ['imported'=>int, 'skipped'=>int, 'errors'=>int, 'details'=>array]
     */
    public function importAll(): array
    {
        $ad      = new ADConnection();
        $adUsers = $ad->getAllUsers();

        $result = ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];

        foreach ($adUsers as $login => $adUser) {
            try {
                $existing = $this->repo->findByLogin($login);

                if ($existing) {
                    $result['skipped']++;
                    $result['details'][] = "[SKIP] $login — déjà en base (id={$existing['id']})";
                    continue;
                }

                $id = $this->insertFromAd($adUser);

                // Marquer la sync AD comme "synced" immédiatement (l'user vient de l'AD)
                $this->repo->markSynced($id, 'ad');

                $result['imported']++;
                $result['details'][] = "[OK] $login importé (id=$id)";
                Logging::log("[AdImport] $login importé depuis l'AD (id=$id)");

            } catch (Exception $e) {
                $result['errors']++;
                $result['details'][] = "[ERR] $login — " . $e->getMessage();
                Logging::log("[AdImport] Erreur import $login : " . $e->getMessage(), 'ERROR');
            }
        }

        Logging::log(sprintf(
            '[AdImport] Terminé — importés: %d, ignorés: %d, erreurs: %d',
            $result['imported'], $result['skipped'], $result['errors']
        ));

        return $result;
    }

    /**
     * Importe un seul utilisateur AD par son login.
     * Retourne l'ID MySQL inséré, ou null si déjà présent.
     */
    public function importByLogin(string $login): ?int
    {
        $existing = $this->repo->findByLogin($login);
        if ($existing) {
            Logging::log("[AdImport] $login déjà en base — import ignoré");
            return null;
        }

        $ad   = new ADConnection();
        $entry = $ad->searchUser($login);
        if (!$entry) {
            throw new Exception("Utilisateur '$login' introuvable dans l'AD");
        }

        // Déduire le user_type depuis le DN
        $userType = $this->detectUserType($entry['dn'] ?? '');

        $adUser = [
            'login'      => $login,
            'nom'        => $entry['sn'][0]                          ?? '',
            'prenom'     => $entry['givenname'][0]                   ?? '',
            'email'      => $entry['mail'][0]                        ?? '',
            'telephone'  => $entry['telephonenumber'][0]             ?? '',
            'dn'         => $entry['dn'],
            'user_type'  => $userType,
            'upn'        => $entry['userprincipalname'][0]           ?? '',
            'titre'      => $entry['title'][0]                       ?? '',
            'departement'=> $entry['department'][0]                  ?? '',
            'societe'    => $entry['company'][0]                     ?? '',
            'bureau'     => $entry['physicaldeliveryofficename'][0]  ?? '',
            'mobile'     => $entry['mobile'][0]                      ?? '',
            'enabled'    => isset($entry['useraccountcontrol'][0])
                            ? (((int)$entry['useraccountcontrol'][0] & 2) === 0)
                            : true,
        ];

        $id = $this->insertFromAd($adUser);
        $this->repo->markSynced($id, 'ad');

        Logging::log("[AdImport] $login importé (id=$id)");
        return $id;
    }

    // ----------------------------------------------------------------
    // PRIVÉ
    // ----------------------------------------------------------------

    private function insertFromAd(array $adUser): int
    {
        return $this->repo->create([
            'nom'              => $adUser['nom'],
            'prenom'           => $adUser['prenom'],
            'date_naissance'   => null,
            'lieu_naissance'   => null,
            'genre'            => null,
            'nationalite'      => null,
            'login'            => $adUser['login'],
            'email'            => $adUser['email'],
            'upn'              => $adUser['upn'],
            'password_tmp'     => '',   // inconnu — l'user existe déjà dans l'AD
            'user_type'        => $adUser['user_type'],
            'dn_ad'            => $adUser['dn'],
            'account_enabled'  => $adUser['enabled'] ? 1 : 0,
            'telephone'        => $adUser['telephone'],
            'telephone_mobile' => $adUser['mobile'] ?? null,
            'fax'              => null,
            'titre'            => $adUser['titre']       ?? null,
            'poste'            => null,
            'departement'      => $adUser['departement'] ?? null,
            'societe'          => $adUser['societe']     ?? null,
            'service'          => null,
            'manager_login'    => null,
            'bureau'           => $adUser['bureau']      ?? null,
            'adresse_rue'      => null,
            'ville'            => null,
            'code_postal'      => null,
            'etat_province'    => null,
            'pays'             => null,
            'home_drive'       => null,
            'home_directory'   => null,
            'profile_path'     => null,
            'logon_script'     => null,
            'date_expiration'  => null,
            'date_debut'       => null,
            'aurion_id'        => null,
            'promo'            => null,
            'created_by'       => 'ad_import',
            'notes'            => 'Importé automatiquement depuis l\'AD',
        ]);
    }

    /**
     * Déduit le user_type depuis le DN AD.
     * Ex: "CN=Jean Dupont,OU=stagiaires,OU=esigelec,DC=test,DC=local" → 'stagiaires'
     */
    private function detectUserType(string $dn): string
    {
        foreach (LDAPConfig::getAllTypes() as $type) {
            if (stripos($dn, "OU=$type,") !== false) {
                return $type;
            }
        }
        // Fallback si OU inconnue
        return USER_TYPE_PERMANENT;
    }
}
?>
