<?php

namespace Benchmark\Scenarios\Doctrine;

use Benchmark\QueryCounter;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;

/**
 * DBAL Middleware that increments QueryCounter on every executed statement.
 * Implements the official Doctrine DBAL Middleware interface (DBAL 4.x).
 *
 * DBAL 4.x changed getDatabasePlatform() to accept ServerVersionProvider.
 */
class QueryCountingMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) implements Driver {
            public function __construct(private readonly Driver $wrapped) {}

            public function connect(array $params): Connection
            {
                $conn = $this->wrapped->connect($params);
                return new class($conn) implements Connection {
                    public function __construct(private readonly Connection $wrapped) {}

                    public function prepare(string $sql): Statement
                    {
                        QueryCounter::increment();
                        return $this->wrapped->prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        QueryCounter::increment();
                        return $this->wrapped->query($sql);
                    }

                    public function quote(string $value): string
                    {
                        return $this->wrapped->quote($value);
                    }

                    public function exec(string $sql): int
                    {
                        QueryCounter::increment();
                        return $this->wrapped->exec($sql);
                    }

                    public function lastInsertId(): int|string
                    {
                        return $this->wrapped->lastInsertId();
                    }

                    public function beginTransaction(): void
                    {
                        $this->wrapped->beginTransaction();
                    }

                    public function commit(): void
                    {
                        $this->wrapped->commit();
                    }

                    public function rollBack(): void
                    {
                        $this->wrapped->rollBack();
                    }

                    public function getNativeConnection(): mixed
                    {
                        return $this->wrapped->getNativeConnection();
                    }

                    // Required by ServerVersionProvider (DBAL 4.x)
                    public function getServerVersion(): string
                    {
                        return $this->wrapped->getServerVersion();
                    }
                };
            }

            // DBAL 4.x signature — requires ServerVersionProvider parameter
            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                return $this->wrapped->getDatabasePlatform($versionProvider);
            }

            public function getExceptionConverter(): ExceptionConverter
            {
                return $this->wrapped->getExceptionConverter();
            }
        };
    }
}
