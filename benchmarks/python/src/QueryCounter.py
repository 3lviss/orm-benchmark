"""
Counts SQL queries executed during a single benchmark iteration.

SQLAlchemy fires a before_cursor_execute event for every statement
sent to the database. Connection.py attaches _on_query() as a listener
to that event, which calls increment() here.

Usage:
    QueryCounter.reset()    # before each iteration
    # ... run scenario ...
    QueryCounter.get()      # after each iteration
"""

class QueryCounter:
    _count: int = 0

    @classmethod
    def reset(cls) -> None:
        cls._count = 0

    @classmethod
    def increment(cls) -> None:
        cls._count += 1

    @classmethod
    def get(cls) -> int:
        return cls._count