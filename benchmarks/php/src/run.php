<?php

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Entry point for PHP benchmarks.
 *
 * Usage: php src/run.php <implementation> <scenario>
 *
 * Warm-up and measurement use adaptive stopping rules:
 * - Warm-up: continues until CV < 5% over rolling window of 10
 * - Measurement: continues until bootstrap p99 CI width < 5% of p99
 */
$implementation = strtolower($argv[1] ?? 'raw_sql');
$scenario       = strtoupper($argv[2] ?? 'A1');
$method         = strtolower($scenario);

$classMap = [
    'raw_sql'  => \Benchmark\Scenarios\RawSql\Scenario::class,
    'eloquent' => \Benchmark\Scenarios\Eloquent\Scenario::class,
    'doctrine' => \Benchmark\Scenarios\Doctrine\Scenario::class,
];

if (!isset($classMap[$implementation])) {
    fwrite(STDERR, "Unknown implementation: {$implementation}\n");
    exit(1);
}

$class = $classMap[$implementation];

if (!class_exists($class)) {
    fwrite(STDERR, "Class not found: {$class}\n");
    exit(1);
}

$runner = new $class();

if (!method_exists($runner, $method)) {
    fwrite(STDERR, "Unknown scenario: {$scenario}\n");
    exit(1);
}

// ── Warm-up phase (adaptive: CV < 5% over rolling window of 10) ──────────────
$warmupWindow   = [];
$warmupCount    = 0;
$warmupDone     = false;
$maxWarmup      = 2000;

while (!$warmupDone && $warmupCount < $maxWarmup) {
    $start = hrtime(true);
    $runner->$method();
    $end = hrtime(true);

    $ms = ($end - $start) / 1_000_000;
    $warmupWindow[] = $ms;
    $warmupCount++;

    if (count($warmupWindow) > 10) {
        array_shift($warmupWindow);
    }

    if (count($warmupWindow) === 10) {
        $mean = array_sum($warmupWindow) / 10;
        if ($mean > 0) {
            $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $warmupWindow)) / 10;
            $cv = sqrt($variance) / $mean * 100;
            if ($cv < 5.0) {
                $warmupDone = true;
            }
        }
    }
}

// ── Measurement phase (adaptive: bootstrap CI width < 5% of p99) ─────────────
$measurements   = [];
$maxMeasure     = 10000;
$checkEvery     = 100;
$minMeasure     = 100;

while (count($measurements) < $maxMeasure) {
    $start = hrtime(true);
    $runner->$method();
    $end = hrtime(true);

    $measurements[] = ($end - $start) / 1_000_000;

    $n = count($measurements);

    if ($n >= $minMeasure && $n % $checkEvery === 0) {
        sort($measurements);
        $p99index = (int) ceil(0.99 * $n) - 1;
        $p99value = $measurements[$p99index];

        if ($p99value > 0) {
            // Bootstrap CI estimation
            $bootstrapP99s = [];
            for ($b = 0; $b < 500; $b++) {
                $sample = [];
                for ($s = 0; $s < $n; $s++) {
                    $sample[] = $measurements[rand(0, $n - 1)];
                }
                sort($sample);
                $bootstrapP99s[] = $sample[(int) ceil(0.99 * $n) - 1];
            }
            sort($bootstrapP99s);
            $lower = $bootstrapP99s[(int) (0.025 * 500)];
            $upper = $bootstrapP99s[(int) (0.975 * 500)];
            $ciWidth = (($upper - $lower) / $p99value) * 100;

            if ($ciWidth < 5.0) {
                break;
            }
        }
    }
}

// ── Compute final statistics ──────────────────────────────────────────────────
sort($measurements);
$n      = count($measurements);
$p50    = $measurements[(int) ceil(0.50 * $n) - 1];
$p95    = $measurements[(int) ceil(0.95 * $n) - 1];
$p99    = $measurements[(int) ceil(0.99 * $n) - 1];
$mean   = array_sum($measurements) / $n;
$variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $measurements)) / $n;
$stddev = sqrt($variance);

echo json_encode([
    'implementation' => $implementation,
    'scenario'       => $scenario,
    'n_warmup'       => $warmupCount,
    'n_measurements' => $n,
    'p50_ms'         => round($p50, 4),
    'p95_ms'         => round($p95, 4),
    'p99_ms'         => round($p99, 4),
    'mean_ms'        => round($mean, 4),
    'stddev_ms'      => round($stddev, 4),
], JSON_PRETTY_PRINT) . "\n";
