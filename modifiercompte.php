<?php
/**
 * modifiercompte.php — Recherche, modification et suppression d'un compte utilisateur
 */

session_start();

require_once 'config.php';
require_once 'Logging.php';
require_once 'AdConnection.php';
require_once 'DBConfig.php';
require_once 'UtilisateurRepository.php';
require_once 'SyncManager.php';
require_once 'data_referentiels.php';
require_once 'vendor/autoload.php';

$loader = new \Twig\Loader\FilesystemLoader('templates');
$twig   = new \Twig\Environment($loader);

$message     = null;
$messageType = 'info';
$user_info   = null;

$repo = new UtilisateurRepository();

/* ─────────────────────────────
   FILTRES LISTE
───────────────────────────── */
$search     = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['user_type'] ?? '');

$filters = [];
if ($search)     $filters['search'] = $search;
if ($typeFilter) $filters['user_type'] = $typeFilter;

$users = $repo->findAll($filters, 200) ?? [];

/* ─────────────────────────────
   POST ACTIONS
───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? 'search';

    /* =========================
       SEARCH USER
    ========================= */
    if ($action === 'search') {
        try {
            $login = trim($_POST['login'] ?? '');
            if ($login === '') {
                throw new Exception('Login manquant');
            }

            $user_info = $repo->findByLogin($login);

            if (!$user_info) {

                $ad = new ADConnection();
                $adResult = $ad->searchUser($login);

                if (!$adResult) {
                    throw new Exception("Utilisateur introuvable (base + AD)");
                }

                $user_info = [
                    'id'        => null,
                    'login'     => $adResult['samaccountname'][0] ?? $login,
                    'nom'       => $adResult['sn'][0] ?? '',
                    'prenom'    => $adResult['givenname'][0] ?? '',
                    'email'     => $adResult['mail'][0] ?? '',
                    'telephone' => $adResult['telephonenumber'][0] ?? '',
                    'user_type' => '',
                    '_ad_only'  => true,
                ];

                $messageType = 'warning';
                $message = "Utilisateur trouvé uniquement dans l'AD";
            } else {
                $message = "Utilisateur chargé";
                $messageType = 'info';
            }

        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }

    /* =========================
       UPDATE USER
    ========================= */
    if ($action === 'update') {
        try {

            $id = (int)($_POST['user_id'] ?? 0);
            if (!$id) throw new Exception("ID manquant");

            $userCurrent = $repo->findById($id);
            if (!$userCurrent) throw new Exception("Utilisateur introuvable");

            $updates = [];

            $fields = [
                'nom' => 'nom',
                'prenom' => 'prenom',
                'email' => 'email',
                'telephone' => 'telephone',
                'titre' => 'titre',
                'departement' => 'departement',
                'description' => 'description',
                'nationalite' => 'nationalite',
                'lieu_naissance' => 'lieu_naissance',
            ];

            foreach ($fields as $post => $col) {
                $val = trim($_POST[$post] ?? '');
                if ($val !== '') {
                    $updates[$col] = $val;
                }
            }

            $oldLogin = $userCurrent['login'];

            $newLogin = trim($_POST['new_login'] ?? '');

            $newNom    = $updates['nom'] ?? $userCurrent['nom'];
            $newPrenom = $updates['prenom'] ?? $userCurrent['prenom'];

            if ($newLogin && $newLogin !== $oldLogin) {
                $updates['login'] = $newLogin;
                $updates['upn'] = $newLogin . '@' . AD_DOMAIN;
                $login = $newLogin;
            }

            if (empty($updates)) {
                throw new Exception("Aucune modification");
            }

            $repo->update($id, $updates, 'web_modifier');

            try {
                $sync = new SyncManager($repo);
                $syncResults = $sync->syncAll($id, null, $oldLogin);
            } catch (Exception $e) {
                Logging::log("SYNC ERROR: " . $e->getMessage(), "ERROR");
                $syncResults = ['ad'=>false,'openldap'=>false,'aurion'=>false];
            }

            $message = "Mise à jour effectuée";
            $messageType = 'success';

            $user_info = $repo->findById($id);

        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }

    /* =========================
       DELETE USER
    ========================= */
    if ($action === 'delete') {
        try {

            $id = (int)($_POST['user_id'] ?? 0);
            $confirmation = trim($_POST['confirmation'] ?? '');

            if ($id === 0) throw new Exception("ID manquant");
            if ($confirmation !== 'Oui.') throw new Exception("Confirmation incorrecte");

            $user = $repo->findById($id);
            if (!$user) throw new Exception("Utilisateur introuvable");

            $login = $user['login'];
            $userType = $user['user_type'] ?? 'permanent';

            $results = ['ad'=>false,'openldap'=>false,'aurion'=>false,'mysql'=>false];

            /* AD */
            try {
                $ad = new ADConnection();
                $existing = $ad->searchUser($login);

                if ($existing) {
                    $ad->supprimerUtilisateur($existing['dn']);
                }
                $results['ad'] = true;

            } catch (Exception $e) {
                Logging::log($e->getMessage(), "ERROR");
            }

            /* OPENLDAP */
            try {
                if ($userType === 'permanent') {
                    require_once 'LdapOpenConnection.php';
                    $ldap = LdapOpenConnection::getInstance();
                    $conn = $ldap->getConnection();

                    $dn = "uid={$login},ou=" . strtolower($userType) . ",ou=esigelec," . OPENLDAP_BASE_DN;

                    @ldap_delete($conn, $dn);
                }
                $results['openldap'] = true;
            } catch (Exception $e) {
                Logging::log($e->getMessage(), "ERROR");
            }

            /* MYSQL */
            try {
                $db = DBConfig::getConnection();
                $stmt = $db->prepare("DELETE FROM utilisateurs WHERE id=?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();

                $results['mysql'] = true;

            } catch (Exception $e) {
                Logging::log($e->getMessage(), "ERROR");
            }

            $redirect = $_SERVER['HTTP_REFERER'] ?? 'modifiercompte.php';
            header("Location: " . $redirect . "?deleted=1");
            exit;

        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

/* ─────────────────────────────
   CLEAN DATA POUR TWIG
───────────────────────────── */
if (!is_array($users)) $users = [];
if (!is_array($user_info)) $user_info = null;

/* ─────────────────────────────
   RENDER TWIG
───────────────────────────── */
echo $twig->render('modifiercompte.html.twig', [
    'title'        => 'Modifier un compte',
    'users'        => $users,
    'user_info'    => $user_info,
    'search'       => $search,
    'type_filter'  => $typeFilter,
    'user_types'   => USER_TYPES,
    'nationalites' => array_keys(getNationalites()),
    'villes'       => getVillesFrance(),
    'message'      => $message,
    'messageType'  => $messageType
]);
?>