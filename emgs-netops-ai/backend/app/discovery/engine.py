"""Network range discovery.

Workflow (matches the specification):
 1. ``plan_scan``  - parse + validate the range, check authorisation and size,
                     count addresses, list methods and approved ports, and
                     store a *planned* scan. Nothing is sent on the network.
 2. ``start_scan`` - requires the plan reference and explicit confirmation.
 3. The scan task probes each address with the permitted methods under a
    concurrency limit and a packets-per-second rate limit; it can be paused,
    resumed and cancelled; progress is published for the WebSocket.
 4. Responding devices get reverse DNS, MAC (ARP) and approved-port checks.
 5. Results are stored and compared with the inventory.

A device that ignores ICMP is NOT classified as offline: TCP answers
(open *or* closed) and ARP entries also count as evidence of life.
"""
from __future__ import annotations

import asyncio
import ipaddress
import time
from datetime import datetime

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.context import ExecutionContext, new_reference
from app.core.events import bus
from app.database.session import session_scope
from app.discovery.comparison import compare_scan
from app.models import entities as m
from app.security.policy import TargetPolicy
from app.security.ranges import parse_range
from app.security.validation import ValidationError, validate_port
from app.services import audit
from app.services.diagnostics import backend_for, guard_execution
from app.services.settings import all_settings

METHOD_INFO = {
    "icmp": "ICMP echo (ping), 1 packet per address",
    "tcp": "TCP connect to approved discovery ports (an open or refused connection proves the host is up)",
    "arp": "ARP/neighbour table (only meaningful when this laptop is on the same subnet)",
    "reverse_dns": "Reverse DNS (PTR) lookup for responding devices",
    "nmap": "Nmap host discovery restricted to approved ports (if Nmap is installed)",
    "os_fingerprint": "Nmap OS fingerprinting (only where the approved network allows it)",
}


class ScanStateError(RuntimeError):
    pass


class _Control:
    def __init__(self) -> None:
        self.resume = asyncio.Event()
        self.resume.set()
        self.cancelled = False
        self.task: asyncio.Task | None = None


_controls: dict[str, _Control] = {}


# ------------------------------------------------------------------ plan

def plan_scan(db: Session, ctx: ExecutionContext, range_input: str, *, wildcard_octets: str | None = None,
              methods: list[str] | None = None, ports: list[int] | None = None,
              confirm_large: bool = False) -> dict:
    cfg = all_settings(db)
    try:
        mode = guard_execution(db, ctx, cfg)
        backend = backend_for(mode)
        policy = TargetPolicy(db, mode)
        rng = parse_range(range_input, wildcard_octets or cfg["default_wildcard_range"])
        scope = policy.authorize_range(rng, confirm_large=confirm_large)
        methods = list(dict.fromkeys(methods or cfg["discovery_methods"]))
        bad = [x for x in methods if x not in METHOD_INFO]
        if bad:
            raise ValidationError(f"Unknown discovery method(s): {', '.join(bad)}")
        if "os_fingerprint" in methods and not scope["os_fingerprint_allowed"]:
            raise ValidationError("OS fingerprinting is not authorised for this network. "
                                  "An administrator can enable it per approved network.")
        if ports is None:
            ports = sorted(p.port for p in policy.ports.values() if p.use_in_discovery)
        ports = sorted({validate_port(p) for p in ports})
        for p in ports:
            policy.authorize_port(p)
        if len(ports) > 25:
            raise ValidationError("At most 25 approved ports per discovery.")
    except Exception as exc:
        audit.record(db, ctx, "discovery.plan", status="rejected", diagnostic_type="discovery",
                     destination=str(range_input)[:128], detail=str(exc))
        db.commit()
        raise

    notes = list(rng.notes)
    if "nmap" in methods and not backend.nmap_available():
        methods.remove("nmap")
        notes.append("Nmap is not installed; continuing with native ICMP/TCP/ARP methods.")
    if "os_fingerprint" in methods and not backend.nmap_available():
        methods.remove("os_fingerprint")
        notes.append("OS fingerprinting needs Nmap, which is not installed.")
    if "arp" in methods:
        notes.append("ARP results only include devices on the laptop's own subnet.")
    concurrency = int(cfg["scan_concurrency"])
    rate = int(cfg["scan_rate_per_second"])
    probes = rng.count * ((1 if "icmp" in methods else 0) + (len(ports) if "tcp" in methods else 0))
    est = max(probes / rate, rng.count / concurrency * 1.5)

    scan = m.DiscoveryScan(
        reference=new_reference("DS"), range_input=str(range_input)[:128], normalized_range=rng.normalized,
        address_count=rng.count, methods=methods, ports=ports, status="planned", mode=mode,
        source_interface=ctx.interface, source_network=backend.source_network(str(rng.first)),
        user_id=ctx.user_id, notes=notes,
    )
    db.add(scan)
    audit.record(db, ctx, "discovery.plan", diagnostic_type="discovery", destination=rng.normalized,
                 reference=scan.reference, source_network=scan.source_network,
                 detail=f"{rng.count} addresses, methods={methods}, ports={ports}")
    db.commit()
    return {
        "reference": scan.reference, "range_input": scan.range_input, "normalized_range": rng.normalized,
        "kind": rng.kind, "address_count": rng.count, "first": str(rng.first), "last": str(rng.last),
        "approved_network": scope["network"], "approved_network_name": scope["network_name"],
        "large_scan": scope["large"], "methods": [{"id": x, "description": METHOD_INFO[x]} for x in methods],
        "ports": ports, "concurrency": concurrency, "rate_per_second": rate,
        "estimated_seconds": round(est, 1), "mode": mode, "simulated": mode == "simulation",
        "notes": notes, "requires_confirmation": True,
    }


