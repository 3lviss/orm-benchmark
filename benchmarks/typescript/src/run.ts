import { closeConnections } from './Connection';

/**
 * Entry point for TypeScript benchmarks.
 * Usage: node dist/run.js <implementation> <scenario>
 * Example: node dist/run.js raw_sql A1
 */

async function main() {
  const implementation = (process.argv[2] || 'raw_sql').toLowerCase();
  const scenario       = (process.argv[3] || 'A1').toUpperCase();
  const method         = scenario.toLowerCase();

  const classMap: Record<string, string> = {
    raw_sql: './scenarios/raw_sql/Scenario',
    prisma:  './scenarios/prisma/Scenario',
    drizzle: './scenarios/drizzle/Scenario',
  };

  if (!classMap[implementation]) {
    process.stderr.write(`Unknown implementation: ${implementation}\n`);
    process.stderr.write(`Available: ${Object.keys(classMap).join(', ')}\n`);
    process.exit(1);
  }

  const module = await import(classMap[implementation]);
  const runner = new module.Scenario();

  if (typeof runner[method] !== 'function') {
    process.stderr.write(`Unknown scenario: ${scenario}\n`);
    process.exit(1);
  }

  // ── Warm-up phase (adaptive: CV < 5% over rolling window of 10) ────────────
  const warmupWindow: number[] = [];
  let warmupCount = 0;
  let warmupDone  = false;
  const maxWarmup = 2000;

  while (!warmupDone && warmupCount < maxWarmup) {
    const start = process.hrtime.bigint();
    await runner[method]();
    const end = process.hrtime.bigint();

    const ms = Number(end - start) / 1_000_000;
    warmupWindow.push(ms);
    warmupCount++;

    if (warmupWindow.length > 10) warmupWindow.shift();

    if (warmupWindow.length === 10) {
      const mean = warmupWindow.reduce((a, b) => a + b, 0) / 10;
      if (mean > 0) {
        const variance = warmupWindow.reduce((a, b) => a + (b - mean) ** 2, 0) / 10;
        const cv = Math.sqrt(variance) / mean * 100;
        if (cv < 5.0) warmupDone = true;
      }
    }
  }

  // ── Measurement phase (adaptive: bootstrap CI width < 5% of p99) ───────────
  const measurements: number[] = [];
  const maxMeasure = 10000;
  const checkEvery = 100;
  const minMeasure = 100;

  while (measurements.length < maxMeasure) {
    const start = process.hrtime.bigint();
    await runner[method]();
    const end = process.hrtime.bigint();

    measurements.push(Number(end - start) / 1_000_000);

    const n = measurements.length;

    if (n >= minMeasure && n % checkEvery === 0) {
      const sorted   = [...measurements].sort((a, b) => a - b);
      const p99index = Math.ceil(0.99 * n) - 1;
      const p99value = sorted[p99index];

      if (p99value > 0) {
        const bootstrapP99s: number[] = [];
        for (let b = 0; b < 500; b++) {
          const sample = Array.from(
            { length: n },
            () => measurements[Math.floor(Math.random() * n)]
          ).sort((a, b) => a - b);
          bootstrapP99s.push(sample[Math.ceil(0.99 * n) - 1]);
        }
        bootstrapP99s.sort((a, b) => a - b);
        const lower   = bootstrapP99s[Math.floor(0.025 * 500)];
        const upper   = bootstrapP99s[Math.floor(0.975 * 500)];
        const ciWidth = ((upper - lower) / p99value) * 100;

        if (ciWidth < 5.0) break;
      }
    }
  }

  // ── Compute final statistics ────────────────────────────────────────────────
  const sorted   = [...measurements].sort((a, b) => a - b);
  const n        = measurements.length;
  const p50      = sorted[Math.ceil(0.50 * n) - 1];
  const p95      = sorted[Math.ceil(0.95 * n) - 1];
  const p99      = sorted[Math.ceil(0.99 * n) - 1];
  const mean     = measurements.reduce((a, b) => a + b, 0) / n;
  const variance = measurements.reduce((a, b) => a + (b - mean) ** 2, 0) / n;
  const stddev   = Math.sqrt(variance);

  console.log(JSON.stringify({
    implementation,
    scenario,
    n_warmup:       warmupCount,
    n_measurements: n,
    p50_ms:         Math.round(p50    * 10000) / 10000,
    p95_ms:         Math.round(p95    * 10000) / 10000,
    p99_ms:         Math.round(p99    * 10000) / 10000,
    mean_ms:        Math.round(mean   * 10000) / 10000,
    stddev_ms:      Math.round(stddev * 10000) / 10000,
  }, null, 2));

  await closeConnections();
}

main().catch(err => {
  process.stderr.write(`Error: ${err.message}\n`);
  process.exit(1);
});
