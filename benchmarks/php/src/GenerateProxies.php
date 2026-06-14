<?php

/**
 * Generates Doctrine proxy classes at container startup.
 * Called from docker-entrypoint.sh before running benchmarks.
 *
 * Requires PostgreSQL to be available (called at runtime, not build time).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Benchmark\Scenarios\Doctrine\Bootstrap;

$em       = Bootstrap::entityManager();
$metadata = $em->getMetadataFactory()->getAllMetadata();
$em->getProxyFactory()->generateProxyClasses($metadata);

echo "Generated " . count($metadata) . " proxy classes\n";
