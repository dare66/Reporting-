"""Diagnostics through the API in Simulation mode, plus Live-mode edge cases with a fake backend."""
import asyncio

import pytest

from app.diagnostics.simulated import SimulatedBackend


def run(c, dtype, **body):
    return c.post(f"/api/diagnostics/{dtype}", json=body)


def test_ping_outcomes(admin):
    assert run(admin, "ping", target="10.1.57.10").json()["outcome"] == "Successful"
    r = run(admin, "ping", target="10.1.57.25").json()
    assert r["outcome"] == "Partial packet loss" and 0 < r["result"]["packet_loss_pct"] < 100
    r = run(admin, "ping", target="10.1.57.15").json()
    assert r["outcome"] == "No ICMP response"
    assert "does not prove" in r["assessment"]["summary"]
    assert not any("firewall" in c.lower() for c in r["assessment"]["confirmed"]), "firewall must not be confirmed"
    r = run(admin, "ping", target="missing.emgs.com.my").json()
    assert r["outcome"] == "DNS resolution failure"
    r = run(admin, "ping", target="10.1.57.10", count=2).json()
    assert r["result"]["packets_transmitted"] == 2 and r["simulated"] is True


def test_ping_parameter_limits(admin):
    assert run(admin, "ping", target="10.1.57.10", count=500).status_code == 422
    assert run(admin, "ping", target="10.1.57.10", timeout=99).status_code == 422


def test_traceroute(admin):
    ok = run(admin, "traceroute", target="10.1.57.10").json()
    assert ok["result"]["destination_reached"] and any(h["status"] == "timeout" for h in ok["result"]["hops"])
    assert "normal" in " ".join(ok["assessment"]["possible_causes"])  # intermediate timeout explained, not alarming
    blocked = run(admin, "traceroute", target="10.1.57.15").json()
    assert blocked["outcome"] == "Destination did not respond"
    assert "does not confirm" in blocked["assessment"]["summary"]


def test_ports_open_closed_filtered_invalid(admin):
    r = run(admin, "port", target="10.1.57.15", ports=[3306, 443, 22]).json()
    st = {x["port"]: x["status"] for x in r["result"]["results"]}
    assert st == {3306: "open", 443: "closed", 22: "filtered"}
    assert run(admin, "port", target="10.1.57.10", port=0).status_code == 422
    assert run(admin, "port", target="10.1.57.10", port=31337).status_code == 403
    r = run(admin, "port", target="10.1.57.10", ports="approved").json()
    assert len(r["result"]["results"]) == 11


def test_dns(admin):
    r = run(admin, "dns", domain="emgs.com.my", record_type="NS").json()
    assert r["result"]["values"] and r["result"]["record_type"] == "NS"
    r = run(admin, "dns", domain="10.1.57.10").json()
    assert r["result"]["record_type"] == "PTR" and "sample-stars-app01" in r["result"]["values"][0]
    assert run(admin, "dns", domain="emgs.com.my", record_type="ANY").status_code == 422
    assert run(admin, "dns", domain="google.com").status_code == 403


def test_http_https_ssl(admin):
    r = run(admin, "http", url="http://visa.emgs.com.my/").json()
    assert r["result"]["status_code"] == 200 and r["result"]["redirects"][0]["status"] == 301
    assert run(admin, "https", url="http://visa.emgs.com.my").status_code == 422
    assert run(admin, "http", url="ftp://visa.emgs.com.my").status_code == 422
    assert run(admin, "http", url="https://user:pw@visa.emgs.com.my").status_code == 422
    assert run(admin, "ssl", target="sample-printer-3f.lab.local").status_code == 403  # not approved
    exp = run(admin, "ssl", target="10.1.57.88").json()
    assert exp["outcome"] == "Expired" and exp["assessment"]["severity"] == "error"
    soon = run(admin, "ssl", target="visa.emgs.com.my").json()
    assert soon["outcome"] == "Expiring soon" and soon["result"]["days_remaining"] == 21
    mism = run(admin, "ssl", target="sample-hub01.lab.local").json()
    assert mism["outcome"] == "Hostname mismatch"
    assert "private" not in str(mism["result"]).lower()


def test_history_saved(admin):
    ref = run(admin, "ping", target="10.1.57.10").json()["reference"]
    assert admin.get(f"/api/history/{ref}").json()["raw_output"].startswith("PING")
    assert any(j["reference"] == ref for j in admin.get("/api/history?type=ping").json())


def test_review_mode_is_read_only(admin):
    admin.put("/api/settings", json={"values": {"operating_mode": "review"}})
    r = run(admin, "ping", target="10.1.57.10")
    assert r.status_code == 409 and "Review mode" in r.json()["detail"]
    assert admin.get("/api/history").status_code == 200


def test_live_mode_requires_real_approved_network(admin):
    admin.put("/api/settings", json={"values": {"operating_mode": "live"}})
    assert run(admin, "ping", target="10.1.57.10").status_code == 403  # sample network ignored in live mode
    assert admin.post("/api/policy/networks", json={"name": "Public", "cidr": "8.8.8.0/24"}).status_code == 422


class SlowBackend(SimulatedBackend):
    async def ping(self, *a, **k):
        await asyncio.sleep(10)


class BrokenBackend(SimulatedBackend):
    async def traceroute(self, *a, **k):
        from app.diagnostics.live import CommandUnavailable
        raise CommandUnavailable("Neither 'traceroute' nor 'tracepath' is installed on this laptop.")


def test_execution_timeout_recorded_as_failure(admin):
    from app.services.diagnostics import set_backend_override
    admin.put("/api/settings", json={"values": {"execution_timeout_s": 5}})
    set_backend_override(SlowBackend())
    import time
    t = time.time()
    r = run(admin, "ping", target="10.1.57.10").json()
    assert time.time() - t < 8
    assert r["status"] == "failed" and r["outcome"] == "Execution error" and "time limit" in r["error"]


def test_missing_tool_reported_clearly(admin):
    from app.services.diagnostics import set_backend_override
    set_backend_override(BrokenBackend())
    r = run(admin, "traceroute", target="10.1.57.10").json()
    assert r["status"] == "failed" and "is installed on this laptop" in r["result"]["error"]


@pytest.mark.asyncio
async def test_live_backend_tcp_open_and_closed():
    """Real sockets against a local listener (bypasses policy; tests the adapter only)."""
    from app.diagnostics.live import LiveBackend
    server = await asyncio.start_server(lambda r, w: w.close(), "127.0.0.1", 0)
    port = server.sockets[0].getsockname()[1]
    b = LiveBackend()
    assert (await b.tcp_connect("127.0.0.1", port, 2))["state"] == "open"
    server.close()
    await server.wait_closed()
    assert (await b.tcp_connect("127.0.0.1", port, 2))["state"] == "closed"


@pytest.mark.asyncio
async def test_live_backend_runs_without_shell():
    from app.diagnostics.live import CommandUnavailable, run_command
    with pytest.raises(CommandUnavailable):
        await run_command(["definitely-not-a-real-binary"], 2)
    code, out = await run_command(["echo", "hello; rm -rf /"], 5)
    assert out.strip() == "hello; rm -rf /"  # passed as one literal argument, never interpreted
