<?php
/**
 * sync_admin.php — Administration des synchronisations + import AD → MySQL
 */

session_start();
require_once 'Logging.php';
require_once 'config.php';
require_once 'AdConnection.php';
require_once 'DBConfig.php';
require_once 'UtilisateurRepository.php';
require_once 'SyncManager.php';
require_once 'AdImportService.php';
require_once 'vendor/autoload.php';

$loader = new \Twig\Loader\FilesystemLoader('templates');
$twig   = new \Twig\Environment($loader);

$repo    = new UtilisateurRepository();
$sync    = new SyncManager($repo);
$service = new AdImportService($repo);

$message      = null;
$msgType      = null;
$importResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'sync_user') {
            $uid   = (int)($_POST['user_id'] ?? 0);
            $cible = $_POST['cible'] ?? null;
            $ok    = $cible ? $sync->syncTo($uid, $cible) : array_sum($sync->syncAll($uid));
            $message = $ok ? "Synchronisation lancée avec succès." : "Sync terminée avec des erreurs (voir logs).";
            $msgType = $ok ? 'success' : 'warning';

        } elseif ($action === 'run_queue') {
            $results = $sync->runPendingQueue($_POST['cible_queue'] ?? null);
            $message = "File traitée : {$results['ok']} OK, {$results['error']} erreur(s).";
            $msgType = $results['error'] === 0 ? 'success' : 'warning';

        } elseif ($action === 'import_ad') {
            // Import de tous les users AD → MySQL
            $importResult = $service->importAll();
            $message = "Import AD terminé : {$importResult['imported']} importé(s), {$importResult['skipped']} déjà en base, {$importResult['errors']} erreur(s).";
            $msgType = $importResult['errors'] === 0 ? 'success' : 'warning';

        } elseif ($action === 'import_ad_one') {
            $login = trim($_POST['login_import'] ?? '');
            if (empty($login)) throw new Exception('Login requis.');
            $id      = $service->importByLogin($login);
            $message = $id
                ? "Utilisateur '$login' importé depuis l'AD (id=$id)."
                : "Utilisateur '$login' était déjà présent en base.";
            $msgType = 'success';
        }

    } catch (Exception $e) {
        $message = 'Erreur : ' . $e->getMessage();
        $msgType = 'error';
        Logging::log('[sync_admin] ' . $e->getMessage(), 'ERROR');
    }
}

// Données tableau
$users = $repo->findAll([], 200);
foreach ($users as &$u) {
    $u['sync'] = $repo->getSyncStatus((int)$u['id']);
}
unset($u);

// Compteurs
$pending = ['ad' => 0, 'openldap' => 0, 'aurion' => 0];
$errors  = ['ad' => 0, 'openldap' => 0, 'aurion' => 0];
foreach ($users as $u) {
    foreach (['ad', 'openldap', 'aurion'] as $c) {
        if (($u['sync'][$c]['etat'] ?? '') === 'pending') $pending[$c]++;
        if (($u['sync'][$c]['etat'] ?? '') === 'error')   $errors[$c]++;
    }
}

echo $twig->render('sync_admin.html.twig', [
    'title'        => 'Synchronisations & Import AD',
    'current_page' => 'sync_admin',
    'message'      => $message,
    'msgType'      => $msgType,
    'users'        => $users,
    'pending'      => $pending,
    'errors'       => $errors,
    'importResult' => $importResult,
]);
?>
