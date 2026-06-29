<?php
/**
 * SyncManager — Orchestre la synchronisation MySQL → AD, OpenLDAP, Aurion
 *
 * Usage :
 *   $sync = new SyncManager($repo);
 *   $sync->syncAll($userId);          // Sync vers toutes les cibles
 *   $sync->syncTo($userId, 'ad');     // Sync vers une cible précise
 *   $sync->runPendingQueue();         // Traite la file d'attente
 */

require_once __DIR__ . '/UtilisateurRepository.php';
require_once __DIR__ . '/AdConnection.php';
require_once __DIR__ . '/LdapOpenConnection.php';
require_once __DIR__ . '/Logging.php';
require_once __DIR__ . '/config.php';

/**
 * Signale qu'une cible a été volontairement ignorée (pas une erreur).
 * Ex: OpenLDAP pour un stagiaire/vacataire, ou cible non configurée.
 */
class SyncSkipped extends Exception {}

class SyncManager
{
    private UtilisateurRepository $repo;

    public function __construct(UtilisateurRepository $repo)
    {
        $this->repo = $repo;
    }

    // ----------------------------------------------------------------
    // POINT D'ENTRÉE PUBLIC
    // ----------------------------------------------------------------

    /**
     * Sync vers toutes les cibles activées.
     * Retourne ['ad'=>bool, 'openldap'=>bool, 'aurion'=>bool]
     */
    /**
     * Sync vers toutes les cibles activées.
     * @param string|null $plainPassword  Mot de passe en clair (créations uniquement,
     *                                     jamais lu/écrit en base — voir syncToAD()).
     * @param string|null $oldLogin       Ancien login si le login a changé (renommage AD/LDAP).
     *                                     Null = pas de changement de login.
     * Retourne ['ad'=>bool, 'openldap'=>bool, 'aurion'=>bool]
     */
    public function syncAll(int $userId, ?string $plainPassword = null, ?string $oldLogin = null): array
    {
        $user = $this->repo->findById($userId);
        if (!$user) throw new Exception("Utilisateur #$userId introuvable");

        return [
            'ad'       => $this->syncTo($userId, 'ad',       $plainPassword, $oldLogin),
            'openldap' => $this->syncTo($userId, 'openldap', null,           $oldLogin),
            'aurion'   => $this->syncTo($userId, 'aurion'),
        ];
    }

