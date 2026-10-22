#!/usr/bin/env python3
"""
Input:  JSON lines produced by the validate entry points (one line per
        implementation x scenario), see scripts/validate.sh.
Output: results/validation.json and a Markdown summary on stdout.

Query count definition: SQL statements sent to the database per iteration,
excluding transaction control (BEGIN, COMMIT, ROLLBACK, SAVEPOINT, RELEASE).
Content is normalised per scenario and compared across all 10 implementations;
the most common normalised result is the reference.
"""
import sys
import json
import hashlib
import datetime
from collections import Counter, defaultdict

SCENARIOS = ["A1", "A2", "A3", "A4", "B1", "B2", "B3", "C1", "C2", "D1"]
TX_CONTROL = ("BEGIN", "COMMIT", "ROLLBACK", "SAVEPOINT", "RELEASE", "START TRANSACTION")

EXPECTED = {
    ("D1", "php/raw_sql"):  "ORDER BY RANDOM() picks different products",
    ("D1", "php/eloquent"): "ORDER BY RANDOM() picks different products",
    ("D1", "php/doctrine"): "ORDER BY RANDOM() picks different products",
}
EXPECTED_RETURN = {
    ("C2", "typescript/drizzle"): "returns rowCount (pg) instead of count (postgres.js) -> 0",
}


def first(d, *keys):
    for k in keys:
        if isinstance(d, dict) and d.get(k) is not None:
            return d[k]
    return None


def money(v):
    return None if v is None else round(float(v), 2)


def name_of(v):
    return v.get("name") if isinstance(v, dict) else v


def normalise(scenario, r):
    if scenario == "A1":
        return [r.get("id"), r.get("name"), r.get("email")]
    if scenario == "A2":
        return [[p["id"], money(p["price"])] for p in r]
    if scenario in ("A3", "A4"):
        return [[first(o, "order_id", "id"), first(o, "user_id") or (o.get("user") or {}).get("id")] for o in r]
    if scenario == "B1":
        if isinstance(r, dict):
            items = [[name_of(first(i, "product", "product_name")), int(i["quantity"])] for i in r["items"]]
            order_id = first(r, "order_id", "id")
        else:
            items = [[first(i, "product_name", "product"), int(i["quantity"])] for i in r]
            order_id = r[0]["order_id"] if r else None
        return [order_id, sorted(items)]
    if scenario == "B2":
        return sorted([c["id"], int(c["product_count"]), money(c["avg_price"])] for c in r)
    if scenario == "B3":
        return [[p["id"], name_of(first(p, "category_name", "category"))] for p in r]
    if scenario in ("C1", "C2"):
        return None  # compared via rows affected on the database side
    if scenario == "D1":
        return [r.get("items_count"), money(r.get("total"))]
    raise ValueError(scenario)


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True).encode()).hexdigest()[:12]


def summarise(line):
    n = line["n"]
    per_kind = defaultdict(lambda: {"calls": 0, "rows": 0})
    excluded = 0
    for s in line["statements"]:
        kind = s["query"].strip().split(None, 1)[0].upper()
        if s["query"].strip().upper().startswith(TX_CONTROL):
            excluded += s["calls"]
            continue
        per_kind[kind]["calls"] += s["calls"]
        per_kind[kind]["rows"] += s["rows"]
    return {
        "statements_per_iteration": round(sum(k["calls"] for k in per_kind.values()) / n, 2),
        "tx_control_per_iteration": round(excluded / n, 2),
        "by_kind_per_iteration": {
            k: {"calls": round(v["calls"] / n, 2), "rows": round(v["rows"] / n, 2)}
            for k, v in sorted(per_kind.items())
        },
    }


def main(path, out_path):
    lines = [json.loads(l) for l in open(path) if l.startswith("{")]
    entries, errors = {}, []

    for line in lines:
        impl = f"{line['lang']}/{line['implementation']}"
        key = (line["scenario"], impl)
        entry = {"implementation": impl, "scenario": line["scenario"], "u": line["u"],
                 "n": line["n"], "warmup": line["warmup"], "ok": line["ok"]}
        if not line["ok"]:
            entry["error"] = line.get("error")
            errors.append(f"{impl} {line['scenario']}: {line.get('error')}")
        else:
            entry.update(summarise(line))
            entry["statements"] = line["statements"]
            if line["scenario"] in ("C1", "C2"):
                entry["returned"] = line["result"]
            else:
                entry["normalised"] = normalise(line["scenario"], line["result"])
                entry["content_hash"] = digest(entry["normalised"])
        entries[key] = entry

    # Content and effect checks per scenario
    for scenario in SCENARIOS:
        group = [e for (s, _), e in entries.items() if s == scenario and e["ok"]]
        if scenario in ("C1", "C2"):
            kind = "INSERT" if scenario == "C1" else "UPDATE"
            values = Counter(e["by_kind_per_iteration"].get(kind, {}).get("rows") for e in group)
            reference = values.most_common(1)[0][0] if values else None
            for e in group:
                db_rows = e["by_kind_per_iteration"].get(kind, {}).get("rows")
                e["db_rows_affected"] = db_rows
                e["content"] = "match" if db_rows == reference else "mismatch"
                expected_return = 10000 if scenario == "C1" else 1000
                if e["returned"] != expected_return:
                    known = EXPECTED_RETURN.get((scenario, e["implementation"]))
                    e["return_value"] = f"warning: {known}" if known else f"mismatch: {e['returned']}"
        else:
            hashes = Counter(e["content_hash"] for e in group)
            reference = hashes.most_common(1)[0][0] if hashes else None
            for e in group:
                if e["content_hash"] == reference:
                    e["content"] = "match"
                elif (scenario, e["implementation"]) in EXPECTED:
                    e["content"] = f"warning: {EXPECTED[(scenario, e['implementation'])]}"
                else:
                    e["content"] = "mismatch"

    implementations = sorted({impl for _, impl in entries}, key=lambda i: (i.split("/")[0], i.split("/")[1] != "raw_sql", i))
    result = {
        "generated_at": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
        "definition": "SQL statements sent per iteration, excluding transaction control "
                      "(BEGIN, COMMIT, ROLLBACK, SAVEPOINT, RELEASE); source: pg_stat_statements",
        "results": [entries[(s, i)] for i in implementations for s in SCENARIOS if (s, i) in entries],
    }
    with open(out_path, "w") as f:
        json.dump(result, f, indent=2, default=str)
        f.write("\n")

    # Markdown summary
    print("| Implementation | " + " | ".join(SCENARIOS) + " |")
    print("|---|" + "---|" * len(SCENARIOS))
    problems = []
    for impl in implementations:
        cells = []
        for s in SCENARIOS:
            e = entries.get((s, impl))
            if not e or not e["ok"]:
                cells.append("ERR")
                continue
            mark = "" if e["content"] == "match" else (" ⚠" if e["content"].startswith("warning") else " ✗")
            if e.get("return_value"):
                mark += " ⚠" if e["return_value"].startswith("warning") else " ✗"
            cells.append(f"{e['statements_per_iteration']:g}{mark}")
            if e["content"] != "match":
                problems.append(f"{impl} {s}: content {e['content']}")
            if e.get("return_value"):
                problems.append(f"{impl} {s}: return value {e['return_value']}")
        print(f"| {impl} | " + " | ".join(cells) + " |")
    print()
    for p in errors + problems:
        print(f"- {p}")
    print(f"\nWrote {out_path}")
    return 1 if errors or any("mismatch" in p for p in problems) else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else "results/validation.json"))
