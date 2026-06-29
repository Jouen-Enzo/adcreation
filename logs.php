<?php
/**
 * Consulter les Logs d'Activité
 */

session_start();
require_once 'Logging.php';
require_once 'vendor/autoload.php';

$loader = new \Twig\Loader\FilesystemLoader('templates');
$twig = new \Twig\Environment($loader);

$logs = Logging::getLogs(200);
$error_logs = Logging::getErrorLogs(50);

$data = array(
    'title' => 'Logs d\'Activité',
    'current_page' => 'logs',
    'logs' => $logs,
    'error_logs' => $error_logs,
    'log_count' => count($logs),
    'error_count' => count($error_logs)
);

echo $twig->render('logs.html.twig', $data);
?>
