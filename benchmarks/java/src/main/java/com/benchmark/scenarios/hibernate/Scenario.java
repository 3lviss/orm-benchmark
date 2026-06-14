package com.benchmark.scenarios.hibernate;
 
import com.benchmark.Connection;
import com.benchmark.scenarios.hibernate.entities.Category;
import com.benchmark.scenarios.hibernate.entities.OrderItem;
import com.benchmark.scenarios.hibernate.entities.Product;
import com.benchmark.scenarios.hibernate.entities.Review;
import com.benchmark.scenarios.hibernate.entities.Tag;
import com.benchmark.scenarios.hibernate.entities.User;
import com.benchmark.scenarios.hibernate.entities.Order;
import jakarta.persistence.EntityManager;
import jakarta.persistence.EntityManagerFactory;
import jakarta.persistence.EntityTransaction;
import org.hibernate.Session;
 
import java.math.BigDecimal;
import java.time.LocalDateTime;
import java.util.*;
 
/**
 * Hibernate ORM benchmark scenarios.
 * Uses Data Mapper pattern via EntityManager and JPA.
 * Second-level cache is disabled for fair benchmarking.
 * Compared against raw SQL baseline in the same Java runtime.
 */
public class Scenario {
 
    private final EntityManagerFactory emf;
 
    public Scenario() {
        this.emf = Connection.getEntityManagerFactory();
    }
 
    /**
     * Returns a fresh EntityManager for each scenario call.
     * First-level cache is cleared between iterations this way.
     */
    private EntityManager em() {
        return emf.createEntityManager();
    }
 
    /**
     * A1 — Simple select by primary key.
     */
    public Map<String, Object> a1() {
        int id = (int) (Math.random() * 10000) + 1;
        try (EntityManager em = em()) {
            User user = em.find(User.class, id);
            if (user == null) return Collections.emptyMap();
            return Map.of(
                "id",         user.getId(),
                "email",      user.getEmail(),
                "name",       user.getName(),
                "created_at", user.getCreatedAt().toString()
            );
        }
    }
 
    /**
     * A2 — Filtered list with ORDER BY and LIMIT.
     */
    public List<Map<String, Object>> a2() {
        int categoryId = (int) (Math.random() * 100) + 1;
        try (EntityManager em = em()) {
            List<Product> products = em.createQuery(
                "SELECT p FROM Product p " +
                "WHERE p.category.id = :catId " +
                "ORDER BY p.createdAt DESC", Product.class)
                .setParameter("catId", categoryId)
                .setMaxResults(20)
                .getResultList();
 
            List<Map<String, Object>> results = new ArrayList<>();
            for (Product p : products) {
                results.add(Map.of(
                    "id",    p.getId(),
                    "name",  p.getName(),
                    "price", p.getPrice()
                ));
            }
            return results;
        }
    }
 
    /**
     * A3 — N+1 diagnostic: load 100 orders then access user for each.
     * Intentionally uses lazy loading to trigger N+1 behaviour.
     */
    public List<Map<String, Object>> a3() {
        try (EntityManager em = em()) {
            List<Order> orders = em.createQuery(
                "SELECT o FROM Order o ORDER BY o.id ASC", Order.class)
                .setMaxResults(100)
                .getResultList();
 
            List<Map<String, Object>> results = new ArrayList<>();
            for (Order order : orders) {
                // Accessing getUser() triggers a separate query (N+1)
                results.add(Map.of(
                    "order_id", order.getId(),
                    "total",    order.getTotal(),
                    "status",   order.getStatus(),
                    "user",     Map.of(
                        "id",    order.getUser().getId(),
                        "name",  order.getUser().getName(),
                        "email", order.getUser().getEmail()
                    )
                ));
            }
            return results;
        }
    }
 
