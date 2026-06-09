<?php

namespace Benchmark;

/**
 * Counts SQL queries executed during a single benchmark iteration.
 *
 * Usage:
 *   QueryCounter::reset();      // before each iteration
 *   // ... run scenario ...
 *   QueryCounter::get();        // after each iteration
 */
final class QueryCounter
{
    private static int $count = 0;

    public static function reset(): void
    {
        self::$count = 0;
    }

    public static function increment(): void
    {
        self::$count++;
    }

    public static function get(): int
    {
        return self::$count;
    }
}