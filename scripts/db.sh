#!/usr/bin/env bash
# Every scenario run starts from an identical copy: same data, same pages, same
# planner statistics (pg_statistic is copied with the template), no dead rows.
# Autovacuum is disabled per table: it is time-triggered (naptime), and a VACUUM or
# ANALYZE in the middle of a scenario changes reltuples/statistics and can flip the
# C2 plan at a random point. Without it the table state after k scenario calls is the
# same for every implementation (C1/C2/D1 drift, but identically per global iteration).
# orders.status and orders.created_at get statistics target 1000: ANALYZE then samples
# all 200 000 rows, so the statistics C2 is planned with are identical in every build.
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

# Starts postgres if needed and checks that the container is attached to a network
# (after a recreate it once came up without one -> ENOTFOUND postgres)
ensure_postgres() {
    docker compose up -d --wait postgres >/dev/null
    local id networks
    id=$(docker compose ps -q postgres)
    networks=$(docker inspect "$id" --format '{{len .NetworkSettings.Networks}}')
    if [ "$networks" = "0" ]; then
        echo "postgres container has no network" >&2
        exit 1
    fi
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
    psql_db $DB -c "ALTER TABLE orders ALTER COLUMN status SET STATISTICS 1000, ALTER COLUMN created_at SET STATISTICS 1000"
    psql_db $DB -c "VACUUM (FREEZE, ANALYZE)"
    for table in "${TABLES[@]}"; do
        psql_db $DB -c "ALTER TABLE $table SET (autovacuum_enabled = false, toast.autovacuum_enabled = false)"
    done

    echo "Turning '$DB' into '$TEMPLATE'..."
    psql_db postgres <<SQL
ALTER DATABASE $DB RENAME TO $TEMPLATE;
ALTER DATABASE $TEMPLATE WITH IS_TEMPLATE true ALLOW_CONNECTIONS false;
COMMENT ON DATABASE $TEMPLATE IS 'created $(date -u +%Y-%m-%dT%H:%M:%SZ)';
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

# Prints the template creation time (UTC, ISO 8601), empty if there is no template
template_created() {
    ensure_postgres
    psql_db postgres -c "SELECT coalesce(substr(shobj_description(oid, 'pg_database'), 9), '')
                         FROM pg_database WHERE datname = '$TEMPLATE'"
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
    template-created) template_created ;;
    *) echo "Usage: $0 {prepare-template|reset|status|template-created}" >&2; exit 1 ;;
esac
