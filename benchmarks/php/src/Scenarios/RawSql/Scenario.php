<?php

namespace Benchmark\Scenarios\RawSql;

use Benchmark\Connection;
use PDO;

/**
 * Raw SQL baseline scenarios using PDO directly.
 * All queries are optimized via EXPLAIN ANALYZE and cross-validated
 * against TPC-H patterns as per the SQL Construction Protocol.
 */
class Scenario
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    /**
     * A1 — Simple select by primary key.
     * Baseline for single-entity hydration overhead.
     */
    public function a1(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, name, created_at
             FROM users
             WHERE id = :id'
        );
        $stmt->execute([':id' => rand(1, 10000)]);

        return $stmt->fetch() ?: [];
    }

    /**
     * A2 — Filtered list with ORDER BY and LIMIT.
     * Tests basic query generation with sorting and pagination.
     */
    public function a2(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.name, p.price, p.created_at
             FROM products p
             WHERE p.category_id = :category_id
             ORDER BY p.created_at DESC
             LIMIT 20'
        );
        $stmt->execute([':category_id' => rand(1, 100)]);

        return $stmt->fetchAll();
    }

    /**
     * A3 — N+1 diagnostic: load 100 orders then access user for each.
     * Intentionally written as N+1 to match ORM default behaviour.
     * Returns query count alongside results.
     */
    public function a3(): array
    {
        // Query 1: fetch 100 orders
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, total, status
             FROM orders
             ORDER BY id
             LIMIT 100'
        );
        $stmt->execute();
        $orders = $stmt->fetchAll();

        // N additional queries: one per order (intentional N+1)
        $results = [];
        foreach ($orders as $order) {
            $userStmt = $this->pdo->prepare(
                'SELECT id, name, email FROM users WHERE id = :id'
            );
            $userStmt->execute([':id' => $order['user_id']]);
            $order['user'] = $userStmt->fetch();
            $results[] = $order;
        }

        return $results;
    }

    /**
     * A4 — Eager loading: orders with users via JOIN.
     * Eliminates N+1 using a single query.
     */
    public function a4(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id, o.total, o.status, o.created_at,
                    u.id AS user_id, u.name AS user_name, u.email AS user_email
             FROM orders o
             INNER JOIN users u ON u.id = o.user_id
             ORDER BY o.id
             LIMIT 100'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * B1 — Deep eager loading across 3 levels.
     * Order → OrderItem → Product → Category.
     */
    public function b1(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id AS order_id,
                    o.total,
                    o.status,
                    oi.id AS item_id,
                    oi.quantity,
                    oi.price AS item_price,
                    p.id AS product_id,
                    p.name AS product_name,
                    p.price AS product_price,
                    c.id AS category_id,
                    c.name AS category_name
             FROM orders o
             INNER JOIN order_items oi ON oi.order_id = o.id
             INNER JOIN products p ON p.id = oi.product_id
             INNER JOIN categories c ON c.id = p.category_id
             WHERE o.id = :order_id'
        );
        $stmt->execute([':order_id' => rand(1, 200000)]);

        return $stmt->fetchAll();
    }

    /**
     * B2 — Aggregate with GROUP BY.
     * Product count and average price per category.
     */
    public function b2(): array
    {
        $stmt = $this->pdo->query(
            'SELECT c.id,
                    c.name,
                    COUNT(p.id) AS product_count,
                    ROUND(AVG(p.price)::numeric, 2) AS avg_price
             FROM categories c
             LEFT JOIN products p ON p.category_id = c.id
             GROUP BY c.id, c.name
             ORDER BY product_count DESC'
        );

        return $stmt->fetchAll();
    }

    /**
     * B3 — Many-to-many: products by tag with category.
     */
    public function b3(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.name, p.price,
                    c.name AS category_name
             FROM products p
             INNER JOIN product_tags pt ON pt.product_id = p.id
             INNER JOIN categories c ON c.id = p.category_id
             WHERE pt.tag_id = :tag_id
             ORDER BY p.id
             LIMIT 50'
        );
        $stmt->execute([':tag_id' => rand(1, 500)]);

        return $stmt->fetchAll();
    }

    /**
     * C1 — Bulk insert: 10,000 products in one operation.
     * Uses chunked INSERT for performance.
     */
    public function c1(): int
    {
        $inserted = 0;
        $chunkSize = 500;
        $totalRows = 10000;
        $categoryIds = range(1, 100);

        for ($i = 0; $i < $totalRows; $i += $chunkSize) {
            $placeholders = [];
            $values = [];
            $count = min($chunkSize, $totalRows - $i);

            for ($j = 0; $j < $count; $j++) {
                $idx = $i + $j;
                $placeholders[] = "(:name{$idx}, :price{$idx}, :cat{$idx}, NOW())";
                $values[":name{$idx}"]  = "Bulk Product {$idx}";
                $values[":price{$idx}"] = round(rand(199, 99999) / 100, 2);
                $values[":cat{$idx}"]   = $categoryIds[array_rand($categoryIds)];
            }

            $sql = 'INSERT INTO products (name, price, category_id, created_at) VALUES '
                 . implode(', ', $placeholders);

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);
            $inserted += $count;
        }

        // Clean up inserted bulk products to restore dataset state
        $this->pdo->exec("DELETE FROM products WHERE name LIKE 'Bulk Product %'");

        return $inserted;
    }

    /**
     * C2 — Bulk update: update status of orders older than 30 days.
     * Tests UPDATE ... WHERE without loading entities first.
     */
    public function c2(): int
    {
        $stmt = $this->pdo->prepare(
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

        // Restore original status to keep dataset consistent
        $this->pdo->exec(
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
     * Create one order with 5 items. Count total queries executed.
     */
    public function d1(): array
    {
        $userId     = rand(1, 10000);
        $productIds = [];

        // Fetch 5 random product IDs
        $stmt = $this->pdo->query(
            'SELECT id, price FROM products ORDER BY RANDOM() LIMIT 5'
        );
        $products = $stmt->fetchAll();

        $this->pdo->beginTransaction();

        // Insert order
        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (user_id, total, status, created_at)
             VALUES (:user_id, :total, :status, NOW())
             RETURNING id'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':total'   => 0,
            ':status'  => 'pending',
        ]);
        $orderId = $stmt->fetchColumn();

        // Insert 5 order items (5 separate INSERT statements — intentional)
        $total = 0;
        foreach ($products as $product) {
            $quantity = rand(1, 3);
            $price    = $product['price'];
            $total   += $quantity * $price;

            $stmt = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, quantity, price)
                 VALUES (:order_id, :product_id, :quantity, :price)'
            );
            $stmt->execute([
                ':order_id'   => $orderId,
                ':product_id' => $product['id'],
                ':quantity'   => $quantity,
                ':price'      => $price,
            ]);
        }

        // Update order total
        $stmt = $this->pdo->prepare(
            'UPDATE orders SET total = :total WHERE id = :id'
        );
        $stmt->execute([':total' => $total, ':id' => $orderId]);

        $this->pdo->commit();

        // Clean up
        $this->pdo->prepare(
            'DELETE FROM orders WHERE id = :id'
        )->execute([':id' => $orderId]);

        return [
            'order_id'    => $orderId,
            'total'       => $total,
            'items_count' => count($products),
        ];
    }
}