# ------------------------------------------------------------------ control

def start_scan(db: Session, ctx: ExecutionContext, reference: str, *, confirm: bool) -> dict:
    if not confirm:
        raise ValidationError("Discovery must be explicitly confirmed (confirm=true) after reviewing the plan.")
    cfg = all_settings(db)
    guard_execution(db, ctx, cfg)
    scan = db.scalar(select(m.DiscoveryScan).where(m.DiscoveryScan.reference == reference))
    if not scan:
        raise ValidationError(f"No planned scan {reference}.")
    if scan.status != "planned":
        raise ScanStateError(f"Scan {reference} is {scan.status}; only planned scans can be started.")
    if scan.mode != cfg["operating_mode"]:
        raise ScanStateError("The operating mode changed since this scan was planned. Plan it again.")
    if scan.user_id != ctx.user_id and not ctx.has_role("admin"):
        raise ScanStateError("Only the user who planned this scan can start it.")
    if any(s.status in ("running", "paused") for s in
           db.scalars(select(m.DiscoveryScan).where(m.DiscoveryScan.status.in_(("running", "paused"))))):
        raise ScanStateError("Another discovery is already running. Wait for it or cancel it first.")
    scan.status = "running"
    scan.started_at = datetime.utcnow()
    audit.record(db, ctx, "discovery.start", diagnostic_type="discovery", destination=scan.normalized_range,
                 reference=scan.reference, source_network=scan.source_network)
    db.commit()
    ctrl = _Control()
    _controls[reference] = ctrl
    ctrl.task = asyncio.get_running_loop().create_task(_run_scan(reference, ctx, ctrl))
    return {"reference": reference, "status": "running"}


async def wait_for_scan(reference: str, timeout: float | None = None) -> None:
    ctrl = _controls.get(reference)
    if ctrl and ctrl.task:
        await asyncio.wait_for(asyncio.shield(ctrl.task), timeout)


def control_scan(db: Session, ctx: ExecutionContext, reference: str, action: str) -> dict:
    if not ctx.has_role("operator"):
        raise PermissionError("Your role does not permit controlling discovery.")
    scan = db.scalar(select(m.DiscoveryScan).where(m.DiscoveryScan.reference == reference))
    if not scan:
        raise ValidationError(f"No scan {reference}.")
    ctrl = _controls.get(reference)
    if action == "cancel" and scan.status == "planned":
        scan.status = "cancelled"
    elif not ctrl or scan.status not in ("running", "paused"):
        raise ScanStateError(f"Scan {reference} is {scan.status}.")
    elif action == "pause":
        ctrl.resume.clear()
        scan.status = "paused"
    elif action == "resume":
        ctrl.resume.set()
        scan.status = "running"
    elif action == "cancel":
        ctrl.cancelled = True
        ctrl.resume.set()
        scan.status = "cancelling"
    else:
        raise ValidationError("Action must be pause, resume or cancel.")
    audit.record(db, ctx, f"discovery.{action}", diagnostic_type="discovery", destination=scan.normalized_range,
                 reference=reference)
    db.commit()
    bus.publish(f"scan:{reference}", {"type": "status", "status": scan.status})
    return {"reference": reference, "status": scan.status}


# ------------------------------------------------------------------ execution

