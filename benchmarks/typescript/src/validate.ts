import postgres from 'postgres';
import { closeConnections } from './Connection';

/**
 * Validation entry point (docs/PLAN_REPO.md 2.5) — not used for timing.
 * Usage: node dist/validate.js <implementation> [scenario ...]
 *
 * Math.random is replaced with a constant (VALIDATE_U, default 0.1) so every
 * implementation receives the same parameters. Per scenario: warm-up, then
 * pg_stat_statements_reset(), N calls, and the statements recorded by the
 * database are printed as one JSON line together with the last result.
 */

const U        = Number(process.env.VALIDATE_U ?? '0.1');
const N        = Number(process.env.VALIDATE_N ?? '10');
const WARMUP   = Number(process.env.VALIDATE_WARMUP ?? '20');
const WARMUP_W = Number(process.env.VALIDATE_WARMUP_WRITE ?? '5');

const ALL_SCENARIOS  = ['A1', 'A2', 'A3', 'A4', 'B1', 'B2', 'B3', 'C1', 'C2', 'D1'];
const writeScenarios = new Set(['C1', 'C2', 'D1']);

const STATS_SQL = `
  SELECT query, calls::int AS calls, rows::int AS rows
  FROM pg_stat_statements
  WHERE dbid = (SELECT oid FROM pg_database WHERE datname = current_database())
    AND query NOT ILIKE '%pg_stat_statements%'
  ORDER BY calls DESC, query`;

async function main() {
  const implementation = (process.argv[2] || 'raw_sql').toLowerCase();
  const scenarios      = process.argv.length > 3 ? process.argv.slice(3).map(s => s.toUpperCase()) : ALL_SCENARIOS;

  Math.random = () => U;

  const classMap: Record<string, string> = {
    raw_sql: './scenarios/raw_sql/Scenario',
    prisma:  './scenarios/prisma/Scenario',
    drizzle: './scenarios/drizzle/Scenario',
  };
  const module = await import(classMap[implementation]);
  const runner = new module.Scenario();

  const admin = postgres({
    host:     process.env.DB_HOST || 'localhost',
    port:     Number(process.env.DB_PORT || 5432),
    database: process.env.DB_NAME || 'benchmark',
    username: process.env.DB_USER || 'benchmark',
    password: process.env.DB_PASS || 'benchmark',
    max: 1,
  });

  for (const scenario of scenarios) {
    const method = scenario.toLowerCase();
    const warmup = writeScenarios.has(scenario) ? WARMUP_W : WARMUP;
    const line: Record<string, unknown> = { lang: 'typescript', implementation, scenario, u: U, n: N, warmup };
    try {
      for (let i = 0; i < warmup; i++) await runner[method]();
      await admin`SELECT pg_stat_statements_reset()`;
      let result: unknown;
      for (let i = 0; i < N; i++) result = await runner[method]();
      line.statements = await admin.unsafe(STATS_SQL);
      line.result     = result;
      line.ok         = true;
    } catch (err) {
      line.ok    = false;
      line.error = String((err as Error).message);
    }
    console.log(JSON.stringify(line, (_k, v) => (typeof v === 'bigint' ? v.toString() : v)));
  }

  await admin.end();
  await closeConnections();
}

main().catch(err => {
  process.stderr.write(`Error: ${err.message}\n`);
  process.exit(1);
});
