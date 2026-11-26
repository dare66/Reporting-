"""Single entry point for every diagnostic.

The web API, WebSocket console, AI assistant, MCP server and CLI all call
``run_diagnostic`` so validation, policy, rate limits, timeouts, history and
audit are identical whichever interface is used.

Pipeline:  mode check -> role check -> rate limit -> validate input ->
           authorise target/port -> execute with timeout -> persist job and
           result -> assess -> audit.
"""
from __future__ import annotations

import asyncio
import time
from datetime import datetime
from urllib.parse import urlparse

from sqlalchemy.orm import Session

from app.ai.assessment import ASSESSORS, assess_error
from app.core.context import ExecutionContext, new_reference
from app.diagnostics.base import NetworkBackend
from app.diagnostics.live import CommandUnavailable, LiveBackend
from app.diagnostics.parsers import classify_ping
from app.diagnostics.simulated import SimulatedBackend
from app.models import entities as m
from app.security.policy import PolicyError, TargetPolicy
from app.security.ratelimit import limiter
from app.security.validation import (
    ValidationError,
    parse_target,
    validate_int,
    validate_port,
    validate_record_type,
)
from app.services import audit
from app.services.settings import all_settings

DIAGNOSTIC_TYPES = ("ping", "traceroute", "port", "ports", "dns", "http", "https", "ssl")
MAX_PORTS_PER_CHECK = 25


class ModeError(PermissionError):
    pass


class RoleError(PermissionError):
    pass


class RateLimitError(RuntimeError):
    def __init__(self, retry_after: float):
        super().__init__(f"Too many requests. Try again in {retry_after:.0f} seconds.")
        self.retry_after = retry_after


# ------------------------------------------------------------------ backends

_live = LiveBackend()
_sim = SimulatedBackend()
_override: NetworkBackend | None = None
_semaphore = asyncio.Semaphore(8)


def set_backend_override(backend: NetworkBackend | None) -> None:
    """Tests inject fakes here."""
    global _override
    _override = backend


def backend_for(mode: str) -> NetworkBackend:
    if _override is not None:
        return _override
    return _sim if mode == "simulation" else _live


# ------------------------------------------------------------------ guards

def guard_execution(db: Session, ctx: ExecutionContext, cfg: dict) -> str:
    mode = cfg["operating_mode"]
    if mode == "review":
        raise ModeError("The application is in Review mode (read-only). Switch to Live or Simulation mode "
                        "in Settings to run diagnostics.")
    if not ctx.has_role("operator"):
        raise RoleError("Your role does not permit running diagnostics.")
    ok, retry = limiter.check(f"diag:{ctx.user_id}:{ctx.username}", int(cfg["rate_limit_per_minute"]))
    if not ok:
        raise RateLimitError(retry)
    return mode


# ------------------------------------------------------------------ entry point

