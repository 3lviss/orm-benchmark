#!/usr/bin/env bash
# Records the actual runtime and library versions inside each benchmark image
# into results/environment.json. Run after `docker compose build`.
set -euo pipefail

cd "$(dirname "$0")/.."
OUT=results/environment.json
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

run() { docker compose run --rm --no-deps -T --entrypoint sh "$1" -c "$2"; }

echo "Collecting TypeScript versions..."
run typescript 'node -v; npm ls --depth=0 --json' > "$TMP/typescript.txt"

echo "Collecting PHP versions..."
run php 'php -r "echo PHP_VERSION, PHP_EOL;"; composer show --format=json 2>/dev/null' > "$TMP/php.txt"

echo "Collecting Python versions..."
run python 'python -c "import platform; print(platform.python_version())"; pip freeze' > "$TMP/python.txt"

echo "Collecting Java versions..."
run java 'java -version 2>&1 | head -1; cat dependencies.txt' > "$TMP/java.txt"

echo "Collecting PostgreSQL version..."
if docker compose ps --status running postgres | grep -q postgres; then
    docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "SELECT version()"' > "$TMP/postgres.txt"
else
    docker compose run --rm --no-deps -T --entrypoint postgres postgres --version > "$TMP/postgres.txt"
fi

docker compose config --images > "$TMP/images.txt"

python3 - "$TMP" "$OUT" <<'PY'
import json, re, sys, datetime, pathlib
tmp, out = pathlib.Path(sys.argv[1]), sys.argv[2]
read = lambda n: (tmp / f"{n}.txt").read_text().strip().splitlines()

ts = read("typescript")
npm = json.loads("\n".join(ts[1:]))
php = read("php")
composer = json.loads("\n".join(php[1:]))
py = read("python")
java = read("java")
java_deps = {}
for line in java[1:]:
    m = re.match(r"\s*([\w.\-]+):([\w.\-]+):jar:([\w.\-]+):\w+", line)
    if m:
        java_deps[f"{m[1]}:{m[2]}"] = m[3]

env = {
    "collected_at": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
    "postgres": read("postgres")[0],
    "images": read("images"),
    "typescript": {
        "node": ts[0],
        "packages": {k: v["version"] for k, v in npm.get("dependencies", {}).items()},
    },
    "php": {
        "php": php[0],
        "packages": {p["name"]: p["version"] for p in composer.get("installed", [])},
    },
    "python": {
        "python": py[0],
        "packages": dict(l.split("==", 1) for l in py[1:] if "==" in l),
    },
    "java": {
        "java": java[0],
        "packages": java_deps,
    },
}
pathlib.Path(out).write_text(json.dumps(env, indent=2) + "\n")
print(f"Wrote {out}")
PY
