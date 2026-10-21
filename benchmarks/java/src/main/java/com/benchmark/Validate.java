package com.benchmark;

import java.lang.reflect.Field;
import java.lang.reflect.Method;
import java.sql.DriverManager;
import java.sql.ResultSet;
import java.sql.Statement;
import java.util.*;
import java.util.logging.LogManager;

/**
 * Validation entry point (docs/PLAN_REPO.md 2.5) — not used for timing.
 * Usage: java -cp app.jar com.benchmark.Validate <implementation> [scenario ...]
 *
 * Math.random() cannot be seeded, so the Random instance behind it
 * (Math$RandomNumberGeneratorHolder) is replaced via sun.misc.Unsafe with one
 * that always returns VALIDATE_U. Every implementation then receives the same
 * parameters. Per scenario: warm-up, then pg_stat_statements_reset(), N calls,
 * and the statements recorded by the database are printed as one JSON line.
 */
public class Validate {

    private static final String STATS_SQL =
        "SELECT query, calls::int AS calls, rows::int AS rows " +
        "FROM pg_stat_statements " +
        "WHERE dbid = (SELECT oid FROM pg_database WHERE datname = current_database()) " +
        "  AND query NOT ILIKE '%pg_stat_statements%' " +
        "ORDER BY calls DESC, query";

    public static void main(String[] args) throws Exception {
        LogManager.getLogManager().reset();

        double u       = Double.parseDouble(System.getenv().getOrDefault("VALIDATE_U", "0.1"));
        int    n       = Integer.parseInt(System.getenv().getOrDefault("VALIDATE_N", "10"));
        int    warmupR = Integer.parseInt(System.getenv().getOrDefault("VALIDATE_WARMUP", "20"));
        int    warmupW = Integer.parseInt(System.getenv().getOrDefault("VALIDATE_WARMUP_WRITE", "5"));
        Set<String> writeScenarios = Set.of("C1", "C2", "D1");

        String implementation = args.length > 0 ? args[0].toLowerCase() : "raw_sql";
        List<String> scenarios = args.length > 1
            ? Arrays.stream(args).skip(1).map(String::toUpperCase).toList()
            : List.of("A1", "A2", "A3", "A4", "B1", "B2", "B3", "C1", "C2", "D1");

        fixMathRandom(u);

        Object runner = Class.forName("com.benchmark.scenarios." + implementation + ".Scenario")
            .getDeclaredConstructor().newInstance();

        String url = "jdbc:postgresql://" + System.getenv().getOrDefault("DB_HOST", "localhost") + ":"
            + System.getenv().getOrDefault("DB_PORT", "5432") + "/"
            + System.getenv().getOrDefault("DB_NAME", "benchmark");
        try (java.sql.Connection admin = DriverManager.getConnection(url,
                System.getenv().getOrDefault("DB_USER", "benchmark"),
                System.getenv().getOrDefault("DB_PASS", "benchmark"))) {

            for (String scenario : scenarios) {
                Method m      = runner.getClass().getMethod(scenario.toLowerCase());
                int    warmup = writeScenarios.contains(scenario) ? warmupW : warmupR;
                Map<String, Object> line = new LinkedHashMap<>();
                line.put("lang", "java");
                line.put("implementation", implementation);
                line.put("scenario", scenario);
                line.put("u", u);
                line.put("n", n);
                line.put("warmup", warmup);
                try {
                    for (int i = 0; i < warmup; i++) m.invoke(runner);
                    try (Statement st = admin.createStatement()) {
                        st.execute("SELECT pg_stat_statements_reset()");
                    }
                    Object result = null;
                    for (int i = 0; i < n; i++) result = m.invoke(runner);

                    List<Map<String, Object>> statements = new ArrayList<>();
                    try (Statement st = admin.createStatement(); ResultSet rs = st.executeQuery(STATS_SQL)) {
                        while (rs.next()) {
                            Map<String, Object> row = new LinkedHashMap<>();
                            row.put("query", rs.getString("query"));
                            row.put("calls", rs.getInt("calls"));
                            row.put("rows", rs.getInt("rows"));
                            statements.add(row);
                        }
                    }
                    line.put("statements", statements);
                    line.put("result", result);
                    line.put("ok", true);
                } catch (Exception e) {
                    Throwable cause = e.getCause() != null ? e.getCause() : e;
                    line.put("ok", false);
                    line.put("error", String.valueOf(cause));
                }
                System.out.println(toJson(line));
            }
        }
        System.exit(0);
    }

    /** Replaces the Random behind Math.random() with a constant generator. */
    private static void fixMathRandom(double u) throws Exception {
        Math.random(); // initialise the holder class
        Field unsafeField = sun.misc.Unsafe.class.getDeclaredField("theUnsafe");
        unsafeField.setAccessible(true);
        sun.misc.Unsafe unsafe = (sun.misc.Unsafe) unsafeField.get(null);

        Field holder = Class.forName("java.lang.Math$RandomNumberGeneratorHolder")
            .getDeclaredField("randomNumberGenerator");
        unsafe.putObject(unsafe.staticFieldBase(holder), unsafe.staticFieldOffset(holder), new Random() {
            @Override public double nextDouble() { return u; }
        });

        if (Math.random() != u) throw new IllegalStateException("Math.random() override failed");
    }

    /** Minimal JSON writer: maps, collections, numbers, booleans; everything else as string. */
    private static String toJson(Object v) {
        if (v == null) return "null";
        if (v instanceof Map<?, ?> map) {
            StringJoiner j = new StringJoiner(",", "{", "}");
            map.forEach((k, val) -> j.add(quote(String.valueOf(k)) + ":" + toJson(val)));
            return j.toString();
        }
        if (v instanceof Collection<?> c) {
            StringJoiner j = new StringJoiner(",", "[", "]");
            c.forEach(x -> j.add(toJson(x)));
            return j.toString();
        }
        if (v instanceof Boolean || v instanceof Integer || v instanceof Long
                || v instanceof Short || v instanceof Double || v instanceof Float) {
            return String.valueOf(v);
        }
        return quote(String.valueOf(v));
    }

    private static String quote(String s) {
        StringBuilder b = new StringBuilder("\"");
        for (char ch : s.toCharArray()) {
            switch (ch) {
                case '"'  -> b.append("\\\"");
                case '\\' -> b.append("\\\\");
                case '\n' -> b.append("\\n");
                case '\r' -> b.append("\\r");
                case '\t' -> b.append("\\t");
                default   -> { if (ch < 0x20) b.append(String.format("\\u%04x", (int) ch)); else b.append(ch); }
            }
        }
        return b.append('"').toString();
    }
}
