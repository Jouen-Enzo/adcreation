<?php
/**
 * sync_badges.php — Synchronisation Aurion → OpenLDAP (attributs badges)
 */

session_start();
require_once 'config_ldap.php';
require_once 'Logging.php';
require_once 'AurionBadgeSync.php';
require_once 'vendor/autoload.php';

$loader = new \Twig\Loader\FilesystemLoader('templates');
$twig   = new \Twig\Environment($loader);

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    $syncer = new AurionBadgeSync();
    $result = $syncer->run();
    Logging::log("[BadgeSync] Terminé — {$result['updated']} MàJ, {$result['skipped']} inchangés, {$result['errors']} erreurs");
}

echo $twig->render('sync_badges.html.twig', [
    'title'        => 'Sync Badges Aurion',
    'current_page' => 'sync_badges',
    'result'       => $result,
    'aurion_url_eleves'    => defined('AURION_URL_ELEVES')    ? AURION_URL_ELEVES    : '',
    'aurion_url_personnel' => defined('AURION_URL_PERSONNEL') ? AURION_URL_PERSONNEL : '',
    'ldap_base_dn' => defined('OPENLDAP_BADGES_BASE_DN') ? OPENLDAP_BADGES_BASE_DN : '',
]);
?>
