#!/usr/bin/env bash
# Every scenario run starts from an identical copy: same data, same pages, same
# planner statistics (pg_statistic is copied with the template), no dead rows.
set -euo pipefail

cd "$(dirname "$0")/.."

DB=benchmark
TEMPLATE=benchmark_template
TABLES=(users categories tags products product_tags orders order_items reviews)

# Expected row counts from dataset/seed.py (order_items and product_tags are random per row)
EXPECTED=(users:10000 categories:100 tags:500 products:50000 orders:200000 reviews:150000)

# psql against a given database inside the postgres container
psql_db() {
    local db=$1; shift
    docker compose exec -T postgres sh -c 'exec psql -X -v ON_ERROR_STOP=1 -qtA -U "$POSTGRES_USER" -d "$0" "$@"' "$db" "$@"
}

ensure_postgres() {
    docker compose up -d --wait postgres >/dev/null
}

prepare_template() {
    ensure_postgres
    echo "Creating empty database '$DB' with schema..."
    # a template database cannot be dropped until it is unmarked
    psql_db postgres <<SQL
SELECT 'ALTER DATABASE $TEMPLATE WITH IS_TEMPLATE false ALLOW_CONNECTIONS true'
WHERE EXISTS (SELECT 1 FROM pg_database WHERE datname = '$TEMPLATE') \gexec
DROP DATABASE IF EXISTS $TEMPLATE WITH (FORCE);
DROP DATABASE IF EXISTS $DB WITH (FORCE);
CREATE DATABASE $DB;
SQL
    psql_db $DB < docker/postgres/schema.sql

    echo "Seeding (dataset/seed.py)..."
    docker compose run --rm --no-deps -T seed >/dev/null

    echo "Checking row counts..."
    local failed=0
    for entry in "${EXPECTED[@]}"; do
        local table=${entry%%:*} expected=${entry##*:} n
        n=$(psql_db $DB -c "SELECT count(*) FROM $table")
        if [ "$n" != "$expected" ]; then
            echo "  $table: $n (expected $expected)" >&2
            failed=1
        fi
    done
    [ $failed -eq 0 ] || { echo "Row count check failed" >&2; exit 1; }

    echo "VACUUM (FREEZE, ANALYZE), autovacuum off..."
    psql_db $DB -c "VACUUM (FREEZE, ANALYZE)"
    for table in "${TABLES[@]}"; do
        psql_db $DB -c "ALTER TABLE $table SET (autovacuum_enabled = false, toast.autovacuum_enabled = false)"
    done

    echo "Turning '$DB' into '$TEMPLATE'..."
    psql_db postgres <<SQL
ALTER DATABASE $DB RENAME TO $TEMPLATE;
ALTER DATABASE $TEMPLATE WITH IS_TEMPLATE true ALLOW_CONNECTIONS false;
SQL
    reset
}

reset() {
    ensure_postgres
    psql_db postgres <<SQL
DROP DATABASE IF EXISTS $DB WITH (FORCE);
CREATE DATABASE $DB TEMPLATE $TEMPLATE STRATEGY = FILE_COPY;
CHECKPOINT;
SQL
}

status() {
    ensure_postgres
    psql_db postgres -c "SELECT datname, datistemplate, datallowconn, pg_size_pretty(pg_database_size(datname))
                         FROM pg_database WHERE datname IN ('$DB', '$TEMPLATE') ORDER BY datname"
    for table in "${TABLES[@]}"; do
        echo "$table|$(psql_db $DB -c "SELECT count(*) || '|' || pg_size_pretty(pg_total_relation_size('$table')) FROM $table")"
    done
}

case "${1:-}" in
    prepare-template) prepare_template ;;
    reset)            reset ;;
    status)           status ;;
    *) echo "Usage: $0 {prepare-template|reset|status}" >&2; exit 1 ;;
esac
