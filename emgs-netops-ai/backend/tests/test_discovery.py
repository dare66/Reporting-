"""Network range discovery, controls and inventory comparison."""
import time

from conftest import wait_scan

from app.diagnostics.simulated import SimulatedBackend


def plan(c, rng, **kw):
    return c.post("/api/discovery/plan", json={"range": rng, **kw})


def start(c, ref, confirm=True):
    return c.post(f"/api/discovery/{ref}/start", json={"confirm": confirm})


def test_plan_shows_scope_before_execution(admin):
    p = plan(admin, "10.1.57.xx", wildcard_octets="1-100").json()
    assert p["address_count"] == 100 and p["requires_confirmation"]
    assert {m["id"] for m in p["methods"]} >= {"icmp", "tcp", "arp", "reverse_dns"}
    assert p["ports"] == [22, 80, 443, 445, 3389]
    assert admin.get(f"/api/discovery/{p['reference']}").json()["status"] == "planned"


def test_plan_rejections(admin):
    assert plan(admin, "10.9.9.0/24").status_code == 403            # unapproved subnet
    assert plan(admin, "8.8.8.0/24").status_code == 403             # public
    assert plan(admin, "10.1.57.0/33").status_code == 422           # invalid CIDR
    r = plan(admin, "10.1.56.0/23")                                 # oversized for default /24 limit
    assert r.status_code == 403 and "maximum" in r.json()["detail"]
    assert plan(admin, "10.1.57.0/24", ports=[31337]).status_code == 403
    assert plan(admin, "10.1.57.0/24", methods=["os_fingerprint"]).status_code == 422  # not authorised for network


def test_start_requires_confirmation(admin):
    ref = plan(admin, "10.1.57.1-10").json()["reference"]
    assert start(admin, ref, confirm=False).status_code == 422
    assert start(admin, ref).status_code == 200
    assert wait_scan(admin, ref)["status"] == "completed"
    assert start(admin, ref).status_code == 409  # cannot re-run a finished plan


def test_full_discovery_and_comparison(admin):
    ref = plan(admin, "10.1.57.0/24").json()["reference"]
    start(admin, ref)
    scan = wait_scan(admin, ref)
    assert scan["status"] == "completed" and scan["responding"] == 11
    devs = {d["ip_address"]: d for d in scan["devices"]}
    db_host = devs["10.1.57.15"]  # ICMP blocked, still discovered
    assert not db_host["responded_icmp"] and db_host["responded_tcp"]
    assert devs["10.1.57.77"]["hostname"] is None  # unknown hostname is allowed
    rows = admin.get(f"/api/discovery/{ref}/comparison").json()["rows"]
    cls = {r["ip_address"]: r["classification"] for r in rows}
    assert cls["10.1.57.10"] == "Registered and Active"
    assert cls["10.1.57.60"] == "Registered but Not Responding"
    assert cls["10.1.57.40"] == "Hostname Mismatch"
    assert cls["10.1.57.77"] == "Unknown Device"
    assert cls["10.1.57.88"] == "Newly Discovered"
    assert cls["10.1.57.91"] == "Possible Unauthorised Asset"
    assert "not a finding of wrongdoing" in next(r for r in rows if r["ip_address"] == "10.1.57.91")["detail"]
    srv = next(s for s in admin.get("/api/inventory?q=SAMPLE-SRV-001").json())
    assert srv["last_discovered_at"]


def test_duplicate_ip_conflict_and_history_changes(admin):
    admin.post("/api/inventory", json={"name": "Second record", "ip_address": "10.1.57.10"})
    ref1 = plan(admin, "10.1.57.1-20").json()["reference"]
    start(admin, ref1)
    wait_scan(admin, ref1)
    cls = {r["ip_address"]: r["classification"] for r in admin.get(f"/api/discovery/{ref1}/comparison").json()["rows"]}
    assert cls["10.1.57.10"] == "Duplicate IP Conflict"
    ref2 = plan(admin, "10.1.57.1-20").json()["reference"]
    start(admin, ref2)
    wait_scan(admin, ref2)
    ch = admin.get(f"/api/discovery/{ref2}/changes").json()
    assert ch["previous_reference"] == ref1 and ch["appeared"] == [] and ch["disappeared"] == []