    public function syncTo(int $userId, string $cible, ?string $plainPassword = null, ?string $oldLogin = null): bool
    {
        $user = $this->repo->findById($userId);
        if (!$user) return false;

        try {
            switch ($cible) {
                case 'ad':       $this->syncToAD($user, $plainPassword, $oldLogin); break;
                case 'openldap': $this->syncToOpenLDAP($user, $oldLogin);           break;
                case 'aurion':   $this->syncToAurion($user);                        break;
                default: throw new Exception("Cible inconnue : $cible");
            }
            $this->repo->markSynced($userId, $cible);
            $this->repo->audit($userId, 'SYNC_' . strtoupper($cible), $cible, 'OK');
            Logging::log("[Sync] $cible OK pour {$user['login']}");
            return true;

        } catch (SyncSkipped $e) {
            $this->repo->markSkipped($userId, $cible, $e->getMessage());
            Logging::log("[Sync] $cible IGNORÉ {$user['login']}: " . $e->getMessage());
            return true;

        } catch (Exception $e) {
            $this->repo->markSyncError($userId, $cible, $e->getMessage());
            $this->repo->audit($userId, 'SYNC_' . strtoupper($cible), $cible, 'ERREUR: ' . $e->getMessage());
            Logging::log("[Sync] $cible ERREUR {$user['login']}: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * Traite la file d'attente (pending) — appelé par cron ou page admin.
     */
    public function runPendingQueue(?string $cible = null): array
    {
        $pending = $this->repo->getPendingSync($cible);
        $results = ['ok' => 0, 'error' => 0];

        foreach ($pending as $row) {
            $success = $this->syncTo((int)$row['id'], $row['cible']);
            $success ? $results['ok']++ : $results['error']++;
        }

        return $results;
    }

    // ================================================================
    // SYNC → ACTIVE DIRECTORY
    // ================================================================

    private function syncToAD(array $user, ?string $plainPassword = null, ?string $oldLogin = null): void
    {
        $ad = new ADConnection();

        // Chercher le compte par son ANCIEN login si un renommage est en cours,
        // sinon par le login actuel (pas de changement de login).
        $searchLogin = ($oldLogin && $oldLogin !== $user['login']) ? $oldLogin : $user['login'];
        $existing    = $ad->searchUser($searchLogin);

        if ($existing) {
            // Mise à jour des attributs + renommage du login si nécessaire
            $attrs = $this->buildADAttributes($user);

            // Si le login a changé, mettre à jour sAMAccountName et UPN
            if ($oldLogin && $oldLogin !== $user['login']) {
                $attrs['sAMAccountName']    = $user['login'];
                $attrs['userPrincipalName'] = $user['upn'] ?: ($user['login'] . '@' . AD_DOMAIN);
            }

            if (!empty($attrs)) {
                if (!@ldap_mod_replace($ad->getConnection(), $existing['dn'], $attrs)) {
                    throw new Exception('ldap_mod_replace AD: ' . ldap_error($ad->getConnection()));
                }
            }
            Logging::log("[AD] Mise à jour {$user['login']}" . ($oldLogin && $oldLogin !== $user['login'] ? " (renommé depuis $oldLogin)" : ""));

        } else {
            // Création — tous les champs optionnels sont passés dans $extra
            // Le mot de passe ne vient JAMAIS de la base (password_tmp n'est plus
            // persisté) : soit il est fourni en mémoire au moment de la création,
            // soit AdConnection::createNewUser() en génère un nouveau (cas d'un
            // retry différé via la file d'attente / cron, sans mot de passe connu).
            $extra = array_filter([
                'login'        => $user['login']              ?: null,
                'password'     => $plainPassword               ?: null,
                'email'        => $user['email']              ?: null,
                'telephone'    => $user['telephone']          ?: null,
                'expiration'   => $user['date_expiration']    ?: null,
                'titre'        => $user['titre']              ?: null,
                'description'  => $user['poste']              ?: null,
                'departement'  => $user['departement']        ?: null,
                'societe'      => $user['societe']            ?: null,
                'bureau'       => $user['bureau']             ?: null,
                'adresse'      => $user['adresse_rue']        ?: null,
                'ville'        => $user['ville']              ?: null,
                'code_postal'  => $user['code_postal']        ?: null,
                'pays'         => $user['pays']               ?: null,
                'mobile'       => $user['telephone_mobile']   ?: null,
                'home_drive'   => $user['home_drive']         ?: null,
                'home_dir'     => $user['home_directory']     ?: null,
                'profile_path' => $user['profile_path']       ?: null,
                'logon_script' => $user['logon_script']       ?: null,
            ], fn($v) => $v !== null && $v !== '');

            $result = $ad->createNewUser(
                $user['nom'],
                $user['prenom'],
                $user['user_type'],
                $extra
            );

            $this->repo->updateDn((int)$user['id'], $result['dn']);
            $this->repo->updateAccountEnabled((int)$user['id'], true);
        }
    }

    /**
     * Construit le tableau d'attributs AD pour une mise à jour (mod_replace).
     * N'inclut que les champs non vides pour éviter d'écraser des valeurs AD.
     */
    private function buildADAttributes(array $user): array
    {
        $attrs = [];

        if (!empty($user['nom']))              $attrs['sn']                         = $user['nom'];
        if (!empty($user['prenom']))           $attrs['givenName']                  = $user['prenom'];
        if (!empty($user['nom']) && !empty($user['prenom']))
                                               $attrs['displayName']                = $user['prenom'] . ' ' . $user['nom'];
        if (!empty($user['email']))            $attrs['mail']                       = $user['email'];
        if (!empty($user['telephone']))        $attrs['telephoneNumber']            = $user['telephone'];
        if (!empty($user['telephone_mobile'])) $attrs['mobile']                     = $user['telephone_mobile'];
        if (!empty($user['titre']))            $attrs['title']                      = $user['titre'];
        if (!empty($user['poste']))            $attrs['description']                = $user['poste'];
        if (!empty($user['departement']))      $attrs['department']                 = $user['departement'];
        if (!empty($user['societe']))          $attrs['company']                    = $user['societe'];
        if (!empty($user['bureau']))           $attrs['physicalDeliveryOfficeName'] = $user['bureau'];
        if (!empty($user['adresse_rue']))      $attrs['streetAddress']              = $user['adresse_rue'];
        if (!empty($user['ville']))            $attrs['l']                          = $user['ville'];
        if (!empty($user['code_postal']))      $attrs['postalCode']                 = $user['code_postal'];
        if (!empty($user['pays']))             $attrs['co']                         = $user['pays'];
        if (!empty($user['home_drive']))       $attrs['homeDrive']                  = $user['home_drive'];
        if (!empty($user['home_directory']))   $attrs['homeDirectory']              = $user['home_directory'];
        if (!empty($user['profile_path']))     $attrs['profilePath']                = $user['profile_path'];
        if (!empty($user['logon_script']))     $attrs['scriptPath']                 = $user['logon_script'];

        return $attrs;
    }

    // ================================================================
    // SYNC → OPENLDAP
    // ================================================================

    private function syncToOpenLDAP(array $user, ?string $oldLogin = null): void
    {
        if (($user['user_type'] ?? '') !== 'permanent') {
            throw new SyncSkipped("OpenLDAP réservé au personnel permanent (type={$user['user_type']})");
        }

        if (!defined('OPENLDAP_SERVER') || empty(OPENLDAP_SERVER)) {
            throw new SyncSkipped('OpenLDAP non configuré');
        }

        $ldap = LdapOpenConnection::getInstance();
        $conn = $ldap->getConnection();

        $baseDn = defined('OPENLDAP_BASE_DN') ? OPENLDAP_BASE_DN : 'dc=esigelec,dc=local';
        $ou     = $user['user_type']; // toujours 'permanent' ici

        // Chercher le compte par l'ANCIEN login si renommage, sinon par le login actuel
        $searchLogin = ($oldLogin && $oldLogin !== $user['login']) ? $oldLogin : $user['login'];
        $oldDn       = "uid={$searchLogin},ou={$ou},ou=esigelec,{$baseDn}";
        $newDn       = "uid={$user['login']},ou={$ou},ou=esigelec,{$baseDn}";

        $attrs = [
            'objectClass'   => ['inetOrgPerson', 'posixAccount', 'shadowAccount'],
            'uid'           => $user['login'],
            'cn'            => $user['prenom'] . ' ' . $user['nom'],
            'sn'            => $user['nom'],
            'givenName'     => $user['prenom'],
            'uidNumber'     => $this->generateUidNumber($user['login']),
            'gidNumber'     => '1000',
            'homeDirectory' => '/home/' . $user['login'],
            'loginShell'    => '/bin/bash',
        ];

        if (!empty($user['email']))       $attrs['mail']            = $user['email'];
        if (!empty($user['telephone']))   $attrs['telephoneNumber'] = $user['telephone'];
        if (!empty($user['titre']))       $attrs['title']           = $user['titre'];
        if (!empty($user['departement'])) $attrs['ou']              = $user['departement'];
        if (!empty($user['societe']))     $attrs['o']               = $user['societe'];

        $search = @ldap_search($conn, $baseDn, "(uid={$searchLogin})", ['dn']);
        $exists = $search && ldap_count_entries($conn, $search) > 0;

        if ($exists) {
            // Si le login a changé, renommer le DN d'abord
            if ($oldLogin && $oldLogin !== $user['login']) {
                if (!@ldap_rename($conn, $oldDn, "uid={$user['login']}", null, true)) {
                    throw new Exception('OpenLDAP ldap_rename: ' . ldap_error($conn));
                }
                Logging::log("[OpenLDAP] DN renommé : $oldDn → $newDn");
            }

            $updateAttrs = $attrs;
            unset($updateAttrs['objectClass'], $updateAttrs['uid'],
                  $updateAttrs['uidNumber'], $updateAttrs['gidNumber']);
            if (!@ldap_mod_replace($conn, $newDn, $updateAttrs)) {
                throw new Exception('OpenLDAP mod_replace: ' . ldap_error($conn));
            }
        } else {
            if (!@ldap_add($conn, $newDn, $attrs)) {
                throw new Exception('OpenLDAP ldap_add: ' . ldap_error($conn));
            }
        }

        Logging::log("[OpenLDAP] Sync {$user['login']} OK");
    }

    // ================================================================
    // SYNC → AURION
    // ================================================================

    private function syncToAurion(array $user): void
    {
        if (!defined('AURION_SERVER') || empty(AURION_SERVER)) {
            Logging::log('[Aurion] Non configuré — sync ignorée', 'WARNING');
            return;
        }

        $payload = [
            'nom'           => $user['nom'],
            'prenom'        => $user['prenom'],
            'login'         => $user['login'],
            'email'         => $user['email'],
            'dateNaissance' => $user['date_naissance'],
            'telephone'     => $user['telephone'],
            'promotion'     => $user['promo'],
            'type'          => $user['user_type'],
        ];

        if (!empty($user['aurion_id'])) {
            $url    = AURION_SERVER . '/api/v1/utilisateurs/' . $user['aurion_id'];
            $method = 'PUT';
        } else {
            $url    = AURION_SERVER . '/api/v1/utilisateurs';
            $method = 'POST';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Aurion-Token: ' . (defined('AURION_API_TOKEN') ? AURION_API_TOKEN : ''),
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) throw new Exception("Aurion cURL error: $curlError");
        if ($httpCode < 200 || $httpCode >= 300) throw new Exception("Aurion HTTP $httpCode: $response");

        if ($method === 'POST' && !empty($response)) {
            $data = json_decode($response, true);
            if (!empty($data['id'])) {
                $this->repo->update((int)$user['id'], ['aurion_id' => $data['id']]);
            }
        }

        Logging::log("[Aurion] Sync {$user['login']} HTTP $httpCode OK");
    }

    // ----------------------------------------------------------------
    // HELPERS
    // ----------------------------------------------------------------

    private function generateUidNumber(string $login): int
    {
        return 10000 + (abs(crc32($login)) % 50000);
    }
}
?>
