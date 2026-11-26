"""NetOps tool functions shared by the MCP server (Claude Code / Codex) and the CLI.

Every function calls the same validated backend services as the web
dashboard: validation, approved-target policy, roles, rate limits, timeouts,
history and audit are identical. There is deliberately no tool that runs an
arbitrary command.

Identity: the caller authenticates with an API token created in the
dashboard (Settings > API tokens) via NETOPS_API_TOKEN. Executions are
audited under that user with interface "mcp" (or "cli").
"""
from __future__ import annotations

import os
import sys
from pathlib import Path

BACKEND = Path(__file__).resolve().parents[2] / "backend"
if str(BACKEND) not in sys.path:
    sys.path.insert(0, str(BACKEND))

from sqlalchemy import select  # noqa: E402

from app.core.context import ExecutionContext  # noqa: E402
from app.database.migrations import run_migrations  # noqa: E402
from app.database.session import session_scope  # noqa: E402
from app.discovery import engine  # noqa: E402
from app.models import entities as m  # noqa: E402
from app.reports import builder  # noqa: E402
from app.security.policy import PolicyError  # noqa: E402
from app.security.validation import ValidationError  # noqa: E402
from app.services import auth, history, inventory  # noqa: E402
from app.services.diagnostics import ModeError, RateLimitError, RoleError, run_diagnostic  # noqa: E402

EXPECTED = (ValidationError, PolicyError, ModeError, RoleError, RateLimitError, engine.ScanStateError,
            PermissionError)
_ready = False


class AuthenticationRequired(RuntimeError):
    pass


def _context(interface: str) -> ExecutionContext:
    global _ready
    if not _ready:
        run_migrations()
        _ready = True
    token = os.environ.get("NETOPS_API_TOKEN", "")
    with session_scope() as db:
        user = auth.api_token_user(db, token)
        if not user:
            raise AuthenticationRequired(
                "NETOPS_API_TOKEN is missing, invalid, expired or revoked. Create a token in the EMGS NetOps AI "
                "dashboard (Settings > API tokens) and set it in the MCP server configuration.")
        return auth.context_for(user, interface)


def _error(exc: Exception) -> dict:
    return {"ok": False, "error": str(exc), "error_type": exc.__class__.__name__}


def _compact(result: dict) -> dict:
    """Structured JSON for an AI client: the raw output is truncated to keep context small."""
    out = dict(result)
    out["raw_output"] = (out.get("raw_output") or "")[:3000]
    out["ok"] = result.get("status") == "completed"
    return out


async def _diag(dtype: str, params: dict, interface: str) -> dict:
    try:
        ctx = _context(interface)
        with session_scope() as db:
            return _compact(await run_diagnostic(db, ctx, dtype, params))
    except AuthenticationRequired as exc:
        return _error(exc)
    except EXPECTED as exc:
        return _error(exc)


# ------------------------------------------------------------------ diagnostics

async def run_ping(target: str, count: int = 4, timeout: int | None = None, interface: str = "mcp") -> dict:
    p = {"target": target, "count": count}
    if timeout:
        p["timeout"] = timeout
    return await _diag("ping", p, interface)


async def run_traceroute(target: str, max_hops: int | None = None, interface: str = "mcp") -> dict:
    return await _diag("traceroute", {"target": target, **({"max_hops": max_hops} if max_hops else {})}, interface)


async def check_tcp_port(target: str, port: int, interface: str = "mcp") -> dict:
    return await _diag("port", {"target": target, "ports": [port]}, interface)


async def check_multiple_ports(target: str, ports: list[int] | None = None, interface: str = "mcp") -> dict:
    return await _diag("port", {"target": target, "ports": ports or "approved"}, interface)


async def check_dns(domain: str, record_type: str = "A", dns_server: str | None = None, interface: str = "mcp") -> dict:
    p = {"domain": domain, "record_type": record_type}
    if dns_server:
        p["dns_server"] = dns_server
    return await _diag("dns", p, interface)


async def check_http(url: str, interface: str = "mcp") -> dict:
    return await _diag("http", {"url": url}, interface)


async def check_https(url: str, interface: str = "mcp") -> dict:
    return await _diag("https", {"url": url}, interface)


async def check_ssl_certificate(host: str, port: int = 443, interface: str = "mcp") -> dict:
    return await _diag("ssl", {"target": host, "port": port}, interface)


# ------------------------------------------------------------------ discovery

