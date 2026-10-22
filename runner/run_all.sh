#!/usr/bin/env bash
# Complete benchmark run: freeze check -> build -> template database -> validation
# -> every language x scenario x implementation from a fresh database copy
# -> environment, versions and EXPLAIN plans.
#
# Usage: runner/run_all.sh [--out DIR] [--only lang/impl,...] [--scenarios A1,C2,...]
#                          [--skip-validate] [--skip-build] [--reuse-template]
#
#   --out DIR         output directory, must not exist or be empty
#                     (default: results/run-<UTC timestamp>)
#   --only ...        subset of implementations, e.g. typescript/raw_sql,php/doctrine
#   --scenarios ...   subset of scenarios
#   --skip-validate   do not run scripts/validate.sh
#   --skip-build      reuse existing images
#   --reuse-template  keep an existing benchmark_template instead of seeding a new one
#                     (same data and statistics as an earlier run; warns if older than 24 h,
#                     because seed timestamps are relative to the seed date and C2 uses NOW())
#   --only, --scenarios, --skip-validate and --skip-build mark the run as partial;
#   --skip-build is only allowed for partial runs.
#
# Output: DIR/<lang>/<impl>/<scenario>.json (raw measurements), DIR/<lang>/<impl>/summary.json,
#         DIR/environment.json, DIR/validation.json, DIR/explain/*.txt,
#         DIR/explain-initial/C2.txt (C2 plan on a fresh copy before measuring), DIR/run.log
set -euo pipefail

SCRIPT="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
cd "$(dirname "$SCRIPT")/.."

# Keep the machine awake for the whole run (macOS)
if [ "$(uname)" = "Darwin" ] && [ -z "${RUN_ALL_CAFFEINATED:-}" ] && command -v caffeinate >/dev/null; then
    RUN_ALL_CAFFEINATED=1 exec caffeinate -dimsu "$SCRIPT" "$@"
fi

FREEZE_REF=112ea1c
# Everything that influences a measurement must be identical to FREEZE_REF,
# except the runner entry points and the validation entry points.
FROZEN_PATHS=(benchmarks docker dataset docker-compose.yml)
ALLOWED_CHANGES='/(run\.(ts|php|py)|Runner\.java|validate\.(ts|php|py)|Validate\.java)$'
LANGS=(typescript php python java)
SCENARIOS=(A1 A2 A3 A4 B1 B2 B3 C1 C2 D1)

impls_of() {
    case $1 in
        typescript) echo "raw_sql prisma drizzle" ;;
        php)        echo "raw_sql eloquent doctrine" ;;
        python)     echo "raw_sql sqlalchemy" ;;
        java)       echo "raw_sql hibernate" ;;
    esac
}

ARGS="$*"
OUT="results/run-$(date -u +%Y%m%dT%H%M%SZ)"
ONLY=""
SKIP_VALIDATE=0
SKIP_BUILD=0
REUSE_TEMPLATE=0
PARTIAL=0
while [ $# -gt 0 ]; do
    case $1 in
        --out)           OUT=$2; shift 2 ;;
        --only)          ONLY=",$2,"; PARTIAL=1; shift 2 ;;
        --scenarios)     IFS=, read -r -a SCENARIOS <<< "$2"; PARTIAL=1; shift 2 ;;
        --skip-validate) SKIP_VALIDATE=1; PARTIAL=1; shift ;;
        --skip-build)    SKIP_BUILD=1; PARTIAL=1; shift ;;
        --reuse-template) REUSE_TEMPLATE=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done
if [ $SKIP_BUILD -eq 1 ] && [ -z "$ONLY" ] && [ "${#SCENARIOS[@]}" -eq 10 ]; then
    echo "--skip-build is only allowed together with --only or --scenarios" >&2
    exit 1
fi
if [ -d "$OUT" ] && [ -n "$(ls -A "$OUT")" ]; then
    echo "Output directory $OUT is not empty" >&2
    exit 1
fi

