"""Local server inventory: CRUD, search, Excel import/export, register-from-discovery."""
from __future__ import annotations

import io
import ipaddress
from datetime import datetime

from openpyxl import Workbook, load_workbook
from openpyxl.styles import Font, PatternFill
from sqlalchemy import or_, select
from sqlalchemy.orm import Session

from app.core.context import ExecutionContext
from app.models import entities as m
from app.security.sanitize import clean_field
from app.security.validation import ValidationError, is_valid_hostname, validate_port
from app.services import audit

CRITICALITY = ("Low", "Medium", "High", "Critical")
STATUSES = ("Active", "Inactive")
CATEGORIES = ("STARS", "Visa Website", "EMGS Hub", "CRM", "Database", "File Server", "Web Server",
              "Application Server", "Network Device", "Other")
EXCEL_COLUMNS = ["Server ID", "Server name", "Hostname", "IP address", "Environment", "Operating system",
                 "Application", "Server category", "Technical owner", "Business owner", "Criticality",
                 "Approved ports", "Description", "Registration date", "Last discovery date", "Status", "Sample data"]


def _app_id(db: Session, name: str | None) -> int | None:
    if not name:
        return None
    app = db.scalar(select(m.Application).where(m.Application.name == name))
    if not app:
        app = m.Application(name=clean_field(name, 64))
        db.add(app)
        db.flush()
    return app.id


def _next_code(db: Session) -> str:
    n = db.query(m.Server).filter(m.Server.server_code.like("SRV-%")).count() + 1
    while db.scalar(select(m.Server).where(m.Server.server_code == f"SRV-{n:04d}")):
        n += 1
    return f"SRV-{n:04d}"


def _validate(data: dict) -> dict:
    out = {}
    if "name" in data:
        out["name"] = clean_field(data["name"], 128)
        if not out["name"]:
            raise ValidationError("Server name is required.")
    if "ip_address" in data:
        try:
            out["ip_address"] = str(ipaddress.ip_address(str(data["ip_address"]).strip()))
        except ValueError:
            raise ValidationError(f"'{data['ip_address']}' is not a valid IP address.") from None
    if "hostname" in data:
        h = str(data["hostname"] or "").strip().lower()
        if h and not is_valid_hostname(h):
            raise ValidationError(f"'{h}' is not a valid hostname.")
        out["hostname"] = h
    if "approved_ports" in data:
        ports = data["approved_ports"] or []
        if isinstance(ports, str):
            ports = [p for p in ports.replace(" ", "").split(",") if p]
        out["approved_ports"] = sorted({validate_port(p) for p in ports})
    if "criticality" in data:
        if data["criticality"] not in CRITICALITY:
            raise ValidationError(f"Criticality must be one of {', '.join(CRITICALITY)}.")
        out["criticality"] = data["criticality"]
    if "status" in data:
        if data["status"] not in STATUSES:
            raise ValidationError("Status must be Active or Inactive.")
        out["status"] = data["status"]
    for f, n in (("environment", 32), ("operating_system", 64), ("category", 64), ("technical_owner", 128),
                 ("business_owner", 128), ("description", 2000)):
        if f in data:
            out[f] = clean_field(data[f] or "", n)
    return out


def create_server(db: Session, ctx: ExecutionContext, data: dict) -> m.Server:
    if "name" not in data or "ip_address" not in data:
        raise ValidationError("Server name and IP address are required.")
    fields = _validate(data)
    code = clean_field(data.get("server_code") or "", 32) or _next_code(db)
    if db.scalar(select(m.Server).where(m.Server.server_code == code)):
        raise ValidationError(f"Server ID {code} already exists.")
    s = m.Server(server_code=code, application_id=_app_id(db, data.get("application")), **fields)
    db.add(s)
    db.flush()
    audit.record(db, ctx, "inventory.create", destination=s.ip_address, reference=s.server_code, detail=s.name)
    return s


