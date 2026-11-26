"""Request authentication, CSRF protection and role checks."""
from __future__ import annotations

import hmac
from dataclasses import dataclass

from fastapi import Depends, HTTPException, Request
from sqlalchemy.orm import Session

from app.core.context import ExecutionContext
from app.database.session import get_db
from app.models import entities as m
from app.security.ratelimit import limiter
from app.services import auth

SESSION_COOKIE = "netops_session"
CSRF_COOKIE = "netops_csrf"
CSRF_HEADER = "x-csrf-token"
SAFE_METHODS = {"GET", "HEAD", "OPTIONS"}
INTERFACES = {"web", "mobile"}


@dataclass
class Principal:
    user: m.User
    ctx: ExecutionContext
    via: str  # session | token


def client_ip(request: Request) -> str:
    return request.client.host if request.client else ""


def _interface(request: Request, via: str) -> str:
    if via == "token":
        hint = request.headers.get("x-client-interface", "").lower()
        return hint if hint in {"mcp", "cli", "codex"} else "api"
    hint = request.headers.get("x-client-interface", "web").lower()
    return hint if hint in INTERFACES else "web"


def get_principal(request: Request, db: Session = Depends(get_db)) -> Principal:
    ip = client_ip(request)
    ok, retry = limiter.check(f"api:{ip}", 600)
    if not ok:
        raise HTTPException(429, f"Too many requests. Retry in {retry:.0f}s.", headers={"Retry-After": str(int(retry) + 1)})
    authz = request.headers.get("authorization", "")
    if authz.lower().startswith("bearer "):
        user = auth.api_token_user(db, authz[7:].strip())
        if not user:
            raise HTTPException(401, "Invalid or expired API token.")
        return Principal(user, auth.context_for(user, _interface(request, "token"), ip), "token")

    token = request.cookies.get(SESSION_COOKIE, "")
    found = auth.session_user(db, token)
    if not found:
        raise HTTPException(401, "Not signed in or session expired.")
    user, sess = found
    if request.method not in SAFE_METHODS:
        sent = request.headers.get(CSRF_HEADER, "")
        if not sent or not hmac.compare_digest(sent, sess.csrf_token):
            raise HTTPException(403, "CSRF check failed. Reload the page and try again.")
    return Principal(user, auth.context_for(user, _interface(request, "session"), ip), "session")


def require(*roles: str):
    def checker(p: Principal = Depends(get_principal)) -> Principal:
        if not p.ctx.has_role(*roles):
            raise HTTPException(403, f"This action requires the {' or '.join(roles)} role.")
        return p
    return checker


require_admin = require("admin")
require_operator = require("operator")
require_viewer = require("viewer", "operator")