async def run_diagnostic(db: Session, ctx: ExecutionContext, dtype: str, params: dict) -> dict:
    dtype = (dtype or "").lower()
    if dtype not in DIAGNOSTIC_TYPES:
        raise ValidationError(f"Unknown diagnostic '{dtype}'. Supported: {', '.join(DIAGNOSTIC_TYPES)}.")
    cfg = all_settings(db)
    target_label = str(params.get("target") or params.get("url") or params.get("domain") or "")[:255]
    try:
        mode = guard_execution(db, ctx, cfg)
    except (ModeError, RoleError, RateLimitError) as exc:
        audit.record(db, ctx, f"diagnostic.{dtype}", status="rejected", diagnostic_type=dtype,
                     destination=target_label, detail=str(exc))
        db.commit()
        raise
    backend = backend_for(mode)
    policy = TargetPolicy(db, mode)
    handler = _HANDLERS[dtype]
    try:
        prepared = await handler.prepare(backend, policy, cfg, params)
    except (ValidationError, PolicyError) as exc:
        audit.record(db, ctx, f"diagnostic.{dtype}", status="rejected", diagnostic_type=dtype,
                     destination=target_label, port=_safe_port(params), detail=str(exc))
        db.commit()
        raise

    job = m.DiagnosticJob(
        reference=new_reference("DX"), diagnostic_type=handler.type_name, target=prepared["target"],
        port=prepared.get("port"), parameters=prepared.get("parameters", {}), mode=mode,
        source_interface=ctx.interface, source_network=backend.source_network(prepared.get("probe_ip")),
        user_id=ctx.user_id, status="running",
    )
    db.add(job)
    # Commit now: never hold SQLite's write lock while awaiting network I/O.
    db.commit()
    start = time.perf_counter()
    raw = ""
    try:
        async with _semaphore:
            result = await asyncio.wait_for(handler.execute(backend, cfg, prepared),
                                            timeout=float(cfg["execution_timeout_s"]))
        raw = result.pop("raw_output", "")
        job.status = "completed"
    except (asyncio.TimeoutError, TimeoutError) as exc:
        result = handler.error_result(prepared, f"Execution exceeded the time limit: {exc or cfg['execution_timeout_s']}s")
        job.status, job.error = "failed", result["error"]
    except CommandUnavailable as exc:
        result = handler.error_result(prepared, str(exc))
        job.status, job.error = "failed", str(exc)
    except Exception as exc:  # noqa: BLE001 - convert to a recorded failure, never a 500 with a trace
        result = handler.error_result(prepared, f"{exc.__class__.__name__}: {exc}")
        job.status, job.error = "failed", result["error"]

    job.duration_ms = round((time.perf_counter() - start) * 1000, 2)
    job.finished_at = datetime.utcnow()
    job.outcome = str(result.get("status", ""))[:64]
    result.update(target=prepared["target"], duration_ms=job.duration_ms)
    if result.get("status") == "Execution error":
        assessment = assess_error(handler.type_name, prepared["target"], result.get("error", ""))
    else:
        assessment = ASSESSORS[handler.assess_key](result)
    db.add(m.DiagnosticResult(job_id=job.id, summary=result, raw_output=raw, assessment=assessment))
    audit.record(db, ctx, f"diagnostic.{handler.type_name}", status=job.status, diagnostic_type=handler.type_name,
                 destination=prepared["target"], port=prepared.get("port"), source_network=job.source_network,
                 reference=job.reference, detail=job.outcome)
    db.commit()
    return job_to_dict(job, result, assessment, raw)


def _safe_port(params: dict) -> int | None:
    try:
        return int(params.get("port")) if params.get("port") is not None else None
    except (TypeError, ValueError):
        return None


def job_to_dict(job: m.DiagnosticJob, result: dict | None = None, assessment: dict | None = None,
                raw: str | None = None) -> dict:
    if result is None and job.results:
        r = job.results[-1]
        result, assessment, raw = r.summary, r.assessment, r.raw_output
    return {
        "reference": job.reference,
        "type": job.diagnostic_type,
        "target": job.target,
        "port": job.port,
        "status": job.status,
        "outcome": job.outcome,
        "mode": job.mode,
        "simulated": job.mode == "simulation",
        "source_interface": job.source_interface,
        "source_network": job.source_network,
        "user": job.user.username if job.user else None,
        "started_at": job.started_at.isoformat() + "Z" if job.started_at else None,
        "duration_ms": job.duration_ms,
        "parameters": job.parameters,
        "result": result or {},
        "assessment": assessment or {},
        "raw_output": raw or "",
        "error": job.error,
    }


# ------------------------------------------------------------------ shared helpers

