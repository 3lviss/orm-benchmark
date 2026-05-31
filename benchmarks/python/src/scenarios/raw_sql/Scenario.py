import random
from sqlalchemy import text
from Connection import get_engine

"""
Raw SQL baseline scenarios using SQLAlchemy Core text() directly.
No ORM abstraction — direct SQL execution via engine connection.
Baseline for SQLAlchemy ORM overhead measurement.
"""

class Scenario:
    def __init__(self):
        self.engine = get_engine()

    def a1(self):
        """A1 — Simple select by primary key."""
        with self.engine.connect() as conn:
            result = conn.execute(
                text("SELECT id, email, name, created_at FROM users WHERE id = :id"),
                {"id": random.randint(1, 10000)}
            )
            row = result.fetchone()
            return dict(row._mapping) if row else {}

    def a2(self):
        """A2 — Filtered list with ORDER BY and LIMIT."""
        with self.engine.connect() as conn:
            result = conn.execute(
                text("""
                    SELECT id, name, price, created_at
                    FROM products
                    WHERE category_id = :category_id
                    ORDER BY created_at DESC
                    LIMIT 20
                """),
                {"category_id": random.randint(1, 100)}
            )
            return [dict(row._mapping) for row in result]

    def a3(self):
        """
        A3 — N+1 diagnostic: load 100 orders then access user for each.
        Intentionally written as N+1 to match ORM default behaviour.
        """
        with self.engine.connect() as conn:
            orders = conn.execute(
                text("SELECT id, user_id, total, status FROM orders ORDER BY id LIMIT 100")
            ).fetchall()

            results = []
            for order in orders:
                user = conn.execute(
                    text("SELECT id, name, email FROM users WHERE id = :id"),
                    {"id": order.user_id}
                ).fetchone()
                results.append({
                    "order_id": order.id,
                    "total":    str(order.total),
                    "status":   order.status,
                    "user":     dict(user._mapping) if user else None,
                })
            return results

    def a4(self):
        """A4 — Eager loading via JOIN."""
        with self.engine.connect() as conn:
            result = conn.execute(text("""
                SELECT o.id, o.total, o.status, o.created_at,
                       u.id    AS user_id,
                       u.name  AS user_name,
                       u.email AS user_email
                FROM orders o
                INNER JOIN users u ON u.id = o.user_id
                ORDER BY o.id ASC
                LIMIT 100
            """))
            return [dict(row._mapping) for row in result]

    def b1(self):
        """B1 — Deep eager loading across 3 levels."""
        with self.engine.connect() as conn:
            result = conn.execute(
                text("""
                    SELECT o.id  AS order_id,
                           o.total,
                           o.status,
                           oi.id AS item_id,
                           oi.quantity,
                           oi.price AS item_price,
                           p.name   AS product_name,
                           p.price  AS product_price,
                           c.name   AS category_name
                    FROM orders o
                    INNER JOIN order_items oi ON oi.order_id = o.id
                    INNER JOIN products p     ON p.id = oi.product_id
                    INNER JOIN categories c   ON c.id = p.category_id
                    WHERE o.id = :order_id
                """),
                {"order_id": random.randint(1, 200000)}
            )
            return [dict(row._mapping) for row in result]

    def b2(self):
        """B2 — Aggregate with GROUP BY."""
        with self.engine.connect() as conn:
            result = conn.execute(text("""
                SELECT c.id,
                       c.name,
                       COUNT(p.id)                    AS product_count,
                       ROUND(AVG(p.price)::numeric, 2) AS avg_price
                FROM categories c
                LEFT JOIN products p ON p.category_id = c.id
                GROUP BY c.id, c.name
                ORDER BY product_count DESC
            """))
            return [dict(row._mapping) for row in result]

    def b3(self):
        """B3 — Many-to-many: products by tag with category."""
        with self.engine.connect() as conn:
            result = conn.execute(
                text("""
                    SELECT p.id,
                           p.name,
                           p.price,
                           c.name AS category_name
                    FROM products p
                    INNER JOIN product_tags pt ON pt.product_id = p.id
                    INNER JOIN categories c    ON c.id = p.category_id
                    WHERE pt.tag_id = :tag_id
                    ORDER BY p.id ASC
                    LIMIT 50
                """),
                {"tag_id": random.randint(1, 500)}
            )
            return [dict(row._mapping) for row in result]

    def c1(self):
        """C1 — Bulk insert: 10,000 products in chunks."""
        chunk_size = 500
        total      = 10000
        cat_ids    = list(range(1, 101))
        inserted   = 0

        with self.engine.connect() as conn:
            for i in range(0, total, chunk_size):
                count = min(chunk_size, total - i)
                rows  = [
                    {
                        "name":        f"Bulk Product {i + j}",
                        "price":       round(random.randint(199, 99999) / 100, 2),
                        "description": None,
                        "category_id": random.choice(cat_ids),
                        "created_at":  "NOW()",
                    }
                    for j in range(count)
                ]

                conn.execute(
                    text("""
                        INSERT INTO products (name, price, description, category_id, created_at)
                        VALUES (:name, :price, :description, :category_id, NOW())
                    """),
                    rows
                )
                conn.commit()
                inserted += count

            # Clean up to restore dataset state
            conn.execute(text("DELETE FROM products WHERE name LIKE 'Bulk Product%'"))
            conn.commit()

        return inserted

    def c2(self):
        """C2 — Bulk update: update status of old shipped orders."""
        with self.engine.connect() as conn:
            result = conn.execute(text("""
                UPDATE orders
                SET status = 'delivered'
                WHERE status = 'shipped'
                  AND created_at < NOW() - INTERVAL '30 days'
            """))
            conn.commit()
            affected = result.rowcount

            # Restore original status
            conn.execute(text("""
                UPDATE orders
                SET status = 'shipped'
                WHERE status = 'delivered'
                  AND created_at < NOW() - INTERVAL '30 days'
            """))
            conn.commit()

        return affected

    def d1(self):
        """
        D1 — Unit of Work diagnostic.
        Raw SQL uses explicit transaction — no Unit of Work pattern.
        """
        user_id = random.randint(1, 10000)
        skip    = random.randint(0, 49995)

        with self.engine.connect() as conn:
            products = conn.execute(
                text("SELECT id, price FROM products ORDER BY id LIMIT 5 OFFSET :skip"),
                {"skip": skip}
            ).fetchall()

            total = 0
            items = []
            for p in products:
                quantity = random.randint(1, 3)
                price    = float(p.price)
                total   += quantity * price
                items.append({
                    "product_id": p.id,
                    "quantity":   quantity,
                    "price":      price,
                })

            # Explicit transaction
            with conn.begin():
                order = conn.execute(
                    text("""
                        INSERT INTO orders (user_id, total, status, created_at)
                        VALUES (:user_id, :total, 'pending', NOW())
                        RETURNING id
                    """),
                    {"user_id": user_id, "total": total}
                ).fetchone()

                order_id = order.id

                for item in items:
                    conn.execute(
                        text("""
                            INSERT INTO order_items (order_id, product_id, quantity, price)
                            VALUES (:order_id, :product_id, :quantity, :price)
                        """),
                        {"order_id": order_id, **item}
                    )

            # Clean up
            with conn.begin():
                conn.execute(
                    text("DELETE FROM order_items WHERE order_id = :id"),
                    {"id": order_id}
                )
                conn.execute(
                    text("DELETE FROM orders WHERE id = :id"),
                    {"id": order_id}
                )

        return {
            "order_id":    order_id,
            "total":       total,
            "items_count": len(items),
        }
