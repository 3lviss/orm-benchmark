<?php

namespace Benchmark;

use PDO;
use PDOException;

class Connection
{
    private static ?PDO $pdo = null;

    /**
     * Returns a shared PDO connection using environment variables.
     * Connection pool size is controlled at the container level.
     */
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = getenv('DB_HOST') ?: 'localhost';
            $port = getenv('DB_PORT') ?: '5432';
            $name = getenv('DB_NAME') ?: 'benchmark';
            $user = getenv('DB_USER') ?: 'benchmark';
            $pass = getenv('DB_PASS') ?: 'benchmark';

            $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT         => false,
            ]);
        }

        return self::$pdo;
    }

    /**
     * Returns DSN string for ORMs that need it directly.
     */
    public static function dsn(): string
    {
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_NAME') ?: 'benchmark';

        return "pgsql:host={$host};port={$port};dbname={$name}";
    }
}
