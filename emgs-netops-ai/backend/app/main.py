"""FastAPI application factory."""
from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import FileResponse, JSONResponse
from fastapi.staticfiles import StaticFiles

from app.api import routes_admin, routes_core, routes_discovery, ws
from app.core.config import PROJECT_ROOT, get_settings
from app.database.migrations import run_migrations
from app.discovery.engine import ScanStateError
from app.security.policy import PolicyError
from app.security.validation import ValidationError
from app.services.auth import AuthError
from app.services.diagnostics import ModeError, RateLimitError, RoleError

log = logging.getLogger("netops")


def _recover_interrupted_scans() -> None:
    """A scan cannot survive a restart; mark leftovers so they are not shown as running forever."""
    from sqlalchemy import update

    from app.database.session import session_scope
    from app.models import entities as m
    with session_scope() as db:
        db.execute(update(m.DiscoveryScan).where(m.DiscoveryScan.status.in_(("running", "paused", "cancelling")))
                   .values(status="failed", error="Interrupted: the application was restarted during the scan."))
        db.execute(update(m.DiagnosticJob).where(m.DiagnosticJob.status == "running")
                   .values(status="failed", error="Interrupted by restart."))


@asynccontextmanager
async def lifespan(app: FastAPI):
    applied = run_migrations()
    if applied:
        log.info("Applied migrations: %s", applied)
    _recover_interrupted_scans()
    yield


def create_app() -> FastAPI:
    settings = get_settings()
    app = FastAPI(title="EMGS NetOps AI", version=settings.version, lifespan=lifespan,
                  docs_url="/api/docs", redoc_url=None, openapi_url="/api/openapi.json")

    @app.middleware("http")
    async def security_headers(request: Request, call_next):
        response = await call_next(request)
        response.headers.setdefault("X-Content-Type-Options", "nosniff")
        response.headers.setdefault("X-Frame-Options", "DENY")
        response.headers.setdefault("Referrer-Policy", "no-referrer")
        response.headers.setdefault("Permissions-Policy", "camera=(), microphone=(), geolocation=()")
        if not request.url.path.startswith("/api/docs"):
            response.headers.setdefault(
                "Content-Security-Policy",
                "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
                "connect-src 'self' ws: wss:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'")
        if request.url.scheme == "https":
            response.headers.setdefault("Strict-Transport-Security", "max-age=31536000")
        if request.url.path.startswith("/api/"):
            response.headers.setdefault("Cache-Control", "no-store")
        return response

    def _err(status: int):
        async def handler(request: Request, exc: Exception):
            headers = {}
            if isinstance(exc, RateLimitError):
                headers["Retry-After"] = str(int(exc.retry_after) + 1)
            return JSONResponse({"detail": str(exc), "error_type": exc.__class__.__name__}, status_code=status,
                                headers=headers)
        return handler

    app.add_exception_handler(ValidationError, _err(422))
    app.add_exception_handler(PolicyError, _err(403))
    app.add_exception_handler(RoleError, _err(403))
    app.add_exception_handler(PermissionError, _err(403))
    app.add_exception_handler(ModeError, _err(409))
    app.add_exception_handler(ScanStateError, _err(409))
    app.add_exception_handler(RateLimitError, _err(429))
    app.add_exception_handler(AuthError, _err(401))

    @app.exception_handler(RequestValidationError)
    async def pydantic_errors(request: Request, exc: RequestValidationError):
        msgs = []
        for e in exc.errors():
            loc = ".".join(str(x) for x in e.get("loc", []) if x != "body")
            msgs.append(f"{loc}: {e.get('msg')}" if loc else e.get("msg"))
        return JSONResponse({"detail": "; ".join(msgs), "error_type": "ValidationError"}, status_code=422)

    app.include_router(routes_core.router)
    app.include_router(routes_discovery.router)
    app.include_router(routes_admin.router)
    app.include_router(ws.router)

    dist = Path(PROJECT_ROOT / "frontend" / "dist")
    if dist.exists():
        app.mount("/assets", StaticFiles(directory=dist / "assets"), name="assets")

        @app.get("/{path:path}", include_in_schema=False)
        async def spa(path: str):
            if path.startswith(("api/", "ws/")):
                return JSONResponse({"detail": "Not found"}, status_code=404)
            candidate = (dist / path).resolve()
            if path and candidate.is_file() and dist.resolve() in candidate.parents:
                return FileResponse(candidate)
            return FileResponse(dist / "index.html", headers={"Cache-Control": "no-cache"})
    return app


app = create_app()
