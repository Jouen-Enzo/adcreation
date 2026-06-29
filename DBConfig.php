<?php
/**
 * DBConfig — Connexion MySQL centralisée (singleton)
 *
 * Utilise les constantes définies dans config.php :
 *   DB_HOST, DB_USER, DB_PASSWORD, DB_NAME
 */

class DBConfig
{
    private static ?mysqli $connection = null;

    public static function getConnection(): mysqli
    {
        if (self::$connection === null) {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

            if ($conn->connect_error) {
                $msg = 'Erreur connexion BD : ' . $conn->connect_error;
                Logging::log($msg, 'ERROR');
                throw new Exception($msg);
            }

            $conn->set_charset('utf8mb4');
            self::$connection = $conn;
        }

        return self::$connection;
    }

    public static function closeConnection(): void
    {
        if (self::$connection !== null) {
            self::$connection->close();
            self::$connection = null;
        }
    }
}
?>
