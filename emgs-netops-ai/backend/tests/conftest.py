import os
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ["NETOPS_TESTING"] = "1"

ADMIN = {"username": "admin", "password": "Str0ng!Passw0rd", "full_name": "Test Admin"}


@pytest.fixture(autouse=True)
def fresh_env(tmp_path, monkeypatch):
    monkeypatch.setenv("NETOPS_DATA_DIR", str(tmp_path / "data"))
    from app.core import config
    from app.database import session
    from app.security.ratelimit import limiter
    from app.services import diagnostics
    session.dispose_engine()
    config.reset_settings()
    limiter.reset()
    diagnostics.set_backend_override(None)
    yield tmp_path
    diagnostics.set_backend_override(None)
    session.dispose_engine()


@pytest.fixture
def client():
    from fastapi.testclient import TestClient

    from app.main import app
    with TestClient(app) as c:
        yield c


def _login(client, creds):
    r = client.post("/api/auth/login", json={"username": creds["username"], "password": creds["password"]})
    assert r.status_code == 200, r.text
    client.headers["X-CSRF-Token"] = r.json()["csrf_token"]
    return client


@pytest.fixture
def admin(client):
    r = client.post("/api/auth/setup", json=ADMIN)
    assert r.status_code == 200, r.text
    client.headers["X-CSRF-Token"] = r.json()["csrf_token"]
    client.put("/api/settings", json={"values": {"scan_rate_per_second": 500, "rate_limit_per_minute": 1000}})
    return client


@pytest.fixture
def make_user(admin):
    def _make(role: str, username: str | None = None):
        u = {"username": username or f"{role}user", "password": "An0ther!Passw0rd", "roles": [role]}
        assert admin.post("/api/users", json=u).status_code == 200
        return u
    return _make


@pytest.fixture
def db():
    from app.database.migrations import run_migrations
    from app.database.session import SessionLocal
    run_migrations()
    s = SessionLocal()
    yield s
    s.close()


@pytest.fixture
def ctx():
    from app.core.context import ExecutionContext
    return ExecutionContext(1, "tester", ["admin"], "cli", "127.0.0.1")


def wait_scan(client, ref, timeout=30):
    import time
    end = time.time() + timeout
    while time.time() < end:
        s = client.get(f"/api/discovery/{ref}").json()
        if s["status"] not in ("running", "paused", "cancelling", "planned"):
            return s
        time.sleep(0.1)
    raise AssertionError(f"scan {ref} did not finish: {s['status']}")
