#!/usr/bin/env python3
"""
EXPLAIN (ANALYZE, BUFFERS) for every raw SQL scenario, one file per scenario.

Usage: scripts/explain.py OUT_DIR [SCENARIO ...]

SQL is the TypeScript raw implementation (same logical SQL in all raw
implementations); parameters are the validation values (u = 0.1). Reads run
once to warm the cache (output discarded), then EXPLAIN. Writes (C1, C2, D1)
run inside BEGIN ... ROLLBACK, so the database is unchanged afterwards.
Run against a fresh copy of the template (scripts/db.sh reset).
"""
import sys
import pathlib
import subprocess

HEADER = r"""\set ON_ERROR_STOP 1
\pset pager off
\pset tuples_only on
\pset format unaligned
\echo '-- Scenario: {sc}'
\echo '-- Source: benchmarks/typescript/src/scenarios/raw_sql/Scenario.ts (same logical SQL in all raw implementations)'
\echo '-- Parameters: validation values (u = 0.1)'
\echo '-- Note: single EXPLAIN (ANALYZE, BUFFERS) run after one warm-up execution; timings are indicative only.'
SELECT '-- ' || version();
SELECT '-- Generated: ' || to_char(now() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS "UTC"');
"""


def quote(sql):
    return sql.replace("'", "''")


def read(title, sql):
    return (f"\\echo ''\n\\echo '-- {title}'\n\\echo '{quote(sql)}'\n"
            f"\\o /dev/null\n{sql};\n\\o\n"
            f"EXPLAIN (ANALYZE, BUFFERS) {sql};\n")


def write(title, sql, echo=True):
    shown = f"\\echo '{quote(sql)}'\n" if echo else ""
    return f"\\echo ''\n\\echo '-- {title}'\n{shown}EXPLAIN (ANALYZE, BUFFERS) {sql};\n"


def generic(title, name, types, sql, args):
    """Same statement as a prepared statement with a forced generic plan, as drivers
    using server-side prepared statements (postgres.js, pgjdbc) may end up with."""
    return (f"\\echo ''\n\\echo '-- {title} (generic plan: PREPARE + EXECUTE, plan_cache_mode = force_generic_plan)'\n"
            f"SET plan_cache_mode = force_generic_plan;\nPREPARE {name} ({types}) AS {sql};\n"
            f"EXPLAIN (ANALYZE, BUFFERS) EXECUTE {name}({args});\nDEALLOCATE {name};\nRESET plan_cache_mode;\n")


def c1_chunk(start):
    return ("INSERT INTO products (name, price, description, category_id, created_at) VALUES "
            + ", ".join(f"('Bulk Product {start + j}', 101.79, NULL, 11, NOW())" for j in range(500)))


C2_UPDATE = ("UPDATE orders SET status = '{to}' FROM (SELECT id FROM orders WHERE status = '{frm}' "
             "AND created_at < NOW() - INTERVAL '30 days' ORDER BY id LIMIT 1000) AS batch "
             "WHERE orders.id = batch.id")