    /**
     * A4 — Eager loading: orders with users via JOIN FETCH.
     */
    public List<Map<String, Object>> a4() {
        try (EntityManager em = em()) {
            List<Order> orders = em.createQuery(
                "SELECT o FROM Order o " +
                "JOIN FETCH o.user u " +
                "ORDER BY o.id ASC", Order.class)
                .setMaxResults(100)
                .getResultList();
 
            List<Map<String, Object>> results = new ArrayList<>();
            for (Order o : orders) {
                results.add(Map.of(
                    "id",     o.getId(),
                    "total",  o.getTotal(),
                    "status", o.getStatus(),
                    "user",   Map.of(
                        "id",   o.getUser().getId(),
                        "name", o.getUser().getName()
                    )
                ));
            }
            return results;
        }
    }
 
    /**
     * B1 — Deep eager loading across 3 levels.
     * Order → OrderItems → Product → Category.
     */
    public Map<String, Object> b1() {
        int orderId = (int) (Math.random() * 200000) + 1;
        try (EntityManager em = em()) {
            Order order = em.createQuery(
                "SELECT o FROM Order o " +
                "LEFT JOIN FETCH o.items i " +
                "LEFT JOIN FETCH i.product p " +
                "LEFT JOIN FETCH p.category " +
                "WHERE o.id = :id", Order.class)
                .setParameter("id", orderId)
                .getResultStream()
                .findFirst()
                .orElse(null);
 
            if (order == null) return Collections.emptyMap();
 
            List<Map<String, Object>> items = new ArrayList<>();
            for (OrderItem item : order.getItems()) {
                items.add(Map.of(
                    "product",  item.getProduct().getName(),
                    "category", item.getProduct().getCategory().getName(),
                    "quantity", item.getQuantity(),
                    "price",    item.getPrice()
                ));
            }
 
            return Map.of(
                "order_id", order.getId(),
                "total",    order.getTotal(),
                "items",    items
            );
        }
    }
 
    /**
     * B2 — Aggregate with GROUP BY using JPQL.
     */
    public List<Map<String, Object>> b2() {
        try (EntityManager em = em()) {
            List<Object[]> rows = em.createQuery(
                "SELECT c.id, c.name, COUNT(p.id), AVG(p.price) " +
                "FROM Category c LEFT JOIN c.products p " +
                "GROUP BY c.id, c.name " +
                "ORDER BY COUNT(p.id) DESC", Object[].class)
                .getResultList();
 
            List<Map<String, Object>> results = new ArrayList<>();
            for (Object[] row : rows) {
                results.add(Map.of(
                    "id",            row[0],
                    "name",          row[1],
                    "product_count", row[2],
                    "avg_price",     row[3] != null ? row[3] : 0.0
                ));
            }
            return results;
        }
    }
 
    /**
     * B3 — Many-to-many: products by tag with category.
     */
    public List<Map<String, Object>> b3() {
        int tagId = (int) (Math.random() * 500) + 1;
        try (EntityManager em = em()) {
            List<Product> products = em.createQuery(
                "SELECT p FROM Product p " +
                "JOIN FETCH p.category " +
                "JOIN p.tags t " +
                "WHERE t.id = :tagId " +
                "ORDER BY p.id ASC", Product.class)
                .setParameter("tagId", tagId)
                .setMaxResults(50)
                .getResultList();
 
            List<Map<String, Object>> results = new ArrayList<>();
            for (Product p : products) {
                results.add(Map.of(
                    "id",       p.getId(),
                    "name",     p.getName(),
                    "price",    p.getPrice(),
                    "category", p.getCategory().getName()
                ));
            }
            return results;
        }
    }
 
    /**
     * C1 — Bulk insert: 10,000 products using JDBC batch via Hibernate session.
     */
    public int c1() {
        int chunkSize = 500;
        int total     = 10000;
        int[] catIds  = new int[100];
        for (int i = 0; i < 100; i++) catIds[i] = i + 1;
 
        try (EntityManager em = em()) {
            EntityTransaction tx = em.getTransaction();
            tx.begin();
 
            Session session = em.unwrap(Session.class);
            session.doWork(conn -> {
                try (var ps = conn.prepareStatement(
                        "INSERT INTO products (name, price, category_id, created_at) " +
                        "VALUES (?, ?, ?, NOW())")) {
                    for (int i = 0; i < total; i++) {
                        ps.setString(1, "Bulk Product " + i);
                        ps.setBigDecimal(2, BigDecimal.valueOf(
                            Math.round(Math.random() * 99800 + 199) / 100.0));
                        ps.setInt(3, catIds[(int)(Math.random() * 100)]);
                        ps.addBatch();
                        if ((i + 1) % chunkSize == 0) ps.executeBatch();
                    }
                    ps.executeBatch();
                }
            });
 
            tx.commit();
 
            // Clean up
            tx.begin();
            em.createQuery(
                "DELETE FROM Product p WHERE p.name LIKE 'Bulk Product%'")
                .executeUpdate();
            tx.commit();
        }
 
        return total;
    }
 
