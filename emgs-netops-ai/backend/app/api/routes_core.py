"""Auth, diagnostics, assistant, dashboard and history routes."""
from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, Request, Response
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.ai import claude
from app.api.deps import (
    CSRF_COOKIE,
    SESSION_COOKIE,
    Principal,
    client_ip,
    get_principal,
    require_operator,
    require_viewer,
)
from app.core.config import get_settings
from app.database.session import get_db
from app.models import entities as m
from app.schemas.requests import AssistantIn, DiagnosticIn, LoginIn, PasswordChangeIn, SetupIn, TokenIn
from app.security.ratelimit import limiter
from app.services import assistant, audit, auth, history
from app.services.diagnostics import DIAGNOSTIC_TYPES, run_diagnostic
from app.services.settings import current_mode, get_setting

router = APIRouter(prefix="/api")


# ------------------------------------------------------------------ public

@router.get("/health")
def health(db: Session = Depends(get_db)):
    s = get_settings()
    return {"status": "ok", "app": get_setting(db, "application_name"), "version": s.version,
            "setup_required": auth.setup_required(db), "mode": current_mode(db)}


def _set_session_cookies(request: Request, response: Response, token: str, csrf: str, hours: int) -> None:
    secure = request.url.scheme == "https" or get_settings().secure_cookies
    response.set_cookie(SESSION_COOKIE, token, httponly=True, samesite="strict", secure=secure,
                        max_age=hours * 3600, path="/")
    # Readable by the SPA so it can echo it in the X-CSRF-Token header (double submit).
    response.set_cookie(CSRF_COOKIE, csrf, httponly=False, samesite="strict", secure=secure,
                        max_age=hours * 3600, path="/")


@router.post("/auth/setup")
def setup(body: SetupIn, request: Request, response: Response, db: Session = Depends(get_db)):
    """First-run wizard. Only available while no user exists, and only from the laptop itself."""
    ip = client_ip(request)
    if ip not in ("127.0.0.1", "::1", "testclient") and not get_settings().testing:
        raise HTTPException(403, "Initial setup must be completed on the laptop itself (http://127.0.0.1).")
    try:
        user = auth.complete_setup(db, body.username, body.password, body.full_name)
    except auth.AuthError as exc:
        raise HTTPException(409, str(exc)) from None
    token, sess = auth.create_session(db, user, ip, request.headers.get("user-agent", ""))
    _set_session_cookies(request, response, token, sess.csrf_token, int(get_setting(db, "session_hours")))
    return {"user": auth.user_to_dict(user), "csrf_token": sess.csrf_token}


@router.post("/auth/login")
def login(body: LoginIn, request: Request, response: Response, db: Session = Depends(get_db)):
    ip = client_ip(request)
    ok, retry = limiter.check(f"login:{ip}", 10)
    if not ok:
        raise HTTPException(429, f"Too many login attempts. Retry in {retry:.0f}s.")
    try:
        user = auth.authenticate(db, body.username, body.password, ip)
    except auth.AuthError as exc:
        db.commit()  # keep failed-attempt counters and audit entries
        raise HTTPException(401, str(exc)) from None
    token, sess = auth.create_session(db, user, ip, request.headers.get("user-agent", ""))
    iface = request.headers.get("x-client-interface", "web")
    audit.record(db, auth.context_for(user, iface if iface in ("web", "mobile") else "web", ip), "auth.login")
    _set_session_cookies(request, response, token, sess.csrf_token, int(get_setting(db, "session_hours")))
    return {"user": auth.user_to_dict(user), "csrf_token": sess.csrf_token}


@router.post("/auth/logout")
def logout(request: Request, response: Response, p: Principal = Depends(get_principal), db: Session = Depends(get_db)):
    auth.revoke_session(db, request.cookies.get(SESSION_COOKIE, ""))
    audit.record(db, p.ctx, "auth.logout")
    response.delete_cookie(SESSION_COOKIE, path="/")
    response.delete_cookie(CSRF_COOKIE, path="/")
    return {"ok": True}


