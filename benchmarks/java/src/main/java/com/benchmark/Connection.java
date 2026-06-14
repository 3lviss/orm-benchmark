package com.benchmark;

import jakarta.persistence.EntityManagerFactory;
import jakarta.persistence.Persistence;
import java.sql.DriverManager;
import java.sql.SQLException;
import java.util.HashMap;
import java.util.Map;

/**
 * Shared database connection factory.
 * EntityManagerFactory is expensive to create — initialized once and reused.
 *
 * Hibernate uses P6Spy proxy driver (jdbc:p6spy:postgresql://...) for query counting.
 * Raw SQL uses plain JDBC (jdbc:postgresql://...) directly.
 */
public class Connection {

    private static EntityManagerFactory emf = null;

    private static String getHost()     { return System.getenv().getOrDefault("DB_HOST", "localhost"); }
    private static String getPort()     { return System.getenv().getOrDefault("DB_PORT", "5432"); }
    private static String getName()     { return System.getenv().getOrDefault("DB_NAME", "benchmark"); }
    private static String getUser()     { return System.getenv().getOrDefault("DB_USER", "benchmark"); }
    private static String getPassword() { return System.getenv().getOrDefault("DB_PASS", "benchmark"); }

    /**
     * JDBC URL for Hibernate — routed through P6Spy proxy for query counting.
     */
    private static String getHibernateUrl() {
        return "jdbc:p6spy:postgresql://" + getHost() + ":" + getPort() + "/" + getName();
    }

    /**
     * Plain JDBC URL for raw SQL — P6Spy not needed, queries are explicit in code.
     */
    private static String getRawUrl() {
        return "jdbc:postgresql://" + getHost() + ":" + getPort() + "/" + getName();
    }

    public static EntityManagerFactory getEntityManagerFactory() {
        if (emf == null) {
            Map<String, String> props = new HashMap<>();
            props.put("jakarta.persistence.jdbc.url",      getHibernateUrl());
            props.put("jakarta.persistence.jdbc.user",     getUser());
            props.put("jakarta.persistence.jdbc.password", getPassword());

            emf = Persistence.createEntityManagerFactory("benchmark", props);
        }
        return emf;
    }

    public static java.sql.Connection getRawConnection() throws SQLException {
        return DriverManager.getConnection(getRawUrl(), getUser(), getPassword());
    }

    public static void close() {
        if (emf != null && emf.isOpen()) {
            emf.close();
            emf = null;
        }
    }
}
