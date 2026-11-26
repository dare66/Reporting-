"""Audit trail. Every diagnostic, discovery, login and configuration change lands here."""
from __future__ import annotations

from sqlalchemy.orm import Session

from app.core.context import ExecutionContext
from app.models import entities as m
from app.security.sanitize import clean_field


def record(db: Session, ctx: ExecutionContext | None, action: str, *, status: str = "success",
           diagnostic_type: str = "", destination: str = "", port: int | None = None,
           source_network: str = "", reference: str = "", detail: str = "") -> m.AuditLog:
    entry = m.AuditLog(
        username=ctx.username if ctx else "system",
        user_id=ctx.user_id if ctx else None,
        source_interface=ctx.interface if ctx else "system",
        client_ip=ctx.client_ip if ctx else "",
        action=action,
        diagnostic_type=diagnostic_type,
        destination=clean_field(destination),
        port=port,
        source_network=clean_field(source_network),
        status=status,
        reference=reference,
        detail=clean_field(detail, 2000),
    )
    db.add(entry)
    db.flush()
    return entry
