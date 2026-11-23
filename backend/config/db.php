<?php
declare(strict_types=1);

/**
 * Provides a single PDO connection for the current PHP request.
 * MySQL and MariaDB compatible PDO connection. Configure DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD.
 */
final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = self::environmentValue('DB_HOST', '127.0.0.1');
        $port = self::environmentValue('DB_PORT', '3306');
        $database = self::environmentValue('DB_NAME', 'smart_gym');
        $username = self::environmentValue('DB_USER', 'root');
        $password = self::environmentValue('DB_PASSWORD', '');

        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new InvalidArgumentException('DB_PORT must be a valid TCP port.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            (int) $port,
            $database
        );

        try {
            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
            // Keep dates and timestamps consistent with the UTC values stored in the schema.
            self::$connection->exec("SET time_zone = '+00:00'");
        } catch (PDOException $exception) {
            // Log the detailed exception server-side; never expose credentials or DSN to clients.
            error_log('Database connection failed: ' . $exception->getMessage());
            throw new RuntimeException('Database connection is unavailable.', 0, $exception);
        }

        return self::$connection;
    }

    private static function environmentValue(string $key, string $default): string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}

/** Returns the shared PDO instance for convenient use by API endpoints. */
function db(): PDO
{
    return Database::connection();
}

