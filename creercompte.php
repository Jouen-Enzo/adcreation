<?php
/**
 * Créer un compte utilisateur — permanent / stagiaire / vacataire
 * v3 : sauvegarde en MySQL, puis synchronisation AD / OpenLDAP / Aurion
 */

session_start();
require_once 'config.php';
require_once 'Logging.php';
require_once 'AdConnection.php';
require_once 'DBConfig.php';
require_once 'UtilisateurRepository.php';
require_once 'SyncManager.php';
require_once 'EnvoiMail.php';
require_once 'GenereDOCX.php';
require_once 'HomeDirectoryManager.php';
require_once 'data_referentiels.php';
require_once 'vendor/autoload.php';

$loader = new \Twig\Loader\FilesystemLoader('templates');
$twig   = new \Twig\Environment($loader);

$message     = null;
$messageType = null;
$user_data   = null;
$sync_status = null;

$repo = new UtilisateurRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ── Champs de base (obligatoires) ──
        $nom      = trim($_POST['nom']      ?? '');
        $prenom   = trim($_POST['prenom']   ?? '');
        $userType = trim($_POST['userType'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $telephone = trim($_POST['telephone'] ?? '');

        if (empty($nom) || empty($prenom)) {
            throw new Exception('Nom et prénom sont obligatoires.');
        }
        if (!LDAPConfig::validateUserType($userType)) {
            throw new Exception('Type de compte invalide.');
        }

        // ── Champs supplémentaires ──
        $date_naissance   = trim($_POST['date_naissance']   ?? '') ?: null;
        $lieu_naissance   = trim($_POST['lieu_naissance']   ?? '') ?: null;
        $genre            = trim($_POST['genre']            ?? '') ?: null;
        $nationalite      = trim($_POST['nationalite']      ?? '') ?: null;
        $telephone_mobile = trim($_POST['telephone_mobile'] ?? '') ?: null;
        $fax              = trim($_POST['fax']              ?? '') ?: null;
        $promo            = trim($_POST['promo']            ?? '') ?: null;
        $notes            = trim($_POST['notes']            ?? '') ?: null;

        // ── Champs avancés (mode création avancée) ──
        $mode_avance     = isset($_POST['mode_avance']);
        $titre           = $mode_avance ? (trim($_POST['titre']           ?? '') ?: null) : null;
        $poste           = $mode_avance ? (trim($_POST['poste']           ?? '') ?: null) : null;
        $departement     = $mode_avance ? (trim($_POST['departement']     ?? '') ?: null) : null;
        $societe         = $mode_avance ? (trim($_POST['societe']         ?? '') ?: null) : null;
        $service         = $mode_avance ? (trim($_POST['service']         ?? '') ?: null) : null;
        $manager_login   = $mode_avance ? (trim($_POST['manager_login']   ?? '') ?: null) : null;
        $bureau          = $mode_avance ? (trim($_POST['bureau']          ?? '') ?: null) : null;
        $adresse_rue     = $mode_avance ? (trim($_POST['adresse_rue']     ?? '') ?: null) : null;
        $ville           = $mode_avance ? (trim($_POST['ville']           ?? '') ?: null) : null;
        $code_postal     = $mode_avance ? (trim($_POST['code_postal']     ?? '') ?: null) : null;
        $etat_province   = $mode_avance ? (trim($_POST['etat_province']   ?? '') ?: null) : null;
        $pays            = $mode_avance ? (trim($_POST['pays']            ?? 'FR') ?: 'FR') : 'FR';
        $home_drive      = $mode_avance ? (trim($_POST['home_drive']      ?? '') ?: null) : null;
        $home_directory  = $mode_avance ? (trim($_POST['home_directory']  ?? '') ?: null) : null;
        $profile_path    = $mode_avance ? (trim($_POST['profile_path']    ?? '') ?: null) : null;
        $logon_script    = $mode_avance ? (trim($_POST['logon_script']    ?? '') ?: null) : null;
        $date_expiration = $mode_avance ? (trim($_POST['date_expiration'] ?? '') ?: null) : null;
        $date_debut      = $mode_avance ? (trim($_POST['date_debut']      ?? '') ?: null) : null;

        // ── Étape 1 : Générer login + mot de passe ──
        $ad         = new ADConnection();
        $login      = $ad->generateLoginPublic($nom, $prenom);
        $password   = $ad->generatePasswordPublic();
        $emailFinal = $email ?: (strtolower($prenom[0]) . '.' . preg_replace('/[^a-z0-9]/', '', strtolower(strtr(mb_strtolower($nom,'UTF-8'), ['é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','ç'=>'c','ù'=>'u','û'=>'u','ô'=>'o','î'=>'i']))) . '@' . AD_DOMAIN);
        $upn        = $login . '@' . AD_DOMAIN;

        // ── Répertoire personnel — permanents uniquement ──
        // Si le mode avancé n'a pas fourni de valeurs manuelles, on applique
        // le partage standard \\192.168.21.131\users\<sAMAccountName> avec P:
        if ($userType === 'permanent') {
            if (empty($home_drive)) {
                $home_drive = PERMANENT_HOME_DRIVE;
            }
            if (empty($home_directory)) {
                $home_directory = PERMANENT_HOME_SHARE . '\\' . $login;
            }

            // Créer le dossier + définir la propriété NTFS selon la méthode configurée
            // (PERMANENT_HOME_DIR_METHOD = 'php' ou 'powershell', voir config.php)
            HomeDirectoryManager::create($login, $home_directory);
        }

        // ── Étape 2 : Sauvegarde en base MySQL ──
        $userId = $repo->create([
            'nom'              => $nom,
            'prenom'           => $prenom,
            'date_naissance'   => $date_naissance,
            'lieu_naissance'   => $lieu_naissance,
            'genre'            => $genre,
            'nationalite'      => $nationalite,
            'login'            => $login,
            'email'            => $emailFinal,
            'upn'              => $upn,
            'password_tmp'     => '', // jamais stocké en base — voir syncAll($userId, $password)
            'user_type'        => $userType,
            'dn_ad'            => null,
            'account_enabled'  => 0,
            'telephone'        => $telephone,
            'telephone_mobile' => $telephone_mobile,
            'fax'              => $fax,
            'titre'            => $titre,
            'poste'            => $poste,
            'departement'      => $departement,
            'societe'          => $societe,
            'service'          => $service,
            'manager_login'    => $manager_login,
            'bureau'           => $bureau,
            'adresse_rue'      => $adresse_rue,
            'ville'            => $ville,
            'code_postal'      => $code_postal,
            'etat_province'    => $etat_province,
            'pays'             => $pays,
            'home_drive'       => $home_drive,
            'home_directory'   => $home_directory,
            'profile_path'     => $profile_path,
            'logon_script'     => $logon_script,
            'date_expiration'  => $date_expiration,
            'date_debut'       => $date_debut,
            'aurion_id'        => null,
            'promo'            => $promo,
            'created_by'       => $_SESSION['user'] ?? 'web',
            'notes'            => $notes,
        ]);

        // ── Étape 3 : Synchronisation vers AD / OpenLDAP / Aurion ──
        $sync        = new SyncManager($repo);
        $syncResults = $sync->syncAll($userId, $password);
        $sync_status = $repo->getSyncStatus($userId);

        // ── Étape 4b : Groupe Auriga — permanents uniquement, AD seulement ──
        if ($userType === 'permanent' && isset($_POST['ajouter_aurion'])) {
            // Groupe Auriga : CN=Auriga,OU=groupe,OU=permanent,OU=esigelec,DC=test,DC=local
            $aurigaDn = 'CN=Auriga,OU=groupe,OU=permanent,OU=esigelec,' . AD_BASE_DN;
            try {
                $userDb = $userDb ?? $repo->findById($userId);
                if (!empty($userDb['dn_ad'])) {
                    $ad->addUserToGroup($userDb['dn_ad'], $aurigaDn);
                    Logging::log("Ajout au groupe Auriga : {$userDb['dn_ad']}");
                } else {
                    Logging::log("Groupe Auriga ignoré : DN AD non disponible pour $login", 'WARNING');
                }
            } catch (Exception $e) {
                Logging::log('Groupe Auriga : ' . $e->getMessage(), 'WARNING');
            }
        }


        $docFile = null;
        if (isset($_POST['generate_docx'])) {
            $docFile = GenereDOCX::generateUserDoc($nom, $prenom, $login, $password, $userType, []);
        }

        // ── Étape 6 : Email optionnel ──
        if (isset($_POST['send_email']) && !empty($emailFinal)) {
            EnvoiMail::sendWelcomeEmail($emailFinal, $login, $password, $nom, $prenom, $docFile);
        }

        $userDb    = $repo->findById($userId);
        $user_data = [
            'id'       => $userId,
            'login'    => $login,
            'email'    => $emailFinal,
            'password' => $password,
            'type'     => $userType,
            'dn'       => $userDb['dn_ad'] ?? '(en attente de sync)',
            'docFile'  => $docFile,
        ];

        $message     = "Compte créé et enregistré en base ! Login : $login";
        $messageType = 'success';
        Logging::log("Compte créé : $nom $prenom ($login) type=$userType | AD:{$syncResults['ad']} LDAP:{$syncResults['openldap']} Aurion:{$syncResults['aurion']}");

    } catch (Exception $e) {
        $message     = 'Erreur : ' . $e->getMessage();
        $messageType = 'error';
        Logging::log('Erreur création compte : ' . $e->getMessage(), 'ERROR');
    }
}

echo $twig->render('creercompte.html.twig', [
    'title'        => 'Créer un compte',
    'current_page' => 'creercompte',
    'message'      => $message,
    'messageType'  => $messageType,
    'user_data'    => $user_data,
    'sync_status'  => $sync_status,
    'user_types'   => USER_TYPES,
    'groupes'      => [], // groupes AD retirés de l'UI
    'nationalites' => array_keys(getNationalites()),
    'villes'       => getVillesFrance(),
]);
?>
