<?php
/**
 * Classe de logging pour tracer les actions
 */

class Logging {
    private static $logDir = __DIR__ . "/logs";
    private static $logFile = null;
    
    public static function initialize() {
        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
        
        $date = date('Y-m-d');
        self::$logFile = self::$logDir . "/adcreation_$date.log";
    }
    
    public static function log($message, $level = "INFO") {
        if (self::$logFile === null) {
            self::initialize();
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        
        file_put_contents(self::$logFile, $logMessage, FILE_APPEND);
        
        // Aussi sauvegarder en JSON pour consultation via web
        self::saveJsonLog($message, $level);
    }
    
    private static function saveJsonLog($message, $level = "INFO") {
        $jsonLogFile = self::$logDir . "/logs.json";
        
        $logEntry = array(
            "timestamp" => date('Y-m-d H:i:s'),
            "level" => $level,
            "message" => $message
        );
        
        $logs = array();
        if (file_exists($jsonLogFile)) {
            $content = file_get_contents($jsonLogFile);
            $logs = json_decode($content, true) ?? array();
        }
        
        array_unshift($logs, $logEntry);
        
        // Garder les 1000 dernières entrées
        if (count($logs) > 1000) {
            $logs = array_slice($logs, 0, 1000);
        }
        
        file_put_contents($jsonLogFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    public static function getLogs($limit = 100) {
        $jsonLogFile = self::$logDir . "/logs.json";
        
        if (!file_exists($jsonLogFile)) {
            return array();
        }
        
        $content = file_get_contents($jsonLogFile);
        $logs = json_decode($content, true) ?? array();
        
        return array_slice($logs, 0, $limit);
    }
    
    public static function getErrorLogs($limit = 50) {
        $logs = self::getLogs($limit * 3);
        
        return array_filter($logs, function($log) {
            return $log["level"] === "ERROR" || $log["level"] === "WARNING";
        });
    }
}

Logging::initialize();
?>
