"""Authentication, sessions, CSRF, API tokens, roles and rate limiting."""
from datetime import datetime, timedelta

from conftest import ADMIN, _login


def test_health_reports_setup_required(client):
    r = client.get("/api/health").json()
    assert r["setup_required"] is True and r["mode"] == "simulation"


def test_setup_once_and_password_policy(client):
    weak = client.post("/api/auth/setup", json={"username": "admin", "password": "short"})
    assert weak.status_code == 422 and "12 characters" in weak.json()["detail"]
    assert client.post("/api/auth/setup", json=ADMIN).status_code == 200
    again = client.post("/api/auth/setup", json={**ADMIN, "username": "other"})
    assert again.status_code == 409


def test_unauthenticated_requests_rejected(client):
    assert client.get("/api/dashboard").status_code == 401
    assert client.post("/api/diagnostics/ping", json={"target": "10.1.57.10"}).status_code == 401


def test_csrf_required_for_cookie_sessions(admin):
    token = admin.headers.pop("X-CSRF-Token")
    assert admin.post("/api/diagnostics/ping", json={"target": "10.1.57.10"}).status_code == 403
    admin.headers["X-CSRF-Token"] = "wrong"
    assert admin.post("/api/diagnostics/ping", json={"target": "10.1.57.10"}).status_code == 403
    admin.headers["X-CSRF-Token"] = token
    assert admin.post("/api/diagnostics/ping", json={"target": "10.1.57.10"}).status_code == 200


def test_login_failure_and_lockout(admin):
    admin.cookies.clear()
    for _ in range(5):
        assert admin.post("/api/auth/login", json={"username": "admin", "password": "bad"}).status_code == 401
    r = admin.post("/api/auth/login", json={"username": "admin", "password": ADMIN["password"]})
    assert r.status_code == 401 and "locked" in r.json()["detail"]


def test_expired_session_rejected(admin, db):
    from app.models import entities as m
    for s in db.query(m.UserSession).all():
        s.expires_at = datetime.utcnow() - timedelta(minutes=1)
    db.commit()
    assert admin.get("/api/auth/me").status_code == 401


def test_logout_revokes_session(admin):
    assert admin.post("/api/auth/logout").status_code == 200
    assert admin.get("/api/auth/me").status_code == 401


def test_api_token_auth(admin):
    tok = admin.post("/api/auth/tokens", json={"name": "mcp"}).json()["token"]
    admin.cookies.clear()
    del admin.headers["X-CSRF-Token"]
    bad = admin.get("/api/dashboard", headers={"Authorization": "Bearer nop_invalid"})
    assert bad.status_code == 401
    ok = admin.post("/api/diagnostics/ping", json={"target": "10.1.57.10"},
                    headers={"Authorization": f"Bearer {tok}", "X-Client-Interface": "mcp"})
    assert ok.status_code == 200 and ok.json()["source_interface"] == "mcp"


def test_revoked_token_rejected(admin):
    r = admin.post("/api/auth/tokens", json={"name": "t"}).json()
    admin.delete(f"/api/auth/tokens/{r['id']}")
    assert admin.get("/api/dashboard", headers={"Authorization": f"Bearer {r['token']}"}).status_code in (401, 200)
    admin.cookies.clear()
    assert admin.get("/api/dashboard", headers={"Authorization": f"Bearer {r['token']}"}).status_code == 401


def test_viewer_cannot_run_diagnostics_or_change_settings(client, make_user):
    viewer = make_user("viewer")
    client.cookies.clear()
    _login(client, viewer)
    assert client.get("/api/dashboard").status_code == 200
    assert client.post("/api/diagnostics/ping", json={"target": "10.1.57.10"}).status_code == 403
    assert client.put("/api/settings", json={"values": {"default_ping_count": 2}}).status_code == 403
    assert client.get("/api/audit").status_code == 403
    r = client.post("/api/assistant/message", json={"message": "ping 10.1.57.10"}).json()
    assert r["error_type"] == "RoleError"


def test_operator_cannot_change_policy(client, make_user):
    op = make_user("operator")
    client.cookies.clear()
    _login(client, op)
    assert client.post("/api/diagnostics/ping", json={"target": "10.1.57.10"}).status_code == 200
    assert client.post("/api/policy/ports", json={"port": 23}).status_code == 403


def test_rate_limit_excessive_requests(admin):
    admin.put("/api/settings", json={"values": {"rate_limit_per_minute": 5}})
    codes = [admin.post("/api/diagnostics/ping", json={"target": "10.1.57.10", "count": 1}).status_code
             for _ in range(7)]
    assert codes[:5] == [200] * 5 and codes[5] == 429


def test_security_headers(client):
    r = client.get("/api/health")
    assert r.headers["x-frame-options"] == "DENY"
    assert "default-src 'self'" in r.headers["content-security-policy"]
    assert r.headers["cache-control"] == "no-store"


def test_ai_key_is_never_returned(admin, db):
    r = admin.put("/api/ai", json={"api_key": "sk-ant-secret-value", "enabled": False}).json()
    assert "sk-ant" not in str(r) and r["configured"] is True
    from app.models import entities as m
    stored = db.query(m.AiSetting).first().api_key_encrypted
    assert stored and "sk-ant-secret-value" not in stored


def test_audit_trail_records_diagnostics(admin):
    admin.post("/api/diagnostics/port", json={"target": "10.1.57.15", "port": 3306})
    admin.post("/api/diagnostics/ping", json={"target": "8.8.8.8"})
    rows = admin.get("/api/audit").json()
    port = next(r for r in rows if r["action"] == "diagnostic.port")
    assert port["user"] == "admin" and port["destination"] == "10.1.57.15" and port["port"] == 3306
    assert port["reference"].startswith("DX-") and port["source_network"] and port["interface"] in ("web", "mobile")
    rej = next(r for r in rows if r["action"] == "diagnostic.ping" and r["status"] == "rejected")
    assert rej["destination"] == "8.8.8.8"
    csv = admin.get("/api/audit/export")
    assert csv.status_code == 200 and "diagnostic.port" in csv.text
