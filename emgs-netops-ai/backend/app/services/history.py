"""Diagnostic history and dashboard aggregates."""
from __future__ import annotations

from datetime import datetime, timedelta

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.discovery.engine import scan_to_dict
from app.models import entities as m
from app.services.diagnostics import job_to_dict


def list_jobs(db: Session, *, dtype: str = "", target: str = "", status: str = "", mode: str = "",
              limit: int = 100, offset: int = 0) -> list[dict]:
    stmt = select(m.DiagnosticJob).order_by(m.DiagnosticJob.id.desc())
    if dtype:
        stmt = stmt.where(m.DiagnosticJob.diagnostic_type == dtype)
    if target:
        stmt = stmt.where(m.DiagnosticJob.target.ilike(f"%{target}%"))
    if status:
        stmt = stmt.where(m.DiagnosticJob.status == status)
    if mode:
        stmt = stmt.where(m.DiagnosticJob.mode == mode)
    jobs = db.scalars(stmt.offset(max(offset, 0)).limit(min(max(limit, 1), 500))).all()
    return [job_to_dict(j) for j in jobs]


def get_job(db: Session, reference: str) -> dict | None:
    j = db.scalar(select(m.DiagnosticJob).where(m.DiagnosticJob.reference == reference))
    return job_to_dict(j) if j else None


def latest_scan(db: Session, mode: str | None = None) -> m.DiscoveryScan | None:
    stmt = select(m.DiscoveryScan).where(m.DiscoveryScan.status.in_(("completed", "cancelled")))
    if mode:
        stmt = stmt.where(m.DiscoveryScan.mode == mode)
    return db.scalar(stmt.order_by(m.DiscoveryScan.id.desc()))


def dashboard(db: Session, mode: str) -> dict:
    include_sample = mode == "simulation"
    servers = [s for s in db.scalars(select(m.Server)).all() if include_sample or not s.is_sample]
    since = datetime.utcnow() - timedelta(days=7)
    jobs = db.scalars(select(m.DiagnosticJob).where(m.DiagnosticJob.mode == mode)
                      .order_by(m.DiagnosticJob.id.desc()).limit(300)).all()
    recent = [j for j in jobs if j.started_at >= since]
    failures = [j for j in recent if j.status == "failed" or j.outcome in (
        "No ICMP response", "DNS resolution failure", "Partial packet loss", "Closed", "Filtered / timed out",
        "None open", "Some open", "Connection failure", "TLS failure", "Destination did not respond",
        "Expired", "Hostname mismatch")]

    port_summary = {"open": 0, "closed": 0, "filtered": 0}
    for j in recent:
        if j.diagnostic_type == "port" and j.results:
            for r in j.results[-1].summary.get("results", []):
                k = "filtered" if r["status"] in ("filtered", "unreachable") else r["status"]
                port_summary[k] = port_summary.get(k, 0) + 1

    ssl_items: dict[str, dict] = {}
    for j in jobs:
        if j.diagnostic_type == "ssl" and j.results and j.status == "completed":
            s = j.results[-1].summary
            key = f"{s.get('host')}:{s.get('port')}"
            if key not in ssl_items and s.get("days_remaining") is not None:
                ssl_items[key] = {"host": s.get("host"), "port": s.get("port"), "days_remaining": s["days_remaining"],
                                  "valid_until": s.get("valid_until"), "status": s.get("status"), "reference": j.reference}
    expiring = sorted((v for v in ssl_items.values() if v["days_remaining"] <= 60), key=lambda v: v["days_remaining"])

    scan = latest_scan(db, mode)
    comparison_counts: dict[str, int] = {}
    unknown = 0
    if scan:
        for c in db.scalars(select(m.InventoryComparison).where(m.InventoryComparison.scan_id == scan.id)):
            comparison_counts[c.classification] = comparison_counts.get(c.classification, 0) + 1
        unknown = sum(comparison_counts.get(k, 0) for k in ("Unknown Device", "Newly Discovered", "Possible Unauthorised Asset"))
    scans = db.scalars(select(m.DiscoveryScan).where(m.DiscoveryScan.mode == mode)
                       .order_by(m.DiscoveryScan.id.desc()).limit(10)).all()

    observations = _observations(failures, expiring, comparison_counts, scan)
    return {
        "mode": mode,
        "simulated": mode == "simulation",
        "totals": {
            "registered_assets": len([s for s in servers if s.status == "Active"]),
            "registered_inactive": len([s for s in servers if s.status != "Active"]),
            "sample_assets": len([s for s in servers if s.is_sample]),
            "active_discovered": scan.responding if scan else 0,
            "unidentified_devices": unknown,
            "recent_failures": len(failures),
            "diagnostics_7d": len(recent),
        },
        "port_summary": port_summary,
        "recent_failures": [_brief(j) for j in failures[:8]],
        "ssl_expiring": expiring[:10],
        "latest_scan": scan_to_dict(scan, include_devices=False) if scan else None,
        "latest_scan_classification": comparison_counts,
        "scan_history": [scan_to_dict(s, include_devices=False) for s in scans],
        "recent_activity": [_brief(j) for j in jobs[:12]],
        "observations": observations,
    }


def _brief(j: m.DiagnosticJob) -> dict:
    return {"reference": j.reference, "type": j.diagnostic_type, "target": j.target, "port": j.port,
            "status": j.status, "outcome": j.outcome, "started_at": j.started_at.isoformat() + "Z",
            "duration_ms": j.duration_ms, "user": j.user.username if j.user else None, "mode": j.mode}


def _observations(failures, expiring, counts, scan) -> list[dict]:
    """Rule-based observations shown on the dashboard (no AI call needed)."""
    obs = []
    by_target: dict[str, int] = {}
    for j in failures:
        by_target[j.target] = by_target.get(j.target, 0) + 1
    for target, n in sorted(by_target.items(), key=lambda x: -x[1])[:3]:
        if n >= 2:
            obs.append({"level": "warning", "text": f"{target} failed {n} checks in the last 7 days. "
                        "Review its history; repeated failures from one target suggest a host or path issue."})
    for c in expiring[:3]:
        word = "has expired" if c["days_remaining"] < 0 else f"expires in {c['days_remaining']} days"
        obs.append({"level": "error" if c["days_remaining"] < 15 else "warning",
                    "text": f"Certificate on {c['host']}:{c['port']} {word}."})
    if counts.get("Registered but Not Responding"):
        obs.append({"level": "warning", "text": f"{counts['Registered but Not Responding']} registered server(s) did not "
                    "respond in the latest discovery. They may be off, filtered or decommissioned."})
    review = counts.get("Possible Unauthorised Asset", 0) + counts.get("Unknown Device", 0)
    if review:
        obs.append({"level": "info", "text": f"{review} device(s) from the latest discovery need review before "
                    "they are registered or investigated."})
    if counts.get("Hostname Mismatch"):
        obs.append({"level": "info", "text": f"{counts['Hostname Mismatch']} hostname mismatch(es) between DNS and the inventory."})
    if not obs:
        obs.append({"level": "info", "text": "No notable issues in recent diagnostics." if scan or failures
                    else "Run a diagnostic or a discovery to start building observations."})
    return obs


def counts(db: Session) -> dict:
    return {"jobs": db.scalar(select(func.count(m.DiagnosticJob.id))) or 0,
            "scans": db.scalar(select(func.count(m.DiscoveryScan.id))) or 0}
