import { getRawSqlClient } from '../../Connection';

/**
 * Raw SQL baseline scenarios using postgres.js directly.
 * No ORM abstraction — direct SQL queries via tagged template literals.
 * Baseline for Prisma and Drizzle overhead measurement.
 */
export class Scenario {
  private sql: ReturnType<typeof getRawSqlClient>;

  constructor() {
    this.sql = getRawSqlClient();
  }

  /**
   * A1 — Simple select by primary key.
   */
  async a1(): Promise<object> {
    const id     = Math.floor(Math.random() * 10000) + 1;
    const result = await this.sql`
      SELECT id, email, name, created_at
      FROM users
      WHERE id = ${id}
    `;
    return result[0] ?? {};
  }

  /**
   * A2 — Filtered list with ORDER BY and LIMIT.
   */
  async a2(): Promise<object[]> {
    const categoryId = Math.floor(Math.random() * 100) + 1;
    return this.sql`
      SELECT id, name, price, created_at
      FROM products
      WHERE category_id = ${categoryId}
      ORDER BY created_at DESC
      LIMIT 20
    `;
  }

  /**
   * A3 — N+1 diagnostic: load 100 orders then access user for each.
   * Intentionally written as N+1 to match ORM default behaviour.
   */
  async a3(): Promise<object[]> {
    const orderList = await this.sql`
      SELECT id, user_id, total, status
      FROM orders
      ORDER BY id ASC
      LIMIT 100
    `;

    // Separate query per order — intentional N+1
    const results = [];
    for (const order of orderList) {
      const userResult = await this.sql`
        SELECT id, name, email
        FROM users
        WHERE id = ${order.user_id}
      `;
      results.push({
        order_id: order.id,
        total:    order.total,
        status:   order.status,
        user:     userResult[0] ?? null,
      });
    }

    return results;
  }

  /**
   * A4 — Eager loading via JOIN.
   */
  async a4(): Promise<object[]> {
    return this.sql`
      SELECT o.id, o.total, o.status, o.created_at,
             u.id   AS user_id,
             u.name AS user_name,
             u.email AS user_email
      FROM orders o
      INNER JOIN users u ON u.id = o.user_id
      ORDER BY o.id ASC
      LIMIT 100
    `;
  }

  /**
   * B1 — Deep eager loading across 3 levels.
   * Order → OrderItems → Product → Category.
   */
  async b1(): Promise<object[]> {
    const orderId = Math.floor(Math.random() * 200000) + 1;
    return this.sql`
      SELECT o.id  AS order_id,
             o.total,
             o.status,
             oi.id AS item_id,
             oi.quantity,
             oi.price AS item_price,
             p.name  AS product_name,
             p.price AS product_price,
             c.name  AS category_name
      FROM orders o
      INNER JOIN order_items oi ON oi.order_id = o.id
      INNER JOIN products p    ON p.id = oi.product_id
      INNER JOIN categories c  ON c.id = p.category_id
      WHERE o.id = ${orderId}
    `;
  }

  /**
   * B2 — Aggregate with GROUP BY.
   */
  async b2(): Promise<object[]> {
    return this.sql`
      SELECT c.id,
             c.name,
             COUNT(p.id)                    AS product_count,
             ROUND(AVG(p.price)::numeric, 2) AS avg_price
      FROM categories c
      LEFT JOIN products p ON p.category_id = c.id
      GROUP BY c.id, c.name
      ORDER BY product_count DESC
    `;
  }

  /**
   * B3 — Many-to-many: products by tag with category.
   */
  async b3(): Promise<object[]> {
    const tagId = Math.floor(Math.random() * 500) + 1;
    return this.sql`
      SELECT p.id,
             p.name,
             p.price,
             c.name AS category_name
      FROM products p
      INNER JOIN product_tags pt ON pt.product_id = p.id
      INNER JOIN categories c   ON c.id = p.category_id
      WHERE pt.tag_id = ${tagId}
      ORDER BY p.id ASC
      LIMIT 50
    `;
  }

