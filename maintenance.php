#!/usr/bin/env php
<?php
/**
 * Script de maintenance et administration
 * Usage: php maintenance.php [action] [options]
 */

require_once 'Logging.php';
require_once 'LogUtils.php';

$action = $argv[1] ?? 'help';
$option = $argv[2] ?? null;

switch ($action) {
    case 'clean-logs':
        $days = $option ?? 30;
        $removed = LogUtils::cleanOldLogs($days);
        echo "Nettoyage terminé: $removed fichier(s) supprimé(s)\n";
        break;
    
    case 'export-logs':
        $file = LogUtils::exportLogsToCSV();
        echo "Logs exportés: $file\n";
        break;
    
    case 'stats':
        $stats = LogUtils::getUsageStats();
        echo "=== STATISTIQUES D'UTILISATION ===\n";
        echo "Total d'opérations: " . $stats['total_operations'] . "\n";
        echo "\nPar niveau:\n";
        foreach ($stats['by_level'] as $level => $count) {
            echo "  $level: $count\n";
        }
        echo "\nPar type d'opération:\n";
        foreach ($stats['by_operation'] as $op => $count) {
            echo "  $op: $count\n";
        }
        break;
    
    case 'help':
    default:
        echo "ADCreation - Script de Maintenance\n";
        echo "Usage: php maintenance.php [action] [options]\n\n";
        echo "Actions disponibles:\n";
        echo "  clean-logs [days]   - Nettoie les logs plus anciens que N jours (default: 30)\n";
        echo "  export-logs         - Exporte les logs en CSV\n";
        echo "  stats               - Affiche les statistiques d'utilisation\n";
        echo "  help                - Affiche cette aide\n";
        break;
}
?>
