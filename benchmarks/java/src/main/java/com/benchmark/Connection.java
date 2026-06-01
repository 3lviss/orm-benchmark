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
 */
public class Connection {

    private static EntityManagerFactory emf = null;

    /**
     * Returns the shared EntityManagerFactory.
     * Reads DB config from environment variables.
     */
    public static EntityManagerFactory getEntityManagerFactory() {
        if (emf == null) {
            String host = System.getenv().getOrDefault("DB_HOST", "localhost");
            String port = System.getenv().getOrDefault("DB_PORT", "5432");
            String name = System.getenv().getOrDefault("DB_NAME", "benchmark");
            String user = System.getenv().getOrDefault("DB_USER", "benchmark");
            String pass = System.getenv().getOrDefault("DB_PASS", "benchmark");

            Map<String, String> props = new HashMap<>();
            props.put("jakarta.persistence.jdbc.url",
                "jdbc:postgresql://" + host + ":" + port + "/" + name);
            props.put("jakarta.persistence.jdbc.user",     user);
            props.put("jakarta.persistence.jdbc.password", pass);

            emf = Persistence.createEntityManagerFactory("benchmark", props);
        }
        return emf;
    }

    /**
     * Returns a raw JDBC connection for raw SQL scenarios.
     */
    public static java.sql.Connection getRawConnection() throws SQLException {
        String host = System.getenv().getOrDefault("DB_HOST", "localhost");
        String port = System.getenv().getOrDefault("DB_PORT", "5432");
        String name = System.getenv().getOrDefault("DB_NAME", "benchmark");
        String user = System.getenv().getOrDefault("DB_USER", "benchmark");
        String pass = System.getenv().getOrDefault("DB_PASS", "benchmark");

        String url = "jdbc:postgresql://" + host + ":" + port + "/" + name;
        return DriverManager.getConnection(url, user, pass);
    }

    public static void close() {
        if (emf != null && emf.isOpen()) {
            emf.close();
            emf = null;
        }
    }
}
