"""Compare a discovery scan against the registered inventory.

Classifications are descriptive, never accusatory: a device that is not in
the inventory is "Newly Discovered" or "Unknown Device" until someone
reviews it. "Possible Unauthorised Asset" only flags an unregistered device
that exposes administrative services and has never been seen before; it
still requires human review.
"""
from __future__ import annotations

import ipaddress
from datetime import datetime

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models import entities as m

CLASSIFICATIONS = [
    "Registered and Active",
    "Registered but Not Responding",
    "Newly Discovered",
    "Unknown Device",
    "Duplicate IP Conflict",
    "Hostname Mismatch",
    "Possible Unauthorised Asset",
]
ADMIN_PORTS = {22, 23, 445, 3389, 3306, 5432, 5900}


def _short(h: str) -> str:
    return (h or "").lower().rstrip(".").split(".")[0]


def _in_scan(ip: str, scan: m.DiscoveryScan) -> bool:
    rng = scan.normalized_range
    first, _, last = rng.partition("-")
    a = ipaddress.ip_address(ip)
    return ipaddress.ip_address(first) <= a <= ipaddress.ip_address(last or first)


def compare_scan(db: Session, scan: m.DiscoveryScan) -> list[m.InventoryComparison]:
    db.query(m.InventoryComparison).filter(m.InventoryComparison.scan_id == scan.id).delete()
    include_sample = scan.mode == "simulation"
    servers = [s for s in db.scalars(select(m.Server)).all() if include_sample or not s.is_sample]
    by_ip: dict[str, list[m.Server]] = {}
    by_host: dict[str, m.Server] = {}
    for s in servers:
        by_ip.setdefault(s.ip_address, []).append(s)
        if s.hostname:
            by_host[_short(s.hostname)] = s

    previous_ips = set(db.scalars(
        select(m.DiscoveredDevice.ip_address).join(m.DiscoveryScan)
        .where(m.DiscoveryScan.id != scan.id, m.DiscoveryScan.status == "completed",
               m.DiscoveryScan.mode == scan.mode)
    ))
    rows: list[m.InventoryComparison] = []
    seen_ips = set()
    now = datetime.utcnow()
    for d in scan.devices:
        seen_ips.add(d.ip_address)
        open_ports = {p.port for p in d.ports if p.state == "open"}
        regs = by_ip.get(d.ip_address, [])
        server_id = regs[0].id if len(regs) == 1 else None
        if len(regs) > 1:
            cls = "Duplicate IP Conflict"
            detail = "Inventory has %d records with this IP: %s" % (len(regs), ", ".join(s.server_code for s in regs))
        elif len(regs) == 1:
            s = regs[0]
            s.last_discovered_at = now
            if s.hostname and d.hostname and _short(s.hostname) != _short(d.hostname):
                cls = "Hostname Mismatch"
                detail = f"Inventory says '{s.hostname}', network reports '{d.hostname}'."
            else:
                cls = "Registered and Active"
                detail = f"Matches {s.server_code} ({s.name})."
                if s.status == "Inactive":
                    detail += " Note: inventory marks this server Inactive but it responds."
        else:
            other = by_host.get(_short(d.hostname)) if d.hostname else None
            if other:
                cls = "Hostname Mismatch"
                server_id = other.id
                detail = f"Hostname '{d.hostname}' is registered as {other.server_code} at {other.ip_address}, but answered at {d.ip_address}."
            elif open_ports & ADMIN_PORTS and d.ip_address not in previous_ips:
                cls = "Possible Unauthorised Asset"
                detail = ("Not in inventory, never seen in earlier scans, and exposes administrative port(s) "
                          f"{sorted(open_ports & ADMIN_PORTS)}. Requires review; this is not a finding of wrongdoing.")
            elif not d.hostname:
                cls = "Unknown Device"
                detail = "No hostname from reverse DNS" + ("" if d.mac_address else " and no MAC address") + "; not in inventory."
            else:
                cls = "Newly Discovered"
                detail = ("Not in inventory. " + ("Seen in earlier scans." if d.ip_address in previous_ips
                                                 else "First time seen.") + " Review and register if legitimate.")
        rows.append(m.InventoryComparison(scan_id=scan.id, server_id=server_id, device_id=d.id,
                                          ip_address=d.ip_address, classification=cls, detail=detail))
    for ip, regs in by_ip.items():
        if ip in seen_ips or not _in_scan(ip, scan):
            continue
        for s in regs:
            rows.append(m.InventoryComparison(
                scan_id=scan.id, server_id=s.id, ip_address=ip, classification="Registered but Not Responding",
                detail=f"{s.server_code} ({s.name}) did not answer any permitted discovery method. "
                       "It may be powered off, filtered, or decommissioned."))
    db.add_all(rows)
    db.flush()
    return rows


def scan_changes(db: Session, scan: m.DiscoveryScan) -> dict:
    """Difference with the previous completed scan of the same range."""
    prev = db.scalar(
        select(m.DiscoveryScan).where(m.DiscoveryScan.normalized_range == scan.normalized_range,
                                      m.DiscoveryScan.status == "completed", m.DiscoveryScan.id < scan.id,
                                      m.DiscoveryScan.mode == scan.mode)
        .order_by(m.DiscoveryScan.id.desc()))
    if not prev:
        return {"previous_reference": None, "appeared": [], "disappeared": [], "changed": []}
    now = {d.ip_address: d for d in scan.devices}
    old = {d.ip_address: d for d in prev.devices}
    changed = []
    for ip in set(now) & set(old):
        a, b = old[ip], now[ip]
        diffs = {}
        if a.hostname != b.hostname:
            diffs["hostname"] = [a.hostname, b.hostname]
        if a.mac_address and b.mac_address and a.mac_address != b.mac_address:
            diffs["mac_address"] = [a.mac_address, b.mac_address]
        pa = sorted(p.port for p in a.ports if p.state == "open")
        pb = sorted(p.port for p in b.ports if p.state == "open")
        if pa != pb:
            diffs["open_ports"] = [pa, pb]
        if diffs:
            changed.append({"ip": ip, "changes": diffs})
    return {"previous_reference": prev.reference, "appeared": sorted(set(now) - set(old), key=ipaddress.ip_address),
            "disappeared": sorted(set(old) - set(now), key=ipaddress.ip_address), "changed": changed}
