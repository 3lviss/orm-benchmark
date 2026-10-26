# ORM Benchmark Suite

A cross-language benchmark of object-relational mappers against raw SQL baselines on PostgreSQL:
**4 runtimes, 10 implementations, 10 scenarios**. Every scenario of every implementation runs on a
fresh copy of the same database, with adaptive warm-up and measurement, and the SQL each
implementation actually sends is verified on the database side.

| Runtime | Raw SQL baseline | ORMs |
|---|---|---|
| TypeScript | postgres.js | Prisma, Drizzle |
| PHP | PDO | Eloquent, Doctrine ORM |
| Python | psycopg2 (via SQLAlchemy Core `text()`) | SQLAlchemy ORM |
| Java | JDBC | Hibernate ORM |

## Contents

- [Versions](#versions)
- [Running](#running)
- [Output](#output)
- [Measurement method](#measurement-method)
- [Scenarios](#scenarios)
- [Query counts](#query-counts)
- [Known deviations](#known-deviations)
- [Results in this repository](#results-in-this-repository)

---

## Versions

All versions are pinned (Docker image tags, `package-lock.json`, `composer.lock`, `requirements.txt`,
`pom.xml`). The versions actually installed in each image are recorded by `scripts/versions.sh` in
`environment.json` of every run.

| Component | Version |
|---|---|
| PostgreSQL | 16.14 (`postgres:16.14-alpine3.23`) |
| Node.js / TypeScript | 20.20 / 5.9 |
| Prisma | 5.22.0 |
| Drizzle ORM | 0.30.10 |
| postgres.js | 3.4.9 |
| PHP | 8.3.31 |
| Doctrine ORM / DBAL | 3.6.7 / 4.4.3 |
| Eloquent (`illuminate/database`) | 11.51.0 |
| Python | 3.12.13 |
| SQLAlchemy | 2.0.30 |
| psycopg2-binary | 2.9.13 |
| Java | OpenJDK 21.0.11 |
| Hibernate ORM | 6.6.59.Final |
| PostgreSQL JDBC | 42.7.3 |
| HikariCP | 5.1.0 |
| P6Spy | 3.9.1 |

Containers: benchmark services are limited to 2 CPUs and 1 GB memory, PostgreSQL to 2 CPUs and 2 GB.
The JVM runs with `-XX:ActiveProcessorCount=1 -XX:ConcGCThreads=1 -XX:ParallelGCThreads=1`.

---

## Running

### Prerequisites

- Docker with Compose v2 (`docker compose`)
- `bash`, `git` and `python3` on the host (the run script merges JSON and writes EXPLAIN plans)
- About 4 GB of free memory for Docker and 10 GB of disk

```bash
cp .env.example .env      # DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
runner/run_all.sh
```

A complete run takes about one hour. On macOS the script keeps the machine awake (`caffeinate`).
Close other applications for the duration of the run.

`runner/run_all.sh` does everything without manual steps:

1. **Freeze check** — benchmark code, schema, seed and `docker-compose.yml` must match the frozen
   scenario definition (commit `112ea1c`); only runner and validation entry points may differ.
2. **Build** all images.
3. **Template database** (`scripts/db.sh prepare-template`): schema, seed (Faker, seed 42), row-count
   check, `VACUUM (FREEZE, ANALYZE)`, autovacuum off per table, statistics target 1000 on
   `orders.status` and `orders.created_at` (ANALYZE then reads the whole table, so the statistics are
   deterministic). The template is never connected to directly.
4. **Validation** (`scripts/validate.sh`): every scenario of every implementation with fixed
   parameters; statements counted with `pg_stat_statements`; results compared across implementations.
5. **Measurement**: for each runtime and scenario, the implementations run in rotated order; before
   **every** scenario run the database is recreated from the template
   (`CREATE DATABASE … TEMPLATE … STRATEGY = FILE_COPY`, about 1 s).
6. **Environment**: installed versions, host and Docker VM, image IDs, PostgreSQL settings, and
   `EXPLAIN (ANALYZE, BUFFERS)` of the raw SQL of every scenario.

Options:

| Option | Meaning |
|---|---|
| `--out DIR` | output directory (default `results/run-<UTC timestamp>`); must be new or empty |
| `--only typescript/prisma,php/doctrine` | subset of implementations |
| `--scenarios A1,C2` | subset of scenarios |
| `--skip-validate` | skip validation |
| `--skip-build` | reuse images (only together with `--only` or `--scenarios`) |
| `--reuse-template` | keep an existing template instead of seeding again (warns if it is older than 24 h) |

Any of `--only`, `--scenarios`, `--skip-validate`, `--skip-build` marks the run as partial in
`environment.json`.

### Single scenario

```bash
bash scripts/db.sh prepare-template     # once
bash scripts/db.sh reset                # fresh copy before each scenario run
docker compose run --rm --no-deps -T typescript node dist/run.js prisma A3
docker compose run --rm --no-deps -T php        php src/run.php doctrine A3
docker compose run --rm --no-deps -T python     python src/run.py sqlalchemy A3
docker compose run --rm --no-deps -T java       sh -c 'exec java $JAVA_OPTS -jar app.jar "$@"' java hibernate A3
```

The runner prints one JSON object to stdout. With `-e RAW_OUTPUT_DIR=/raw -v "$PWD/out:/raw"` it also
writes the raw measurement array to `out/<scenario>.json`.

### Validation only

```bash
bash scripts/validate.sh                                  # all 10 -> results/validation.json
bash scripts/validate.sh php/doctrine typescript/prisma   # subset -> results/validation.partial.json
```

`validate.sh` restarts PostgreSQL with `docker-compose.validate.yml` (`pg_stat_statements` enabled) and
restores the normal configuration afterwards. Measurement runs never use `pg_stat_statements`.

---

## Output

```
results/run-<timestamp>/
├── <lang>/<impl>/<scenario>.json   raw latencies in ms, in measurement order
├── <lang>/<impl>/summary.json      per-scenario statistics + meta
├── validation.json                 statements per call, content check
├── environment.json                versions, host, image IDs, PostgreSQL settings, run metadata
├── explain/<scenario>.txt          EXPLAIN (ANALYZE, BUFFERS) of the raw SQL, after the run
├── explain-initial/C2.txt          C2 plan on a fresh copy, before measuring
└── run.log
```

`summary.json` per scenario:

| Field | Meaning |
|---|---|
| `n_warmup` | warm-up iterations until the warm-up criterion was met (or the cap) |
| `warmup_converged` | `true` if the warm-up stopped on the criterion, `false` if it hit the cap |
| `n_measurements` | measured iterations |
| `converged` | `true` if measurement stopped on the CI criterion, `false` if it hit the cap |
| `p50_ms`, `p95_ms`, `p99_ms`, `mean_ms`, `stddev_ms` | statistics over all measured iterations |
| `query_count_mean`, `query_count_median` | in-process counters — **do not cite**, see [Query counts](#query-counts) |

`environment.json` → `run` records start and end, arguments, whether the run is partial, the git
commit, the number of uncommitted files, the template creation time, the execution order and any
failed scenario runs.

---

## Measurement method

Identical in all four runners (`run.ts`, `run.php`, `run.py`, `Runner.java`):

| | Read scenarios (A, B) | Write scenarios (C1, C2, D1) |
|---|---|---|
| Warm-up stop | coefficient of variation < 5 % over the last 10 iterations | same |
| Warm-up cap | 2 000 | 20 |
| Measurement stop | bootstrap 95 % CI of **p99** narrower than 5 % of p99, checked every 100 iterations | same |
| Measurement min / max | 100 / 10 000 | 100 / 200 (minimum set to 20, but the first check is at 100) |

- Each iteration is one scenario call; latency is wall-clock time around the call in the runtime.
- Parameters are random per iteration (user, category, order, tag, offset).
- `converged = false` means the cap was reached first; the p99 criterion is strict, so this is common.
  The median is far more stable than p99 and is what implementations are compared on.

**Write scenarios change the database with every call** (dead row versions, a growing table). The
state after k calls is the same for every implementation, because each run starts from the same
template and autovacuum is off. Compare write scenarios on the same **global iterations**
(warm-up + measurement), not on the first N measurements: measurement *i* (1-based) is global
iteration `n_warmup + i`. A window of global iterations 21–110 exists in every write run
(warm-up 10–20, at least 100 measurements).

---

## Scenarios

Dataset (`dataset/seed.py`, Faker seed 42): 10 000 users, 100 categories in two levels (20 root, 80
sub-categories with a randomly assigned parent), 500 tags, 50 000 products, 200 000 orders, 150 000
reviews. Generation is deterministic except timestamps, which are relative to the time of seeding.

| ID | Scenario | Raw SQL statements |
|---|---|---|
| A1 | User by primary key | 1 |
| A2 | 20 newest products of a category (`ORDER BY created_at DESC LIMIT 20`) | 1 |
| A3 | 100 orders, then the user of each order (N+1 diagnostic) | 101 |
| A4 | 100 orders with users (`JOIN`) | 1 |
| B1 | One order → items → product → category | 1 |
| B2 | Product count and average price per category (`GROUP BY`, 100 rows) | 1 |
| B3 | 50 products with a tag, with category (many-to-many) | 1 |
| C1 | Insert 10 000 products in 20 chunks of 500, then delete them | 21 |
| C2 | Update 1 000 orders `shipped → delivered`, then restore (control scenario) | 2 |
| D1 | Unit of work: pick 5 products, insert order + 5 items, delete them | 9 |

ORM implementations use the idiomatic API of each library (`find`, query builder, DQL/JPQL,
`include`/`with`/`joinedload`/`JOIN FETCH`, …). Cleanup (C1, D1) and restore (C2) are part of the
measured call.

---

## Query counts

The `query_count_*` fields in `summary.json` come from different in-process counters (Prisma
middleware counts client operations, P6Spy does not count JDBC batches one-to-one, Eloquent's listener
misses direct PDO calls, raw implementations report −1). **They are not comparable and must not be
cited.**

The authoritative count is `validation.json`: SQL statements per call, counted on the database side
with `pg_stat_statements`, **excluding transaction control** (`BEGIN`, `COMMIT`, `ROLLBACK`,
`SAVEPOINT`, `RELEASE`). Validation uses fixed parameters (the random generator returns 0.1 in every
runtime). Statements per call (run of 22.10.2026):

| Implementation | A1 | A2 | A3 | A4 | B1 | B2 | B3 | C1 | C2 | D1 |
|---|---|---|---|---|---|---|---|---|---|---|
| TS raw | 1 | 1 | 101 | 1 | 1 | 1 | 1 | 21 | 2 | 9 |
| Prisma | 1 | 1 | 2 | 2 | 4 | 2 | 2 | 21 | 2 | 7 |
| Drizzle | 1 | 1 | 101 | 1 | 1 | 1 | 1 | 21 | 2 | 5 |
| PHP raw | 1 | 1 | 101 | 1 | 1 | 1 | 1 | 21 | 2 | 9 |
| Doctrine | 1 | 1 | 100 | 1 | 1 | 1 | 2 | 21 | 2 | 16 |
| Eloquent | 1 | 1 | 101 | 2 | 4 | 1 | 3 | 21 | 2 | 10 |
| Python raw | 1 | 1 | 101 | 1 | 1 | 1 | 1 | 10 001 | 2 | 9 |
| SQLAlchemy | 1 | 1 | 100 | 1 | 1 | 1 | 2 | 21 | 2 | 7 |
| Java raw | 1 | 1 | 101 | 1 | 1 | 1 | 1 | 10 001 | 2 | 9 |
| Hibernate | 1 | 1 | 100 | 1 | 1 | 1 | 1 | 10 002 | 2 | 11 |

- **A3, 100 vs 101:** the first 100 orders reference 99 distinct users. Doctrine, SQLAlchemy and
  Hibernate return the repeated user from the identity map.
- **Prisma and Eloquent eager loading** is one `WHERE id IN (…)` query per relation level, not a JOIN
  (A4 = 2, B1 = 4). Prisma A3 uses `include` (its idiomatic form), not lazy access.
- **C1 counts are not comparable:** multi-row `INSERT` (21) vs one execution per row (10 001).

---

## Known deviations

Kept deliberately (the scenarios are frozen); they matter when interpreting results.

**Raw SQL inside ORM implementations**
- **C2:** no ORM supports `LIMIT` in a bulk update, so all six ORMs send the same raw
  `UPDATE … FROM (SELECT … LIMIT 1000)`. C2 is a control scenario (ORM/raw ≈ 1.0 expected).
- **C1:** Doctrine inserts through DBAL and Hibernate through JDBC batch (`session.doWork`).
- **A4, B1 (Doctrine):** native SQL with `ResultSetMappingBuilder` instead of DQL.

**Driver behaviour**
- **PHP:** PDO (raw and Eloquent) prepares, executes and deallocates every statement
  (3 round-trips); Doctrine DBAL 4 disables server-side prepares (1 round-trip). This is why Doctrine
  can be faster than the PHP raw baseline (A1).
- **C2 plan:** on a fresh copy the planner is on the edge between a Hash Join (≈ 30 ms) and a Nested
  Loop (≈ 15 ms); as dead rows accumulate, the custom plan switches to the Nested Loop at global
  iteration 40. Implementations that send SQL text or unnamed statements (PDO, Eloquent, Doctrine,
  psycopg2, SQLAlchemy, and Drizzle, which inlines the date as a literal) get the custom plan and
  switch; server-side prepared statements (postgres.js in TS raw, Prisma, pgjdbc after 5 executions)
  keep the generic Hash Join. Cross-runtime C2 latencies differ by plan, and Drizzle C2 vs TS raw is a
  plan effect, not an ORM effect.

**Different work in the same scenario**
- **D1 (PHP):** raw PDO, Eloquent and Doctrine pick products with `ORDER BY RANDOM()`, the others with
  `ORDER BY id LIMIT 5 OFFSET <random>`. D1 latencies vary by design.
- **Python C1 baseline** executes one `INSERT` per row (`executemany`) and is about 2× slower than the
  other raw baselines, so SQLAlchemy C1 is faster than its baseline.
- **C1** is dominated by the cleanup `DELETE` and its foreign-key checks (≈ 280 ms of the call).
- **C2** matches rows by `created_at < now − 30 days`; seed timestamps are relative to the seed time,
  so `run_all.sh` seeds right before measuring.

**Instrumentation**
- ORM implementations run their query counters inside the measured loop (Hibernate through the P6Spy
  proxy driver, Doctrine middleware, Eloquent events, Prisma middleware, Drizzle logger); the raw
  baselines mostly do not (Python is symmetric). Reported ORM overhead includes this cost.

**Connections**
- Every call is sequential, so one connection is in use at a time. PHP and Java raw use a single
  connection; Hibernate uses HikariCP (max 10), Python a SQLAlchemy `QueuePool` (10), TS raw and
  Drizzle postgres.js (max 10), Prisma its default pool.

**Schema**
- No index on `product_tags(tag_id)` (the primary key is `(product_id, tag_id)`); the effect on B3 is
  small.

---

## Results in this repository

- `results/run-<timestamp>/` — complete runs produced by `runner/run_all.sh` as described above.
- `results/outdated/<lang>/<impl>/` — earlier results (June 2026), measured **before** the template-based
  reset, on a database that grew during the run. Not comparable with the runs above: Prisma B2 and
  Doctrine D1 have changed since (B2 now computes the average price; a Doctrine mapping fix removed
  5 unnecessary `UPDATE`s from D1), and the PHP, Python and Java raw arrays of these files are sorted,
  not in measurement order.
- `results/methodology/c2_limit_analyse.json` — `EXPLAIN ANALYZE` of the C2 update for different batch
  sizes, used to choose `LIMIT 1000`.
