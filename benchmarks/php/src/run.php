<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Benchmark\QueryCounter;

/**
 * Entry point for PHP benchmarks.
 *
 * Usage: php src/run.php <implementation> <scenario>
 *
 * Warm-up and measurement use adaptive stopping rules:
 * - Warm-up: continues until CV < 5% over rolling window of 10
 * - Measurement: continues until bootstrap p99 CI width < 5% of p99
 *
 * Query counting:
 * - raw_sql: returns -1 (queries are explicit and statically known from code)
 * - eloquent/doctrine: returns actual query count via listeners/middleware
 *
 * Raw export (optional):
 * - Set RAW_OUTPUT_DIR env var to export raw measurement arrays
 * - Output: {RAW_OUTPUT_DIR}/{implementation}_{scenario}.json
 * - Used by analysis/analyse.py for exact Mann-Whitney U tests
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

// raw_sql uses PDO directly — no listener mechanism available.
// Query counts are statically known from code, so we skip collection.
$isRawSql = $implementation === 'raw_sql';

// ── Warm-up phase (adaptive: CV < 5% over rolling window of 10) ──────────────
// Write scenarios (C, D) are inherently slower — cap warm-up to avoid excessive runtime
$writeScenarios = ['C1', 'C2', 'D1'];
$warmupWindow   = [];
$warmupCount    = 0;
$warmupDone     = false;
$maxWarmup      = in_array($scenario, $writeScenarios) ? 20 : 2000;

while (!$warmupDone && $warmupCount < $maxWarmup) {
    QueryCounter::reset();
    $start = hrtime(true);
    $runner->$method();
    $end = hrtime(true);
    // query count discarded during warm-up

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
$queryCounts    = [];
$maxMeasure     = in_array($scenario, $writeScenarios) ? 200 : 10000;
$checkEvery     = 100;
$minMeasure     = in_array($scenario, $writeScenarios) ? 20  : 100;

while (count($measurements) < $maxMeasure) {
    QueryCounter::reset();
    $start = hrtime(true);
    $runner->$method();
    $end = hrtime(true);

    $measurements[] = ($end - $start) / 1_000_000;
    $queryCounts[]  = $isRawSql ? -1 : QueryCounter::get();

    $n = count($measurements);

    if ($n >= $minMeasure && $n % $checkEvery === 0) {
        sort($measurements);
        $p99index = (int) ceil(0.99 * $n) - 1;
        $p99value = $measurements[$p99index];

        if ($p99value > 0) {
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

$validCounts = array_filter($queryCounts, fn($q) => $q >= 0);
$qMean       = count($validCounts) > 0
    ? array_sum($validCounts) / count($validCounts)
    : -1;
$qSorted     = array_values($validCounts);
sort($qSorted);
$qMedian     = count($qSorted) > 0
    ? $qSorted[(int) ceil(0.50 * count($qSorted)) - 1]
    : -1;

// ── Export raw measurements (optional) ───────────────────────────────────────
// Enabled by setting RAW_OUTPUT_DIR environment variable.
// Used by analysis/analyse.py for exact Mann-Whitney U tests.
// Output: {RAW_OUTPUT_DIR}/{scenario}.json
$rawOutputDir = getenv('RAW_OUTPUT_DIR');
if ($rawOutputDir) {
    if (!is_dir($rawOutputDir)) {
        mkdir($rawOutputDir, 0777, true);
    }
    $rawFile = rtrim($rawOutputDir, '/') . "/{$scenario}.json";
    file_put_contents($rawFile, json_encode(array_map(
        fn($v) => round($v, 6),
        $measurements
    )));
}

echo json_encode([
    'implementation'     => $implementation,
    'scenario'           => $scenario,
    'n_warmup'           => $warmupCount,
    'n_measurements'     => $n,
    'p50_ms'             => round($p50, 4),
    'p95_ms'             => round($p95, 4),
    'p99_ms'             => round($p99, 4),
    'mean_ms'            => round($mean, 4),
    'stddev_ms'          => round($stddev, 4),
    'query_count_mean'   => $qMean >= 0 ? round($qMean, 2) : -1,
    'query_count_median' => $qMedian,
], JSON_PRETTY_PRINT) . "\n";