@router.get("/auth/me")
def me(request: Request, p: Principal = Depends(get_principal), db: Session = Depends(get_db)):
    sess = None
    if p.via == "session":
        found = auth.session_user(db, request.cookies.get(SESSION_COOKIE, ""))
        sess = found[1] if found else None
    return {"user": auth.user_to_dict(p.user), "csrf_token": sess.csrf_token if sess else None,
            "session_expires_at": sess.expires_at.isoformat() + "Z" if sess else None,
            "mode": current_mode(db), "ai": claude.status(db)}


@router.post("/auth/change-password")
def change_password(body: PasswordChangeIn, p: Principal = Depends(get_principal), db: Session = Depends(get_db)):
    try:
        auth.change_password(db, p.user, body.current_password, body.new_password)
    except auth.AuthError as exc:
        raise HTTPException(400, str(exc)) from None
    audit.record(db, p.ctx, "auth.change_password")
    return {"ok": True, "message": "Password changed. Sign in again on other devices."}


@router.get("/auth/tokens")
def list_tokens(p: Principal = Depends(get_principal), db: Session = Depends(get_db)):
    rows = db.scalars(select(m.ApiToken).where(m.ApiToken.user_id == p.user.id).order_by(m.ApiToken.id.desc()))
    return [{"id": r.id, "name": r.name, "prefix": r.prefix, "created_at": r.created_at.isoformat() + "Z",
             "expires_at": r.expires_at.isoformat() + "Z" if r.expires_at else None,
             "last_used_at": r.last_used_at.isoformat() + "Z" if r.last_used_at else None, "revoked": r.revoked}
            for r in rows]


@router.post("/auth/tokens")
def create_token(body: TokenIn, p: Principal = Depends(require_operator), db: Session = Depends(get_db)):
    token, row = auth.create_api_token(db, p.user, body.name, body.days)
    audit.record(db, p.ctx, "auth.token_create", reference=row.prefix, detail=body.name)
    return {"token": token, "id": row.id, "prefix": row.prefix,
            "note": "Copy this token now. It is shown once and stored only as a hash."}


@router.delete("/auth/tokens/{token_id}")
def revoke_token(token_id: int, p: Principal = Depends(get_principal), db: Session = Depends(get_db)):
    row = db.get(m.ApiToken, token_id)
    if not row or (row.user_id != p.user.id and not p.ctx.has_role("admin")):
        raise HTTPException(404, "Token not found.")
    row.revoked = True
    audit.record(db, p.ctx, "auth.token_revoke", reference=row.prefix)
    return {"ok": True}


# ------------------------------------------------------------------ diagnostics

@router.post("/diagnostics/{dtype}")
async def diagnostic(dtype: str, body: DiagnosticIn, p: Principal = Depends(require_operator),
                     db: Session = Depends(get_db)):
    if dtype not in DIAGNOSTIC_TYPES:
        raise HTTPException(404, f"Unknown diagnostic. Supported: {', '.join(DIAGNOSTIC_TYPES)}")
    return await run_diagnostic(db, p.ctx, dtype, body.model_dump(exclude_none=True))


@router.get("/history")
def list_history(type: str = "", target: str = "", status: str = "", mode: str = "", limit: int = 100, offset: int = 0,
                 p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    return history.list_jobs(db, dtype=type, target=target, status=status, mode=mode, limit=limit, offset=offset)


@router.get("/history/{reference}")
def get_history(reference: str, p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    job = history.get_job(db, reference)
    if not job:
        raise HTTPException(404, "Not found.")
    return job


@router.get("/dashboard")
def dashboard(p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    return history.dashboard(db, current_mode(db))


# ------------------------------------------------------------------ assistant

@router.post("/assistant/message")
async def assistant_message(body: AssistantIn, p: Principal = Depends(require_viewer), db: Session = Depends(get_db)):
    return await assistant.handle(db, p.ctx, body.message, body.pending)