async def scan_ip_range(ip_range: str, confirm: bool = False, plan_reference: str | None = None,
                        wildcard_octets: str | None = None, ports: list[int] | None = None,
                        wait_seconds: int = 300, interface: str = "mcp") -> dict:
    """Two-step: without confirm, returns the plan (address count, methods, ports). Re-call with the
    plan_reference and confirm=true to execute; returns devices, ports and inventory comparison."""
    try:
        ctx = _context(interface)
        if not confirm or not plan_reference:
            with session_scope() as db:
                plan = engine.plan_scan(db, ctx, ip_range, wildcard_octets=wildcard_octets, ports=ports)
            return {"ok": True, "stage": "plan", "plan": plan,
                    "next_step": f"Review the scope, then call scan_ip_range with plan_reference='{plan['reference']}' "
                                 "and confirm=true to execute."}
        with session_scope() as db:
            engine.start_scan(db, ctx, plan_reference, confirm=True)
        await engine.wait_for_scan(plan_reference, timeout=max(10, min(wait_seconds, 3600)))
        return {"ok": True, "stage": "result", **_scan_result(plan_reference, full=True)}
    except AuthenticationRequired as exc:
        return _error(exc)
    except TimeoutError:
        return {"ok": True, "stage": "running", "reference": plan_reference,
                "message": "Discovery is still running; check it later with get_diagnostic_history or the dashboard."}
    except EXPECTED as exc:
        return _error(exc)


async def discover_hosts(ip_range: str = "", confirm: bool = False, plan_reference: str | None = None,
                         scan_reference: str | None = None, interface: str = "mcp") -> dict:
    """Which hosts are alive. Returns a compact host list from an existing scan (scan_reference),
    or runs the same plan/confirm flow as scan_ip_range."""
    if scan_reference:
        try:
            _context(interface)
            return {"ok": True, **_scan_result(scan_reference, full=False)}
        except (AuthenticationRequired, *EXPECTED) as exc:
            return _error(exc)
    res = await scan_ip_range(ip_range, confirm, plan_reference, interface=interface)
    if res.get("stage") == "result":
        return {"ok": True, "stage": "result", **_scan_result(res["reference"], full=False)}
    return res


def _scan_result(reference: str, full: bool) -> dict:
    with session_scope() as db:
        scan = db.scalar(select(m.DiscoveryScan).where(m.DiscoveryScan.reference == reference))
        if not scan:
            raise ValidationError(f"No scan {reference}.")
        data = engine.scan_to_dict(scan, include_devices=True)
        comps = db.scalars(select(m.InventoryComparison).where(m.InventoryComparison.scan_id == scan.id))
        data["comparison"] = [{"ip_address": c.ip_address, "classification": c.classification, "detail": c.detail}
                              for c in comps]
    if not full:
        data["devices"] = [{"ip_address": d["ip_address"], "hostname": d["hostname"], "mac_address": d["mac_address"],
                            "detected_by": d["detection_methods"],
                            "open_ports": [p["port"] for p in d["ports"] if p["state"] == "open"]}
                           for d in data["devices"]]
    return data


# ------------------------------------------------------------------ inventory, history, reports

async def get_server_inventory(status: str = "", category: str = "", interface: str = "mcp") -> dict:
    try:
        _context(interface)
        with session_scope() as db:
            servers = [inventory.server_to_dict(s) for s in inventory.search_servers(db, "", category, status)]
        return {"ok": True, "count": len(servers), "servers": servers,
                "note": "Records with is_sample=true are sample data, not real EMGS assets."}
    except (AuthenticationRequired, *EXPECTED) as exc:
        return _error(exc)


async def search_server_inventory(query: str, interface: str = "mcp") -> dict:
    try:
        _context(interface)
        with session_scope() as db:
            servers = [inventory.server_to_dict(s) for s in inventory.search_servers(db, query)]
        return {"ok": True, "count": len(servers), "servers": servers}
    except (AuthenticationRequired, *EXPECTED) as exc:
        return _error(exc)


async def get_diagnostic_history(diagnostic_type: str = "", target: str = "", limit: int = 20,
                                 interface: str = "mcp") -> dict:
    try:
        _context(interface)
        with session_scope() as db:
            jobs = history.list_jobs(db, dtype=diagnostic_type, target=target, limit=min(limit, 100))
        for j in jobs:
            j["raw_output"] = (j.get("raw_output") or "")[:500]
        return {"ok": True, "count": len(jobs), "jobs": jobs}
    except (AuthenticationRequired, *EXPECTED) as exc:
        return _error(exc)


async def generate_diagnostic_report(report_type: str = "consolidated", format: str = "pdf", target: str = "",
                                     scan_reference: str | None = None, days: int = 30, interface: str = "mcp") -> dict:
    try:
        ctx = _context(interface)
        if not ctx.has_role("operator"):
            raise RoleError("Your role does not permit generating reports.")
        with session_scope() as db:
            row = builder.generate(db, ctx, report_type, format, target=target, scan_reference=scan_reference, days=days)
            data = builder.report_to_dict(row)
            data["file_path"] = str(builder.report_dir(db) / row.file_name)
        return {"ok": True, **data}
    except (AuthenticationRequired, *EXPECTED) as exc:
        return _error(exc)


TOOLS = [run_ping, run_traceroute, check_tcp_port, check_multiple_ports, check_dns, check_http, check_https,
         check_ssl_certificate, scan_ip_range, discover_hosts, get_server_inventory, search_server_inventory,
         get_diagnostic_history, generate_diagnostic_report]
