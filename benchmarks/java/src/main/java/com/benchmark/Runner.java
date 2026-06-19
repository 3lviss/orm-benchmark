package com.benchmark;

import com.fasterxml.jackson.databind.ObjectMapper;
import java.lang.reflect.Method;
import java.util.*;
import java.util.logging.LogManager;
import java.util.OptionalDouble;
import java.nio.file.Files;
import java.nio.file.Paths;

/**
 * Entry point for Java benchmarks.
 * Usage: java -jar app.jar <implementation> <scenario>
 * Example: java -jar app.jar raw_sql A1
 *
 * Query counting is handled by P6Spy via QueryCounter.
 * P6Spy intercepts all JDBC calls transparently — no Hibernate Statistics needed.
 */
public class Runner {

    private static final ObjectMapper mapper = new ObjectMapper();

    public static void main(String[] args) throws Exception {
        // Silence all java.util.logging output — Hibernate logs go to stdout by default
        LogManager.getLogManager().reset();

        if (args.length < 2) {
            System.err.println("Usage: java -jar app.jar <implementation> <scenario>");
            System.exit(1);
        }

        String implementation = args[0].toLowerCase();
        String scenario       = args[1].toUpperCase();
        String method         = scenario.toLowerCase();

        Map<String, String> classMap = new HashMap<>();
        classMap.put("raw_sql",   "com.benchmark.scenarios.raw_sql.Scenario");
        classMap.put("hibernate", "com.benchmark.scenarios.hibernate.Scenario");

        if (!classMap.containsKey(implementation)) {
            System.err.println("Unknown implementation: " + implementation);
            System.err.println("Available: " + String.join(", ", classMap.keySet()));
            System.exit(1);
        }

        Class<?> clazz  = Class.forName(classMap.get(implementation));
        Object   runner = clazz.getDeclaredConstructor().newInstance();
        Method   m      = clazz.getMethod(method);

        // raw_sql uses plain JDBC — query count is statically known from code
        boolean isRawSql = implementation.equals("raw_sql");

        // Write scenarios (C, D) are inherently slower — cap warm-up and measurement to avoid excessive runtime
        Set<String> writeScenarios = Set.of("C1", "C2", "D1");
        boolean     isWriteScenario = writeScenarios.contains(scenario);

        // ── Warm-up phase (adaptive: CV < 5% over rolling window of 10) ──────
        List<Double> warmupWindow = new ArrayList<>();
        int     warmupCount = 0;
        boolean warmupDone  = false;
        int     maxWarmup   = isWriteScenario ? 20 : 2000;

        while (!warmupDone && warmupCount < maxWarmup) {
            QueryCounter.reset();
            long start = System.nanoTime();
            m.invoke(runner);
            long end = System.nanoTime();
            // query count discarded during warm-up

            double ms = (end - start) / 1_000_000.0;
            warmupWindow.add(ms);
            warmupCount++;

            if (warmupWindow.size() > 10) warmupWindow.remove(0);

            if (warmupWindow.size() == 10) {
                double mean = warmupWindow.stream()
                    .mapToDouble(Double::doubleValue).average().orElse(0);
                if (mean > 0) {
                    double variance = warmupWindow.stream()
                        .mapToDouble(v -> Math.pow(v - mean, 2))
                        .average().orElse(0);
                    double cv = Math.sqrt(variance) / mean * 100;
                    if (cv < 5.0) warmupDone = true;
                }
            }
        }

        // ── Measurement phase (adaptive: bootstrap CI width < 5% of p99) ─────
        List<Double> measurements = new ArrayList<>();
        List<Long>   queryCounts  = new ArrayList<>();
        int maxMeasure = isWriteScenario ? 200  : 10000;
        int checkEvery = 100;
        int minMeasure = isWriteScenario ? 20   : 100;
        Random rng = new Random();

        while (measurements.size() < maxMeasure) {
            QueryCounter.reset();
            long start = System.nanoTime();
            try {
                m.invoke(runner);
            } catch (Exception e) {
                // Skip failed iterations (e.g. deadlock) — do not record measurement
                System.err.println("Iteration failed, skipping: " + e.getCause().getMessage());
                continue;
            }
            long end = System.nanoTime();

            measurements.add((end - start) / 1_000_000.0);
            queryCounts.add(isRawSql ? -1L : QueryCounter.get());

            int n = measurements.size();
            if (n >= minMeasure && n % checkEvery == 0) {
                List<Double> sorted = new ArrayList<>(measurements);
                Collections.sort(sorted);
                int    p99index = (int) Math.ceil(0.99 * n) - 1;
                double p99value = sorted.get(p99index);

                if (p99value > 0) {
                    List<Double> bootstrapP99s = new ArrayList<>();
                    for (int b = 0; b < 500; b++) {
                        List<Double> sample = new ArrayList<>();
                        for (int s = 0; s < n; s++) {
                            sample.add(measurements.get(rng.nextInt(n)));
                        }
                        Collections.sort(sample);
                        bootstrapP99s.add(sample.get((int) Math.ceil(0.99 * n) - 1));
                    }
                    Collections.sort(bootstrapP99s);
                    double lower   = bootstrapP99s.get((int) (0.025 * 500));
                    double upper   = bootstrapP99s.get((int) (0.975 * 500));
                    double ciWidth = ((upper - lower) / p99value) * 100;
                    if (ciWidth < 5.0) break;
                }
            }
        }

        // ── Compute final statistics ──────────────────────────────────────────
        Collections.sort(measurements);
        int    n      = measurements.size();
        double p50    = measurements.get((int) Math.ceil(0.50 * n) - 1);
        double p95    = measurements.get((int) Math.ceil(0.95 * n) - 1);
        double p99    = measurements.get((int) Math.ceil(0.99 * n) - 1);
        double mean   = measurements.stream().mapToDouble(Double::doubleValue).average().orElse(0);
        double variance = measurements.stream()
                            .mapToDouble(v -> Math.pow(v - mean, 2))
                            .average().orElse(0);
        double stddev = Math.sqrt(variance);

        double qMean   = computeQueryCountMean(queryCounts);
        long   qMedian = computeQueryCountMedian(queryCounts);

        Map<String, Object> result = new LinkedHashMap<>();
        result.put("implementation",      implementation);
        result.put("scenario",            scenario);
        result.put("n_warmup",            warmupCount);
        result.put("n_measurements",      n);
        result.put("p50_ms",  Math.round(p50    * 10000.0) / 10000.0);
        result.put("p95_ms",  Math.round(p95    * 10000.0) / 10000.0);
        result.put("p99_ms",  Math.round(p99    * 10000.0) / 10000.0);
        result.put("mean_ms", Math.round(mean   * 10000.0) / 10000.0);
        result.put("stddev_ms", Math.round(stddev * 10000.0) / 10000.0);
        result.put("query_count_mean",   qMean);
        result.put("query_count_median", qMedian);

        // ── Export raw measurements (optional) ──────────────────────────────
        // Enabled by setting RAW_OUTPUT_DIR environment variable.
        // Used by analysis/analyse.py for exact Mann-Whitney U tests.
        // Output: {RAW_OUTPUT_DIR}/{scenario}.json
        String rawOutputDir = System.getenv("RAW_OUTPUT_DIR");
        if (rawOutputDir != null && !rawOutputDir.isEmpty()) {
            java.nio.file.Path rawDir = Paths.get(rawOutputDir);
            Files.createDirectories(rawDir);
            java.nio.file.Path rawFile = rawDir.resolve(scenario + ".json");
            // Write unsorted raw measurements (preserve original order)
            // Sort a copy for stats but keep originals for distribution analysis
            StringBuilder sb = new StringBuilder("[");
            for (int i = 0; i < measurements.size(); i++) {
                sb.append(Math.round(measurements.get(i) * 1000000.0) / 1000000.0);
                if (i < measurements.size() - 1) sb.append(",");
            }
            sb.append("]");
            Files.writeString(rawFile, sb.toString());
        }

        System.out.println(mapper.writerWithDefaultPrettyPrinter()
            .writeValueAsString(result));

        Connection.close();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static long computeQueryCountMean(List<Long> queryCounts) {
        OptionalDouble avg = queryCounts.stream()
            .filter(q -> q >= 0)
            .mapToLong(Long::longValue)
            .average();
        return avg.isPresent() ? (long) avg.getAsDouble() : -1L;
    }

    private static long computeQueryCountMedian(List<Long> queryCounts) {
        List<Long> valid = queryCounts.stream()
            .filter(q -> q >= 0)
            .sorted()
            .collect(java.util.stream.Collectors.toList());
        if (valid.isEmpty()) return -1L;
        return valid.get((int) Math.ceil(0.50 * valid.size()) - 1);
    }
}