class _TokenBucket:
    def __init__(self, rate: int):
        self.interval = 1.0 / max(rate, 1)
        self.next = time.monotonic()
        self.lock = asyncio.Lock()

    async def take(self) -> None:
        async with self.lock:
            now = time.monotonic()
            if self.next > now:
                await asyncio.sleep(self.next - now)
            self.next = max(self.next, now) + self.interval


async def _run_scan(reference: str, ctx: ExecutionContext, ctrl: _Control) -> None:
    channel = f"scan:{reference}"

    def _load():
        with session_scope() as db:
            scan = db.scalar(select(m.DiscoveryScan).where(m.DiscoveryScan.reference == reference))
            cfg = all_settings(db)
            rng = parse_range(scan.normalized_range)
            allowed = TargetPolicy(db, scan.mode).authorize_range(rng, confirm_large=True)["os_fingerprint_allowed"]
            return cfg, scan.mode, list(scan.methods), list(scan.ports), rng, allowed

    # SQLite work runs in a worker thread so it never blocks the event loop.
    cfg, mode, methods, ports, rng, os_allowed = await asyncio.to_thread(_load)
    backend = backend_for(mode)
    concurrency = int(cfg["scan_concurrency"])
    bucket = _TokenBucket(int(cfg["scan_rate_per_second"]))
    tcp_timeout = min(float(cfg["tcp_timeout_s"]), 2.0)
    found: dict[str, dict] = {}
    processed = 0
    sem = asyncio.Semaphore(concurrency)
    lock = asyncio.Lock()

    async def probe(ip: str) -> None:
        nonlocal processed
        async with sem:
            await ctrl.resume.wait()
            if ctrl.cancelled:
                return
            info = {"ip": ip, "icmp": False, "tcp": False, "arp": False, "latency": None, "ports": {}}
            if "icmp" in methods:
                await bucket.take()
                try:
                    r = await backend.ping(ip, 1, 1, 5)
                    if r["received"]:
                        info["icmp"], info["latency"] = True, r["avg_ms"]
                except Exception:  # noqa: BLE001 - a missing ping binary must not stop TCP/ARP discovery
                    pass
            if "tcp" in methods and ports:
                async def one(port: int):
                    await bucket.take()
                    return port, await backend.tcp_connect(ip, port, tcp_timeout)
                for port, res in await asyncio.gather(*(one(p) for p in ports)):
                    info["ports"][port] = res
                    if res["state"] in ("open", "closed"):
                        info["tcp"] = True
            async with lock:
                processed += 1
                if info["icmp"] or info["tcp"]:
                    found[ip] = info
                if processed % 8 == 0 or processed == rng.count:
                    bus.publish(channel, {"type": "progress", "processed": processed, "total": rng.count,
                                          "responding": len(found)})

    error = ""
    try:
        await asyncio.gather(*(probe(str(a)) for a in rng.addresses()))
        if not ctrl.cancelled and "arp" in methods:
            await ctrl.resume.wait()
            table = await backend.arp_table()
            for ip, mac in table.items():
                try:
                    if not (rng.first <= ipaddress.IPv4Address(ip) <= rng.last):
                        continue
                except ValueError:
                    continue
                entry = found.setdefault(ip, {"ip": ip, "icmp": False, "tcp": False, "arp": False,
                                              "latency": None, "ports": {}})
                entry["arp"], entry["mac"] = True, mac
        if not ctrl.cancelled and ("nmap" in methods or "os_fingerprint" in methods) and backend.nmap_available():
            targets = sorted(found) if found else [str(a) for a in rng.addresses()]
            try:
                extra = await backend.nmap_discover(targets, ports, "os_fingerprint" in methods and os_allowed, 600)
            except Exception as exc:  # noqa: BLE001
                extra = {}
                error = f"Nmap step failed: {exc}"
            for ip, d in extra.items():
                entry = found.setdefault(ip, {"ip": ip, "icmp": False, "tcp": False, "arp": False,
                                              "latency": None, "ports": {}})
                entry["nmap"] = True
                entry.setdefault("mac", d.get("mac"))
                entry["vendor"], entry["os_guess"] = d.get("vendor", ""), d.get("os_guess", "")
        if not ctrl.cancelled and "reverse_dns" in methods:
            names = await asyncio.gather(*(backend.reverse_dns(ip, 3.0) for ip in found))
            for ip, name in zip(list(found), names):
                found[ip]["hostname"] = name or ""
        # Approved-port check for devices found by ICMP/ARP only (TCP discovery already covered them).
        if not ctrl.cancelled and ports:
            pending = [ip for ip, i in found.items() if not i["ports"]]
            for ip in pending:
                res = await asyncio.gather(*(backend.tcp_connect(ip, p, tcp_timeout) for p in ports))
                found[ip]["ports"] = dict(zip(ports, res))
    except Exception as exc:  # noqa: BLE001
        error = f"{exc.__class__.__name__}: {exc}"

    def _persist() -> str:
        with session_scope() as db:
            scan = db.scalar(select(m.DiscoveryScan).where(m.DiscoveryScan.reference == reference))
            for ip in sorted(found, key=ipaddress.ip_address):
                i = found[ip]
                dm = [k for k in ("icmp", "tcp", "arp", "nmap") if i.get(k)]
                dev = m.DiscoveredDevice(
                    scan_id=scan.id, ip_address=ip, hostname=i.get("hostname", ""), mac_address=i.get("mac") or "",
                    vendor=i.get("vendor", ""), os_guess=i.get("os_guess", ""), responded_icmp=i["icmp"],
                    responded_tcp=i["tcp"], responded_arp=i["arp"], detection_methods=dm, latency_ms=i["latency"])
                for port, res in sorted(i["ports"].items()):
                    dev.ports.append(m.DiscoveredPort(port=port, state=res["state"] if res["state"] != "unreachable" else "filtered",
                                                      response_ms=res.get("response_ms")))
                db.add(dev)
            scan.progress = processed
            scan.responding = len(found)
            scan.finished_at = datetime.utcnow()
            scan.error = error
            if ctrl.cancelled:
                scan.status = "cancelled"
                scan.notes = list(scan.notes) + [f"Cancelled by user after {processed} of {scan.address_count} addresses; partial results kept."]
            else:
                scan.status = "failed" if error and not found else "completed"
            db.flush()
            db.refresh(scan)
            if scan.status in ("completed", "cancelled"):
                compare_scan(db, scan)
            audit.record(db, ctx, "discovery.finish", status=scan.status, diagnostic_type="discovery",
                         destination=scan.normalized_range, reference=reference, source_network=scan.source_network,
                         detail=f"{len(found)} responding of {processed} checked" + (f"; {error}" if error else ""))
            return scan.status

    def _mark_failed(exc: Exception) -> str:
        with session_scope() as db:
            scan = db.scalar(select(m.DiscoveryScan).where(m.DiscoveryScan.reference == reference))
            scan.status, scan.finished_at = "failed", datetime.utcnow()
            scan.error = f"Could not save results: {exc.__class__.__name__}: {exc}"
            return "failed"

    try:
        final_status = await asyncio.to_thread(_persist)
    except Exception as exc:  # noqa: BLE001 - never leave a scan stuck in "running"
        final_status = await asyncio.to_thread(_mark_failed, exc)
    bus.publish(channel, {"type": "finished", "status": final_status, "processed": processed,
                          "total": rng.count, "responding": len(found)})
    _controls.pop(reference, None)


