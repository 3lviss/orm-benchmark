<?php

namespace Benchmark\Scenarios\Doctrine;

use Benchmark\Scenarios\Doctrine\Entities\{
    User, Category, Product, Tag, Order, OrderItem, Review
};
use Doctrine\ORM\EntityManager;

/**
 * Doctrine ORM benchmark scenarios.
 * Uses Data Mapper pattern via EntityManager without Symfony framework.
 * Compared against raw SQL baseline in the same PHP runtime.
 */
class Scenario
{
    private EntityManager $em;

    public function __construct()
    {
        $this->em = Bootstrap::entityManager();
    }

    /**
     * Clears identity map before each scenario call to prevent
     * first-level cache from skewing latency measurements.
     */
    private function reset(): void
    {
        $this->em->clear();
    }

    /**
     * A1 — Simple select by primary key.
     */
    public function a1(): array
    {
        $this->reset();
        $user = $this->em->find(User::class, rand(1, 10000));

        return $user ? [
            'id'         => $user->getId(),
            'name'       => $user->getName(),
            'email'      => $user->getEmail(),
            'created_at' => $user->getCreatedAt()->format('Y-m-d H:i:s'),
        ] : [];
    }

    /**
     * A2 — Filtered list with ORDER BY and LIMIT.
     */
    public function a2(): array
    {
        $this->reset();
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p
             WHERE p.category = :catId
             ORDER BY p.createdAt DESC'
        )
        ->setParameter('catId', rand(1, 100))
        ->setMaxResults(20)
        ->getResult();