SCENARIOS = {
    "A1": read("A1: user by primary key (id = 1001)",
               "SELECT id, email, name, created_at FROM users WHERE id = 1001"),
    "A2": read("A2: products by category (category_id = 11)",
               "SELECT id, name, price, created_at FROM products WHERE category_id = 11 "
               "ORDER BY created_at DESC LIMIT 20")
          + generic("A2", "a2", "int",
                    "SELECT id, name, price, created_at FROM products WHERE category_id = $1 "
                    "ORDER BY created_at DESC LIMIT 20", "11"),
    "A3": read("A3 query 1/101: first 100 orders",
               "SELECT id, user_id, total, status FROM orders ORDER BY id ASC LIMIT 100")
          + "SELECT user_id AS a3_user_id FROM orders ORDER BY id LIMIT 1 \\gset\n"
          + read("A3 queries 2..101: user per order (shown for the user of the first order)",
                 "SELECT id, name, email FROM users WHERE id = :a3_user_id"),
    "A4": read("A4: orders with users (single JOIN)",
               "SELECT o.id, o.total, o.status, o.created_at, u.id AS user_id, u.name AS user_name, "
               "u.email AS user_email FROM orders o INNER JOIN users u ON u.id = o.user_id "
               "ORDER BY o.id ASC LIMIT 100"),
    "B1": read("B1: order -> items -> product -> category (order_id = 20001)",
               "SELECT o.id AS order_id, o.total, o.status, oi.id AS item_id, oi.quantity, "
               "oi.price AS item_price, p.name AS product_name, p.price AS product_price, "
               "c.name AS category_name FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id "
               "INNER JOIN products p ON p.id = oi.product_id INNER JOIN categories c ON c.id = p.category_id "
               "WHERE o.id = 20001"),
    "B2": read("B2: product count and average price per category",
               "SELECT c.id, c.name, COUNT(p.id) AS product_count, ROUND(AVG(p.price)::numeric, 2) AS avg_price "
               "FROM categories c LEFT JOIN products p ON p.category_id = c.id GROUP BY c.id, c.name "
               "ORDER BY product_count DESC"),
    "B3": read("B3: products by tag (tag_id = 51); no index on product_tags(tag_id), PK is (product_id, tag_id)",
               "SELECT p.id, p.name, p.price, c.name AS category_name FROM products p "
               "INNER JOIN product_tags pt ON pt.product_id = p.id INNER JOIN categories c ON c.id = p.category_id "
               "WHERE pt.tag_id = 51 ORDER BY p.id ASC LIMIT 50")
          + generic("B3", "b3", "int",
                    "SELECT p.id, p.name, p.price, c.name AS category_name FROM products p "
                    "INNER JOIN product_tags pt ON pt.product_id = p.id INNER JOIN categories c ON c.id = p.category_id "
                    "WHERE pt.tag_id = $1 ORDER BY p.id ASC LIMIT 50", "51"),
    "C1": "BEGIN;\n"
          + write("C1 INSERT chunk 1/20 (500 rows, multi-row VALUES; chunks 2..20 are identical in shape)",
                  c1_chunk(0), echo=False)
          + "".join(c1_chunk(i) + ";\n" for i in range(500, 10000, 500))
          + write("C1 cleanup: delete the 10 000 inserted rows", "DELETE FROM products WHERE name LIKE 'Bulk Product%'")
          + "ROLLBACK;\n",
    "C2": "BEGIN;\n"
          + write("C2: shipped -> delivered (1000 rows)", C2_UPDATE.format(to="delivered", frm="shipped"))
          + write("C2 restore: delivered -> shipped (1000 rows)", C2_UPDATE.format(to="shipped", frm="delivered"))
          + "ROLLBACK;\n"
          + "BEGIN;\n"
          + generic("C2", "c2", "timestamptz",
                    "UPDATE orders SET status = 'delivered' FROM (SELECT id FROM orders WHERE status = 'shipped' "
                    "AND created_at < $1 ORDER BY id LIMIT 1000) AS batch WHERE orders.id = batch.id",
                    "NOW() - INTERVAL '30 days'")
          + "ROLLBACK;\n",
    "D1": "BEGIN;\n"
          + write("D1: pick 5 products (skip = 4999)", "SELECT id, price FROM products ORDER BY id ASC LIMIT 5 OFFSET 4999")
          + "SELECT id AS product_id, price AS product_price FROM products ORDER BY id ASC LIMIT 1 OFFSET 4999 \\gset\n"
          + write("D1: insert order",
                  "INSERT INTO orders (user_id, total, status, created_at) VALUES (1001, 100.00, 'pending', NOW()) RETURNING id")
          + "INSERT INTO orders (user_id, total, status, created_at) VALUES (1001, 100.00, 'pending', NOW()) "
            "RETURNING id AS order_id \\gset\n"
          + write("D1: insert order item (x5 per iteration; shown once)",
                  "INSERT INTO order_items (order_id, product_id, quantity, price) "
                  "VALUES (:order_id, :product_id, 1, :product_price)")
          + write("D1 cleanup: delete order items", "DELETE FROM order_items WHERE order_id = :order_id")
          + write("D1 cleanup: delete order", "DELETE FROM orders WHERE id = :order_id")
          + "ROLLBACK;\n",
}


def main(out_dir, only):
    out = pathlib.Path(out_dir)
    out.mkdir(parents=True, exist_ok=True)
    selected = {sc: body for sc, body in SCENARIOS.items() if not only or sc in only}
    psql = ["docker", "compose", "exec", "-T", "postgres", "sh", "-c",
            'exec psql -X -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f -']
    for sc, body in selected.items():
        result = subprocess.run(psql, input=HEADER.format(sc=sc) + body, capture_output=True, text=True)
        (out / f"{sc}.txt").write_text(result.stdout + result.stderr)
        if result.returncode != 0:
            print(f"EXPLAIN {sc} failed, see {out / (sc + '.txt')}", file=sys.stderr)
            return 1
    print(f"Wrote {len(selected)} EXPLAIN files to {out}")
    return 0


if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.exit("Usage: scripts/explain.py OUT_DIR [SCENARIO ...]")
    sys.exit(main(sys.argv[1], [s.upper() for s in sys.argv[2:]]))
