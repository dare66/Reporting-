"""WebSocket endpoints for real-time progress.

Authentication uses the session cookie. Because browsers send cookies on
cross-site WebSocket handshakes, the Origin header must match the host, and
console commands must carry the session's CSRF token.
"""
from __future__ import annotations

import asyncio
import hmac
from urllib.parse import urlparse

from fastapi import APIRouter, WebSocket, WebSocketDisconnect
from sqlalchemy import select

from app.api.deps import SESSION_COOKIE
from app.core.events import bus
from app.database.session import session_scope
from app.discovery.engine import scan_to_dict
from app.models import entities as m
from app.security.policy import PolicyError
from app.security.validation import ValidationError
from app.services import auth
from app.services.diagnostics import ModeError, RateLimitError, RoleError, run_diagnostic

router = APIRouter()


def _origin_ok(ws: WebSocket) -> bool:
    origin = ws.headers.get("origin")
    if not origin:
        return True  # non-browser client; still needs the session cookie
    return urlparse(origin).netloc == ws.headers.get("host", "")


def _authenticate(ws: WebSocket):
    with session_scope() as db:
        found = auth.session_user(db, ws.cookies.get(SESSION_COOKIE, ""))
        if not found:
            return None
        user, sess = found
        iface = ws.query_params.get("interface", "web")
        return auth.context_for(user, iface if iface in ("web", "mobile") else "web",
                                ws.client.host if ws.client else ""), sess.csrf_token


@router.websocket("/ws/scans/{reference}")
async def scan_progress(ws: WebSocket, reference: str):
    if not _origin_ok(ws):
        await ws.close(code=4403)
        return
    auth_info = _authenticate(ws)
    if not auth_info:
        await ws.close(code=4401)
        return
    await ws.accept()
    q = bus.subscribe(f"scan:{reference}")
    try:
        with session_scope() as db:
            scan = db.scalar(select(m.DiscoveryScan).where(m.DiscoveryScan.reference == reference))
            if not scan:
                await ws.send_json({"type": "error", "message": "Scan not found"})
                return
            snap = scan_to_dict(scan, include_devices=False)
        await ws.send_json({"type": "snapshot", "scan": snap})
        if snap["status"] in ("completed", "cancelled", "failed"):
            await ws.send_json({"type": "finished", "status": snap["status"]})
            return
        while True:
            try:
                event = await asyncio.wait_for(q.get(), timeout=20)
            except asyncio.TimeoutError:
                await ws.send_json({"type": "ping"})
                continue
            await ws.send_json(event)
            if event.get("type") == "finished":
                break
    except WebSocketDisconnect:
        pass
    finally:
        bus.unsubscribe(f"scan:{reference}", q)
        try:
            await ws.close()
        except RuntimeError:
            pass


@router.websocket("/ws/console")
async def console(ws: WebSocket):
    """Run diagnostics with live status: {"type":"run","diagnostic":"ping","params":{...},"csrf":"..."}"""
    if not _origin_ok(ws):
        await ws.close(code=4403)
        return
    auth_info = _authenticate(ws)
    if not auth_info:
        await ws.close(code=4401)
        return
    ctx, csrf = auth_info
    await ws.accept()
    try:
        while True:
            msg = await ws.receive_json()
            if msg.get("type") != "run":
                continue
            if not hmac.compare_digest(str(msg.get("csrf", "")), csrf):
                await ws.send_json({"type": "error", "message": "CSRF check failed"})
                continue
            dtype, params, rid = str(msg.get("diagnostic", "")), msg.get("params") or {}, msg.get("id")
            await ws.send_json({"type": "started", "id": rid, "diagnostic": dtype})
            try:
                with session_scope() as db:
                    result = await run_diagnostic(db, ctx, dtype, params if isinstance(params, dict) else {})
                await ws.send_json({"type": "result", "id": rid, "result": result})
            except (ValidationError, PolicyError, ModeError, RoleError, RateLimitError) as exc:
                await ws.send_json({"type": "error", "id": rid, "message": str(exc),
                                    "error_type": exc.__class__.__name__})
    except WebSocketDisconnect:
        pass