async def _resolve_and_authorize(backend, policy: TargetPolicy, raw_target: str) -> dict:
    target = parse_target(raw_target)
    if target.is_ip:
        auth = policy.authorize_target(target)
        return {"target": target.value, "ips": auth.addresses, "dns_failed": False, "policy": auth.reason}
    policy.authorize_target(target, resolved=[])  # domain / inventory check before any lookup
    ips = await backend.resolve(target.value, 5.0)
    if not ips:
        return {"target": target.value, "ips": [], "dns_failed": True, "policy": "hostname approved"}
    auth = policy.authorize_target(target, resolved=ips)
    return {"target": target.value, "ips": ips, "dns_failed": False, "policy": auth.reason}


# ------------------------------------------------------------------ handlers

class _Handler:
    type_name = ""
    assess_key = ""

    async def prepare(self, backend, policy, cfg, params) -> dict: ...

    async def execute(self, backend, cfg, prepared) -> dict: ...

    def error_result(self, prepared, error: str) -> dict:
        return {"status": "Execution error", "error": error}


class PingHandler(_Handler):
    type_name, assess_key = "ping", "ping"

    async def prepare(self, backend, policy, cfg, params):
        count = validate_int(params.get("count", cfg["default_ping_count"]), "Packet count", 1, 20)
        timeout = validate_int(params.get("timeout", cfg["ping_timeout_s"]), "Packet timeout", 1, 10)
        t = await _resolve_and_authorize(backend, policy, params.get("target", ""))
        return {**t, "count": count, "timeout": timeout, "probe_ip": (t["ips"] or [None])[0],
                "parameters": {"count": count, "timeout": timeout}}

    async def execute(self, backend, cfg, p):
        base = {"hostname": None if p["target"] == (p["ips"] or [None])[0] else p["target"],
                "target_ip": (p["ips"] or [None])[0], "packets_transmitted": p["count"], "packets_received": 0,
                "packet_loss_pct": 100.0, "min_latency_ms": None, "avg_latency_ms": None, "max_latency_ms": None}
        if p["dns_failed"]:
            return {**base, "status": "DNS resolution failure", "error": f"Could not resolve {p['target']}",
                    "raw_output": ""}
        r = await backend.ping(p["ips"][0], p["count"], p["timeout"], p["count"] * (p["timeout"] + 1) + 5)
        return {**base, "packets_transmitted": r["transmitted"], "packets_received": r["received"],
                "packet_loss_pct": r["loss_pct"], "min_latency_ms": r["min_ms"], "avg_latency_ms": r["avg_ms"],
                "max_latency_ms": r["max_ms"], "replies_ms": r.get("replies", []), "status": classify_ping(r),
                "method": r.get("method"), "raw_output": r.get("raw_output", "")}

    def error_result(self, prepared, error):
        return {"status": "Execution error", "error": error, "packets_transmitted": prepared.get("count", 0),
                "packets_received": 0, "packet_loss_pct": 100.0, "target_ip": (prepared.get("ips") or [None])[0]}


class TracerouteHandler(_Handler):
    type_name, assess_key = "traceroute", "traceroute"

    async def prepare(self, backend, policy, cfg, params):
        hops = validate_int(params.get("max_hops", cfg["traceroute_max_hops"]), "Maximum hops", 1, 30)
        t = await _resolve_and_authorize(backend, policy, params.get("target", ""))
        if t["dns_failed"]:
            raise ValidationError(f"Could not resolve {t['target']} to an address.")
        return {**t, "max_hops": hops, "probe_ip": t["ips"][0], "parameters": {"max_hops": hops}}

    async def execute(self, backend, cfg, p):
        r = await backend.traceroute(p["ips"][0], p["max_hops"], 2, p["max_hops"] * 7 + 10)
        hops = r["hops"]
        reached = any(h["ip"] == p["ips"][0] for h in hops)
        return {"target_ip": p["ips"][0], "max_hops": p["max_hops"], "hops": hops, "hop_count": len(hops),
                "destination_reached": reached,
                "status": "Destination reached" if reached else "Destination did not respond",
                "method": r.get("method"), "raw_output": r.get("raw_output", "")}

    def error_result(self, prepared, error):
        return {"status": "Execution error", "error": error, "hops": [], "destination_reached": False,
                "max_hops": prepared.get("max_hops")}