# ------------------------------------------------------------------ serialisation

def scan_to_dict(scan: m.DiscoveryScan, include_devices: bool = True) -> dict:
    data = {
        "reference": scan.reference, "range_input": scan.range_input, "normalized_range": scan.normalized_range,
        "address_count": scan.address_count, "methods": scan.methods, "ports": scan.ports, "status": scan.status,
        "progress": scan.progress, "responding": scan.responding, "mode": scan.mode,
        "simulated": scan.mode == "simulation", "source_interface": scan.source_interface,
        "source_network": scan.source_network, "user": scan.user.username if scan.user else None,
        "created_at": _iso(scan.created_at), "started_at": _iso(scan.started_at),
        "finished_at": _iso(scan.finished_at), "error": scan.error, "notes": scan.notes,
    }
    if include_devices:
        data["devices"] = [device_to_dict(d) for d in sorted(scan.devices, key=lambda d: ipaddress.ip_address(d.ip_address))]
    return data


def device_to_dict(d: m.DiscoveredDevice) -> dict:
    return {
        "id": d.id, "ip_address": d.ip_address, "hostname": d.hostname or None, "mac_address": d.mac_address or None,
        "vendor": d.vendor or None, "os_guess": d.os_guess or None, "responded_icmp": d.responded_icmp,
        "responded_tcp": d.responded_tcp, "responded_arp": d.responded_arp, "detection_methods": d.detection_methods,
        "latency_ms": d.latency_ms,
        "ports": [{"port": p.port, "state": p.state, "response_ms": p.response_ms} for p in sorted(d.ports, key=lambda p: p.port)],
    }


def _iso(v):
    return v.isoformat() + "Z" if v else None