def update_server(db: Session, ctx: ExecutionContext, server_id: int, data: dict) -> m.Server:
    s = db.get(m.Server, server_id)
    if not s:
        raise ValidationError("Server not found.")
    for k, v in _validate(data).items():
        setattr(s, k, v)
    if "application" in data:
        s.application_id = _app_id(db, data["application"])
    db.flush()
    audit.record(db, ctx, "inventory.update", destination=s.ip_address, reference=s.server_code,
                 detail=", ".join(sorted(data)))
    return s


def delete_server(db: Session, ctx: ExecutionContext, server_id: int, confirm: str) -> None:
    s = db.get(m.Server, server_id)
    if not s:
        raise ValidationError("Server not found.")
    if confirm != s.server_code:
        raise ValidationError(f"To delete, confirm by typing the Server ID ({s.server_code}).")
    audit.record(db, ctx, "inventory.delete", destination=s.ip_address, reference=s.server_code, detail=s.name)
    db.delete(s)
    db.flush()


def search_servers(db: Session, q: str = "", category: str = "", status: str = "", environment: str = "",
                   include_sample: bool = True, limit: int = 500) -> list[m.Server]:
    stmt = select(m.Server).order_by(m.Server.server_code)
    if q:
        like = f"%{q.strip()}%"
        stmt = stmt.where(or_(m.Server.name.ilike(like), m.Server.hostname.ilike(like), m.Server.ip_address.ilike(like),
                              m.Server.server_code.ilike(like), m.Server.description.ilike(like),
                              m.Server.technical_owner.ilike(like)))
    if category:
        stmt = stmt.where(m.Server.category == category)
    if status:
        stmt = stmt.where(m.Server.status == status)
    if environment:
        stmt = stmt.where(m.Server.environment == environment)
    if not include_sample:
        stmt = stmt.where(m.Server.is_sample.is_(False))
    return list(db.scalars(stmt.limit(min(limit, 5000))))


def server_to_dict(s: m.Server) -> dict:
    return {
        "id": s.id, "server_code": s.server_code, "name": s.name, "hostname": s.hostname, "ip_address": s.ip_address,
        "environment": s.environment, "operating_system": s.operating_system,
        "application": s.application.name if s.application else None, "category": s.category,
        "technical_owner": s.technical_owner, "business_owner": s.business_owner, "criticality": s.criticality,
        "approved_ports": s.approved_ports or [], "description": s.description,
        "registered_at": s.registered_at.isoformat() + "Z" if s.registered_at else None,
        "last_discovered_at": s.last_discovered_at.isoformat() + "Z" if s.last_discovered_at else None,
        "status": s.status, "is_sample": s.is_sample,
    }


def register_from_device(db: Session, ctx: ExecutionContext, device_id: int, data: dict) -> m.Server:
    d = db.get(m.DiscoveredDevice, device_id)
    if not d:
        raise ValidationError("Discovered device not found.")
    open_ports = [p.port for p in d.ports if p.state == "open"]
    payload = {"name": data.get("name") or d.hostname or f"Discovered {d.ip_address}",
               "ip_address": d.ip_address, "hostname": data.get("hostname", d.hostname or ""),
               "operating_system": data.get("operating_system", d.os_guess or ""),
               "approved_ports": data.get("approved_ports", open_ports),
               "category": data.get("category", "Other"), "environment": data.get("environment", "Production"),
               "criticality": data.get("criticality", "Medium"), "application": data.get("application"),
               "technical_owner": data.get("technical_owner", ""), "business_owner": data.get("business_owner", ""),
               "description": data.get("description", f"Registered from discovery scan {d.scan.reference}.")}
    s = create_server(db, ctx, payload)
    s.last_discovered_at = d.first_seen_at
    s.is_sample = d.scan.mode == "simulation"
    if s.is_sample:
        s.description = "SAMPLE DATA (registered from a simulation scan). " + s.description
    for c in db.scalars(select(m.InventoryComparison).where(m.InventoryComparison.device_id == d.id)):
        c.reviewed = True
        c.server_id = s.id
    return s


# ------------------------------------------------------------------ Excel

