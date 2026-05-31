import { getDrizzleClient } from '../../Connection';
import { eq, lt, desc, asc, count, avg, sql } from 'drizzle-orm';
import {
  users, categories, products, tags, productTags,
  orders, orderItems, reviews
} from './schema';

/**
 * Drizzle ORM benchmark scenarios.
 * Query-builder approach with thin abstraction over SQL.
 * Compared against raw SQL baseline in the same TypeScript/Node.js runtime.
 */
export class Scenario {
  private db: ReturnType<typeof getDrizzleClient>;

  constructor() {
    this.db = getDrizzleClient();
  }

  /**
   * A1 — Simple select by primary key.
   */
  async a1(): Promise<object> {
    const id     = Math.floor(Math.random() * 10000) + 1;
    const result = await this.db
      .select()
      .from(users)
      .where(eq(users.id, id))
      .limit(1);

    return result[0] ?? {};
  }

  /**
   * A2 — Filtered list with ORDER BY and LIMIT.
   */
  async a2(): Promise<object[]> {
    const categoryId = Math.floor(Math.random() * 100) + 1;

    return this.db
      .select({
        id:         products.id,
        name:       products.name,
        price:      products.price,
        created_at: products.created_at,
      })
      .from(products)
      .where(eq(products.category_id, categoryId))
      .orderBy(desc(products.created_at))
      .limit(20);
  }

  /**
   * A3 — N+1 diagnostic: load 100 orders then access user for each.
   * Drizzle has no automatic dataloader — each user lookup is a separate query.
   */
  async a3(): Promise<object[]> {
    const orderList = await this.db
      .select()
      .from(orders)
      .orderBy(asc(orders.id))
      .limit(100);

    // Separate query per order — N+1 behaviour
    const results = [];
    for (const order of orderList) {
      const userResult = await this.db
        .select()
        .from(users)
        .where(eq(users.id, order.user_id))
        .limit(1);

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
   * A4 — Eager loading: orders with users via JOIN.
   */
  async a4(): Promise<object[]> {
    return this.db
      .select({
        order_id:   orders.id,
        total:      orders.total,
        status:     orders.status,
        created_at: orders.created_at,
        user_id:    users.id,
        user_name:  users.name,
        user_email: users.email,
      })
      .from(orders)
      .innerJoin(users, eq(orders.user_id, users.id))
      .orderBy(asc(orders.id))
      .limit(100);
  }

  /**
   * B1 — Deep eager loading across 3 levels.
   * Order → OrderItems → Product → Category via JOINs.
   */
  async b1(): Promise<object[]> {
    const orderId = Math.floor(Math.random() * 200000) + 1;

    return this.db
      .select({
        order_id:      orders.id,
        total:         orders.total,
        status:        orders.status,
        item_id:       orderItems.id,
        quantity:      orderItems.quantity,
        item_price:    orderItems.price,
        product_name:  products.name,
        product_price: products.price,
        category_name: categories.name,
      })
      .from(orders)
      .innerJoin(orderItems, eq(orderItems.order_id, orders.id))
      .innerJoin(products,   eq(products.id, orderItems.product_id))
      .innerJoin(categories, eq(categories.id, products.category_id))
      .where(eq(orders.id, orderId));
  }

  /**
   * B2 — Aggregate with GROUP BY.
   */
  async b2(): Promise<object[]> {
    return this.db
      .select({
        id:            categories.id,
        name:          categories.name,
        product_count: count(products.id),
        avg_price:     avg(products.price),
      })
      .from(categories)
      .leftJoin(products, eq(products.category_id, categories.id))
      .groupBy(categories.id, categories.name)
      .orderBy(desc(count(products.id)));
  }

  /**
   * B3 — Many-to-many: products by tag with category.
   */
  async b3(): Promise<object[]> {
    const tagId = Math.floor(Math.random() * 500) + 1;

    return this.db
      .select({
        id:            products.id,
        name:          products.name,
        price:         products.price,
        category_name: categories.name,
      })
      .from(products)
      .innerJoin(productTags, eq(productTags.product_id, products.id))
      .innerJoin(categories,  eq(categories.id, products.category_id))
      .where(eq(productTags.tag_id, tagId))
      .orderBy(asc(products.id))
      .limit(50);
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
      const count = Math.min(chunkSize, total - i);
      const data  = Array.from({ length: count }, (_, j) => ({
        name:        `Bulk Product ${i + j}`,
        price:       String(Math.round(Math.random() * 99800 + 199) / 100),
        description: null,
        category_id: catIds[Math.floor(Math.random() * catIds.length)],
        created_at:  new Date(),
      }));

      await this.db.insert(products).values(data);
      inserted += count;
    }

    // Clean up to restore dataset state
    await this.db
      .delete(products)
      .where(sql`${products.name} LIKE 'Bulk Product%'`);

    return inserted;
  }

  /**
   * C2 — Bulk update: update status of old shipped orders.
   */
  async c2(): Promise<number> {
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);

    const result = await this.db
      .update(orders)
      .set({ status: 'delivered' })
      .where(
        sql`${orders.status} = 'shipped' AND ${orders.created_at} < ${thirtyDaysAgo}`
      );

    // Restore original status
    await this.db
      .update(orders)
      .set({ status: 'shipped' })
      .where(
        sql`${orders.status} = 'delivered' AND ${orders.created_at} < ${thirtyDaysAgo}`
      );

    return (result as any).rowCount ?? 0;
  }

  /**
   * D1 — Unit of Work diagnostic.
   * Drizzle has no built-in Unit of Work — each operation is explicit.
   */
  async d1(): Promise<object> {
    const userId  = Math.floor(Math.random() * 10000) + 1;
    const skip    = Math.floor(Math.random() * 49995);

    const productList = await this.db
      .select()
      .from(products)
      .orderBy(asc(products.id))
      .limit(5)
      .offset(skip);

    let total = 0;
    const items = productList.map((p) => {
      const quantity = Math.floor(Math.random() * 3) + 1;
      const price    = Number(p.price);
      total         += quantity * price;
      return { product_id: p.id, quantity, price: String(price) };
    });

    // Insert order
    const orderResult = await this.db
      .insert(orders)
      .values({
        user_id:    userId,
        total:      String(total),
        status:     'pending',
        created_at: new Date(),
      })
      .returning({ id: orders.id });

    const orderId = orderResult[0].id;

    // Insert items separately — no Unit of Work batching
    await this.db.insert(orderItems).values(
      items.map((item) => ({ order_id: orderId, ...item }))
    );

    // Clean up
    await this.db.delete(orderItems).where(eq(orderItems.order_id, orderId));
    await this.db.delete(orders).where(eq(orders.id, orderId));

    return {
      order_id:    orderId,
      total,
      items_count: items.length,
    };
  }
}
