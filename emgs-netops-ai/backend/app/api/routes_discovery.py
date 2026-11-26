"""Discovery, inventory and comparison routes."""
from __future__ import annotations

from fastapi import APIRouter, Depends, File, HTTPException, UploadFile
from fastapi.responses import Response
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import Principal, require_operator, require_viewer
from app.database.session import get_db
from app.discovery import engine
from app.discovery.comparison import CLASSIFICATIONS, scan_changes
from app.models import entities as m
from app.schemas.requests import (
    DiscoveryControlIn,
    DiscoveryPlanIn,
    DiscoveryStartIn,
    RegisterDeviceIn,
    ServerIn,
    ServerUpdateIn,
)
from app.services import audit, inventory

router = APIRouter(prefix="/api")


def _scan(db: Session, ref: str) -> m.DiscoveryScan:
    scan = db.scalar(select(m.DiscoveryScan).where(m.DiscoveryScan.reference == ref))
    if not scan:
        raise HTTPException(404, "Scan not found.")
    return scan


@router.post("/discovery/plan")
def plan(body: DiscoveryPlanIn, p: Principal = Depends(require_operator), db: Session = Depends(get_db)):
    return engine.plan_scan(db, p.ctx, body.range, wildcard_octets=body.wildcard_octets, methods=body.methods,
                            ports=body.ports, confirm_large=body.confirm_large)


@router.post("/discovery/{ref}/start")
async def start(ref: str, body: DiscoveryStartIn, p: Principal = Depends(require_operator), db: Session = Depends(get_db)):
    return engine.start_scan(db, p.ctx, ref, confirm=body.confirm)


@router.post("/discovery/{ref}/control")
def control(ref: str, body: DiscoveryControlIn, p: Principal = Depends(require_operator), db: Session = Depends(get_db)):
    return engine.control_scan(db, p.ctx, ref, body.action)


@router.get("/discovery")
def list_scans(limit: int = 50, p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    scans = db.scalars(select(m.DiscoveryScan).order_by(m.DiscoveryScan.id.desc()).limit(min(limit, 500)))
    return [engine.scan_to_dict(s, include_devices=False) for s in scans]


@router.get("/discovery/{ref}")
def get_scan(ref: str, p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    return engine.scan_to_dict(_scan(db, ref))


@router.get("/discovery/{ref}/comparison")
def comparison(ref: str, p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    scan = _scan(db, ref)
    rows = db.scalars(select(m.InventoryComparison).where(m.InventoryComparison.scan_id == scan.id))
    devices = {d.id: d for d in scan.devices}
    out = []
    for c in rows:
        srv = db.get(m.Server, c.server_id) if c.server_id else None
        d = devices.get(c.device_id)
        out.append({"id": c.id, "ip_address": c.ip_address, "classification": c.classification, "detail": c.detail,
                    "reviewed": c.reviewed, "device": engine.device_to_dict(d) if d else None,
                    "server": inventory.server_to_dict(srv) if srv else None})
    order = {k: i for i, k in enumerate(CLASSIFICATIONS)}
    out.sort(key=lambda r: (order.get(r["classification"], 99), tuple(int(x) for x in r["ip_address"].split("."))))
    return {"reference": ref, "classifications": CLASSIFICATIONS, "rows": out}


@router.get("/discovery/{ref}/changes")
def changes(ref: str, p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    return scan_changes(db, _scan(db, ref))


@router.post("/comparisons/{cid}/review")
def mark_reviewed(cid: int, p: Principal = Depends(require_operator), db: Session = Depends(get_db)):
    c = db.get(m.InventoryComparison, cid)
    if not c:
        raise HTTPException(404, "Not found.")
    c.reviewed = True
    audit.record(db, p.ctx, "discovery.review", destination=c.ip_address, detail=c.classification)
    return {"ok": True}


@router.post("/discovery/devices/{device_id}/register")
def register_device(device_id: int, body: RegisterDeviceIn, p: Principal = Depends(require_operator),
                    db: Session = Depends(get_db)):
    s = inventory.register_from_device(db, p.ctx, device_id, body.model_dump(exclude_none=True))
    return inventory.server_to_dict(s)


# ------------------------------------------------------------------ inventory

@router.get("/inventory")
def list_servers(q: str = "", category: str = "", status: str = "", environment: str = "",
                 p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    return [inventory.server_to_dict(s) for s in inventory.search_servers(db, q, category, status, environment)]


@router.get("/inventory/meta")
def inventory_meta(p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    return {"categories": inventory.CATEGORIES, "criticality": inventory.CRITICALITY, "statuses": inventory.STATUSES,
            "applications": [a.name for a in db.scalars(select(m.Application).order_by(m.Application.name))]}


@router.get("/inventory/export")
def export_inventory(p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    data = inventory.export_excel(inventory.search_servers(db, limit=5000))
    audit.record(db, p.ctx, "inventory.export")
    return Response(data, media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                    headers={"Content-Disposition": 'attachment; filename="netops-inventory.xlsx"'})


@router.post("/inventory/import")
async def import_inventory(dry_run: bool = False, file: UploadFile = File(...), p: Principal = Depends(require_operator),
                           db: Session = Depends(get_db)):
    content = await file.read(5 * 1024 * 1024 + 1)
    return inventory.import_excel(db, p.ctx, content, dry_run=dry_run)


@router.post("/inventory")
def create_server(body: ServerIn, p: Principal = Depends(require_operator), db: Session = Depends(get_db)):
    return inventory.server_to_dict(inventory.create_server(db, p.ctx, body.model_dump()))


@router.get("/inventory/{server_id}")
def get_server(server_id: int, p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    s = db.get(m.Server, server_id)
    if not s:
        raise HTTPException(404, "Server not found.")
    data = inventory.server_to_dict(s)
    jobs = db.scalars(select(m.DiagnosticJob).where(m.DiagnosticJob.target.in_([s.ip_address, s.hostname or "-"]))
                      .order_by(m.DiagnosticJob.id.desc()).limit(10))
    data["recent_diagnostics"] = [{"reference": j.reference, "type": j.diagnostic_type, "outcome": j.outcome,
                                   "started_at": j.started_at.isoformat() + "Z"} for j in jobs]
    return data


@router.put("/inventory/{server_id}")
def update_server(server_id: int, body: ServerUpdateIn, p: Principal = Depends(require_operator),
                  db: Session = Depends(get_db)):
    return inventory.server_to_dict(inventory.update_server(db, p.ctx, server_id, body.model_dump(exclude_none=True)))


@router.delete("/inventory/{server_id}")
def delete_server(server_id: int, confirm: str = "", p: Principal = Depends(require_operator),
                  db: Session = Depends(get_db)):
    inventory.delete_server(db, p.ctx, server_id, confirm)
    return {"ok": True}
