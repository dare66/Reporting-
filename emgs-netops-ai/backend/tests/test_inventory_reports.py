"""Inventory CRUD, Excel import/export and all report types."""
import io

import pytest
from openpyxl import load_workbook

from conftest import wait_scan


def test_sample_data_is_labelled(admin):
    servers = admin.get("/api/inventory").json()
    assert servers and all(s["is_sample"] and s["name"].startswith("SAMPLE") for s in servers)


def test_crud_and_delete_confirmation(admin):
    s = admin.post("/api/inventory", json={"name": "Core DB", "ip_address": "10.1.57.200", "hostname": "db.emgs.local",
                                           "approved_ports": [3306], "criticality": "High"}).json()
    assert s["server_code"].startswith("SRV-") and not s["is_sample"]
    assert admin.post("/api/inventory", json={"name": "x", "ip_address": "999.1.1.1"}).status_code == 422
    assert admin.post("/api/inventory", json={"name": "x", "ip_address": "10.1.1.1", "criticality": "Huge"}).status_code == 422
    u = admin.put(f"/api/inventory/{s['id']}", json={"status": "Inactive"}).json()
    assert u["status"] == "Inactive"
    assert admin.delete(f"/api/inventory/{s['id']}").status_code == 422
    assert admin.delete(f"/api/inventory/{s['id']}?confirm={s['server_code']}").status_code == 200
    assert admin.get(f"/api/inventory/{s['id']}").status_code == 404


def test_search_and_filter(admin):
    assert len(admin.get("/api/inventory?q=stars").json()) == 2
    assert all(s["category"] == "Database" for s in admin.get("/api/inventory?category=Database").json())
    assert all(s["status"] == "Inactive" for s in admin.get("/api/inventory?status=Inactive").json())


def test_excel_round_trip(admin):
    data = admin.get("/api/inventory/export").content
    wb = load_workbook(io.BytesIO(data))
    ws = wb.active
    assert ws.cell(1, 1).value == "Server ID"
    ws.append(["", "Imported server", "imp.emgs.local", "10.1.57.210", "Production", "Linux", "CRM",
               "Application Server", "Ops", "Biz", "Low", "22,443", "From Excel", "", "", "Active", "No"])
    ws.append(["", "Broken", "", "not-an-ip"])
    buf = io.BytesIO()
    wb.save(buf)
    dry = admin.post("/api/inventory/import?dry_run=true", files={"file": ("inv.xlsx", buf.getvalue())}).json()
    assert dry["dry_run"] and dry["created"] == 1 and len(dry["errors"]) == 1
    assert not admin.get("/api/inventory?q=Imported").json()
    res = admin.post("/api/inventory/import", files={"file": ("inv.xlsx", buf.getvalue())}).json()
    assert res["created"] == 1 and res["updated"] == 8 and res["errors"][0]["row"] == 11
    imp = admin.get("/api/inventory?q=Imported").json()[0]
    assert imp["approved_ports"] == [22, 443] and imp["application"] == "CRM"
    assert admin.post("/api/inventory/import", files={"file": ("x.xlsx", b"not excel")}).status_code == 422


def _populate(admin):
    for t in ("10.1.57.10", "10.1.57.15"):
        admin.post("/api/diagnostics/ping", json={"target": t})
        admin.post("/api/diagnostics/traceroute", json={"target": t})
        admin.post("/api/diagnostics/port", json={"target": t, "ports": [22, 443, 3306]})
    admin.post("/api/diagnostics/dns", json={"domain": "emgs.com.my"})
    admin.post("/api/diagnostics/ssl", json={"target": "visa.emgs.com.my"})
    ref = admin.post("/api/discovery/plan", json={"range": "10.1.57.0/24"}).json()["reference"]
    admin.post(f"/api/discovery/{ref}/start", json={"confirm": True})
    wait_scan(admin, ref)


REPORT_TYPES = ["ping", "traceroute", "ports", "dns", "ssl", "discovery", "inventory", "unknown_devices", "consolidated"]


@pytest.mark.parametrize("fmt", ["pdf", "xlsx"])
def test_all_reports_generate(admin, fmt):
    _populate(admin)
    for rtype in REPORT_TYPES:
        r = admin.post("/api/reports", json={"report_type": rtype, "format": fmt})
        assert r.status_code == 200, (rtype, r.text)
        dl = admin.get(r.json()["download_url"])
        assert dl.status_code == 200 and len(dl.content) > 1500
        if fmt == "pdf":
            assert dl.content.startswith(b"%PDF")
        else:
            wb = load_workbook(io.BytesIO(dl.content))
            summary = {row[0]: row[1] for row in wb["Summary"].iter_rows(values_only=True) if row and row[0]}
            for field in ("Report reference", "Execution date and time", "Requesting user",
                          "Source laptop / network interface", "Destination or subnet", "Test methodology"):
                assert field in summary, (rtype, field)
            assert summary["Requesting user"] == "admin"
            assert any("SIMULATION" in str(r[0]) or "SAMPLE" in str(r[0])
                       for r in wb["Summary"].iter_rows(values_only=True) if r and r[0])
    assert len(admin.get("/api/reports").json()) == len(REPORT_TYPES)


def test_report_invalid_type(admin):
    assert admin.post("/api/reports", json={"report_type": "nope"}).status_code == 422
    assert admin.get("/api/reports/RP-X/download").status_code == 404
