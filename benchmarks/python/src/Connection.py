import os
from sqlalchemy import create_engine, text
from sqlalchemy.orm import sessionmaker, Session
from sqlalchemy.pool import QueuePool

# ── Database URL from environment variables ───────────────────────────────────
def get_database_url() -> str:
    host = os.getenv("DB_HOST", "localhost")
    port = os.getenv("DB_PORT", "5432")
    name = os.getenv("DB_NAME", "benchmark")
    user = os.getenv("DB_USER", "benchmark")
    password = os.getenv("DB_PASS", "benchmark")
    return f"postgresql+psycopg2://{user}:{password}@{host}:{port}/{name}"

# ── SQLAlchemy engine — shared across all scenarios ───────────────────────────
_engine = None
_SessionFactory = None

def get_engine():
    global _engine
    if _engine is None:
        _engine = create_engine(
            get_database_url(),
            poolclass=QueuePool,
            pool_size=10,
            max_overflow=0,
            echo=False,
        )
    return _engine

def get_session() -> Session:
    global _SessionFactory
    if _SessionFactory is None:
        _SessionFactory = sessionmaker(bind=get_engine())
    return _SessionFactory()

# ── Raw SQL connection — direct engine execution ──────────────────────────────
def get_raw_connection():
    return get_engine().connect()