    /**
     * C2 — Bulk update using JPQL UPDATE for performance.
     */
    public int c2() {
        LocalDateTime thirtyDaysAgo = LocalDateTime.now().minusDays(30);
 
        try (EntityManager em = em()) {
            EntityTransaction tx = em.getTransaction();
            tx.begin();
 
            // JPQL does not support LIMIT on UPDATE — use native SQL with UPDATE FROM subquery
            int affected = (int) em.createNativeQuery(
                "UPDATE orders SET status = 'delivered' " +
                "FROM (" +
                "  SELECT id FROM orders " +
                "  WHERE status = 'shipped' " +
                "  AND created_at < :cutoff " +
                "  ORDER BY id LIMIT 1000" +
                ") AS batch WHERE orders.id = batch.id")
                .setParameter("cutoff", thirtyDaysAgo)
                .executeUpdate();

            tx.commit();

            // Restore original status
            tx.begin();
            em.createNativeQuery(
                "UPDATE orders SET status = 'shipped' " +
                "FROM (" +
                "  SELECT id FROM orders " +
                "  WHERE status = 'delivered' " +
                "  AND created_at < :cutoff " +
                "  ORDER BY id LIMIT 1000" +
                ") AS batch WHERE orders.id = batch.id")
                .setParameter("cutoff", thirtyDaysAgo)
                .executeUpdate();
            tx.commit();
 
            return affected;
        }
    }
 
    /**
     * D1 — Unit of Work diagnostic.
     * Uses EntityManager persist/flush to demonstrate Hibernate's
     * change tracking and deferred write behaviour.
     */
    public Map<String, Object> d1() {
        int userId = (int) (Math.random() * 10000) + 1;
        int skip   = (int) (Math.random() * 49995);
 
        try (EntityManager em = em()) {
            EntityTransaction tx = em.getTransaction();
            tx.begin();
 
            User user = em.find(User.class, userId);
 
            List<Product> products = em.createQuery(
                "SELECT p FROM Product p ORDER BY p.id ASC",
                Product.class)
                .setFirstResult(skip)
                .setMaxResults(5)
                .getResultList();
 
            Order order = new Order();
            order.setUser(user);
            order.setTotal(BigDecimal.ZERO);
            order.setStatus("pending");
            order.setCreatedAt(LocalDateTime.now());
            em.persist(order);
            em.flush();
 
            BigDecimal total = BigDecimal.ZERO;
            List<OrderItem> items = new ArrayList<>();
 
            for (Product product : products) {
                int quantity = (int)(Math.random() * 3) + 1;
 
                OrderItem item = new OrderItem();
                item.setOrder(order);
                item.setProduct(product);
                item.setQuantity(quantity);
                item.setPrice(product.getPrice());
                em.persist(item);
                items.add(item);
 
                total = total.add(
                    product.getPrice().multiply(BigDecimal.valueOf(quantity))
                );
            }
 
            order.setTotal(total);
            em.flush();
            tx.commit();
 
            int orderId = order.getId();
 
            // Clean up
            tx.begin();
            em.createQuery(
                "DELETE FROM OrderItem i WHERE i.order.id = :id")
                .setParameter("id", orderId)
                .executeUpdate();
            em.createQuery(
                "DELETE FROM Order o WHERE o.id = :id")
                .setParameter("id", orderId)
                .executeUpdate();
            tx.commit();
 
            return Map.of(
                "order_id",    orderId,
                "total",       total,
                "items_count", items.size()
            );
        }
    }
}
