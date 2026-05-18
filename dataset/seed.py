import random
import psycopg2
from psycopg2.extras import execute_values
from faker import Faker
import time
import os

# ============================================================
# FIXED SEED — critical for reproducibility
# ============================================================
SEED = 42
random.seed(SEED)
fake = Faker()
Faker.seed(SEED)

# ============================================================
# DATABASE CONNECTION CONFIG
# ============================================================
DB = {
    "host": os.getenv("DB_HOST", "localhost"),
    "port": int(os.getenv("DB_PORT", 5432)),
    "dbname": os.getenv("DB_NAME", "benchmark"),
    "user": os.getenv("DB_USER", "benchmark"),
    "password": os.getenv("DB_PASS", "benchmark")
}

# ============================================================
# DATASET SIZE CONFIGURATION
# ============================================================
COUNTS = {
    "users": 10_000,
    "categories_root": 20,
    "categories_sub": 80,
    "tags": 500,
    "products": 50_000,
    "orders": 200_000,
    "reviews": 150_000,
}

STATUSES = ['pending', 'processing', 'shipped', 'delivered', 'cancelled']

# ============================================================
# HELPERS
# ============================================================
def log(msg):
    print(f"[{time.strftime('%H:%M:%S')}] {msg}", flush=True)

def batch_insert(cur, table, cols, rows, batch=1000):
    """Insert rows in batches to avoid memory issues."""
    for i in range(0, len(rows), batch):
        execute_values(
            cur,
            f"INSERT INTO {table} ({','.join(cols)}) VALUES %s",
            rows[i:i+batch]
        )

