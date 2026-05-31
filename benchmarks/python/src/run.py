import sys
import json
import math
import random
import time
import importlib

"""
Entry point for Python benchmarks.
Usage: python src/run.py <implementation> <scenario>
Example: python src/run.py raw_sql A1
"""

def coefficient_of_variation(window: list[float]) -> float:
    """CV for warm-up criterion."""
    if len(window) < 2:
        return float('inf')
    mean = sum(window) / len(window)
    if mean == 0:
        return float('inf')
    variance = sum((x - mean) ** 2 for x in window) / len(window)
    return (math.sqrt(variance) / mean) * 100

def bootstrap_ci_width_pct(
    measurements: list[float],
    percentile: float = 99,
    n_bootstrap: int = 500,
    ci_level: float = 0.95,
) -> float:
    """Bootstrap CI width as % of p99 — stopping criterion."""
    n = len(measurements)
    p99_value = sorted(measurements)[math.ceil(percentile / 100 * n) - 1]
    if p99_value == 0:
        return float('inf')

    bootstrapped = []
    for _ in range(n_bootstrap):
        sample = sorted(random.choices(measurements, k=n))
        bootstrapped.append(sample[math.ceil(percentile / 100 * n) - 1])

    bootstrapped.sort()
    lower = bootstrapped[int(0.025 * n_bootstrap)]
    upper = bootstrapped[int(0.975 * n_bootstrap)]
    return ((upper - lower) / p99_value) * 100

def main():
    if len(sys.argv) < 3:
        print("Usage: python run.py <implementation> <scenario>", file=sys.stderr)
        sys.exit(1)

    implementation = sys.argv[1].lower()
    scenario       = sys.argv[2].upper()
    method         = scenario.lower()

    # Map implementation to module path and class name
    class_map = {
        "raw_sql":    ("scenarios.raw_sql.Scenario",    "Scenario"),
        "sqlalchemy": ("scenarios.sqlalchemy.Scenario", "Scenario"),
    }

    if implementation not in class_map:
        print(f"Unknown implementation: {implementation}", file=sys.stderr)
        print(f"Available: {', '.join(class_map.keys())}", file=sys.stderr)
        sys.exit(1)

    module_path, class_name = class_map[implementation]
    module = importlib.import_module(module_path)
    runner = getattr(module, class_name)()

    if not hasattr(runner, method):
        print(f"Unknown scenario: {scenario}", file=sys.stderr)
        sys.exit(1)

    fn = getattr(runner, method)

    # ── Warm-up phase (adaptive: CV < 5% over rolling window of 10) ──────────
    warmup_window = []
    warmup_count  = 0
    warmup_done   = False
    max_warmup    = 2000

    while not warmup_done and warmup_count < max_warmup:
        start = time.perf_counter_ns()
        fn()
        end   = time.perf_counter_ns()

        ms = (end - start) / 1_000_000
        warmup_window.append(ms)
        warmup_count += 1

        if len(warmup_window) > 10:
            warmup_window.pop(0)

        if len(warmup_window) == 10:
            if coefficient_of_variation(warmup_window) < 5.0:
                warmup_done = True

    # ── Measurement phase (adaptive: bootstrap CI width < 5% of p99) ─────────
    measurements = []
    max_measure  = 10000
    check_every  = 100
    min_measure  = 100

    while len(measurements) < max_measure:
        start = time.perf_counter_ns()
        fn()
        end   = time.perf_counter_ns()

        measurements.append((end - start) / 1_000_000)

        n = len(measurements)
        if n >= min_measure and n % check_every == 0:
            if bootstrap_ci_width_pct(measurements) < 5.0:
                break

    # ── Compute final statistics ──────────────────────────────────────────────
    measurements.sort()
    n      = len(measurements)
    p50    = measurements[math.ceil(0.50 * n) - 1]
    p95    = measurements[math.ceil(0.95 * n) - 1]
    p99    = measurements[math.ceil(0.99 * n) - 1]
    mean   = sum(measurements) / n
    stddev = math.sqrt(sum((x - mean) ** 2 for x in measurements) / n)

    print(json.dumps({
        "implementation": implementation,
        "scenario":       scenario,
        "n_warmup":       warmup_count,
        "n_measurements": n,
        "p50_ms":         round(p50,    4),
        "p95_ms":         round(p95,    4),
        "p99_ms":         round(p99,    4),
        "mean_ms":        round(mean,   4),
        "stddev_ms":      round(stddev, 4),
    }, indent=2))

if __name__ == "__main__":
    main()
