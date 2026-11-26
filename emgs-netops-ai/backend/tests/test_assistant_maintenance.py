"""Assistant flows, backup/restore and retention."""
from datetime import datetime, timedelta


def say(c, text, pending=None):
    r = c.post("/api/assistant/message", json={"message": text, "pending": pending})
    assert r.status_code == 200, r.text
    return r.json()


def test_assistant_executes_and_explains(admin):
    r = say(admin, "Check whether port 3306 is accessible on 10.1.57.15.")
    assert r["intent"] == "port" and r["results"][0]["result"]["results"][0]["status"] == "open"
    assert r["source"] == "rules" and r["explanation"]["confirmed"]
    assert r["results"][0]["source_interface"] == "assistant"


def test_assistant_asks_for_missing_information(admin):
    r = say(admin, "Check port 443.")
    assert r["pending"] and "Which server" in r["reply"] and not r["results"]
    r2 = say(admin, "10.1.57.10", r["pending"])
    assert r2["intent"] == "port" and r2["results"][0]["target"] == "10.1.57.10"


def test_assistant_discovery_requires_confirmation(admin):
    r = say(admin, "Discover all active servers in 10.1.57.xx.")
    assert r["action"] == "confirm_discovery" and r["plan"]["address_count"] == 254
    scans = admin.get("/api/discovery").json()
    assert scans[0]["status"] == "planned"


def test_assistant_application_lookup_and_explain(admin):
    r = say(admin, "Test connectivity to Visa Website.")
    assert r["results"] and r["results"][0]["type"] == "https"
    r = say(admin, "Explain why STARS is not accessible.")
    assert {x["target"] for x in r["results"]} == {"10.1.57.10", "10.1.57.15"}
    assert r["explanation"]["possible_causes"] and r["explanation"]["recommendations"]


def test_assistant_compare_and_report_and_refusals(admin):
    r = say(admin, "Compare 10.1.57.10 and 10.1.57.15")
    assert len(r["comparison"]) == 2
    r = say(admin, "Generate an infrastructure connectivity report.")
    assert r["report"]["report_type"] == "consolidated"
    r = say(admin, "ping 8.8.8.8")
    assert r["error_type"] == "PolicyError"


def test_backup_restore(admin):
    admin.post("/api/inventory", json={"name": "Before backup", "ip_address": "10.1.57.230"})
    b = admin.post("/api/maintenance/backups").json()
    admin.post("/api/inventory", json={"name": "After backup", "ip_address": "10.1.57.231"})
    assert admin.post("/api/maintenance/restore", json={"file_name": b["file_name"]}).status_code == 422
    assert admin.post("/api/maintenance/restore", json={"file_name": "../../etc/passwd", "confirm": True}).status_code == 422
    r = admin.post("/api/maintenance/restore", json={"file_name": b["file_name"], "confirm": True}).json()
    assert r["safety_backup"].endswith("pre-restore.db")
    names = [s["name"] for s in admin.get("/api/inventory").json()]
    assert "Before backup" in names and "After backup" not in names
    assert len(admin.get("/api/maintenance/backups").json()) == 2


def test_retention(admin, db):
    from app.models import entities as m
    admin.post("/api/diagnostics/ping", json={"target": "10.1.57.10"})
    job = db.query(m.DiagnosticJob).first()
    job.started_at = datetime.utcnow() - timedelta(days=400)
    db.commit()
    r = admin.post("/api/maintenance/retention").json()
    assert r["diagnostic_jobs"] == 1


def test_settings_validation(admin):
    assert admin.put("/api/settings", json={"values": {"operating_mode": "chaos"}}).status_code == 422
    assert admin.put("/api/settings", json={"values": {"scan_concurrency": 10000}}).status_code == 422
    assert admin.put("/api/settings", json={"values": {"unknown": 1}}).status_code == 422
    assert admin.put("/api/settings", json={"values": {"default_ping_count": 6}}).json()["default_ping_count"] == 6


def test_dashboard(admin):
    admin.post("/api/diagnostics/ping", json={"target": "10.1.57.15"})
    admin.post("/api/diagnostics/ssl", json={"target": "visa.emgs.com.my"})
    d = admin.get("/api/dashboard").json()
    assert d["simulated"] and d["totals"]["registered_assets"] == 7
    assert d["ssl_expiring"][0]["days_remaining"] == 21
    assert d["recent_failures"][0]["outcome"] == "No ICMP response"