# ============================================================
# MAIN GENERATION LOGIC
# ============================================================
def main():
    log("Connecting to PostgreSQL...")
    conn = psycopg2.connect(**DB)
    conn.autocommit = False
    cur = conn.cursor()

    # ── Users ────────────────────────────────────────────────
    log(f"Generating {COUNTS['users']:,} users...")
    users = []
    while len(users) < COUNTS["users"]:
        users.append((
            fake.name(),
            fake.unique.email(),
            fake.date_time_between(start_date="-3y", end_date="now")
        ))
    batch_insert(cur, "users", ["name", "email", "created_at"], users)
    conn.commit()
    log("  Users done.")

    # ── Categories ───────────────────────────────────────────
    log("Generating 100 categories...")

    # Root categories first
    root_rows = [(fake.unique.word().capitalize(), None)
                 for _ in range(COUNTS["categories_root"])]
    batch_insert(cur, "categories", ["name", "parent_id"], root_rows)
    conn.commit()

    # Fetch root IDs for sub-category parent assignment
    cur.execute("SELECT id FROM categories ORDER BY id")
    root_ids = [r[0] for r in cur.fetchall()]

    # Sub-categories
    sub_rows = [(fake.word().capitalize() + f"_{i}", random.choice(root_ids))
                for i in range(COUNTS["categories_sub"])]
    batch_insert(cur, "categories", ["name", "parent_id"], sub_rows)
    conn.commit()

    cur.execute("SELECT id FROM categories ORDER BY id")
    all_cat_ids = [r[0] for r in cur.fetchall()]
    log(f"  Categories done: {len(all_cat_ids)} total.")

    # ── Tags ─────────────────────────────────────────────────
    log(f"Generating {COUNTS['tags']:,} tags...")
    tag_names = set()
    while len(tag_names) < COUNTS["tags"]:
        tag_names.add(fake.word().lower() + f"_{len(tag_names)}")
    batch_insert(cur, "tags", ["name"], [(n,) for n in tag_names])
    conn.commit()

    cur.execute("SELECT id FROM tags ORDER BY id")
    all_tag_ids = [r[0] for r in cur.fetchall()]
    log("  Tags done.")

    # ── Products ─────────────────────────────────────────────
    log(f"Generating {COUNTS['products']:,} products...")
    product_rows = [
        (
            fake.catch_phrase()[:255],
            round(random.uniform(1.99, 999.99), 2),
            fake.text(max_nb_chars=500),
            random.choice(all_cat_ids),
            fake.date_time_between(start_date="-2y", end_date="now")
        )
        for _ in range(COUNTS["products"])
    ]
    batch_insert(
        cur, "products",
        ["name", "price", "description", "category_id", "created_at"],
        product_rows
    )
    conn.commit()

    cur.execute("SELECT id FROM products ORDER BY id")
    all_product_ids = [r[0] for r in cur.fetchall()]
    log("  Products done.")

    # ── Product Tags (many-to-many) ──────────────────────────
    # Use a set to prevent duplicate (product_id, tag_id) pairs
    log("Generating product_tags (avg 4 tags per product)...")
    pt_rows = set()
    for pid in all_product_ids:
        for tid in random.sample(all_tag_ids, random.randint(2, 6)):
            pt_rows.add((pid, tid))
    batch_insert(cur, "product_tags", ["product_id", "tag_id"], list(pt_rows))
    conn.commit()
    log(f"  Product tags done: {len(pt_rows):,} rows.")

    # ── Orders ───────────────────────────────────────────────
    log(f"Generating {COUNTS['orders']:,} orders...")
    cur.execute("SELECT id FROM users ORDER BY id")
    all_user_ids = [r[0] for r in cur.fetchall()]

    order_rows = [
        (
            random.choice(all_user_ids),
            round(random.uniform(10, 500), 2),
            random.choice(STATUSES),
            fake.date_time_between(start_date="-2y", end_date="now")
        )
        for _ in range(COUNTS["orders"])
    ]
    batch_insert(
        cur, "orders",
        ["user_id", "total", "status", "created_at"],
        order_rows
    )
    conn.commit()

    cur.execute("SELECT id FROM orders ORDER BY id")
    all_order_ids = [r[0] for r in cur.fetchall()]
    log("  Orders done.")

    # ── Order Items (streamed in chunks of 10,000 orders) ────
    # Process in chunks to avoid loading ~800K rows into RAM at once
    log("Generating order_items (avg 4 items per order, streamed)...")
    CHUNK_SIZE = 10_000
    total_items = 0

    for chunk_start in range(0, len(all_order_ids), CHUNK_SIZE):
        chunk = all_order_ids[chunk_start:chunk_start + CHUNK_SIZE]
        chunk_rows = []

        for oid in chunk:
            n = random.randint(2, 6)
            for pid in random.sample(all_product_ids, min(n, len(all_product_ids))):
                chunk_rows.append((
                    oid,
                    pid,
                    random.randint(1, 5),
                    round(random.uniform(1.99, 299.99), 2)
                ))

        batch_insert(
            cur, "order_items",
            ["order_id", "product_id", "quantity", "price"],
            chunk_rows
        )
        conn.commit()
        total_items += len(chunk_rows)

        progress = min(chunk_start + CHUNK_SIZE, len(all_order_ids))
        log(f"  Order items: {progress:,}/{len(all_order_ids):,} orders processed "
            f"({total_items:,} items so far)")

    log(f"  Order items done: {total_items:,} total rows.")

    # ── Reviews ──────────────────────────────────────────────
    log(f"Generating {COUNTS['reviews']:,} reviews...")
    review_set = set()
    review_rows = []
    attempts = 0

    while len(review_rows) < COUNTS["reviews"] and \
            attempts < COUNTS["reviews"] * 3:
        uid = random.choice(all_user_ids)
        pid = random.choice(all_product_ids)
        if (uid, pid) not in review_set:
            review_set.add((uid, pid))
            review_rows.append((
                uid,
                pid,
                random.randint(1, 5),
                fake.text(max_nb_chars=300) if random.random() > 0.3 else None,
                fake.date_time_between(start_date="-2y", end_date="now")
            ))
        attempts += 1

    batch_insert(
        cur, "reviews",
        ["user_id", "product_id", "rating", "comment", "created_at"],
        review_rows
    )
    conn.commit()
    log(f"  Reviews done: {len(review_rows):,} rows.")

    # ── Final summary ─────────────────────────────────────────
    log("\n=== DATASET SUMMARY ===")
    for table in ["users", "categories", "tags", "products",
                  "product_tags", "orders", "order_items", "reviews"]:
        cur.execute(f"SELECT COUNT(*) FROM {table}")
        count = cur.fetchone()[0]
        log(f"  {table}: {count:,}")

    cur.close()
    conn.close()
    log("\nDataset generation complete!")

if __name__ == "__main__":
    main()