class SlowLab(SimulatedBackend):
    def __init__(self):
        super().__init__(delay=0.03)


def test_cancel_keeps_partial_results(admin):
    from app.services.diagnostics import set_backend_override
    set_backend_override(SlowLab())
    admin.put("/api/settings", json={"values": {"scan_concurrency": 2}})
    ref = plan(admin, "10.1.57.0/24", methods=["icmp", "tcp"]).json()["reference"]
    start(admin, ref)
    time.sleep(0.6)
    assert admin.post(f"/api/discovery/{ref}/control", json={"action": "cancel"}).status_code == 200
    scan = wait_scan(admin, ref)
    assert scan["status"] == "cancelled" and 0 < scan["progress"] < 254
    assert any("Cancelled" in n for n in scan["notes"])


def test_pause_and_resume(admin):
    from app.services.diagnostics import set_backend_override
    set_backend_override(SlowLab())
    admin.put("/api/settings", json={"values": {"scan_concurrency": 4}})
    ref = plan(admin, "10.1.57.1-40", methods=["icmp"]).json()["reference"]
    start(admin, ref)
    time.sleep(0.2)
    assert admin.post(f"/api/discovery/{ref}/control", json={"action": "pause"}).json()["status"] == "paused"
    assert start(admin, plan(admin, "10.1.57.41-50").json()["reference"]).status_code == 409  # one scan at a time
    assert admin.post(f"/api/discovery/{ref}/control", json={"action": "resume"}).json()["status"] == "running"
    assert wait_scan(admin, ref)["status"] == "completed"


class NoArpLab(SimulatedBackend):
    async def arp_table(self):
        return {}


def test_mac_unavailable(admin):
    from app.services.diagnostics import set_backend_override
    set_backend_override(NoArpLab())
    ref = plan(admin, "10.1.57.70-80").json()["reference"]
    start(admin, ref)
    scan = wait_scan(admin, ref)
    dev = scan["devices"][0]
    assert dev["ip_address"] == "10.1.57.77" and dev["mac_address"] is None
    row = admin.get(f"/api/discovery/{ref}/comparison").json()["rows"][0]
    assert row["classification"] == "Unknown Device" and "no MAC" in row["detail"]


def test_register_discovered_device(admin):
    ref = plan(admin, "10.1.57.85-90").json()["reference"]
    start(admin, ref)
    scan = wait_scan(admin, ref)
    dev = next(d for d in scan["devices"] if d["ip_address"] == "10.1.57.88")
    srv = admin.post(f"/api/discovery/devices/{dev['id']}/register", json={"category": "Network Device"}).json()
    assert srv["ip_address"] == "10.1.57.88" and srv["approved_ports"] == [80, 443] and srv["is_sample"]
    rows = admin.get(f"/api/discovery/{ref}/comparison").json()["rows"]
    assert next(r for r in rows if r["ip_address"] == "10.1.57.88")["reviewed"]


def test_websocket_progress(admin):
    ref = plan(admin, "10.1.57.1-30").json()["reference"]
    with admin.websocket_connect(f"/ws/scans/{ref}") as ws:
        assert ws.receive_json()["type"] == "snapshot"
        start(admin, ref)
        events = []
        while True:
            e = ws.receive_json()
            events.append(e)
            if e["type"] == "finished":
                break
    assert events[-1]["status"] == "completed" and any(e["type"] == "progress" for e in events)


def test_websocket_rejects_foreign_origin(admin):
    import pytest
    from starlette.websockets import WebSocketDisconnect
    with pytest.raises(WebSocketDisconnect):
        with admin.websocket_connect("/ws/console", headers={"origin": "http://evil.example"}) as ws:
            ws.receive_json()


def test_websocket_console(admin):
    csrf = admin.headers["X-CSRF-Token"]
    with admin.websocket_connect("/ws/console") as ws:
        ws.send_json({"type": "run", "diagnostic": "ping", "params": {"target": "10.1.57.10"}, "csrf": csrf, "id": 1})
        assert ws.receive_json()["type"] == "started"
        assert ws.receive_json()["result"]["outcome"] == "Successful"
        ws.send_json({"type": "run", "diagnostic": "ping", "params": {"target": "10.1.57.10"}, "csrf": "x", "id": 2})
        assert ws.receive_json()["type"] == "error"
