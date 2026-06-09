<?php

namespace Benchmark\Scenarios\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

/**
 * Bootstraps Doctrine ORM without Symfony framework.
 * Uses attribute-based entity mapping (Doctrine 3.x style).
 */
class Bootstrap
{
    private static ?EntityManager $em = null;

    public static function entityManager(): EntityManager
    {
        if (self::$em === null) {
            $config = ORMSetup::createAttributeMetadataConfiguration(
                paths: [__DIR__ . '/Entities'],
                isDevMode: false,
            );

            // Attach query counter middleware before creating connection
            $config->setMiddlewares([new QueryCountingMiddleware()]);

            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => getenv('DB_HOST') ?: 'localhost',
                'port'     => (int)(getenv('DB_PORT') ?: 5432),
                'dbname'   => getenv('DB_NAME') ?: 'benchmark',
                'user'     => getenv('DB_USER') ?: 'benchmark',
                'password' => getenv('DB_PASS') ?: 'benchmark',
            ], $config);

            self::$em = new EntityManager($connection, $config);
        }

        return self::$em;
    }

    /**
     * Clears the EntityManager identity map between benchmark iterations.
     * Prevents first-level cache from skewing latency measurements.
     */
    public static function clear(): void
    {
        self::$em?->clear();
    }
}