  /**
   * C1 — Bulk insert: 10,000 products in chunks.
   */
  async c1(): Promise<number> {
    const chunkSize = 500;
    const total     = 10000;
    const catIds    = Array.from({ length: 100 }, (_, i) => i + 1);
    let inserted    = 0;

    for (let i = 0; i < total; i += chunkSize) {
      const chunkCount = Math.min(chunkSize, total - i);
      const rows = Array.from({ length: chunkCount }, (_, j) => ({
        name:        `Bulk Product ${i + j}`,
        price:       (Math.round(Math.random() * 99800 + 199) / 100).toFixed(2),
        description: null as string | null,
        category_id: catIds[Math.floor(Math.random() * catIds.length)],
        created_at:  new Date(),
      }));

      // Build parameterized VALUES for bulk insert
      const values: (string | number | null)[] = [];
      const placeholders = rows.map((r, i) => {
        const base = i * 4;
        values.push(r.name, r.price, r.category_id, r.created_at.toISOString());
        return `($${base+1}, $${base+2}, NULL, $${base+3}, $${base+4})`;
      }).join(', ');

      await this.sql.unsafe(
        `INSERT INTO products (name, price, description, category_id, created_at) VALUES ${placeholders}`,
        values
      );
      inserted += chunkCount;
    }

    // Clean up to restore dataset state
    await this.sql`DELETE FROM products WHERE name LIKE 'Bulk Product%'`;

    return inserted;
  }

  /**
   * C2 — Bulk update: update status of old shipped orders.
   */
  async c2(): Promise<number> {
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);

    // PostgreSQL does not support LIMIT in UPDATE directly — use UPDATE FROM subquery
    const result = await this.sql`
      UPDATE orders SET status = 'delivered'
      FROM (
        SELECT id FROM orders
        WHERE status = 'shipped'
          AND created_at < ${thirtyDaysAgo}
        ORDER BY id LIMIT 1000
      ) AS batch
      WHERE orders.id = batch.id
    `;

    // Restore original status
    await this.sql`
      UPDATE orders SET status = 'shipped'
      FROM (
        SELECT id FROM orders
        WHERE status = 'delivered'
          AND created_at < ${thirtyDaysAgo}
        ORDER BY id LIMIT 1000
      ) AS batch
      WHERE orders.id = batch.id
    `;

    return result.count;
  }

  /**
   * D1 — Unit of Work diagnostic.
   * Raw SQL uses explicit transactions — no Unit of Work pattern.
   */
  async d1(): Promise<object> {
    const userId = Math.floor(Math.random() * 10000) + 1;
    const skip   = Math.floor(Math.random() * 49995);

    const productList = await this.sql`
      SELECT id, price FROM products
      ORDER BY id ASC
      LIMIT 5 OFFSET ${skip}
    `;

    let total = 0;
    const items = productList.map((p) => {
      const quantity = Math.floor(Math.random() * 3) + 1;
      const price    = Number(p.price);
      total         += quantity * price;
      return { product_id: p.id, quantity, price };
    });

    // Explicit transaction
    const result = await this.sql.begin(async (sql) => {
      const [order] = await sql`
        INSERT INTO orders (user_id, total, status, created_at)
        VALUES (${userId}, ${total}, 'pending', NOW())
        RETURNING id
      `;

      for (const item of items) {
        await sql`
          INSERT INTO order_items (order_id, product_id, quantity, price)
          VALUES (${order.id}, ${item.product_id}, ${item.quantity}, ${item.price})
        `;
      }

      return order;
    });

    // Clean up
    await this.sql`DELETE FROM order_items WHERE order_id = ${result.id}`;
    await this.sql`DELETE FROM orders WHERE id = ${result.id}`;

    return {
      order_id:    result.id,
      total,
      items_count: items.length,
    };
  }
}
