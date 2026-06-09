<?php

namespace Benchmark\Scenarios\Eloquent;

use Benchmark\QueryCounter;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;

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

        $capsuleManager = new Manager();

        $capsuleManager->addConnection([
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

        $capsuleManager->setAsGlobal();
        $capsuleManager->bootEloquent();

        // Capsule standalone does not create an event dispatcher automatically.
        // We must instantiate and set one before registering any listeners.
        $dispatcher = new Dispatcher();
        $capsuleManager->setEventDispatcher($dispatcher);

        $dispatcher->listen(QueryExecuted::class, function () {
            QueryCounter::increment();
        });

        self::$initialized = true;
    }
}