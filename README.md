# ORM Benchmark Suite

A comprehensive, cross-language benchmarking suite for comparing ORM performance across **Java**, **PHP**, **Python**, and **TypeScript**. Measures overhead of popular object-relational mappers against raw SQL baselines in controlled, statistically rigorous conditions.

## 📋 Table of Contents

- [Overview](#overview)
- [What This Project Does](#what-this-project-does)
- [Key Design Decisions](#key-design-decisions)
- [Technology Stack](#technology-stack)
- [Prerequisites](#prerequisites)
- [Setup](#setup)
- [Running Benchmarks](#running-benchmarks)
- [Understanding Results](#understanding-results)
- [Benchmark Scenarios](#benchmark-scenarios)
- [Important Implementation Details](#important-implementation-details)

---

## Overview

This benchmark suite provides **fair, statistically rigorous performance comparison** between ORM frameworks and raw SQL across multiple programming languages. Unlike simple microbenchmarks, this project uses:

- **Adaptive warm-up phase** (convergence on coefficient of variation < 5%)
- **Bootstrap confidence intervals** for statistical rigor
- **Identical scenarios** across all implementations
- **Controlled environment** with resource limits
- **Real-world query patterns** (N+1, eager loading, aggregates, bulk operations)

## What This Project Does

The benchmark measures **ORM overhead** by comparing:

1. **Raw SQL baseline** - Direct database access (JDBC, PDO, SQLAlchemy Core, postgres.js)
2. **ORM implementations** - Business logic layer abstraction
   - Java: Hibernate ORM
   - PHP: Eloquent, Doctrine ORM
   - Python: SQLAlchemy ORM
   - TypeScript: Prisma, Drizzle ORM

**Output:** Percentiles (p50, p95, p99), mean, standard deviation with confidence intervals

## Key Design Decisions

- **Docker Compose** - Reproduces consistent environment across machines
- **Unified .env** - All services use same configuration (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS)
- **Resource Limits** - CPU capped at 2 cores, memory at 1GB to prevent outliers
- **JVM Tuning** - Java uses single-threaded GC for fair comparison
- **Connection Pooling** - All services configured with pool_size=10 (except PHP PDO which is single-connection)

---

## Technology Stack

### Java
| Component | Version | Notes |
|:---|:---|:---|
| **Runtime** | OpenJDK 21 (LTS) | Alpine JRE |
| **Hibernate ORM** | 6.4.4.Final | JPA/Hibernate standard |
| **JDBC Driver** | PostgreSQL 42.7.3 | Latest stable |
| **Connection Pooling** | HikariCP 5.1.0 | Production-standard pool |
| **Build Tool** | Maven 3.9 | Multi-stage Docker build |

### PHP
| Component | Version | Notes |
|:----------|:--------|:------|
| **Runtime** | PHP 8.3 (LTS) | CLI, Alpine |
| **Laravel Eloquent** | 11.0 | Modern ORM |
| **Doctrine ORM** | 3.0 | Enterprise ORM |
| **DBAL** | 4.0 | Database abstraction |
| **Dependency Manager** | Composer | PSR-4 autoloading |

### Python
| Component | Version | Notes |
|:----------|:--------|:------|
| **Runtime** | Python 3.12 | Slim image (Alpine compatibility) |
| **SQLAlchemy** | 2.0.30 | v2 ORM (modern) |
| **psycopg2** | 2.9.9 | PostgreSQL adapter (binary) |
| **Package Manager** | pip | Virtual environment |

### TypeScript
| Component | Version | Notes |
|:----------|:--------|:------|
| **Runtime** | Node 20 (LTS) | Alpine |
| **Prisma** | 5.0.0 | Schema-first ORM |
| **Drizzle ORM** | 0.30.0 | TypeScript-first ORM |
| **Postgres.js** | 3.4.0 | Native PostgreSQL client |
| **Language** | TypeScript 5.0+ | Strict mode |

---

## Prerequisites

### Docker
- Docker Engine 20.10+
- Docker Compose 2.0+
- 4GB RAM available
- 10GB disk space

---

## Setup

#### 1. Clone & Configure

```bash
git clone <repository>
cd orm-benchmark

# Copy environment template
cp .env.example .env

# Verify .env contents:
cat .env
# Should contain:
# DB_HOST=postgres
# DB_PORT=5432
# DB_NAME=benchmark
# DB_USER=benchmark
# DB_PASS=benchmark
```

#### 2. Start Services

```bash
# Build all images and start database + seed data
docker-compose up -d

# Watch database initialization (should be healthy in ~30 seconds)
docker-compose ps

# Verify database is ready
docker-compose exec postgres pg_isready -U benchmark -d benchmark
```

#### 3. Verify Setup

```bash
# Check all services are running
docker-compose ps
# Output should show: postgres (healthy), php, java, python, typescript

# View logs
docker-compose logs -f postgres
docker-compose logs php
```

---

## Running Benchmarks

#### Run Single Benchmark

```bash
# Syntax: docker-compose exec <service> <command>

# Java - Raw SQL
docker-compose exec java java -jar target/orm-benchmark-java-1.0.0-jar-with-dependencies.jar raw_sql A1

# PHP - Eloquent ORM
docker-compose exec php php src/run.php eloquent A2

# Python - SQLAlchemy ORM
docker-compose exec python python src/run.py sqlalchemy B1

# TypeScript - Prisma
docker-compose exec typescript node dist/run.js prisma B2
```

#### Run Comprehensive Benchmark Suite

```bash
# All Java scenarios
for scenario in A1 A2 A3 A4 B1 B2 B3 C1 C2 D1; do
  echo "=== Java: raw_sql $scenario ==="
  docker-compose exec java java -jar target/orm-benchmark-java-1.0.0-jar-with-dependencies.jar raw_sql $scenario
done

for scenario in A1 A2 A3 A4 B1 B2 B3 C1 C2 D1; do
  echo "=== Java: hibernate $scenario ==="
  docker-compose exec java java -jar target/orm-benchmark-java-1.0.0-jar-with-dependencies.jar hibernate $scenario
done
```

#### Capture Results to File

```bash
# Run all implementations and save results
mkdir -p results

for implementation in raw_sql eloquent doctrine; do
  for scenario in A1 A2 A3 A4 B1 B2 B3 C1 C2 D1; do
    echo "Running PHP $implementation $scenario..."
    docker-compose exec php php src/run.php $implementation $scenario \
      > results/php_${implementation}_${scenario}.json
  done
done
```

---

## Understanding Results

### Output Format

Each benchmark run returns JSON with statistical metrics:

```json
{
  "implementation": "raw_sql",
  "scenario": "A1",
  "n_warmup": 127,
  "n_measurements": 523,
  "p50_ms": 2.3456,
  "p95_ms": 3.2145,
  "p99_ms": 4.1234,
  "mean_ms": 2.4567,
  "stddev_ms": 0.5678
}
```

### Metrics Explained

| Metric | Meaning | Use Case |
|--------|---------|----------|
| **n_warmup** | Iterations until warm-up converged | Quality indicator (higher = more stable) |
| **n_measurements** | Iterations in measurement phase | Sample size (higher = more confident) |
| **p50_ms** | Median latency | Typical response time |
| **p95_ms** | 95th percentile latency | "Fast enough" threshold |
| **p99_ms** | 99th percentile latency | Worst-case acceptable response time |
| **mean_ms** | Average latency | Overall performance |
| **stddev_ms** | Standard deviation | Stability/consistency |

---

## Benchmark Scenarios

All scenarios are **identical across all implementations** to ensure fair comparison.

### A Series: Basic Operations

#### **A1** - Simple Select by Primary Key
- **Purpose:** Measure single-entity hydration overhead
- **Query:** `SELECT id, email, name, created_at FROM users WHERE id = ?`
- **Randomization:** Random user ID (1-10000)
- **ORM Test:** Basic find/get operations

#### **A2** - Filtered List with ORDER BY and LIMIT
- **Purpose:** Measure query generation and result collection
- **Query:** `SELECT id, name, price, created_at FROM products WHERE category_id = ? ORDER BY created_at DESC LIMIT 20`
- **Randomization:** Random category ID (1-100)
- **ORM Test:** Filtering, sorting, pagination

#### **A3** - N+1 Diagnostic (Lazy Loading)
- **Purpose:** Measure ORM default lazy-loading behavior
- **Execution:**
  1. Load 100 orders with single query
  2. For each order, separate query to fetch associated user
  3. Total: **1 + 100 = 101 queries** (intentional N+1)
- **ORM Test:** Default relationship loading behavior
- **Implementation Note:** All languages use **sequential queries** to match ORM default behavior

#### **A4** - Eager Loading via JOIN
- **Purpose:** Measure ORM eager-loading optimization
- **Query:** `SELECT o.*, u.* FROM orders o INNER JOIN users u ON u.id = o.user_id LIMIT 100`
- **Comparison:** Compare against A3 to quantify N+1 impact
- **ORM Test:** join(), with(), fetch=EAGER equivalents

---

### B Series: Complex Operations

#### **B1** - Deep Eager Loading (3 Levels)
- **Purpose:** Measure ORM performance with deep relationship chains
- **Path:** Order → OrderItems → Product → Category
- **Query:** 4-table join with all relationships
- **Randomization:** Random order ID (1-200000)
- **ORM Test:** Nested relationship loading

#### **B2** - Aggregate with GROUP BY
- **Purpose:** Measure ORM aggregation and complex SELECT
- **Query:** `SELECT c.id, c.name, COUNT(p.id) AS product_count, AVG(p.price) AS avg_price FROM categories c LEFT JOIN products p GROUP BY c.id`
- **ORM Test:** Raw query execution, result mapping with aliases
- **Note:** Uses selectRaw/query builder, not model hydration

#### **B3** - Many-to-Many Relationship
- **Purpose:** Measure ORM handling of many-to-many associations
- **Path:** Products with specific tag, including category
- **Query:** `SELECT p.id, p.name, p.price, c.name FROM products p INNER JOIN product_tags pt INNER JOIN categories c LIMIT 50`
- **Randomization:** Random tag ID (1-500)
- **ORM Test:** Junction table queries, relationship hydration

---

### C Series: Bulk Operations

#### **C1** - Bulk Insert (10,000 Records)
- **Purpose:** Measure ORM batch insertion performance
- **Operation:** Insert 10,000 products in chunks of 500
- **Cleanup:** Delete all inserted records to restore dataset state
- **ORM Test:** Batch insert, transaction handling
- **Implementation:**
  - Java: PreparedStatement batch + explicit commit every 500
  - PHP: Multi-value INSERT with parameterized queries
  - Python: SQLAlchemy insert()
  - TypeScript: postgres.js bulk syntax

#### **C2** - Bulk Update
- **Purpose:** Measure ORM bulk update without loading entities
- **Operation:** Update 100% of old shipped orders to "delivered"
- **Conditions:** `status='shipped' AND created_at < NOW() - INTERVAL '30 days'`
- **Cleanup:** Restore to original status
- **ORM Test:** Bulk update, query builder efficiency

## C2 Bulk Update Methodology

LIMIT 1000 was chosen based on empirical `EXPLAIN ANALYZE` testing (see `results/methodology/c2_limit_analysis.json`):

| Batch Size | Execution Time | Decision |
|---|---|---|
| 100 | ~1.7ms | Too small — unrepresentative of real bulk operations |
| 500 | ~5ms | Acceptable but conservative |
| **1000** | **~8ms** | **✓ Chosen — balances realism and stability** |
| 5000 | ~55ms | Non-linear increase, lock contention begins |
| 10000 | ~107ms | Excessive latency, risk of deadlocks |

The non-linear jump from 1000 to 5000 rows (~7× slower for 5× more rows) is caused by buffer pool pressure and increased row-level lock contention. LIMIT 1000 sits at the inflection point before this degradation begins.

**PostgreSQL constraint:** `UPDATE` does not support `LIMIT` directly. All implementations use a subquery pattern:

```sql
UPDATE orders SET status = 'delivered'
FROM (
    SELECT id FROM orders
    WHERE status = 'shipped'
      AND created_at < NOW() - INTERVAL '30 days'
    ORDER BY id LIMIT 1000
) AS batch
WHERE orders.id = batch.id
```

---

### D Series: Unit of Work Pattern

#### **D1** - Unit of Work Diagnostic
- **Purpose:** Measure ORM transaction and change-tracking overhead
- **Operations:**
  1. Fetch 5 random products
  2. Create order with user_id
  3. Insert 5 order items (one per product)
  4. Update order total
  5. Commit transaction
  6. Clean up: delete order and items
- **Query Count:** Minimum ~8-10 queries depending on ORM optimization
- **ORM Test:** Transaction handling, Unit of Work pattern, change tracking

---

## Important Implementation Details

### 1. Statistical Methodology

All benchmarks use **identical adaptive warm-up and measurement logic**:

**Warm-up Phase:**
- Run until coefficient of variation (CV) < 5% over rolling window of 10 iterations
- Maximum 2000 warmup iterations
- Purpose: Allow JIT compilation, connection pooling, caching to stabilize

**Measurement Phase:**
- Run until bootstrap 95% CI width < 5% of p99
- Minimum 100 measurements, maximum 10000
- Check convergence every 100 iterations
- Purpose: Ensure statistical confidence in results

### 2. Environment Variable Consistency

All services use **unified environment configuration**:

```bash
DB_HOST=postgres
DB_PORT=5432
DB_NAME=benchmark
DB_USER=benchmark
DB_PASS=benchmark
```

**TypeScript Special Case:**
TypeScript builds `DATABASE_URL` from these variables:
```
DATABASE_URL=postgresql://DB_USER:DB_PASS@DB_HOST:DB_PORT/DB_NAME
```

This is handled automatically in `Connection.ts` via `buildDatabaseUrl()` function.

### 3. Connection Pooling Configuration

| Language | Pool Type | Pool Size | Notes |
|----------|-----------|-----------|-------|
| Java | HikariCP | 10 | Production-standard |
| PHP | None | 1 | PDO is single-connection |
| Python | SQLAlchemy | 10 | QueuePool |
| TypeScript | postgres.js | 10 | Built-in |

### 4. Resource Constraints (Docker)

All benchmark containers are limited to:
- **CPU:** 2 cores (enforced via `cpus: '2'`)
- **Memory:** 1GB (enforced via `memory: 1G`)

This prevents one language from dominating shared resources.

### 5. Query Consistency

**All raw SQL scenarios use identical queries across languages:**
- Same WHERE conditions
- Same ORDER BY clauses
- Same LIMIT values
- Same JOIN logic
- Same randomization ranges

**ORM scenarios mirror raw SQL queries** but use ORM-idiomatic syntax (find(), where(), with(), etc.)

### 6. N+1 Intentional Design

Scenario A3 and D1 intentionally use N+1 patterns to:
- Measure **default ORM behavior** (lazy loading)
- Demonstrate **performance impact** of unoptimized queries
- Show **difference between A3 (N+1) and A4 (eager load)**

This is **not a bug** — it's the benchmark design to expose ORM overhead.

### 7. Cleanup Strategy

All mutation scenarios (C1, C2, D1) **restore dataset state** after execution:
- C1 (Bulk Insert): Deletes inserted products
- C2 (Bulk Update): Reverts status changes
- D1 (Unit of Work): Deletes created order and items

Purpose: **Each run is independent**, dataset state is consistent.