mkdir -p "$OUT"
OUT=$(cd "$OUT" && pwd)
LOG="$OUT/run.log"
log() { echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*" | tee -a "$LOG"; }
STARTED=$(date -u +%Y-%m-%dT%H:%M:%SZ)
FAILED=()
ORDER=()

# ── 1. Freeze check ──────────────────────────────────────────────────────────
log "Freeze check against $FREEZE_REF"
changed=$( { git diff --name-only "$FREEZE_REF" -- "${FROZEN_PATHS[@]}"
             git ls-files --others --exclude-standard -- "${FROZEN_PATHS[@]}"; } \
           | grep -vE "$ALLOWED_CHANGES" || true)
if [ -n "$changed" ]; then
    log "Files differ from $FREEZE_REF:"
    echo "$changed" | tee -a "$LOG"
    exit 1
fi

# ── 2. Build and database server ─────────────────────────────────────────────
if [ $SKIP_BUILD -eq 0 ]; then
    log "Building images"
    docker compose build >>"$LOG" 2>&1
fi
log "Starting PostgreSQL"
docker compose up -d --force-recreate --wait postgres >>"$LOG" 2>&1

# ── 3. Template database and validation ──────────────────────────────────────
TEMPLATE_CREATED=$(bash scripts/db.sh template-created 2>>"$LOG")
if [ $REUSE_TEMPLATE -eq 1 ] && [ -n "$TEMPLATE_CREATED" ]; then
    log "Reusing template database created $TEMPLATE_CREATED"
    age=$(python3 -c "import datetime as d, sys; print(int((d.datetime.now(d.timezone.utc) - d.datetime.fromisoformat(sys.argv[1].replace('Z', '+00:00'))).total_seconds() // 3600))" "$TEMPLATE_CREATED")
    if [ "$age" -ge 24 ]; then
        log "WARNING: template is ${age} h old — C2 matches a different set of rows than right after seeding"
    fi
else
    log "Preparing template database"
    bash scripts/db.sh prepare-template >>"$LOG" 2>&1
    TEMPLATE_CREATED=$(bash scripts/db.sh template-created 2>>"$LOG")
fi

log "Initial C2 plan (fresh copy)"
bash scripts/db.sh reset >>"$LOG" 2>&1
python3 scripts/explain.py "$OUT/explain-initial" C2 >>"$LOG"
cat "$OUT/explain-initial/C2.txt" >>"$LOG"

if [ $SKIP_VALIDATE -eq 0 ]; then
    log "Validation (all implementations)"
    if ! bash scripts/validate.sh 2>>"$LOG" | tee -a "$LOG"; then
        log "Validation failed — aborting"
        exit 1
    fi
    cp results/validation.json "$OUT/validation.json"
fi

# ── 4. Measurement ───────────────────────────────────────────────────────────
# Merges one runner result (stdin) into <dir>/summary.json
MERGE_SUMMARY=$(cat <<'PY'
import json, sys, pathlib
path, lang, impl, scenario, started = sys.argv[1:]
text = sys.stdin.read()
result, _ = json.JSONDecoder().raw_decode(text[text.index("{"):])
p = pathlib.Path(path)
summary = json.loads(p.read_text()) if p.exists() else {
    "meta": {"implementation": impl, "language": lang, "run_started": started,
             "environment": "../../environment.json"},
    "results": {},
}
result.pop("implementation", None)
result.pop("scenario", None)
summary["results"][scenario] = result
order = ["A1", "A2", "A3", "A4", "B1", "B2", "B3", "C1", "C2", "D1"]
summary["results"] = dict(sorted(summary["results"].items(), key=lambda kv: order.index(kv[0])))
p.write_text(json.dumps(summary, indent=4) + "\n")
PY
)

run_scenario() {
    local lang=$1 impl=$2 sc=$3 dir="$OUT/$1/$2" stdout cmd
    mkdir -p "$dir"
    bash scripts/db.sh reset >>"$LOG" 2>&1
    case $lang in
        typescript) cmd=(node dist/run.js "$impl" "$sc") ;;
        php)        cmd=(php src/run.php "$impl" "$sc") ;;
        python)     cmd=(python src/run.py "$impl" "$sc") ;;
        java)       cmd=(sh -c 'exec java $JAVA_OPTS -jar app.jar "$@"' java "$impl" "$sc") ;;
    esac
    # --no-deps: postgres is managed by db.sh, compose must not recreate it
    if ! stdout=$(docker compose run --rm --no-deps -T -e RAW_OUTPUT_DIR=/raw \
                  -v "$dir:/raw" "$lang" "${cmd[@]}" 2>>"$LOG"); then
        log "FAILED $lang/$impl $sc"
        FAILED+=("$lang/$impl/$sc")
        return
    fi
    python3 -c "$MERGE_SUMMARY" "$dir/summary.json" "$lang" "$impl" "$sc" "$STARTED" <<<"$stdout"
}