class PortsHandler(_Handler):
    type_name, assess_key = "port", "ports"

    async def prepare(self, backend, policy, cfg, params):
        raw_ports = params.get("ports")
        if raw_ports in (None, "", []):
            raw_ports = [params.get("port")] if params.get("port") is not None else []
        if raw_ports == "approved" or params.get("all_approved"):
            ports = sorted(policy.ports)
        else:
            if isinstance(raw_ports, str):
                raw_ports = [x for x in raw_ports.replace(" ", "").split(",") if x]
            ports = sorted({validate_port(p) for p in raw_ports})
        if not ports:
            raise ValidationError("At least one port is required.")
        if len(ports) > MAX_PORTS_PER_CHECK:
            raise ValidationError(f"At most {MAX_PORTS_PER_CHECK} ports per check.")
        for p in ports:
            policy.authorize_port(p)
        timeout = validate_int(params.get("timeout", cfg["tcp_timeout_s"]), "TCP timeout", 1, 15)
        t = await _resolve_and_authorize(backend, policy, params.get("target", ""))
        if t["dns_failed"]:
            raise ValidationError(f"Could not resolve {t['target']} to an address.")
        services = {p: policy.ports[p].service for p in ports}
        return {**t, "ports": ports, "services": services, "timeout": timeout, "probe_ip": t["ips"][0],
                "port": ports[0] if len(ports) == 1 else None, "parameters": {"ports": ports, "timeout": timeout}}

    async def execute(self, backend, cfg, p):
        ip = p["ips"][0]
        checks = await asyncio.gather(*(backend.tcp_connect(ip, port, p["timeout"]) for port in p["ports"]))
        results = [{"destination": ip, "port": port, "protocol": "tcp", "service": p["services"].get(port, ""),
                    "status": c["state"], "response_ms": c["response_ms"], "error": c["error"]}
                   for port, c in zip(p["ports"], checks)]
        n_open = sum(r["status"] == "open" for r in results)
        status = "All open" if n_open == len(results) else ("None open" if n_open == 0 else "Some open")
        if len(results) == 1:
            status = {"open": "Open", "closed": "Closed", "filtered": "Filtered / timed out",
                      "unreachable": "Unreachable"}[results[0]["status"]]
        return {"target_ip": ip, "results": results, "open_count": n_open, "status": status, "raw_output": ""}

    def error_result(self, prepared, error):
        return {"status": "Execution error", "error": error, "results": []}


class DnsHandler(_Handler):
    type_name, assess_key = "dns", "dns"

    async def prepare(self, backend, policy, cfg, params):
        name = str(params.get("domain") or params.get("target") or "").strip().lower().rstrip(".")
        rtype = validate_record_type(params.get("record_type", "A"))
        target = parse_target(name)
        if rtype == "PTR" and not target.is_ip:
            raise ValidationError("PTR / reverse DNS needs an IP address.")
        if target.is_ip and rtype != "PTR":
            rtype = "PTR"
        policy.authorize_dns_name(target.value)
        server = params.get("dns_server") or None
        if server:
            st = parse_target(server)
            if not st.is_ip:
                raise ValidationError("DNS server must be an IP address.")
        return {"target": target.value, "record_type": rtype, "dns_server": server,
                "parameters": {"record_type": rtype, "dns_server": server}}

    async def execute(self, backend, cfg, p):
        r = await backend.dns_query(p["target"], p["record_type"], p["dns_server"], 8.0)
        return {"domain": p["target"], "record_type": p["record_type"], **r, "raw_output": ""}

    def error_result(self, prepared, error):
        return {"status": "Execution error", "error": error, "values": [], "domain": prepared["target"],
                "record_type": prepared["record_type"], "dns_server": prepared.get("dns_server") or "", "duration_ms": None}