def export_excel(servers: list[m.Server]) -> bytes:
    wb = Workbook()
    ws = wb.active
    ws.title = "Server inventory"
    ws.append(EXCEL_COLUMNS)
    for c in ws[1]:
        c.font = Font(bold=True, color="FFFFFF")
        c.fill = PatternFill("solid", fgColor="1F3A5F")
    for s in servers:
        d = server_to_dict(s)
        ws.append([d["server_code"], d["name"], d["hostname"], d["ip_address"], d["environment"], d["operating_system"],
                   d["application"] or "", d["category"], d["technical_owner"], d["business_owner"], d["criticality"],
                   ",".join(map(str, d["approved_ports"])), d["description"],
                   (d["registered_at"] or "")[:19], (d["last_discovered_at"] or "")[:19], d["status"],
                   "YES - SAMPLE" if d["is_sample"] else "No"])
    for col, width in zip("ABCDEFGHIJKLMNOPQ", (14, 30, 32, 16, 14, 20, 18, 20, 20, 20, 11, 16, 40, 20, 20, 10, 14)):
        ws.column_dimensions[col].width = width
    ws.freeze_panes = "A2"
    buf = io.BytesIO()
    wb.save(buf)
    return buf.getvalue()


def import_excel(db: Session, ctx: ExecutionContext, content: bytes, *, dry_run: bool = False) -> dict:
    """Import or update servers. Matching is by Server ID; new rows get a generated ID."""
    if len(content) > 5 * 1024 * 1024:
        raise ValidationError("File is larger than 5 MB.")
    try:
        wb = load_workbook(io.BytesIO(content), read_only=True, data_only=True)
    except Exception:  # noqa: BLE001
        raise ValidationError("The file is not a valid .xlsx workbook.") from None
    ws = wb.worksheets[0]
    rows = list(ws.iter_rows(values_only=True))
    if not rows:
        raise ValidationError("The workbook is empty.")
    header = [str(h or "").strip().lower() for h in rows[0]]
    idx = {name.lower(): i for i, name in enumerate(header)}
    for need in ("server name", "ip address"):
        if need not in idx:
            raise ValidationError(f"Missing required column '{need}'. Export the inventory to get a template.")

    def val(row, col):
        i = idx.get(col.lower())
        return "" if i is None or i >= len(row) or row[i] is None else str(row[i]).strip()

    created = updated = 0
    errors: list[dict] = []
    seen_codes: set[str] = set()
    for n, row in enumerate(rows[1:], start=2):
        if not any(row):
            continue
        data = {"name": val(row, "Server name"), "ip_address": val(row, "IP address"), "hostname": val(row, "Hostname"),
                "environment": val(row, "Environment") or "Production", "operating_system": val(row, "Operating system"),
                "application": val(row, "Application") or None, "category": val(row, "Server category") or "Other",
                "technical_owner": val(row, "Technical owner"), "business_owner": val(row, "Business owner"),
                "criticality": val(row, "Criticality") or "Medium", "approved_ports": val(row, "Approved ports"),
                "description": val(row, "Description"), "status": val(row, "Status") or "Active"}
        code = val(row, "Server ID")
        try:
            existing = db.scalar(select(m.Server).where(m.Server.server_code == code)) if code else None
            if dry_run:
                # Validate only; nothing is written.
                _validate(data)
                if not existing and code in seen_codes:
                    raise ValidationError(f"Server ID {code} appears twice in the file.")
            elif existing:
                update_server(db, ctx, existing.id, data)  # validates before modifying
            else:
                create_server(db, ctx, {**data, "server_code": code})  # validates before inserting
            if existing:
                updated += 1
            else:
                created += 1
            if code:
                seen_codes.add(code)
        except ValidationError as exc:
            errors.append({"row": n, "error": str(exc)})
    audit.record(db, ctx, "inventory.import", status="dry-run" if dry_run else "success",
                 detail=f"created={created} updated={updated} errors={len(errors)}")
    return {"created": created, "updated": updated, "errors": errors, "dry_run": dry_run,
            "imported_at": datetime.utcnow().isoformat() + "Z"}
