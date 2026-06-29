#!/usr/bin/env php
<?php
/**
 * sync_cron.php — Script cron de synchronisation automatique
 *
 * Usage :
 *   php sync_cron.php              # Traite toutes les cibles
 *   php sync_cron.php ad           # Traite uniquement AD
 *   php sync_cron.php openldap     # Traite uniquement OpenLDAP
 *   php sync_cron.php aurion       # Traite uniquement Aurion
 *
 * Crontab (toutes les 5 minutes) :
 *   *\/5 * * * * /usr/bin/php /var/www/html/adcreation/sync_cron.php >> /var/log/adcreation_sync.log 2>&1
 */

// Chemin vers la racine du projet
chdir(__DIR__);

require_once 'Logging.php';
require_once 'AdConnection.php';
require_once 'DBConfig.php';
require_once 'UtilisateurRepository.php';
require_once 'SyncManager.php';
require_once 'vendor/autoload.php';

$cible = $argv[1] ?? null;
if ($cible && !in_array($cible, ['ad', 'openldap', 'aurion'])) {
    echo "Usage : php sync_cron.php [ad|openldap|aurion]\n";
    exit(1);
}

$repo  = new UtilisateurRepository();
$sync  = new SyncManager($repo);

$label = $cible ?? 'toutes cibles';
Logging::log("[CRON] Démarrage sync — $label");
echo date('[Y-m-d H:i:s]') . " Sync cron — $label\n";

$results = $sync->runPendingQueue($cible);

$msg = "OK: {$results['ok']}, Erreurs: {$results['error']}";
Logging::log("[CRON] Terminé — $msg");
echo date('[Y-m-d H:i:s]') . " $msg\n";

exit($results['error'] > 0 ? 1 : 0);
