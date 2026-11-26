"""Versioned, forward-only schema migrations.

Each migration is a function taking a connection-bound Session. Applied
versions are recorded in ``schema_migrations`` so a migration runs once.
Kept deliberately small (no Alembic) because the whole application is a
single local SQLite file.
"""
from __future__ import annotations

from typing import Callable

from sqlalchemy import inspect, select, text
from sqlalchemy.orm import Session

from app.database.session import Base, get_engine, session_scope
from app.models import entities as m

DEFAULT_SETTINGS: dict[str, object] = {
    "application_name": "EMGS NetOps AI",
    # simulation: lab data only, never touches the network
    # live: real diagnostics from this laptop
    # review: read-only, no execution (offline / travelling)
    "operating_mode": "simulation",
    "default_ping_count": 4,
    "ping_timeout_s": 2,
    "execution_timeout_s": 60,
    "traceroute_max_hops": 20,
    "tcp_timeout_s": 3,
    "scan_concurrency": 32,
    "scan_rate_per_second": 50,
    # Smallest CIDR prefix allowed for discovery. 24 => at most 256 addresses.
    "max_subnet_prefix": 24,
    "allow_large_scans": False,
    "default_wildcard_range": "1-254",
    "rate_limit_per_minute": 60,
    "retention_days": 180,
    "report_storage_path": "",
    "backup_path": "",
    "session_hours": 8,
    "mobile_access": {"enabled": False, "bind_ip": "", "https": True, "note": ""},
    "discovery_methods": ["icmp", "arp", "tcp", "reverse_dns"],
}

DEFAULT_PORTS = [
    (22, "SSH"), (25, "SMTP"), (53, "DNS/TCP"), (80, "HTTP"), (443, "HTTPS"), (445, "SMB"),
    (3306, "MySQL"), (3389, "RDP"), (5432, "PostgreSQL"), (8080, "HTTP alternate"), (8443, "HTTPS alternate"),
]
DISCOVERY_DEFAULT_PORTS = {22, 80, 443, 445, 3389}

ROLES = [
    ("admin", "Full control: settings, users, approved networks and ports"),
    ("operator", "Run diagnostics and discovery, manage inventory, generate reports"),
    ("viewer", "Read history, inventory and reports only"),
]

APPLICATIONS = ["STARS", "Visa Website", "EMGS Hub", "CRM", "Database", "File Server",
                "Web Server", "Application Server", "Network Device", "Other"]

# SAMPLE inventory. These are NOT real EMGS assets: names start with "SAMPLE-"
# and every row carries is_sample=True so the UI and reports label them.
SAMPLE_SERVERS = [
    ("SAMPLE-SRV-001", "SAMPLE STARS App 01", "sample-stars-app01.lab.local", "10.1.57.10", "STARS", "Application Server", "Linux", "High", [22, 443]),
    ("SAMPLE-SRV-002", "SAMPLE STARS DB 01", "sample-stars-db01.lab.local", "10.1.57.15", "Database", "Database", "Linux", "High", [22, 3306]),
    ("SAMPLE-SRV-003", "SAMPLE Visa Web 01", "sample-visa-web01.lab.local", "10.1.57.20", "Visa Website", "Web Server", "Linux", "High", [80, 443]),
    ("SAMPLE-SRV-004", "SAMPLE EMGS Hub 01", "sample-hub01.lab.local", "10.1.57.30", "EMGS Hub", "Application Server", "Windows Server", "Medium", [443, 3389]),
    ("SAMPLE-SRV-005", "SAMPLE CRM 01", "sample-crm01.lab.local", "10.1.57.40", "CRM", "Application Server", "Windows Server", "Medium", [443, 3389]),
    ("SAMPLE-SRV-006", "SAMPLE File Server 01", "sample-fs01.lab.local", "10.1.57.50", "File Server", "File Server", "Windows Server", "Medium", [445]),
    ("SAMPLE-SRV-007", "SAMPLE Legacy App (decommissioning)", "sample-legacy01.lab.local", "10.1.57.60", "Other", "Application Server", "Linux", "Low", [22, 8080]),
    ("SAMPLE-SRV-008", "SAMPLE Core Switch", "sample-core-sw01.lab.local", "10.1.57.1", "Other", "Network Device", "Network OS", "High", [22, 443]),
]


