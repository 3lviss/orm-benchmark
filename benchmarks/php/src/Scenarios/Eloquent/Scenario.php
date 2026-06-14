<?php

namespace Benchmark\Scenarios\Eloquent;

use Benchmark\Scenarios\Eloquent\Models\{
    User, Category, Product, Tag, Order, OrderItem, Review
};
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Eloquent ORM benchmark scenarios.
 * Uses standalone Eloquent without Laravel framework.
 * Compared against raw SQL baseline in the same PHP runtime.
 */
class Scenario
{
    public function __construct()
    {
        Bootstrap::init();
    }

    /**
     * A1 — Simple select by primary key.
     */
    public function a1(): array
    {
        $user = User::find(rand(1, 10000));

        return $user ? $user->toArray() : [];
    }

    /**
     * A2 — Filtered list with ORDER BY and LIMIT.
     */
    public function a2(): array
    {
        return Product::where('category_id', rand(1, 100))
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get(['id', 'name', 'price', 'created_at'])
            ->toArray();
    }

    /**
     * A3 — N+1 diagnostic: load 100 orders then access user for each.
     * Intentionally uses lazy loading (no eager load) to trigger N+1.
     * This matches Eloquent default behaviour.
     */
    public function a3(): array
    {
        $orders = Order::orderBy('id')->limit(100)->get();

        $results = [];
        foreach ($orders as $order) {
            // Accessing ->user triggers a separate query per order (N+1)
            $results[] = [
                'order_id' => $order->id,
                'total'    => $order->total,
                'status'   => $order->status,
                'user'     => [
                    'id'    => $order->user->id,
                    'name'  => $order->user->name,
                    'email' => $order->user->email,
                ],
            ];
        }

        return $results;
    }

    /**
     * A4 — Eager loading: orders with users.
     * Uses Eloquent with() to eliminate N+1.
     */
    public function a4(): array
    {
        return Order::with('user')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->toArray();
    }

    /**
     * B1 — Deep eager loading across 3 levels.
     * Order → OrderItems → Product → Category.
     */
    public function b1(): array
    {
        $order = Order::with([
            'items.product.category',
        ])->find(rand(1, 200000));

        return $order ? $order->toArray() : [];
    }

    /**
     * B2 — Aggregate with GROUP BY.
     * Product count and average price per category.
     */
    public function b2(): array
    {
        return Category::leftJoin('products', 'products.category_id', '=', 'categories.id')
            ->selectRaw('categories.id, categories.name, COUNT(products.id) as product_count, ROUND(AVG(products.price)::numeric, 2) as avg_price')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('product_count')
            ->get()
            ->toArray();
    }

    /**
     * B3 — Many-to-many: products by tag with category.
     */
    public function b3(): array
    {
        $tag = Tag::find(rand(1, 500));

        if (!$tag) {
            return [];
        }

        return $tag->products()
            ->with('category')
            ->orderBy('id')
            ->limit(50)
            ->get(['products.id', 'products.name', 'products.price', 'products.category_id'])
            ->toArray();
    }

    /**
     * C1 — Bulk insert: 10,000 products.
     * Uses Eloquent insert() for chunked bulk insert.
     */
    public function c1(): int
    {
        $chunkSize  = 500;
        $totalRows  = 10000;
        $catIds     = range(1, 100);
        $inserted   = 0;

        for ($i = 0; $i < $totalRows; $i += $chunkSize) {
            $chunk = [];
            $count = min($chunkSize, $totalRows - $i);

            for ($j = 0; $j < $count; $j++) {
                $chunk[] = [
                    'name'        => 'Bulk Product ' . ($i + $j),
                    'price'       => round(rand(199, 99999) / 100, 2),
                    'description' => null,
                    'category_id' => $catIds[array_rand($catIds)],
                    'created_at'  => new \DateTime(),
                ];
            }

            // Eloquent insert() bypasses model events for performance
            Product::insert($chunk);
            $inserted += $count;
        }

        // Clean up to restore dataset state
        Product::where('name', 'like', 'Bulk Product %')->delete();

        return $inserted;
    }

    /**
     * C2 — Bulk update: update status of old shipped orders.
     * Uses Eloquent query builder UPDATE without loading models.
     */
    public function c2(): int
    {
        // Eloquent does not support UPDATE FROM — use PDO directly via Capsule connection
        $pdo = \Illuminate\Database\Capsule\Manager::connection()->getPdo();

        $stmt = $pdo->prepare(
            "UPDATE orders SET status = 'delivered'
             FROM (
                 SELECT id FROM orders
                 WHERE status = 'shipped'
                   AND created_at < NOW() - INTERVAL '30 days'
                 ORDER BY id LIMIT 1000
             ) AS batch
             WHERE orders.id = batch.id"
        );
        $stmt->execute();
        $affected = $stmt->rowCount();

        // Restore original status
        $pdo->exec(
            "UPDATE orders SET status = 'shipped'
             FROM (
                 SELECT id FROM orders
                 WHERE status = 'delivered'
                   AND created_at < NOW() - INTERVAL '30 days'
                 ORDER BY id LIMIT 1000
             ) AS batch
             WHERE orders.id = batch.id"
        );

        return $affected;
    }


    /**
     * D1 — Unit of Work diagnostic.
     * Create one order with 5 items using Eloquent model methods.
     * Counts queries to assess change tracking behaviour.
     */
    public function d1(): array
    {
        $userId   = rand(1, 10000);
        $products = Product::inRandomOrder()->limit(5)->get();

        $order = Order::create([
            'user_id'    => $userId,
            'total'      => 0,
            'status'     => 'pending',
            'created_at' => new \DateTime(),
        ]);

        $total = 0;
        foreach ($products as $product) {
            $quantity = rand(1, 3);
            $price    = $product->price;
            $total   += $quantity * $price;

            OrderItem::create([
                'order_id'   => $order->id,
                'product_id' => $product->id,
                'quantity'   => $quantity,
                'price'      => $price,
            ]);
        }

        $order->update(['total' => $total]);

        // Clean up
        $order->items()->delete();
        $order->delete();

        return [
            'order_id'    => $order->id,
            'total'       => $total,
            'items_count' => $products->count(),
        ];
    }
}
