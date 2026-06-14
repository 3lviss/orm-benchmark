/**
 * Counts SQL queries executed during a single benchmark iteration.
 *
 * Usage:
 *   QueryCounter.reset();    // before each iteration
 *   // ... run scenario ...
 *   QueryCounter.get();      // after each iteration
 */
export class QueryCounter {
  private static count: number = 0;

  static reset(): void {
    QueryCounter.count = 0;
  }

  static increment(): void {
    QueryCounter.count++;
  }

  static get(): number {
    return QueryCounter.count;
  }
}
