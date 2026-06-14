import random
from datetime import datetime, timedelta
from sqlalchemy import func, select, update, delete, insert
from sqlalchemy.orm import Session, joinedload
from Connection import get_engine
from scenarios.sqlalchemy.Models import (
    User, Category, Product, Tag, Order, OrderItem, Review
)

"""
SQLAlchemy ORM benchmark scenarios.
Uses Data Mapper pattern via Session.
Models are defined in Models.py to avoid circular imports.
Compared against raw SQL baseline in the same Python runtime.
"""

class Scenario:
    def __init__(self):
        self.engine = get_engine()

    def _session(self) -> Session:
        """Returns a fresh session for each scenario call."""
        return Session(self.engine)

    def a1(self):
        """A1 — Simple select by primary key."""
        with self._session() as session:
            user = session.get(User, random.randint(1, 10000))
            return {
                "id":         user.id,
                "email":      user.email,
                "name":       user.name,
                "created_at": str(user.created_at),
            } if user else {}

    def a2(self):
        """A2 — Filtered list with ORDER BY and LIMIT."""
        with self._session() as session:
            products = session.execute(
                select(Product)
                .where(Product.category_id == random.randint(1, 100))
                .order_by(Product.created_at.desc())
                .limit(20)
            ).scalars().all()

            return [
                {"id": p.id, "name": p.name,
                 "price": str(p.price), "created_at": str(p.created_at)}
                for p in products
            ]

    def a3(self):
        """
        A3 — N+1 diagnostic: load 100 orders then access user for each.
        Intentionally uses lazy loading to trigger N+1 behaviour.
        """
        with self._session() as session:
            orders = session.execute(
                select(Order).order_by(Order.id).limit(100)
            ).scalars().all()

            results = []
            for order in orders:
                # Accessing order.user triggers a separate query (N+1)
                results.append({
                    "order_id": order.id,
                    "total":    str(order.total),
                    "status":   order.status,
                    "user": {
                        "id":    order.user.id,
                        "name":  order.user.name,
                        "email": order.user.email,
                    },
                })
            return results

    def a4(self):
        """A4 — Eager loading: orders with users via joinedload."""
        with self._session() as session:
            orders = session.execute(
                select(Order)
                .options(joinedload(Order.user))
                .order_by(Order.id)
                .limit(100)
            ).scalars().all()

            return [
                {
                    "id":     o.id,
                    "total":  str(o.total),
                    "status": o.status,
                    "user":   {"id": o.user.id, "name": o.user.name},
                }
                for o in orders
            ]

    def b1(self):
        """B1 — Deep eager loading across 3 levels."""
        with self._session() as session:
            order = session.execute(
                select(Order)
                .options(
                    joinedload(Order.items)
                    .joinedload(OrderItem.product)
                    .joinedload(Product.category)
                )
                .where(Order.id == random.randint(1, 200000))
            ).unique().scalar_one_or_none()

            if not order:
                return []

            return [
                {
                    "order_id":     order.id,
                    "total":        str(order.total),
                    "product_name": item.product.name,
                    "category":     item.product.category.name,
                    "quantity":     item.quantity,
                    "price":        str(item.price),
                }
                for item in order.items
            ]

    def b2(self):
        """B2 — Aggregate with GROUP BY."""
        with self._session() as session:
            rows = session.execute(
                select(
                    Category.id,
                    Category.name,
                    func.count(Product.id).label("product_count"),
                    func.round(func.avg(Product.price), 2).label("avg_price"),
                )
                .outerjoin(Product, Product.category_id == Category.id)
                .group_by(Category.id, Category.name)
                .order_by(func.count(Product.id).desc())
            ).all()

            return [
                {"id": r.id, "name": r.name,
                 "product_count": r.product_count,
                 "avg_price": str(r.avg_price)}
                for r in rows
            ]

    def b3(self):
        """B3 — Many-to-many: products by tag with category."""
        with self._session() as session:
            tag = session.get(Tag, random.randint(1, 500))
            if not tag:
                return []

            products = session.execute(
                select(Product)
                .options(joinedload(Product.category))
                .join(Product.tags)
                .where(Tag.id == tag.id)
                .order_by(Product.id)
                .limit(50)
            ).scalars().all()

            return [
                {
                    "id":       p.id,
                    "name":     p.name,
                    "price":    str(p.price),
                    "category": p.category.name,
                }
                for p in products
            ]

    def c1(self):
        """C1 — Bulk insert: 10,000 products in chunks."""
        chunk_size = 500
        total      = 10000
        cat_ids    = list(range(1, 101))
        inserted   = 0

        with self._session() as session:
            for i in range(0, total, chunk_size):
                count = min(chunk_size, total - i)
                rows  = [
                    {
                        "name":        f"Bulk Product {i + j}",
                        "price":       round(random.randint(199, 99999) / 100, 2),
                        "description": None,
                        "category_id": random.choice(cat_ids),
                        "created_at":  datetime.now(),
                    }
                    for j in range(count)
                ]
                session.execute(insert(Product), rows)
                session.commit()
                inserted += count

            # Clean up to restore dataset state
            session.execute(
                delete(Product).where(Product.name.like("Bulk Product%"))
            )
            session.commit()

        return inserted

    def c2(self):
        """C2 — Bulk update: UPDATE FROM subquery, LIMIT 1000 rows.
        SQLAlchemy ORM update does not support LIMIT — use text() with native SQL.
        See methodology/c2_limit_analysis.json for batch size justification.
        """
        from sqlalchemy import text
        thirty_days_ago = datetime.now() - timedelta(days=30)

        with self._session() as session:
            result = session.execute(text("""
                UPDATE orders SET status = 'delivered'
                FROM (
                    SELECT id FROM orders
                    WHERE status = 'shipped'
                      AND created_at < :cutoff
                    ORDER BY id LIMIT 1000
                ) AS batch
                WHERE orders.id = batch.id
            """), {"cutoff": thirty_days_ago})
            session.commit()
            affected = result.rowcount

            # Restore original status
            session.execute(text("""
                UPDATE orders SET status = 'shipped'
                FROM (
                    SELECT id FROM orders
                    WHERE status = 'delivered'
                      AND created_at < :cutoff
                    ORDER BY id LIMIT 1000
                ) AS batch
                WHERE orders.id = batch.id
            """), {"cutoff": thirty_days_ago})
            session.commit()

        return affected

    def d1(self):
        """
        D1 — Unit of Work diagnostic.
        Uses Session flush to demonstrate SQLAlchemy change tracking.
        """
        user_id = random.randint(1, 10000)
        skip    = random.randint(0, 49995)

        with self._session() as session:
            products = session.execute(
                select(Product).order_by(Product.id).limit(5).offset(skip)
            ).scalars().all()

            order = Order(
                user_id=user_id,
                total=0,
                status="pending",
                created_at=datetime.now(),
            )
            session.add(order)
            session.flush()  # Get order.id without committing

            total = 0
            for product in products:
                quantity = random.randint(1, 3)
                price    = float(product.price)
                total   += quantity * price

                item = OrderItem(
                    order_id=order.id,
                    product_id=product.id,
                    quantity=quantity,
                    price=price,
                )
                session.add(item)

            order.total = total
            session.commit()
            order_id = order.id

            # Clean up
            session.execute(
                delete(OrderItem).where(OrderItem.order_id == order_id)
            )
            session.execute(
                delete(Order).where(Order.id == order_id)
            )
            session.commit()

        return {
            "order_id":    order_id,
            "total":       total,
            "items_count": len(products),
        }
    