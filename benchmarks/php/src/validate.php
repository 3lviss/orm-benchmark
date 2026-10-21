<?php

/**
 * Validation entry point (docs/PLAN_REPO.md 2.5) — not used for timing.
 *
 * Usage: php src/validate.php <implementation> [scenario ...]
 *
 * rand() and array_rand() are shadowed in the scenario namespaces (PHP resolves
 * unqualified function calls in the current namespace first), so every
 * implementation receives the same parameters derived from VALIDATE_U.
 * Per scenario: warm-up, then pg_stat_statements_reset(), N calls, and the
 * statements recorded by the database are printed as one JSON line.
 *
 * Note: D1 also uses ORDER BY RANDOM() in SQL, which cannot be fixed here.
 */

namespace Benchmark\Scenarios\RawSql {
    function rand(int $min, int $max): int { return \validate_rand($min, $max); }
    function array_rand(array $array): int|string { return \validate_array_rand($array); }
}

namespace Benchmark\Scenarios\Eloquent {
    function rand(int $min, int $max): int { return \validate_rand($min, $max); }
    function array_rand(array $array): int|string { return \validate_array_rand($array); }
}

namespace Benchmark\Scenarios\Doctrine {
    function rand(int $min, int $max): int { return \validate_rand($min, $max); }
    function array_rand(array $array): int|string { return \validate_array_rand($array); }
}

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';

    define('VALIDATE_U', (float)(getenv('VALIDATE_U') ?: 0.1));

    function validate_rand(int $min, int $max): int
    {
        return $min + (int)floor(VALIDATE_U * ($max - $min + 1));
    }

    function validate_array_rand(array $array): int|string
    {
        $keys = array_keys($array);
        return $keys[(int)floor(VALIDATE_U * count($keys))];
    }

    $n            = (int)(getenv('VALIDATE_N') ?: 10);
    $warmupRead   = (int)(getenv('VALIDATE_WARMUP') ?: 20);
    $warmupWrite  = (int)(getenv('VALIDATE_WARMUP_WRITE') ?: 5);
    $writeScenarios = ['C1', 'C2', 'D1'];

    $implementation = strtolower($argv[1] ?? 'raw_sql');
    $scenarios      = count($argv) > 2
        ? array_map('strtoupper', array_slice($argv, 2))
        : ['A1', 'A2', 'A3', 'A4', 'B1', 'B2', 'B3', 'C1', 'C2', 'D1'];

    $classMap = [
        'raw_sql'  => \Benchmark\Scenarios\RawSql\Scenario::class,
        'eloquent' => \Benchmark\Scenarios\Eloquent\Scenario::class,
        'doctrine' => \Benchmark\Scenarios\Doctrine\Scenario::class,
    ];
    $runner = new $classMap[$implementation]();

    $admin = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s',
            getenv('DB_HOST') ?: 'localhost', getenv('DB_PORT') ?: '5432', getenv('DB_NAME') ?: 'benchmark'),
        getenv('DB_USER') ?: 'benchmark',
        getenv('DB_PASS') ?: 'benchmark',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $statsSql = "SELECT query, calls::int AS calls, rows::int AS rows
                 FROM pg_stat_statements
                 WHERE dbid = (SELECT oid FROM pg_database WHERE datname = current_database())
                   AND query NOT ILIKE '%pg_stat_statements%'
                 ORDER BY calls DESC, query";

    foreach ($scenarios as $scenario) {
        $method = strtolower($scenario);
        $warmup = in_array($scenario, $writeScenarios) ? $warmupWrite : $warmupRead;
        $line   = ['lang' => 'php', 'implementation' => $implementation, 'scenario' => $scenario,
                   'u' => VALIDATE_U, 'n' => $n, 'warmup' => $warmup];
        try {
            for ($i = 0; $i < $warmup; $i++) {
                $runner->$method();
            }
            $admin->query('SELECT pg_stat_statements_reset()');
            $result = null;
            for ($i = 0; $i < $n; $i++) {
                $result = $runner->$method();
            }
            $line['statements'] = $admin->query($statsSql)->fetchAll(PDO::FETCH_ASSOC);
            $line['result']     = $result;
            $line['ok']         = true;
        } catch (\Throwable $e) {
            $line['ok']    = false;
            $line['error'] = $e->getMessage();
        }
        echo json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
    }
}
