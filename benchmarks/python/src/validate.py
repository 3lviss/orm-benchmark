import os
import sys
import json
import math
import random
import importlib

import psycopg2
import psycopg2.extras

"""
Validation entry point (docs/PLAN_REPO.md 2.5) — not used for timing.
Usage: python src/validate.py <implementation> [scenario ...]

random.randint and random.choice are replaced so every implementation receives
the same parameters derived from VALIDATE_U. Per scenario: warm-up, then
pg_stat_statements_reset(), N calls, and the statements recorded by the
database are printed as one JSON line together with the last result.
"""

U        = float(os.getenv("VALIDATE_U", "0.1"))
N        = int(os.getenv("VALIDATE_N", "10"))
WARMUP   = int(os.getenv("VALIDATE_WARMUP", "20"))
WARMUP_W = int(os.getenv("VALIDATE_WARMUP_WRITE", "5"))

ALL_SCENARIOS   = ["A1", "A2", "A3", "A4", "B1", "B2", "B3", "C1", "C2", "D1"]
WRITE_SCENARIOS = {"C1", "C2", "D1"}

STATS_SQL = """
    SELECT query, calls::int AS calls, rows::int AS rows
    FROM pg_stat_statements
    WHERE dbid = (SELECT oid FROM pg_database WHERE datname = current_database())
      AND query NOT ILIKE '%%pg_stat_statements%%'
    ORDER BY calls DESC, query
"""

def fixed_randint(a: int, b: int) -> int:
    return a + math.floor(U * (b - a + 1))

def fixed_choice(seq):
    return seq[math.floor(U * len(seq))]

def main():
    implementation = (sys.argv[1] if len(sys.argv) > 1 else "raw_sql").lower()
    scenarios      = [s.upper() for s in sys.argv[2:]] or ALL_SCENARIOS

    random.randint = fixed_randint
    random.choice  = fixed_choice

    module = importlib.import_module(f"scenarios.{implementation}.Scenario")
    runner = module.Scenario()

    admin = psycopg2.connect(
        host=os.getenv("DB_HOST", "localhost"),
        port=os.getenv("DB_PORT", "5432"),
        dbname=os.getenv("DB_NAME", "benchmark"),
        user=os.getenv("DB_USER", "benchmark"),
        password=os.getenv("DB_PASS", "benchmark"),
    )
    admin.autocommit = True

    for scenario in scenarios:
        fn     = getattr(runner, scenario.lower())
        warmup = WARMUP_W if scenario in WRITE_SCENARIOS else WARMUP
        line   = {"lang": "python", "implementation": implementation, "scenario": scenario,
                  "u": U, "n": N, "warmup": warmup}
        try:
            for _ in range(warmup):
                fn()
            with admin.cursor() as cur:
                cur.execute("SELECT pg_stat_statements_reset()")
            result = None
            for _ in range(N):
                result = fn()
            with admin.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
                cur.execute(STATS_SQL)
                line["statements"] = cur.fetchall()
            line["result"] = result
            line["ok"]     = True
        except Exception as e:
            line["ok"]    = False
            line["error"] = str(e)
        print(json.dumps(line, default=str), flush=True)

    admin.close()

if __name__ == "__main__":
    main()
