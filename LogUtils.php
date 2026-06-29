<?php
/**
 * Utilitaires de logging
 */

class LogUtils {
    
    /**
     * Exporte les logs en CSV
     */
    public static function exportLogsToCSV($filename = null) {
        if ($filename === null) {
            $filename = __DIR__ . "/exports/logs_" . date('Y-m-d_His') . ".csv";
        }
        
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $logs = Logging::getLogs(1000);
        $fp = fopen($filename, 'w');
        
        // En-têtes
        fputcsv($fp, array('Timestamp', 'Level', 'Message'));
        
        // Données
        foreach ($logs as $log) {
            fputcsv($fp, array($log['timestamp'], $log['level'], $log['message']));
        }
        
        fclose($fp);
        return $filename;
    }
    
    /**
     * Nettoie les anciens logs
     */
    public static function cleanOldLogs($daysOld = 30) {
        $logsDir = __DIR__;
        $cutoffTime = time() - ($daysOld * 24 * 60 * 60);
        
        $files = glob($logsDir . "/*.log");
        $removed = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
                $removed++;
            }
        }
        
        return $removed;
    }
    
    /**
     * Récupère les statistiques d'utilisation
     */
    public static function getUsageStats() {
        $logs = Logging::getLogs(10000);
        
        $stats = array(
            'total_operations' => count($logs),
            'by_level' => array(),
            'by_hour' => array(),
            'by_operation' => array()
        );
        
        foreach ($logs as $log) {
            // Par level
            $level = $log['level'];
            if (!isset($stats['by_level'][$level])) {
                $stats['by_level'][$level] = 0;
            }
            $stats['by_level'][$level]++;
            
            // Par heure
            $hour = substr($log['timestamp'], 11, 2);
            if (!isset($stats['by_hour'][$hour])) {
                $stats['by_hour'][$hour] = 0;
            }
            $stats['by_hour'][$hour]++;
            
            // Par type d'opération
            if (preg_match('/Apprenant créé|Compte créé|Utilisateur créé/', $log['message'])) {
                $op = 'user_creation';
            } elseif (preg_match('/mis à jour|Mise à jour/', $log['message'])) {
                $op = 'user_update';
            } elseif (preg_match('/Email envoyé/', $log['message'])) {
                $op = 'email_sent';
            } elseif (preg_match('/Document|DOCX/', $log['message'])) {
                $op = 'docx_generated';
            } else {
                $op = 'other';
            }
            
            if (!isset($stats['by_operation'][$op])) {
                $stats['by_operation'][$op] = 0;
            }
            $stats['by_operation'][$op]++;
        }
        
        return $stats;
    }
}
?>