for lang in "${LANGS[@]}"; do
    read -r -a impls <<< "$(impls_of "$lang")"
    selected=()
    for impl in "${impls[@]}"; do
        if [ -z "$ONLY" ] || [[ "$ONLY" == *",$lang/$impl,"* ]]; then selected+=("$impl"); fi
    done
    [ ${#selected[@]} -gt 0 ] || continue
    log "Measuring $lang: ${selected[*]}"
    for i in "${!SCENARIOS[@]}"; do
        sc=${SCENARIOS[$i]}
        k=${#selected[@]}
        # rotate implementation order per scenario to spread drift across implementations
        for j in $(seq 0 $((k - 1))); do
            impl=${selected[$(( (i + j) % k ))]}
            ORDER+=("$lang/$impl/$sc")
            log "Run $lang/$impl $sc"
            run_scenario "$lang" "$impl" "$sc"
        done
    done
done

# ── 5. Environment, versions, EXPLAIN ────────────────────────────────────────
log "Collecting versions and environment"
bash scripts/versions.sh >>"$LOG" 2>&1
mv results/environment.json "$OUT/environment.json"

python3 - "$OUT/environment.json" "$STARTED" "$ARGS" "$PARTIAL" "$TEMPLATE_CREATED" "$REUSE_TEMPLATE" \
          "${ORDER[*]:-}" "${FAILED[*]:-}" <<'PY'
import json, sys, subprocess, datetime, pathlib, platform

path, started, args, partial, template_created, reused, order, failed = sys.argv[1:]

def sh(*cmd):
    return subprocess.run(cmd, capture_output=True, text=True).stdout.strip()

def psql(sql):
    return sh("docker", "compose", "exec", "-T", "postgres", "sh", "-c",
              'psql -X -qtA -F "|" -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "$0"', sql)

if platform.system() == "Darwin":
    host = {"cpu": sh("sysctl", "-n", "machdep.cpu.brand_string"),
            "cpu_count": int(sh("sysctl", "-n", "hw.ncpu")),
            "memory_bytes": int(sh("sysctl", "-n", "hw.memsize")),
            "os": f"macOS {sh('sw_vers', '-productVersion')} ({platform.machine()})"}
else:
    cpu = next((l.split(":", 1)[1].strip() for l in open("/proc/cpuinfo") if l.startswith("model name")), "")
    mem = next(int(l.split()[1]) * 1024 for l in open("/proc/meminfo") if l.startswith("MemTotal"))
    host = {"cpu": cpu, "cpu_count": int(sh("nproc")), "memory_bytes": mem,
            "os": f"{platform.system()} {platform.release()} ({platform.machine()})"}

docker = lambda fmt: sh("docker", "info", "--format", fmt)
host["docker"] = {
    "server_version": docker("{{.ServerVersion}}"),
    "vm_os": docker("{{.OperatingSystem}}"),
    "vm_kernel": docker("{{.KernelVersion}}"),
    "vm_cpus": int(docker("{{.NCPU}}") or 0),
    "vm_memory_bytes": int(docker("{{.MemTotal}}") or 0),
}

images = {}
for service in ["typescript", "php", "python", "java"]:
    images[service] = sh("docker", "image", "inspect", f"orm-benchmark-{service}", "--format", "{{.Id}}")
images["postgres"] = sh("docker", "inspect", sh("docker", "compose", "ps", "-q", "postgres"), "--format", "{{.Image}}")

settings = ["shared_buffers", "work_mem", "maintenance_work_mem", "effective_cache_size", "max_wal_size",
            "checkpoint_timeout", "random_page_cost", "jit", "autovacuum", "autovacuum_naptime",
            "max_connections", "synchronous_commit", "fsync"]
rows = psql("SELECT name, setting, coalesce(unit, '') FROM pg_settings WHERE name IN ("
            + ", ".join(f"'{s}'" for s in settings) + ") ORDER BY name")
pg_settings = {name: f"{value}{unit and ' ' + unit}" for name, value, unit in
               (r.split("|") for r in rows.splitlines() if r)}

p = pathlib.Path(path)
env = json.loads(p.read_text())
env["run"] = {
    "started": started,
    "finished": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
    "arguments": args,
    "partial": partial == "1",
    "template_created": template_created,
    "template_reused": reused == "1",
    "git_commit": sh("git", "rev-parse", "HEAD"),
    "uncommitted_files": len(sh("git", "status", "--porcelain").splitlines()),
    "order": order.split() if order else [],
    "failed": failed.split() if failed else [],
}
env["host"] = host
env["image_ids"] = images
env["postgres_settings"] = pg_settings
p.write_text(json.dumps(env, indent=2) + "\n")
PY

log "EXPLAIN plans"
bash scripts/db.sh reset >>"$LOG" 2>&1
python3 scripts/explain.py "$OUT/explain" | tee -a "$LOG"

if [ ${#FAILED[@]} -gt 0 ]; then
    log "Finished with ${#FAILED[@]} failed runs: ${FAILED[*]}"
    exit 1
fi
log "Finished"
