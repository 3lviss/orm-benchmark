package com.benchmark;
 
import java.util.concurrent.atomic.AtomicLong;
 
/**
 * Counts SQL queries executed during a single benchmark iteration.
 *
 * Uses AtomicLong to be safe across Hibernate's internal threading.
 *
 * Usage:
 *   QueryCounter.reset();       // before each iteration
 *   // ... run scenario ...
 *   QueryCounter.get();         // after each iteration
 */
public class QueryCounter {
 
    private static final AtomicLong count = new AtomicLong(0);
 
    public static void reset() {
        count.set(0);
    }
 
    public static void increment() {
        count.incrementAndGet();
    }
 
    public static long get() {
        return count.get();
    }
}
