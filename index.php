<?php
/**
 * Page d'accueil - ADCreation
 */

session_start();
require_once 'Logging.php';

// Initialisation Twig
require_once 'vendor/autoload.php';

$loader = new \Twig\Loader\FilesystemLoader('templates');
$twig = new \Twig\Environment($loader);

// Récupère les logs récents
$logs = Logging::getLogs(10);

// Prépare les données pour le template
$data = array(
    'title' => 'Gestion des Comptes Active Directory - ESIGELEC',
    'current_page' => 'index',
    'logs' => $logs,
    'features' => array(
        array(
            'title' => 'Créer un Compte',
            'description' => 'Créer un nouveau compte utilisateur dans Active Directory',
            'url' => 'creercompte.php',
            'icon' => '👤'
        ),
        /*array(
            'title' => 'Création en Masse',
            'description' => 'Créer plusieurs comptes à partir d\'un fichier XML ou CSV',
            'url' => 'creationenmasse.php',
            'icon' => '👥'
        ),*/
        array(
            'title' => 'Modifier un Compte **',
            'description' => 'Modifier les informations d\'un compte existant',
            'url' => 'modifiercompte.php',
            'icon' => '✏️'
        ),
        array(
            'title' => 'Logs d\'Activité',
            'description' => 'Consulter les logs des opérations effectuées',
            'url' => 'logs.php',
            'icon' => '📋'
        )
    )
);

echo $twig->render('index.html.twig', $data);
?>