def _m001_initial_schema(db: Session) -> None:
    Base.metadata.create_all(bind=db.get_bind())


def _m002_seed_reference_data(db: Session) -> None:
    for name, desc in ROLES:
        if not db.scalar(select(m.Role).where(m.Role.name == name)):
            db.add(m.Role(name=name, description=desc))
    for key, value in DEFAULT_SETTINGS.items():
        if not db.get(m.ApplicationSetting, key):
            db.add(m.ApplicationSetting(key=key, value=value))
    for port, svc in DEFAULT_PORTS:
        if not db.scalar(select(m.ApprovedPort).where(m.ApprovedPort.port == port)):
            db.add(m.ApprovedPort(port=port, service=svc, use_in_discovery=port in DISCOVERY_DEFAULT_PORTS))
    if not db.scalar(select(m.AiSetting)):
        db.add(m.AiSetting())


def _m003_seed_sample_data(db: Session) -> None:
    apps: dict[str, m.Application] = {}
    for name in APPLICATIONS:
        app = db.scalar(select(m.Application).where(m.Application.name == name))
        if not app:
            app = m.Application(name=name, description="Application category", is_sample=False)
            db.add(app)
        apps[name] = app
    if not db.scalar(select(m.ApprovedNetwork).where(m.ApprovedNetwork.cidr == "10.1.57.0/24")):
        db.add(m.ApprovedNetwork(
            name="SAMPLE lab subnet", cidr="10.1.57.0/24", is_sample=True,
            description="Sample range for Simulation mode only. Ignored in Live mode. "
                        "Replace with real authorised ranges in Settings.",
        ))
    if not db.scalar(select(m.ApprovedDomain).where(m.ApprovedDomain.domain == "emgs.com.my")):
        db.add(m.ApprovedDomain(domain="emgs.com.my", is_sample=True,
                                description="Sample approved domain. Ignored in Live mode until confirmed by an admin."))
    db.flush()
    for code, name, host, ip, app, cat, os_, crit, ports in SAMPLE_SERVERS:
        if db.scalar(select(m.Server).where(m.Server.server_code == code)):
            continue
        db.add(m.Server(
            server_code=code, name=name, hostname=host, ip_address=ip, environment="Sample",
            operating_system=os_, application_id=apps[app].id, category=cat,
            technical_owner="SAMPLE owner", business_owner="SAMPLE owner", criticality=crit,
            approved_ports=ports, description="SAMPLE DATA - not a real EMGS asset.",
            status="Inactive" if "Legacy" in name else "Active", is_sample=True,
        ))


MIGRATIONS: list[tuple[int, str, Callable[[Session], None]]] = [
    (1, "initial schema", _m001_initial_schema),
    (2, "seed roles, settings and approved ports", _m002_seed_reference_data),
    (3, "seed clearly-labelled sample data", _m003_seed_sample_data),
]


def run_migrations() -> list[int]:
    engine = get_engine()
    with engine.begin() as conn:
        if not inspect(conn).has_table("schema_migrations"):
            m.SchemaMigration.__table__.create(conn)
    applied: list[int] = []
    with session_scope() as db:
        done = set(db.scalars(select(m.SchemaMigration.version)))
        for version, name, fn in MIGRATIONS:
            if version in done:
                continue
            fn(db)
            db.add(m.SchemaMigration(version=version, name=name))
            db.flush()
            applied.append(version)
    return applied


def schema_version() -> int:
    with session_scope() as db:
        return db.scalar(text("SELECT COALESCE(MAX(version), 0) FROM schema_migrations")) or 0
