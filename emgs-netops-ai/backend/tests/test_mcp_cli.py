"""MCP server (real stdio protocol), shared tool functions and the CLI."""
import json
import os
import subprocess
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "mcp-server"))

EXPECTED_TOOLS = {"run_ping", "run_traceroute", "check_tcp_port", "check_multiple_ports", "check_dns", "check_http",
                  "check_https", "check_ssl_certificate", "scan_ip_range", "discover_hosts", "get_server_inventory",
                  "search_server_inventory", "get_diagnostic_history", "generate_diagnostic_report"}


@pytest.fixture
def token(admin, monkeypatch):
    tok = admin.post("/api/auth/tokens", json={"name": "mcp-test"}).json()["token"]
    monkeypatch.setenv("NETOPS_API_TOKEN", tok)
    from tools import netops_tools
    netops_tools._ready = False
    return tok


async def test_tools_require_valid_token(monkeypatch, admin):
    from tools import netops_tools as t
    monkeypatch.setenv("NETOPS_API_TOKEN", "nop_bogus")
    r = await t.run_ping("10.1.57.10")
    assert r["ok"] is False and r["error_type"] == "AuthenticationRequired"


async def test_tool_functions(token, admin):
    from tools import netops_tools as t
    r = await t.run_ping("10.1.57.10")
    assert r["ok"] and r["source_interface"] == "mcp" and r["outcome"] == "Successful"
    assert (await t.check_tcp_port("10.1.57.15", 3306))["result"]["results"][0]["status"] == "open"
    bad = await t.check_tcp_port("8.8.8.8", 443)
    assert bad["ok"] is False and bad["error_type"] == "PolicyError"
    assert (await t.check_dns("emgs.com.my", "MX"))["ok"]
    assert (await t.check_ssl_certificate("visa.emgs.com.my"))["outcome"] == "Expiring soon"
    plan = await t.scan_ip_range("10.1.57.1-20")
    assert plan["stage"] == "plan" and plan["plan"]["address_count"] == 20
    res = await t.scan_ip_range("10.1.57.1-20", confirm=True, plan_reference=plan["plan"]["reference"], wait_seconds=60)
    assert res["stage"] == "result" and res["status"] == "completed" and res["comparison"]
    hosts = await t.discover_hosts(scan_reference=res["reference"])
    assert {"ip_address", "open_ports"} <= set(hosts["devices"][0])
    assert (await t.search_server_inventory("stars"))["count"] == 2
    hist = await t.get_diagnostic_history("ping")
    assert hist["count"] >= 1
    rep = await t.generate_diagnostic_report("discovery", "xlsx", scan_reference=res["reference"])
    assert rep["ok"] and Path(rep["file_path"]).exists()
    audit = admin.get("/api/audit?action=diagnostic").json()
    assert any(a["interface"] == "mcp" for a in audit)


def test_mcp_stdio_protocol(token, fresh_env):
    """Spawn the real MCP server and talk to it over stdio."""
    import anyio
    from mcp import ClientSession
    from mcp.client.stdio import StdioServerParameters, stdio_client

    env = {**os.environ, "NETOPS_API_TOKEN": token}
    params = StdioServerParameters(command=sys.executable, args=[str(ROOT / "mcp-server" / "server.py")], env=env)

    async def scenario():
        async with stdio_client(params) as (read, write):
            async with ClientSession(read, write) as session:
                await session.initialize()
                tools = {tl.name for tl in (await session.list_tools()).tools}
                assert tools == EXPECTED_TOOLS
                assert not any("exec" in n or "shell" in n or "command" in n for n in tools)
                res = await session.call_tool("check_tcp_port", {"target": "10.1.57.15", "port": 3306})
                payload = res.structured_content or json.loads(res.content[0].text)
                payload = payload.get("result", payload) if "ok" not in payload else payload
                assert payload["ok"] and payload["outcome"] == "Open"
                plan = await session.call_tool("scan_ip_range", {"ip_range": "10.1.57.0/24"})
                pp = plan.structured_content or json.loads(plan.content[0].text)
                pp = pp.get("result", pp) if "stage" not in pp else pp
                assert pp["stage"] == "plan"

    anyio.run(scenario)


def test_cli(token):
    env = {**os.environ}
    out = subprocess.run([sys.executable, str(ROOT / "scripts" / "netops_cli.py"), "port", "10.1.57.15", "3306", "22"],
                         capture_output=True, text=True, env=env, timeout=60)
    assert out.returncode == 0, out.stderr
    data = json.loads(out.stdout)
    assert data["source_interface"] == "cli"
    assert {r["port"]: r["status"] for r in data["result"]["results"]} == {22: "filtered", 3306: "open"}
    bad = subprocess.run([sys.executable, str(ROOT / "scripts" / "netops_cli.py"), "ping", "10.1.57.10; whoami"],
                         capture_output=True, text=True, env=env, timeout=60)
    assert bad.returncode == 1 and json.loads(bad.stdout)["error_type"] == "ValidationError"
