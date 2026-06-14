package com.benchmark.scenarios.raw_sql;

import com.benchmark.Connection;

import java.math.BigDecimal;
import java.sql.*;
import java.util.*;

/**
 * Raw SQL baseline scenarios using JDBC directly.
 * No ORM abstraction — direct SQL execution via PreparedStatement.
 * Baseline for Hibernate overhead measurement in the same Java runtime.
 */
public class Scenario {

    private final java.sql.Connection conn;

    public Scenario() throws SQLException {
        this.conn = Connection.getRawConnection();
        this.conn.setAutoCommit(true);
    }

    /**
     * A1 — Simple select by primary key.
     */
    public Map<String, Object> a1() throws SQLException {
        int id = (int) (Math.random() * 10000) + 1;
        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT id, email, name, created_at FROM users WHERE id = ?")) {
            ps.setInt(1, id);
            try (ResultSet rs = ps.executeQuery()) {
                if (rs.next()) {
                    return Map.of(
                        "id",         rs.getInt("id"),
                        "email",      rs.getString("email"),
                        "name",       rs.getString("name"),
                        "created_at", rs.getString("created_at")
                    );
                }
            }
        }
        return Collections.emptyMap();
    }

    /**
     * A2 — Filtered list with ORDER BY and LIMIT.
     */
    public List<Map<String, Object>> a2() throws SQLException {
        int categoryId = (int) (Math.random() * 100) + 1;
        List<Map<String, Object>> results = new ArrayList<>();

        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT id, name, price, created_at " +
                "FROM products " +
                "WHERE category_id = ? " +
                "ORDER BY created_at DESC " +
                "LIMIT 20")) {
            ps.setInt(1, categoryId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    results.add(Map.of(
                        "id",         rs.getInt("id"),
                        "name",       rs.getString("name"),
                        "price",      rs.getBigDecimal("price"),
                        "created_at", rs.getString("created_at")
                    ));
                }
            }
        }
        return results;
    }

    /**
     * A3 — N+1 diagnostic: load 100 orders then access user for each.
     * Intentionally written as N+1 to match ORM default behaviour.
     */
    public List<Map<String, Object>> a3() throws SQLException {
        List<Map<String, Object>> orders = new ArrayList<>();

        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT id, user_id, total, status " +
                "FROM orders ORDER BY id LIMIT 100")) {
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    Map<String, Object> order = new HashMap<>();
                    order.put("order_id", rs.getInt("id"));
                    order.put("total",    rs.getBigDecimal("total"));
                    order.put("status",   rs.getString("status"));
                    order.put("user_id",  rs.getInt("user_id"));
                    orders.add(order);
                }
            }
        }

        // N additional queries — one per order (intentional N+1)
        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT id, name, email FROM users WHERE id = ?")) {
            for (Map<String, Object> order : orders) {
                ps.setInt(1, (int) order.get("user_id"));
                try (ResultSet rs = ps.executeQuery()) {
                    if (rs.next()) {
                        order.put("user", Map.of(
                            "id",    rs.getInt("id"),
                            "name",  rs.getString("name"),
                            "email", rs.getString("email")
                        ));
                    }
                }
            }
        }

        return orders;
    }

    /**
     * A4 — Eager loading via JOIN.
     */
    public List<Map<String, Object>> a4() throws SQLException {
        List<Map<String, Object>> results = new ArrayList<>();

        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT o.id, o.total, o.status, o.created_at, " +
                "       u.id AS user_id, u.name AS user_name, u.email AS user_email " +
                "FROM orders o " +
                "INNER JOIN users u ON u.id = o.user_id " +
                "ORDER BY o.id ASC LIMIT 100")) {
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    results.add(Map.of(
                        "id",         rs.getInt("id"),
                        "total",      rs.getBigDecimal("total"),
                        "status",     rs.getString("status"),
                        "user_id",    rs.getInt("user_id"),
                        "user_name",  rs.getString("user_name"),
                        "user_email", rs.getString("user_email")
                    ));
                }
            }
        }
        return results;
    }

    /**
     * B1 — Deep eager loading across 3 levels.
     * Order → OrderItems → Product → Category.
     */
    public List<Map<String, Object>> b1() throws SQLException {
        int orderId = (int) (Math.random() * 200000) + 1;
        List<Map<String, Object>> results = new ArrayList<>();

        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT o.id AS order_id, o.total, o.status, " +
                "       oi.id AS item_id, oi.quantity, oi.price AS item_price, " +
                "       p.name AS product_name, p.price AS product_price, " +
                "       c.name AS category_name " +
                "FROM orders o " +
                "INNER JOIN order_items oi ON oi.order_id = o.id " +
                "INNER JOIN products p    ON p.id = oi.product_id " +
                "INNER JOIN categories c  ON c.id = p.category_id " +
                "WHERE o.id = ?")) {
            ps.setInt(1, orderId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    results.add(Map.of(
                        "order_id",      rs.getInt("order_id"),
                        "total",         rs.getBigDecimal("total"),
                        "item_id",       rs.getInt("item_id"),
                        "quantity",      rs.getInt("quantity"),
                        "product_name",  rs.getString("product_name"),
                        "category_name", rs.getString("category_name")
                    ));
                }
            }
        }
        return results;
    }

    /**
     * B2 — Aggregate with GROUP BY.
     */
    public List<Map<String, Object>> b2() throws SQLException {
        List<Map<String, Object>> results = new ArrayList<>();

        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT c.id, c.name, " +
                "       COUNT(p.id) AS product_count, " +
                "       ROUND(AVG(p.price)::numeric, 2) AS avg_price " +
                "FROM categories c " +
                "LEFT JOIN products p ON p.category_id = c.id " +
                "GROUP BY c.id, c.name " +
                "ORDER BY product_count DESC")) {
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    results.add(Map.of(
                        "id",            rs.getInt("id"),
                        "name",          rs.getString("name"),
                        "product_count", rs.getLong("product_count"),
                        "avg_price",     rs.getBigDecimal("avg_price")
                    ));
                }
            }
        }
        return results;
    }

    /**
     * B3 — Many-to-many: products by tag with category.
     */
    public List<Map<String, Object>> b3() throws SQLException {
        int tagId = (int) (Math.random() * 500) + 1;
        List<Map<String, Object>> results = new ArrayList<>();

        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT p.id, p.name, p.price, c.name AS category_name " +
                "FROM products p " +
                "INNER JOIN product_tags pt ON pt.product_id = p.id " +
                "INNER JOIN categories c   ON c.id = p.category_id " +
                "WHERE pt.tag_id = ? " +
                "ORDER BY p.id ASC LIMIT 50")) {
            ps.setInt(1, tagId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    results.add(Map.of(
                        "id",            rs.getInt("id"),
                        "name",          rs.getString("name"),
                        "price",         rs.getBigDecimal("price"),
                        "category_name", rs.getString("category_name")
                    ));
                }
            }
        }
        return results;
    }

    /**
     * C1 — Bulk insert: 10,000 products in chunks using batch execution.
     */
    public int c1() throws SQLException {
        int chunkSize = 500;
        int total     = 10000;
        int inserted  = 0;
        int[] catIds  = new int[100];
        for (int i = 0; i < 100; i++) catIds[i] = i + 1;

        conn.setAutoCommit(false);
        try (PreparedStatement ps = conn.prepareStatement(
                "INSERT INTO products (name, price, category_id, created_at) " +
                "VALUES (?, ?, ?, NOW())")) {

            for (int i = 0; i < total; i++) {
                ps.setString(1, "Bulk Product " + i);
                ps.setBigDecimal(2, BigDecimal.valueOf(
                    Math.round(Math.random() * 99800 + 199) / 100.0));
                ps.setInt(3, catIds[(int)(Math.random() * 100)]);
                ps.addBatch();

                if ((i + 1) % chunkSize == 0 || i == total - 1) {
                    ps.executeBatch();
                    conn.commit();
                    inserted += Math.min(chunkSize, i + 1 - inserted);
                }
            }
        }

        // Clean up to restore dataset state
        try (PreparedStatement ps = conn.prepareStatement(
                "DELETE FROM products WHERE name LIKE 'Bulk Product%'")) {
            ps.executeUpdate();
            conn.commit();
        }

        conn.setAutoCommit(true);
        return inserted;
    }

    /**
     * C2 — Bulk update: update status of old shipped orders.
     */
    public int c2() throws SQLException {
        conn.setAutoCommit(false);
        int affected;

        // PostgreSQL does not support LIMIT in UPDATE directly — use UPDATE FROM subquery
        try (PreparedStatement ps = conn.prepareStatement(
                "UPDATE orders SET status = 'delivered' " +
                "FROM (" +
                "  SELECT id FROM orders " +
                "  WHERE status = 'shipped' " +
                "  AND created_at < NOW() - INTERVAL '30 days' " +
                "  ORDER BY id LIMIT 1000" +
                ") AS batch WHERE orders.id = batch.id")) {
            affected = ps.executeUpdate();
            conn.commit();
        }

        // Restore original status
        try (PreparedStatement ps = conn.prepareStatement(
                "UPDATE orders SET status = 'shipped' " +
                "FROM (" +
                "  SELECT id FROM orders " +
                "  WHERE status = 'delivered' " +
                "  AND created_at < NOW() - INTERVAL '30 days' " +
                "  ORDER BY id LIMIT 1000" +
                ") AS batch WHERE orders.id = batch.id")) {
            ps.executeUpdate();
            conn.commit();
        }

        conn.setAutoCommit(true);
        return affected;
    }

    /**
     * D1 — Unit of Work diagnostic.
     * Raw SQL uses explicit transaction — no Unit of Work pattern.
     */
    public Map<String, Object> d1() throws SQLException {
        int userId = (int) (Math.random() * 10000) + 1;
        int skip   = (int) (Math.random() * 49995);

        conn.setAutoCommit(false);

        List<Map<String, Object>> products = new ArrayList<>();
        try (PreparedStatement ps = conn.prepareStatement(
                "SELECT id, price FROM products ORDER BY id LIMIT 5 OFFSET ?")) {
            ps.setInt(1, skip);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    products.add(new HashMap<>(Map.of(
                        "id",    rs.getInt("id"),
                        "price", rs.getBigDecimal("price")
                    )));
                }
            }
        }

        double total = 0;
        List<Map<String, Object>> items = new ArrayList<>();
        for (Map<String, Object> p : products) {
            int    quantity = (int)(Math.random() * 3) + 1;
            double price    = ((BigDecimal) p.get("price")).doubleValue();
            total += quantity * price;
            items.add(Map.of(
                "product_id", p.get("id"),
                "quantity",   quantity,
                "price",      price
            ));
        }

        // Insert order
        int orderId;
        try (PreparedStatement ps = conn.prepareStatement(
                "INSERT INTO orders (user_id, total, status, created_at) " +
                "VALUES (?, ?, 'pending', NOW()) RETURNING id")) {
            ps.setInt(1, userId);
            ps.setDouble(2, total);
            try (ResultSet rs = ps.executeQuery()) {
                rs.next();
                orderId = rs.getInt("id");
            }
        }

        // Insert items
        try (PreparedStatement ps = conn.prepareStatement(
                "INSERT INTO order_items (order_id, product_id, quantity, price) " +
                "VALUES (?, ?, ?, ?)")) {
            for (Map<String, Object> item : items) {
                ps.setInt(1, orderId);
                ps.setInt(2, (int) item.get("product_id"));
                ps.setInt(3, (int) item.get("quantity"));
                ps.setDouble(4, (double) item.get("price"));
                ps.addBatch();
            }
            ps.executeBatch();
        }

        conn.commit();

        // Clean up
        try (PreparedStatement ps = conn.prepareStatement(
                "DELETE FROM order_items WHERE order_id = ?")) {
            ps.setInt(1, orderId);
            ps.executeUpdate();
        }
        try (PreparedStatement ps = conn.prepareStatement(
                "DELETE FROM orders WHERE id = ?")) {
            ps.setInt(1, orderId);
            ps.executeUpdate();
        }
        conn.commit();
        conn.setAutoCommit(true);

        return Map.of(
            "order_id",    orderId,
            "total",       total,
            "items_count", items.size()
        );
    }
}