class HttpHandler(_Handler):
    type_name, assess_key = "http", "http"

    def __init__(self, force_https: bool = False):
        self.force_https = force_https
        self.type_name = "https" if force_https else "http"

    async def prepare(self, backend, policy, cfg, params):
        raw = str(params.get("url") or params.get("target") or "").strip()
        if not raw:
            raise ValidationError("A URL or hostname is required.")
        if "://" not in raw:
            raw = ("https://" if self.force_https else "http://") + raw
        u = urlparse(raw)
        if u.scheme not in ("http", "https"):
            raise ValidationError("Only http:// and https:// URLs are supported.")
        if self.force_https and u.scheme != "https":
            raise ValidationError("An https:// URL is required for an HTTPS check.")
        if u.username or u.password:
            raise ValidationError("URLs with embedded credentials are not allowed.")
        if not u.hostname or len(raw) > 512:
            raise ValidationError("Invalid URL.")
        try:
            port = u.port or (443 if u.scheme == "https" else 80)
        except ValueError:
            raise ValidationError("Invalid port in URL.") from None
        policy.authorize_port(port)
        t = await _resolve_and_authorize(backend, policy, u.hostname)
        if t["dns_failed"]:
            raise ValidationError(f"Could not resolve {u.hostname}.")
        url = f"{u.scheme}://{u.hostname}{':' + str(u.port) if u.port else ''}{u.path or '/'}"
        if u.query:
            url += "?" + u.query
        return {**t, "url": url, "port": port, "probe_ip": t["ips"][0], "parameters": {"url": url}}

    async def execute(self, backend, cfg, p):
        r = await backend.http_check(p["url"], float(cfg["tcp_timeout_s"]) + 7)
        status = (f"HTTP {r['status_code']}" if r["reachable"] else
                  ("TLS failure" if r.get("tls_ok") is False else "Connection failure"))
        return {"url": p["url"], **r, "status": status, "raw_output": ""}

    def error_result(self, prepared, error):
        return {"status": "Execution error", "error": error, "url": prepared["url"], "reachable": False,
                "redirects": [], "status_code": None, "reason": "", "duration_ms": None}


class SslHandler(_Handler):
    type_name, assess_key = "ssl", "ssl"

    async def prepare(self, backend, policy, cfg, params):
        raw = str(params.get("target") or params.get("host") or "").strip()
        if "://" in raw:
            u = urlparse(raw)
            raw = u.hostname or ""
            port = u.port or 443
        else:
            port = validate_port(params.get("port", 443))
        policy.authorize_port(port)
        t = await _resolve_and_authorize(backend, policy, raw)
        if t["dns_failed"]:
            raise ValidationError(f"Could not resolve {t['target']}.")
        return {**t, "port": port, "probe_ip": t["ips"][0], "parameters": {"port": port}}

    async def execute(self, backend, cfg, p):
        r = await backend.ssl_certificate(p["target"], p["port"], float(cfg["tcp_timeout_s"]) + 5)
        if r["days_remaining"] < 0:
            status = "Expired"
        elif not r["hostname_match"]:
            status = "Hostname mismatch"
        elif r["days_remaining"] <= 30:
            status = "Expiring soon"
        elif not r["chain_valid"]:
            status = "Untrusted"
        else:
            status = "Valid"
        return {"host": p["target"], "port": p["port"], **r, "status": status, "raw_output": ""}

    def error_result(self, prepared, error):
        return {"status": "Execution error", "error": error, "host": prepared["target"], "port": prepared["port"]}


_HANDLERS: dict[str, _Handler] = {
    "ping": PingHandler(),
    "traceroute": TracerouteHandler(),
    "port": PortsHandler(),
    "ports": PortsHandler(),
    "dns": DnsHandler(),
    "http": HttpHandler(),
    "https": HttpHandler(force_https=True),
    "ssl": SslHandler(),
}