        return array_map(fn($p) => [
            'id'         => $p->getId(),
            'name'       => $p->getName(),
            'price'      => $p->getPrice(),
            'created_at' => $p->getCreatedAt()->format('Y-m-d H:i:s'),
        ], $products);
    }

    /**
     * A3 — N+1 diagnostic: load 100 orders then access user for each.
     * Intentionally uses lazy loading to trigger N+1 behaviour.
     */
    public function a3(): array
    {
        $this->reset();
        $orders = $this->em->createQuery(
            'SELECT o FROM ' . Order::class . ' o ORDER BY o.id ASC'
        )
        ->setMaxResults(100)
        ->getResult();

        $results = [];
        foreach ($orders as $order) {
            // Accessing getUser() triggers a separate query per order (N+1)
            $results[] = [
                'order_id' => $order->getId(),
                'total'    => $order->getTotal(),
                'status'   => $order->getStatus(),
                'user'     => [
                    'id'    => $order->getUser()->getId(),
                    'name'  => $order->getUser()->getName(),
                    'email' => $order->getUser()->getEmail(),
                ],
            ];
        }

        return $results;
    }

    /**
     * A4 — Eager loading: orders with users via JOIN FETCH.
     */
    public function a4(): array
    {
        $this->reset();
        $orders = $this->em->createQuery(
            'SELECT o, u FROM ' . Order::class . ' o
             JOIN FETCH o.user u
             ORDER BY o.id ASC'
        )
        ->setMaxResults(100)
        ->getResult();

        return array_map(fn($o) => [
            'id'     => $o->getId(),
            'total'  => $o->getTotal(),
            'status' => $o->getStatus(),
            'user'   => [
                'id'    => $o->getUser()->getId(),
                'name'  => $o->getUser()->getName(),
                'email' => $o->getUser()->getEmail(),
            ],
        ], $orders);
    }

    /**
     * B1 — Deep eager loading across 3 levels.
     * Order → OrderItems → Product → Category.
     */
    public function b1(): array
    {
        $this->reset();
        $order = $this->em->createQuery(
            'SELECT o, i, p, c FROM ' . Order::class . ' o
             JOIN FETCH o.items i
             JOIN FETCH i.product p
             JOIN FETCH p.category c
             WHERE o.id = :id'
        )
        ->setParameter('id', rand(1, 200000))
        ->getOneOrNullResult();

        if (!$order) {
            return [];
        }

        return [
            'order_id' => $order->getId(),
            'total'    => $order->getTotal(),
            'items'    => array_map(fn($item) => [
                'product'  => $item->getProduct()->getName(),
                'category' => $item->getProduct()->getCategory()->getName(),
                'quantity' => $item->getQuantity(),
                'price'    => $item->getPrice(),
            ], $order->getItems()->toArray()),
        ];
    }

    /**
     * B2 — Aggregate with GROUP BY using DQL.
     */
    public function b2(): array
    {
        $this->reset();
        return $this->em->createQuery(
            'SELECT c.id, c.name,
                    COUNT(p.id) AS product_count,
                    AVG(p.price) AS avg_price
             FROM ' . Category::class . ' c
             LEFT JOIN c.products p
             GROUP BY c.id, c.name
             ORDER BY product_count DESC'
        )
        ->getResult();
    }

    /**
     * B3 — Many-to-many: products by tag with category.
     */
    public function b3(): array
    {
        $this->reset();
        $products = $this->em->createQuery(
            'SELECT p, c FROM ' . Product::class . ' p
             JOIN FETCH p.category c
             JOIN p.tags t
             WHERE t.id = :tagId
             ORDER BY p.id ASC'
        )
        ->setParameter('tagId', rand(1, 500))
        ->setMaxResults(50)
        ->getResult();

        return array_map(fn($p) => [
            'id'           => $p->getId(),
            'name'         => $p->getName(),
            'price'        => $p->getPrice(),
            'category'     => $p->getCategory()->getName(),
        ], $products);
    }

    /**
     * C1 — Bulk insert: 10,000 products.
     * Uses raw SQL via DBAL for performance — Doctrine flush per entity
     * would be prohibitively slow for bulk operations.
     */
    public function c1(): int
    {
        $this->reset();
        $conn      = $this->em->getConnection();
        $chunkSize = 500;
        $total     = 10000;
        $catIds    = range(1, 100);
        $inserted  = 0;

        for ($i = 0; $i < $total; $i += $chunkSize) {
            $rows  = [];
            $count = min($chunkSize, $total - $i);

            for ($j = 0; $j < $count; $j++) {
                $rows[] = sprintf(
                    "('Bulk Product %d', %s, %d, NOW())",
                    $i + $j,
                    number_format(rand(199, 99999) / 100, 2, '.', ''),
                    $catIds[array_rand($catIds)]
                );
            }

            $conn->executeStatement(
                'INSERT INTO products (name, price, category_id, created_at) VALUES '
                . implode(', ', $rows)
            );
            $inserted += $count;
        }

        // Clean up to restore dataset state
        $conn->executeStatement("DELETE FROM products WHERE name LIKE 'Bulk Product %'");

        return $inserted;
    }

    /**
     * C2 — Bulk update using DBAL for performance.
     * Doctrine Unit of Work is not suited for mass updates without loading entities.
     */
    public function c2(): int
    {
        $this->reset();
        $conn = $this->em->getConnection();

        $affected = $conn->executeStatement(
            "UPDATE orders SET status = 'delivered'
             WHERE status = 'shipped'
             AND created_at < NOW() - INTERVAL '30 days'"
        );

        // Restore original status to keep dataset consistent
        $conn->executeStatement(
            "UPDATE orders SET status = 'shipped'
             WHERE status = 'delivered'
             AND created_at < NOW() - INTERVAL '30 days'"
        );

        return $affected;
    }

    /**
     * D1 — Unit of Work diagnostic.
     * Uses EntityManager persist/flush to demonstrate Doctrine's
     * change tracking and deferred write behaviour.
     */
    public function d1(): array
    {
        $this->reset();

        $user = $this->em->find(User::class, rand(1, 10000));

        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p ORDER BY RANDOM()'
        )
        ->setMaxResults(5)
        ->getResult();

        // Create order entity
        $order = new Order();
        $order->setUser($user);
        $order->setTotal(0);
        $order->setStatus('pending');
        $order->setCreatedAt(new \DateTime());

        $this->em->persist($order);

        $total = 0;
        foreach ($products as $product) {
            $quantity = rand(1, 3);
            $price    = $product->getPrice();
            $total   += $quantity * $price;

            $item = new OrderItem();
            $item->setOrder($order);
            $item->setProduct($product);
            $item->setQuantity($quantity);
            $item->setPrice($price);

            $this->em->persist($item);
            $order->addItem($item);
        }

        $order->setTotal($total);

        // Single flush — Doctrine batches all INSERTs here (Unit of Work)
        $this->em->flush();

        $orderId = $order->getId();

        // Clean up
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM order_items WHERE order_id = ?', [$orderId]);
        $conn->executeStatement('DELETE FROM orders WHERE id = ?', [$orderId]);

        $this->reset();

        return [
            'order_id'    => $orderId,
            'total'       => $total,
            'items_count' => count($products),
        ];
    }
}
