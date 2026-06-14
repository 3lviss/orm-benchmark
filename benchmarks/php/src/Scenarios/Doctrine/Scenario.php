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
        $rsm = new \Doctrine\ORM\Query\ResultSetMappingBuilder($this->em);
        $rsm->addRootEntityFromClassMetadata(Order::class, 'o');
        $rsm->addJoinedEntityFromClassMetadata(User::class, 'u', 'o', 'user', [
            'id'         => 'u_id',
            'name'       => 'u_name',
            'email'      => 'u_email',
            'created_at' => 'u_created_at',
        ]);

        $orders = $this->em->createNativeQuery(
            'SELECT o.id, o.total, o.status, o.created_at, o.user_id,
                    u.id AS u_id, u.name AS u_name, u.email AS u_email, u.created_at AS u_created_at
             FROM orders o
             INNER JOIN users u ON u.id = o.user_id
             ORDER BY o.id ASC
             LIMIT 100',
            $rsm
        )->getResult();

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
        $rsm = new \Doctrine\ORM\Query\ResultSetMappingBuilder($this->em);
        $rsm->addRootEntityFromClassMetadata(Order::class, 'o');
        $rsm->addJoinedEntityFromClassMetadata(OrderItem::class, 'i', 'o', 'items', [
            'id'         => 'i_id',
            'quantity'   => 'i_quantity',
            'price'      => 'i_price',
            'order_id'   => 'i_order_id',
            'product_id' => 'i_product_id',
        ]);
        $rsm->addJoinedEntityFromClassMetadata(Product::class, 'p', 'i', 'product', [
            'id'          => 'p_id',
            'name'        => 'p_name',
            'price'       => 'p_price',
            'description' => 'p_description',
            'created_at'  => 'p_created_at',
            'category_id' => 'p_category_id',
        ]);
        $rsm->addJoinedEntityFromClassMetadata(Category::class, 'cat', 'p', 'category', [
            'id'   => 'cat_id',
            'name' => 'cat_name',
        ]);

        $orderId = rand(1, 200000);
        $order   = $this->em->createNativeQuery(
            'SELECT o.id, o.total, o.status, o.created_at, o.user_id,
                    i.id AS i_id, i.quantity AS i_quantity, i.price AS i_price,
                    i.order_id AS i_order_id, i.product_id AS i_product_id,
                    p.id AS p_id, p.name AS p_name, p.price AS p_price,
                    p.description AS p_description, p.created_at AS p_created_at,
                    p.category_id AS p_category_id,
                    cat.id AS cat_id, cat.name AS cat_name
             FROM orders o
             INNER JOIN order_items i ON i.order_id = o.id
             INNER JOIN products p    ON p.id = i.product_id
             INNER JOIN categories cat ON cat.id = p.category_id
             WHERE o.id = :id',
            $rsm
        )
        ->setParameter('id', $orderId)
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
            'SELECT p FROM ' . Product::class . ' p
             JOIN p.category c
             JOIN p.tags t
             WHERE t.id = :tagId
             ORDER BY p.id ASC'
        )
        ->setFetchMode(Product::class, 'category', \Doctrine\ORM\Mapping\ClassMetadata::FETCH_EAGER)
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
             FROM (
                 SELECT id FROM orders
                 WHERE status = 'shipped'
                   AND created_at < NOW() - INTERVAL '30 days'
                 ORDER BY id LIMIT 1000
             ) AS batch
             WHERE orders.id = batch.id"
        );

        // Restore original status to keep dataset consistent
        $conn->executeStatement(
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
     * Uses EntityManager persist/flush to demonstrate Doctrine's
     * change tracking and deferred write behaviour.
     */
    public function d1(): array
    {
        $this->reset();

        $user = $this->em->find(User::class, rand(1, 10000));

        $rsm = new \Doctrine\ORM\Query\ResultSetMappingBuilder($this->em);
        $rsm->addRootEntityFromClassMetadata(Product::class, 'p');
        $products = $this->em->createNativeQuery(
            'SELECT ' . $rsm->generateSelectClause() . ' FROM products p ORDER BY RANDOM() LIMIT 5',
            $rsm
        )->getResult();

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
