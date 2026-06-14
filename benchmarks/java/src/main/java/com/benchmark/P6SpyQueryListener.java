package com.benchmark;

import com.p6spy.engine.logging.Category;
import com.p6spy.engine.spy.appender.StdoutLogger;

/**
 * P6Spy appender that increments QueryCounter on every SQL statement.
 * Suppresses all log output — only counts queries.
 *
 * Registered in spy.properties as the sole appender.
 */
public class P6SpyQueryListener extends StdoutLogger {

    @Override
    public void logSQL(int connectionId, String now, long elapsed,
                       Category category, String prepared, String sql, String url) {
        // Count every SQL statement sent to the database
        if (sql != null && !sql.isEmpty()) {
            QueryCounter.increment();
        }
        // Do NOT call super — suppresses all stdout output
    }

    @Override
    public boolean isCategoryEnabled(Category category) {
        return true;
    }
}
