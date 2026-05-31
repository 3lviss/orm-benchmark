<?php

namespace Benchmark\Scenarios\Eloquent;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Bootstraps Eloquent ORM without Laravel framework.
 * Uses Illuminate\Database\Capsule\Manager as standalone setup.
 */
class Bootstrap
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        $capsule = new Capsule();

        $capsule->addConnection([
            'driver'   => 'pgsql',
            'host'     => getenv('DB_HOST') ?: 'localhost',
            'port'     => getenv('DB_PORT') ?: '5432',
            'database' => getenv('DB_NAME') ?: 'benchmark',
            'username' => getenv('DB_USER') ?: 'benchmark',
            'password' => getenv('DB_PASS') ?: 'benchmark',
            'charset'  => 'utf8',
            'prefix'   => '',
            'schema'   => 'public',
        ]);

        // Make capsule globally available
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$initialized = true;
    }
}
