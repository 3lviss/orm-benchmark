import { PrismaClient } from '@prisma/client';
import { getPrismaClient } from '../../Connection';

/**
 * Prisma ORM benchmark scenarios.
 * Schema-first approach with built-in dataloader for N+1 prevention.
 * Compared against raw SQL baseline in the same TypeScript/Node.js runtime.
 */
export class Scenario {
  private client: PrismaClient;

  constructor() {
    this.client = getPrismaClient();
  }

  /**
   * A1 — Simple select by primary key.
   */
  async a1(): Promise<object> {
    const user = await this.client.user.findUnique({
      where: { id: Math.floor(Math.random() * 10000) + 1 },
    });
    return user ?? {};
  }

  /**
   * A2 — Filtered list with ORDER BY and LIMIT.
   */
  async a2(): Promise<object[]> {
    return this.client.product.findMany({
      where: { category_id: Math.floor(Math.random() * 100) + 1 },
      orderBy: { created_at: 'desc' },
      take: 20,
      select: { id: true, name: true, price: true, created_at: true },
    });
  }

  /**
   * A3 — N+1 diagnostic: load 100 orders then access user for each.
   * Prisma uses dataloader by default — batches user queries automatically.
   * This scenario tests whether N+1 is exhibited in default configuration.
   */
  async a3(): Promise<object[]> {
    const orders = await this.client.order.findMany({
      orderBy: { id: 'asc' },
      take: 100,
    });

    // Accessing user for each order — Prisma dataloader batches these
    const results = await Promise.all(
      orders.map(async (order) => {
        const user = await this.client.user.findUnique({
          where: { id: order.user_id },
        });
        return {
          order_id: order.id,
          total:    order.total,
          status:   order.status,
          user:     user,
        };
      })
    );

    return results;
  }

  /**
   * A4 — Eager loading: orders with users.
   * Uses Prisma include to eliminate N+1.
   */
  async a4(): Promise<object[]> {
    return this.client.order.findMany({
      orderBy: { id: 'asc' },
      take: 100,
      include: { user: true },
    });
  }

  /**
   * B1 — Deep eager loading across 3 levels.
   * Order → OrderItems → Product → Category.
   */
  async b1(): Promise<object> {
    const order = await this.client.order.findUnique({
      where: { id: Math.floor(Math.random() * 200000) + 1 },
      include: {
        items: {
          include: {
            product: {
              include: { category: true },
            },
          },
        },
      },
    });
    return order ?? {};
  }

  /**
   * B2 — Aggregate with GROUP BY.
   * Product count and average price per category.
   */
  async b2(): Promise<object[]> {
    return this.client.category.findMany({
      include: {
        _count: { select: { products: true } },
      },
      orderBy: {
        products: { _count: 'desc' },
      },
    });
  }

  /**
   * B3 — Many-to-many: products by tag with category.
   */
  async b3(): Promise<object[]> {
    return this.client.product.findMany({
      where: {
        tags: {
          some: { id: Math.floor(Math.random() * 500) + 1 },
        },
      },
      include: { category: true },
      orderBy: { id: 'asc' },
      take: 50,
    });
  }

  /**
   * C1 — Bulk insert: 10,000 products.
   * Uses createMany for efficient batch insertion.
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
        price:       Math.round(Math.random() * 99800 + 199) / 100,
        description: null,
        category_id: catIds[Math.floor(Math.random() * catIds.length)],
        created_at:  new Date(),
      }));

      await this.client.product.createMany({ data });
      inserted += count;
    }

    // Clean up to restore dataset state
    await this.client.product.deleteMany({
      where: { name: { startsWith: 'Bulk Product' } },
    });

    return inserted;
  }

  /**
   * C2 — Bulk update: update status of old shipped orders.
   * Uses updateMany without loading entities first.
   */
  async c2(): Promise<number> {
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);

    const result = await this.client.order.updateMany({
      where: {
        status:     'shipped',
        created_at: { lt: thirtyDaysAgo },
      },
      data: { status: 'delivered' },
    });

    // Restore original status to keep dataset consistent
    await this.client.order.updateMany({
      where: {
        status:     'delivered',
        created_at: { lt: thirtyDaysAgo },
      },
      data: { status: 'shipped' },
    });

    return result.count;
  }

  /**
   * D1 — Unit of Work diagnostic.
   * Create one order with 5 items using Prisma nested writes.
   * Prisma executes this as a transaction automatically.
   */
  async d1(): Promise<object> {
    const userId   = Math.floor(Math.random() * 10000) + 1;
    const products = await this.client.product.findMany({
      take:    5,
      orderBy: { id: 'asc' },
      skip:    Math.floor(Math.random() * 49995),
    });

    let total = 0;
    const items = products.map((p) => {
      const quantity = Math.floor(Math.random() * 3) + 1;
      const price    = Number(p.price);
      total         += quantity * price;
      return {
        product_id: p.id,
        quantity,
        price,
      };
    });

    // Nested write — Prisma wraps in a single transaction
    const order = await this.client.order.create({
      data: {
        user_id:    userId,
        total,
        status:     'pending',
        created_at: new Date(),
        items: {
          create: items,
        },
      },
      include: { items: true },
    });

    // Clean up
    await this.client.orderItem.deleteMany({
      where: { order_id: order.id },
    });
    await this.client.order.delete({
      where: { id: order.id },
    });

    return {
      order_id:    order.id,
      total,
      items_count: items.length,
    };
  }
}
