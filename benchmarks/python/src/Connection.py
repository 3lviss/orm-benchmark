import os
from sqlalchemy import create_engine, event
from sqlalchemy.orm import sessionmaker, Session
from sqlalchemy.pool import QueuePool

from QueryCounter import QueryCounter

# ── Database URL from environment variables ───────────────────────────────────
def get_database_url() -> str:
    host = os.getenv("DB_HOST", "localhost")
    port = os.getenv("DB_PORT", "5432")
    name = os.getenv("DB_NAME", "benchmark")
    user = os.getenv("DB_USER", "benchmark")
    password = os.getenv("DB_PASS", "benchmark")
    return f"postgresql+psycopg2://{user}:{password}@{host}:{port}/{name}"

# ── Query counter event listener ──────────────────────────────────────────────
def _on_query(conn, cursor, statement, parameters, context, executemany) -> None:
    """
    Fired by SQLAlchemy before every statement is sent to the database.
    Attached once to the engine via event.listen() in _create_engine().
    """
    QueryCounter.increment()

# ── SQLAlchemy engine — shared across all scenarios ───────────────────────────
_engine = None
_SessionFactory = None

def _create_engine():
    engine = create_engine(
        get_database_url(),
        poolclass=QueuePool,
        pool_size=10,
        max_overflow=0,
        echo=False,
    )
    # Attach query counter listener once at engine creation
    event.listen(engine, "before_cursor_execute", _on_query)
    return engine

def get_engine():
    global _engine
    if _engine is None:
        _engine = _create_engine()
    return _engine

def get_session() -> Session:
    global _SessionFactory
    if _SessionFactory is None:
        _SessionFactory = sessionmaker(bind=get_engine())
    return _SessionFactory()

# ── Raw SQL connection — direct engine execution ──────────────────────────────
def get_raw_connection():
    return get_engine().connect()
