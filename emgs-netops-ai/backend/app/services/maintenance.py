"""Backup, restore and data retention for the local SQLite database."""
from __future__ import annotations

import re
import shutil
import sqlite3
from datetime import datetime, timedelta
from pathlib import Path

from sqlalchemy import delete, select
from sqlalchemy.orm import Session

from app.core.config import get_settings
from app.core.context import ExecutionContext
from app.database.session import dispose_engine
from app.models import entities as m
from app.security.validation import ValidationError
from app.services import audit
from app.services.settings import get_setting

_NAME = re.compile(r"^netops-\d{8}-\d{6}(-[a-z0-9-]+)?\.db$")


def backup_dir(db: Session | None = None) -> Path:
    custom = get_setting(db, "backup_path") if db is not None else ""
    p = Path(custom) if custom else get_settings().data_dir / "backups"
    p.mkdir(parents=True, exist_ok=True)
    return p


def create_backup(db: Session | None, ctx: ExecutionContext | None, label: str = "") -> dict:
    label = re.sub(r"[^a-z0-9-]", "", (label or "").lower())[:20]
    name = f"netops-{datetime.now():%Y%m%d-%H%M%S}{'-' + label if label else ''}.db"
    dest = backup_dir(db) / name
    src = sqlite3.connect(get_settings().database_path)
    try:
        out = sqlite3.connect(dest)
        with out:
            src.backup(out)  # consistent online copy, safe while the app is running
        out.close()
    finally:
        src.close()
    if db is not None:
        audit.record(db, ctx, "backup.create", reference=name, detail=f"{dest.stat().st_size} bytes")
    return {"file_name": name, "size_bytes": dest.stat().st_size, "created_at": datetime.utcnow().isoformat() + "Z"}


def list_backups(db: Session | None = None) -> list[dict]:
    out = []
    for p in sorted(backup_dir(db).glob("netops-*.db"), reverse=True):
        out.append({"file_name": p.name, "size_bytes": p.stat().st_size,
                    "created_at": datetime.utcfromtimestamp(p.stat().st_mtime).isoformat() + "Z"})
    return out


def restore_backup(db: Session, ctx: ExecutionContext, file_name: str) -> dict:
    if not _NAME.match(file_name or ""):
        raise ValidationError("Invalid backup file name.")
    src = backup_dir(db) / file_name
    if not src.exists():
        raise ValidationError("Backup not found.")
    con = sqlite3.connect(f"file:{src}?mode=ro", uri=True)
    try:
        ok = con.execute("PRAGMA integrity_check").fetchone()[0] == "ok"
        has_schema = con.execute("SELECT name FROM sqlite_master WHERE name='schema_migrations'").fetchone()
    finally:
        con.close()
    if not ok or not has_schema:
        raise ValidationError("The backup failed the integrity check and was not restored.")
    safety = create_backup(db, ctx, "pre-restore")
    audit.record(db, ctx, "backup.restore", reference=file_name, detail=f"safety copy {safety['file_name']}")
    db.commit()
    db.close()
    dispose_engine()
    target = get_settings().database_path
    for suffix in ("-wal", "-shm"):
        Path(str(target) + suffix).unlink(missing_ok=True)
    shutil.copyfile(src, target)
    return {"restored": file_name, "safety_backup": safety["file_name"]}


def apply_retention(db: Session, ctx: ExecutionContext | None = None) -> dict:
    days = int(get_setting(db, "retention_days") or 180)
    cutoff = datetime.utcnow() - timedelta(days=days)
    jobs = db.execute(delete(m.DiagnosticJob).where(m.DiagnosticJob.started_at < cutoff)).rowcount
    old_scans = list(db.scalars(select(m.DiscoveryScan).where(m.DiscoveryScan.created_at < cutoff)))
    for s in old_scans:
        db.delete(s)
    sessions = db.execute(delete(m.UserSession).where(m.UserSession.expires_at < datetime.utcnow() - timedelta(days=7))).rowcount
    audit_cut = datetime.utcnow() - timedelta(days=max(days, 365))  # audit kept at least one year
    audits = db.execute(delete(m.AuditLog).where(m.AuditLog.timestamp < audit_cut)).rowcount
    result = {"retention_days": days, "diagnostic_jobs": jobs, "discovery_scans": len(old_scans),
              "expired_sessions": sessions, "audit_entries": audits}
    audit.record(db, ctx, "retention.apply", detail=str(result))
    db.flush()
    return result
