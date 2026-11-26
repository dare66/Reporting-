"""Settings, policy, users, audit, reports and maintenance routes."""
from __future__ import annotations

import csv
import io
import ipaddress
import shutil
import socket
from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import FileResponse, Response
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.ai import claude
from app.api.deps import Principal, require_admin, require_operator, require_viewer
from app.core.config import get_settings
from app.database.session import get_db
from app.models import entities as m
from app.reports import builder
from app.schemas.requests import (
    AiSettingsIn,
    DomainIn,
    NetworkIn,
    PortIn,
    ReportIn,
    RestoreIn,
    SettingsIn,
    UserIn,
    UserUpdateIn,
)
from app.security.passwords import hash_password, password_problems
from app.security.tokens import encrypt_secret
from app.security.validation import ValidationError, is_valid_hostname
from app.services import audit, auth, maintenance
from app.services.diagnostics import backend_for
from app.services.settings import all_settings, current_mode, set_setting

router = APIRouter(prefix="/api")


# ------------------------------------------------------------------ settings

@router.get("/settings")
def get_settings_route(p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    s = get_settings()
    return {"values": all_settings(db),
            "bootstrap": {"host_os": s.host_os, "bind_host": s.bind_host, "backend_port": s.backend_port,
                          "frontend_port": s.frontend_port, "database_path": str(s.database_path),
                          "data_dir": str(s.data_dir)},
            "editable": p.ctx.has_role("admin")}


@router.put("/settings")
def update_settings(body: SettingsIn, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    changed = {}
    for k, v in body.values.items():
        changed[k] = set_setting(db, k, v)
    audit.record(db, p.ctx, "settings.update", detail=", ".join(f"{k}={v}" for k, v in changed.items()))
    return all_settings(db)


@router.get("/system")
def system_info(p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    s = get_settings()
    mode = current_mode(db)
    tools = {t: bool(shutil.which(t)) for t in ("ping", "traceroute", "tracepath", "tracert", "nmap", "arp", "ip")}
    return {"hostname": socket.gethostname(), "host_os": s.host_os, "mode": mode,
            "source_network": backend_for(mode).source_network(), "tools": tools,
            "version": s.version, "ai": claude.status(db), "bind_host": s.bind_host, "port": s.backend_port,
            "https": bool(s.tls_cert and s.tls_key)}


# ------------------------------------------------------------------ approved networks / domains / ports

def _net_dict(n: m.ApprovedNetwork) -> dict:
    return {"id": n.id, "name": n.name, "cidr": n.cidr, "allow_diagnostics": n.allow_diagnostics,
            "allow_discovery": n.allow_discovery, "allow_os_fingerprint": n.allow_os_fingerprint,
            "description": n.description, "is_sample": n.is_sample}


@router.get("/policy")
def get_policy(p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    return {"networks": [_net_dict(n) for n in db.scalars(select(m.ApprovedNetwork).order_by(m.ApprovedNetwork.cidr))],
            "domains": [{"id": d.id, "domain": d.domain, "description": d.description, "is_sample": d.is_sample}
                        for d in db.scalars(select(m.ApprovedDomain).order_by(m.ApprovedDomain.domain))],
            "ports": [{"id": x.id, "port": x.port, "protocol": x.protocol, "service": x.service, "enabled": x.enabled,
                       "use_in_discovery": x.use_in_discovery}
                      for x in db.scalars(select(m.ApprovedPort).order_by(m.ApprovedPort.port))],
            "mode": current_mode(db),
            "note": "Rows marked sample apply only in Simulation mode."}


@router.post("/policy/networks")
def add_network(body: NetworkIn, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    try:
        net = ipaddress.ip_network(body.cidr.strip(), strict=False)
    except ValueError:
        raise ValidationError(f"'{body.cidr}' is not a valid CIDR network.") from None
    if net.version == 4 and net.is_global:
        raise ValidationError("Approved networks must be private (on-premises) address space.")
    if net.version == 4 and net.prefixlen < 8:
        raise ValidationError("Network is too large; use /8 or smaller.")
    if db.scalar(select(m.ApprovedNetwork).where(m.ApprovedNetwork.cidr == str(net))):
        raise ValidationError("That network is already approved.")
    row = m.ApprovedNetwork(**{**body.model_dump(), "cidr": str(net)})
    db.add(row)
    db.flush()
    audit.record(db, p.ctx, "policy.network_add", destination=str(net), detail=body.name)
    return _net_dict(row)


@router.put("/policy/networks/{nid}")
def update_network(nid: int, body: NetworkIn, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    row = db.get(m.ApprovedNetwork, nid)
    if not row:
        raise HTTPException(404, "Not found.")
    for k in ("name", "allow_diagnostics", "allow_discovery", "allow_os_fingerprint", "description"):
        setattr(row, k, getattr(body, k))
    row.is_sample = False  # an admin has reviewed and confirmed it
    audit.record(db, p.ctx, "policy.network_update", destination=row.cidr)
    return _net_dict(row)


@router.delete("/policy/networks/{nid}")
def delete_network(nid: int, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    row = db.get(m.ApprovedNetwork, nid)
    if not row:
        raise HTTPException(404, "Not found.")
    audit.record(db, p.ctx, "policy.network_delete", destination=row.cidr)
    db.delete(row)
    return {"ok": True}


@router.post("/policy/domains")
def add_domain(body: DomainIn, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    d = body.domain.strip().lower().rstrip(".")
    if not is_valid_hostname(d) or "." not in d:
        raise ValidationError("Enter a domain such as emgs.com.my.")
    if db.scalar(select(m.ApprovedDomain).where(m.ApprovedDomain.domain == d)):
        raise ValidationError("Domain already approved.")
    row = m.ApprovedDomain(domain=d, description=body.description)
    db.add(row)
    db.flush()
    audit.record(db, p.ctx, "policy.domain_add", destination=d)
    return {"id": row.id, "domain": d, "description": row.description, "is_sample": False}


@router.post("/policy/domains/{did}/confirm")
def confirm_domain(did: int, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    row = db.get(m.ApprovedDomain, did)
    if not row:
        raise HTTPException(404, "Not found.")
    row.is_sample = False
    audit.record(db, p.ctx, "policy.domain_confirm", destination=row.domain)
    return {"ok": True}


@router.delete("/policy/domains/{did}")
def delete_domain(did: int, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    row = db.get(m.ApprovedDomain, did)
    if not row:
        raise HTTPException(404, "Not found.")
    audit.record(db, p.ctx, "policy.domain_delete", destination=row.domain)
    db.delete(row)
    return {"ok": True}


@router.post("/policy/ports")
def upsert_port(body: PortIn, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    row = db.scalar(select(m.ApprovedPort).where(m.ApprovedPort.port == body.port))
    if row is None:
        row = m.ApprovedPort(port=body.port)
        db.add(row)
    row.service, row.enabled, row.use_in_discovery = body.service, body.enabled, body.use_in_discovery
    db.flush()
    audit.record(db, p.ctx, "policy.port_set", port=body.port, detail=f"enabled={body.enabled} discovery={body.use_in_discovery}")
    return {"id": row.id, "port": row.port, "service": row.service, "enabled": row.enabled,
            "use_in_discovery": row.use_in_discovery, "protocol": row.protocol}


@router.delete("/policy/ports/{port}")
def delete_port(port: int, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    row = db.scalar(select(m.ApprovedPort).where(m.ApprovedPort.port == port))
    if not row:
        raise HTTPException(404, "Not found.")
    audit.record(db, p.ctx, "policy.port_delete", port=port)
    db.delete(row)
    return {"ok": True}


# ------------------------------------------------------------------ AI settings

@router.get("/ai")
def get_ai(p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    return claude.status(db)


@router.put("/ai")
def put_ai(body: AiSettingsIn, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    s = db.scalar(select(m.AiSetting))
    if body.enabled is not None:
        s.enabled = body.enabled
    if body.model:
        s.model = body.model.strip()
    if body.send_raw_output is not None:
        s.send_raw_output = body.send_raw_output
    if body.clear_api_key:
        s.api_key_encrypted = ""
    elif body.api_key:
        s.api_key_encrypted = encrypt_secret(body.api_key.strip())
    audit.record(db, p.ctx, "settings.ai_update", detail=f"enabled={s.enabled} model={s.model} "
                 f"key={'updated' if body.api_key else ('cleared' if body.clear_api_key else 'unchanged')}")
    db.flush()
    return claude.status(db)


# ------------------------------------------------------------------ users

@router.get("/users")
def list_users(p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    return [auth.user_to_dict(u) for u in db.scalars(select(m.User).order_by(m.User.username))]


@router.post("/users")
def create_user(body: UserIn, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    return auth.user_to_dict(auth.create_user(db, body.username, body.password, list(body.roles), body.full_name, p.ctx))


@router.put("/users/{uid}")
def update_user(uid: int, body: UserUpdateIn, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    u = db.get(m.User, uid)
    if not u:
        raise HTTPException(404, "User not found.")
    if u.id == p.user.id and (body.is_active is False or (body.roles is not None and "admin" not in body.roles)):
        raise ValidationError("You cannot deactivate or demote your own account.")
    if body.roles is not None:
        u.roles = list(db.scalars(select(m.Role).where(m.Role.name.in_(body.roles))))
    if body.is_active is not None:
        u.is_active = body.is_active
    if body.full_name is not None:
        u.full_name = body.full_name
    if body.new_password:
        problems = password_problems(body.new_password, u.username)
        if problems:
            raise ValidationError("Password needs " + "; ".join(problems) + ".")
        u.password_hash = hash_password(body.new_password)
    if body.is_active is False or body.new_password:
        for s in db.scalars(select(m.UserSession).where(m.UserSession.user_id == u.id)):
            s.revoked = True
    audit.record(db, p.ctx, "user.update", destination=u.username,
                 detail=", ".join(k for k, v in body.model_dump().items() if v is not None and k != "new_password")
                 + (", password reset" if body.new_password else ""))
    return auth.user_to_dict(u)


# ------------------------------------------------------------------ audit

def _audit_rows(db: Session, action: str, user: str, limit: int):
    stmt = select(m.AuditLog).order_by(m.AuditLog.id.desc())
    if action:
        stmt = stmt.where(m.AuditLog.action.like(f"{action}%"))
    if user:
        stmt = stmt.where(m.AuditLog.username == user)
    return db.scalars(stmt.limit(min(max(limit, 1), 10000)))


def _audit_dict(a: m.AuditLog) -> dict:
    return {"id": a.id, "timestamp": a.timestamp.isoformat() + "Z", "user": a.username, "interface": a.source_interface,
            "client_ip": a.client_ip, "action": a.action, "diagnostic_type": a.diagnostic_type,
            "destination": a.destination, "port": a.port, "source_network": a.source_network, "status": a.status,
            "reference": a.reference, "detail": a.detail}


@router.get("/audit")
def audit_log(action: str = "", user: str = "", limit: int = 200, p: Principal = Depends(require_admin),
              db: Session = Depends(get_db)):
    return [_audit_dict(a) for a in _audit_rows(db, action, user, limit)]


@router.get("/audit/export")
def audit_export(action: str = "", user: str = "", p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    buf = io.StringIO()
    w = csv.writer(buf)
    cols = ["id", "timestamp", "user", "interface", "client_ip", "action", "diagnostic_type", "destination", "port",
            "source_network", "status", "reference", "detail"]
    w.writerow(cols)
    for a in _audit_rows(db, action, user, 10000):
        d = _audit_dict(a)
        # Neutralise spreadsheet formula injection.
        w.writerow([("'" + str(d[c])) if str(d[c] or "").startswith(("=", "+", "-", "@")) else d[c] for c in cols])
    audit.record(db, p.ctx, "audit.export")
    return Response(buf.getvalue(), media_type="text/csv",
                    headers={"Content-Disposition": f'attachment; filename="netops-audit-{datetime.now():%Y%m%d}.csv"'})


# ------------------------------------------------------------------ reports

@router.get("/reports/types")
def report_types(p: Principal = Depends(require_viewer)):
    return [{"id": k, "title": v, "methodology": builder.METHODOLOGY[k]} for k, v in builder.REPORT_TYPES.items()]


@router.post("/reports")
def create_report(body: ReportIn, p: Principal = Depends(require_operator), db: Session = Depends(get_db)):
    row = builder.generate(db, p.ctx, body.report_type, body.format, references=body.references, target=body.target,
                           scan_reference=body.scan_reference, days=body.days)
    return builder.report_to_dict(row)


@router.get("/reports")
def list_reports(p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    return [builder.report_to_dict(r) for r in
            db.scalars(select(m.ReportHistory).order_by(m.ReportHistory.id.desc()).limit(200))]


@router.get("/reports/{reference}/download")
def download_report(reference: str, inline: bool = False, p: Principal = Depends(require_viewer),
                    db: Session = Depends(get_db)):
    r = db.scalar(select(m.ReportHistory).where(m.ReportHistory.reference == reference))
    if not r:
        raise HTTPException(404, "Report not found.")
    path = builder.report_dir(db) / r.file_name
    if not path.exists() or path.resolve().parent != builder.report_dir(db).resolve():
        raise HTTPException(410, "Report file is no longer available.")
    media = "application/pdf" if r.format == "pdf" else \
        "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    audit.record(db, p.ctx, "report.download", reference=reference)
    return FileResponse(path, media_type=media, filename=r.file_name,
                        content_disposition_type="inline" if inline and r.format == "pdf" else "attachment")


# ------------------------------------------------------------------ maintenance

@router.get("/maintenance/backups")
def backups(p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    return maintenance.list_backups(db)


@router.post("/maintenance/backups")
def backup_now(p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    db.commit()
    return maintenance.create_backup(db, p.ctx, "manual")


@router.post("/maintenance/restore")
def restore(body: RestoreIn, p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    if not body.confirm:
        raise ValidationError("Restoring replaces all current data. Confirm to continue.")
    return maintenance.restore_backup(db, p.ctx, body.file_name)


@router.post("/maintenance/retention")
def retention(p: Principal = Depends(require_admin), db: Session = Depends(get_db)):
    return maintenance.apply_retention(db, p.ctx)
