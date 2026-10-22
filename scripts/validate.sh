#!/usr/bin/env bash
# Runs every scenario of every implementation through its validate entry point
# (fixed parameters, warm-up, N calls) against PostgreSQL with pg_stat_statements,
# then compares results and writes results/validation.json.
#
# Usage: scripts/validate.sh            # all 10 implementations -> results/validation.json
#        scripts/validate.sh php/doctrine typescript/prisma   # subset -> results/validation.partial.json
#
# Env: VALIDATE_U (0.1), VALIDATE_N (10), VALIDATE_WARMUP (20), VALIDATE_WARMUP_WRITE (5)
set -euo pipefail

cd "$(dirname "$0")/.."
OUT=results/validation.json
RAW=$(mktemp)
trap 'rm -f "$RAW"' EXIT

ALL=(typescript/raw_sql typescript/prisma typescript/drizzle
     php/raw_sql php/eloquent php/doctrine
     python/raw_sql python/sqlalchemy
     java/raw_sql java/hibernate)
TARGETS=("${@:-${ALL[@]}}")
# A subset compares content only within itself — never overwrite the full result
[ $# -gt 0 ] && OUT=results/validation.partial.json

VALIDATE_ENV=(-e VALIDATE_U -e VALIDATE_N -e VALIDATE_WARMUP -e VALIDATE_WARMUP_WRITE)
psql() { docker compose exec -T postgres sh -c "psql -v ON_ERROR_STOP=1 -qtA -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -c \"$1\""; }

restore_postgres() {
    echo "Restoring PostgreSQL without pg_stat_statements..."
    psql "DROP EXTENSION IF EXISTS pg_stat_statements" || true
    docker compose up -d --force-recreate --wait postgres >/dev/null
}

echo "Starting PostgreSQL with pg_stat_statements..."
docker compose -f docker-compose.yml -f docker-compose.validate.yml up -d --force-recreate --wait postgres >/dev/null
trap 'restore_postgres; rm -f "$RAW"' EXIT
psql "CREATE EXTENSION IF NOT EXISTS pg_stat_statements"

for target in "${TARGETS[@]}"; do
    lang=${target%%/*}
    impl=${target##*/}
    echo "Validating $target..."
    # --no-deps: do not let compose recreate postgres with the base config
    case $lang in
        typescript) cmd=(node dist/validate.js "$impl") ;;
        php)        cmd=(php src/validate.php "$impl") ;;
        python)     cmd=(python src/validate.py "$impl") ;;
        java)       cmd=(java -cp app.jar com.benchmark.Validate "$impl") ;;
        *) echo "Unknown target: $target" >&2; exit 1 ;;
    esac
    docker compose run --rm --no-deps -T "${VALIDATE_ENV[@]}" "$lang" "${cmd[@]}" 2>/dev/null >> "$RAW"
done

python3 scripts/validate_compare.py "$RAW" "$OUT"
