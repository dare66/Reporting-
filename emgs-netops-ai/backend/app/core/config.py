"""Bootstrap configuration.

Only values needed *before* the database is open live here (paths, bind
address, ports). Everything an administrator changes at runtime lives in the
``application_settings`` table and is read through ``app.services.settings``.

Values come from environment variables prefixed ``NETOPS_`` so nothing secret
is ever written into source code.
"""
from __future__ import annotations

import os
import platform
import secrets
from dataclasses import dataclass, field
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parents[3]


def _env(name: str, default: str) -> str:
    return os.environ.get(f"NETOPS_{name}", default)


def _load_or_create_secret(path: Path) -> str:
    """Per-installation secret used to sign CSRF tokens and API tokens.

    Generated on first start and stored with owner-only permissions."""
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists():
        return path.read_text().strip()
    value = secrets.token_urlsafe(48)
    path.write_text(value)
    try:
        os.chmod(path, 0o600)
    except OSError:  # Windows: ACLs apply instead
        pass
    return value


@dataclass
class Settings:
    app_name: str = "EMGS NetOps AI"
    version: str = "1.0.0"
    data_dir: Path = field(default_factory=lambda: Path(_env("DATA_DIR", str(PROJECT_ROOT / "data"))))
    database_url: str = ""
    bind_host: str = field(default_factory=lambda: _env("HOST", "127.0.0.1"))
    backend_port: int = field(default_factory=lambda: int(_env("PORT", "8750")))
    frontend_port: int = field(default_factory=lambda: int(_env("FRONTEND_PORT", "5173")))
    # "simulation" | "live" | "review". Only the initial value; admins change it in Settings.
    initial_mode: str = field(default_factory=lambda: _env("MODE", "simulation"))
    tls_cert: str = field(default_factory=lambda: _env("TLS_CERT", ""))
    tls_key: str = field(default_factory=lambda: _env("TLS_KEY", ""))
    session_hours: int = field(default_factory=lambda: int(_env("SESSION_HOURS", "8")))
    secure_cookies: bool = field(default_factory=lambda: _env("SECURE_COOKIES", "auto") == "true")
    testing: bool = field(default_factory=lambda: _env("TESTING", "0") == "1")
    secret_key: str = ""

    def __post_init__(self) -> None:
        self.data_dir.mkdir(parents=True, exist_ok=True)
        for sub in ("database", "reports", "backups", "logs"):
            (self.data_dir / sub).mkdir(parents=True, exist_ok=True)
        if not self.database_url:
            db_path = Path(_env("DB_PATH", str(self.data_dir / "database" / "netops.db")))
            self.database_url = f"sqlite:///{db_path}"
        if not self.secret_key:
            self.secret_key = _env("SECRET_KEY", "") or _load_or_create_secret(self.data_dir / "secret.key")

    @property
    def database_path(self) -> Path:
        return Path(self.database_url.replace("sqlite:///", ""))

    @property
    def host_os(self) -> str:
        return platform.system().lower()  # "windows" | "linux" | "darwin"


_settings: Settings | None = None


def get_settings() -> Settings:
    global _settings
    if _settings is None:
        _settings = Settings()
    return _settings


def reset_settings(**overrides) -> Settings:
    """Used by tests and the CLI to point at a different data directory."""
    global _settings
    _settings = Settings(**overrides)
    return _settings
