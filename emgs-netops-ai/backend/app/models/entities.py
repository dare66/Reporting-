"""ORM models. See docs/architecture/database-erd.md for the ERD."""
from __future__ import annotations

from datetime import datetime, timezone

from sqlalchemy import (
    JSON,
    Boolean,
    DateTime,
    Float,
    ForeignKey,
    Index,
    Integer,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database.session import Base


def utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


# --------------------------------------------------------------------------- identity

class Role(Base):
    __tablename__ = "roles"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(32), unique=True)  # admin | operator | viewer
    description: Mapped[str] = mapped_column(String(255), default="")


class User(Base):
    __tablename__ = "users"
    id: Mapped[int] = mapped_column(primary_key=True)
    username: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    full_name: Mapped[str] = mapped_column(String(128), default="")
    password_hash: Mapped[str] = mapped_column(String(255))
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    failed_logins: Mapped[int] = mapped_column(Integer, default=0)
    locked_until: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    last_login_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    roles: Mapped[list[Role]] = relationship(secondary="user_roles", lazy="selectin")

    @property
    def role_names(self) -> list[str]:
        return sorted(r.name for r in self.roles)


class UserRole(Base):
    __tablename__ = "user_roles"
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), primary_key=True)
    role_id: Mapped[int] = mapped_column(ForeignKey("roles.id", ondelete="CASCADE"), primary_key=True)


class UserSession(Base):
    __tablename__ = "user_sessions"
    id: Mapped[int] = mapped_column(primary_key=True)
    token_hash: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    csrf_token: Mapped[str] = mapped_column(String(64))
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), index=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    expires_at: Mapped[datetime] = mapped_column(DateTime)
    last_seen_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    client_ip: Mapped[str] = mapped_column(String(64), default="")
    user_agent: Mapped[str] = mapped_column(String(255), default="")
    revoked: Mapped[bool] = mapped_column(Boolean, default=False)


class ApiToken(Base):
    """Bearer tokens for MCP / Codex / CLI clients. Only a hash is stored."""
    __tablename__ = "api_tokens"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(64))
    token_hash: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    prefix: Mapped[str] = mapped_column(String(12))
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"))
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    expires_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    last_used_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    revoked: Mapped[bool] = mapped_column(Boolean, default=False)


# --------------------------------------------------------------------------- inventory

class Application(Base):
    __tablename__ = "applications"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(64), unique=True)
    description: Mapped[str] = mapped_column(String(255), default="")
    is_sample: Mapped[bool] = mapped_column(Boolean, default=False)


class Server(Base):
    __tablename__ = "servers"
    id: Mapped[int] = mapped_column(primary_key=True)
    server_code: Mapped[str] = mapped_column(String(32), unique=True)  # "Server ID" shown to users
    name: Mapped[str] = mapped_column(String(128))
    hostname: Mapped[str] = mapped_column(String(255), default="", index=True)
    ip_address: Mapped[str] = mapped_column(String(45), index=True)
    environment: Mapped[str] = mapped_column(String(32), default="Production")
    operating_system: Mapped[str] = mapped_column(String(64), default="")
    application_id: Mapped[int | None] = mapped_column(ForeignKey("applications.id", ondelete="SET NULL"), nullable=True)
    category: Mapped[str] = mapped_column(String(64), default="Other")
    technical_owner: Mapped[str] = mapped_column(String(128), default="")
    business_owner: Mapped[str] = mapped_column(String(128), default="")
    criticality: Mapped[str] = mapped_column(String(16), default="Medium")
    approved_ports: Mapped[list] = mapped_column(JSON, default=list)
    description: Mapped[str] = mapped_column(Text, default="")
    registered_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    last_discovered_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    status: Mapped[str] = mapped_column(String(16), default="Active")  # Active | Inactive
    is_sample: Mapped[bool] = mapped_column(Boolean, default=False)
    application: Mapped[Application | None] = relationship(lazy="selectin")


# --------------------------------------------------------------------------- policy

class ApprovedNetwork(Base):
    __tablename__ = "approved_networks"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(64))
    cidr: Mapped[str] = mapped_column(String(43), unique=True)
    allow_diagnostics: Mapped[bool] = mapped_column(Boolean, default=True)
    allow_discovery: Mapped[bool] = mapped_column(Boolean, default=True)
    allow_os_fingerprint: Mapped[bool] = mapped_column(Boolean, default=False)
    description: Mapped[str] = mapped_column(String(255), default="")
    is_sample: Mapped[bool] = mapped_column(Boolean, default=False)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)


class ApprovedDomain(Base):
    """Hostnames/domain suffixes approved for DNS, HTTP(S), SSL, ping and port checks."""
    __tablename__ = "approved_domains"
    id: Mapped[int] = mapped_column(primary_key=True)
    domain: Mapped[str] = mapped_column(String(255), unique=True)  # "emgs.com.my" also covers sub-domains
    description: Mapped[str] = mapped_column(String(255), default="")
    is_sample: Mapped[bool] = mapped_column(Boolean, default=False)


class ApprovedPort(Base):
    __tablename__ = "approved_ports"
    id: Mapped[int] = mapped_column(primary_key=True)
    port: Mapped[int] = mapped_column(Integer, unique=True)
    protocol: Mapped[str] = mapped_column(String(8), default="tcp")
    service: Mapped[str] = mapped_column(String(64), default="")
    enabled: Mapped[bool] = mapped_column(Boolean, default=True)
    use_in_discovery: Mapped[bool] = mapped_column(Boolean, default=True)


# --------------------------------------------------------------------------- diagnostics

class DiagnosticJob(Base):
    __tablename__ = "diagnostic_jobs"
    id: Mapped[int] = mapped_column(primary_key=True)
    reference: Mapped[str] = mapped_column(String(32), unique=True, index=True)
    diagnostic_type: Mapped[str] = mapped_column(String(32), index=True)
    target: Mapped[str] = mapped_column(String(255), index=True)
    port: Mapped[int | None] = mapped_column(Integer, nullable=True)
    parameters: Mapped[dict] = mapped_column(JSON, default=dict)
    status: Mapped[str] = mapped_column(String(32), default="running")  # running|completed|failed|rejected
    outcome: Mapped[str] = mapped_column(String(64), default="")  # module-specific status label
    mode: Mapped[str] = mapped_column(String(16), default="live")
    source_interface: Mapped[str] = mapped_column(String(32), default="web")  # web|mobile|mcp|cli|assistant
    source_network: Mapped[str] = mapped_column(String(255), default="")
    user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"), nullable=True)
    started_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)
    finished_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    duration_ms: Mapped[float | None] = mapped_column(Float, nullable=True)
    error: Mapped[str] = mapped_column(Text, default="")
    user: Mapped[User | None] = relationship(lazy="selectin")
    results: Mapped[list["DiagnosticResult"]] = relationship(
        back_populates="job", cascade="all, delete-orphan", lazy="selectin"
    )


class DiagnosticResult(Base):
    __tablename__ = "diagnostic_results"
    id: Mapped[int] = mapped_column(primary_key=True)
    job_id: Mapped[int] = mapped_column(ForeignKey("diagnostic_jobs.id", ondelete="CASCADE"), index=True)
    summary: Mapped[dict] = mapped_column(JSON, default=dict)
    raw_output: Mapped[str] = mapped_column(Text, default="")
    assessment: Mapped[dict] = mapped_column(JSON, default=dict)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    job: Mapped[DiagnosticJob] = relationship(back_populates="results")


# --------------------------------------------------------------------------- discovery

class DiscoveryScan(Base):
    __tablename__ = "discovery_scans"
    id: Mapped[int] = mapped_column(primary_key=True)
    reference: Mapped[str] = mapped_column(String(32), unique=True, index=True)
    range_input: Mapped[str] = mapped_column(String(128))
    normalized_range: Mapped[str] = mapped_column(String(128))
    address_count: Mapped[int] = mapped_column(Integer)
    methods: Mapped[list] = mapped_column(JSON, default=list)
    ports: Mapped[list] = mapped_column(JSON, default=list)
    status: Mapped[str] = mapped_column(String(16), default="planned")  # planned|running|paused|cancelled|completed|failed
    progress: Mapped[int] = mapped_column(Integer, default=0)  # addresses processed
    responding: Mapped[int] = mapped_column(Integer, default=0)
    mode: Mapped[str] = mapped_column(String(16), default="live")
    source_interface: Mapped[str] = mapped_column(String(32), default="web")
    source_network: Mapped[str] = mapped_column(String(255), default="")
    user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)
    started_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    finished_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    error: Mapped[str] = mapped_column(Text, default="")
    notes: Mapped[list] = mapped_column(JSON, default=list)
    user: Mapped[User | None] = relationship(lazy="selectin")
    devices: Mapped[list["DiscoveredDevice"]] = relationship(
        back_populates="scan", cascade="all, delete-orphan", lazy="selectin"
    )


class DiscoveredDevice(Base):
    __tablename__ = "discovered_devices"
    __table_args__ = (UniqueConstraint("scan_id", "ip_address"),)
    id: Mapped[int] = mapped_column(primary_key=True)
    scan_id: Mapped[int] = mapped_column(ForeignKey("discovery_scans.id", ondelete="CASCADE"), index=True)
    ip_address: Mapped[str] = mapped_column(String(45), index=True)
    hostname: Mapped[str] = mapped_column(String(255), default="")
    mac_address: Mapped[str] = mapped_column(String(17), default="")
    vendor: Mapped[str] = mapped_column(String(128), default="")
    os_guess: Mapped[str] = mapped_column(String(128), default="")
    responded_icmp: Mapped[bool] = mapped_column(Boolean, default=False)
    responded_tcp: Mapped[bool] = mapped_column(Boolean, default=False)
    responded_arp: Mapped[bool] = mapped_column(Boolean, default=False)
    detection_methods: Mapped[list] = mapped_column(JSON, default=list)
    latency_ms: Mapped[float | None] = mapped_column(Float, nullable=True)
    first_seen_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    scan: Mapped[DiscoveryScan] = relationship(back_populates="devices")
    ports: Mapped[list["DiscoveredPort"]] = relationship(
        back_populates="device", cascade="all, delete-orphan", lazy="selectin"
    )


class DiscoveredPort(Base):
    __tablename__ = "discovered_ports"
    id: Mapped[int] = mapped_column(primary_key=True)
    device_id: Mapped[int] = mapped_column(ForeignKey("discovered_devices.id", ondelete="CASCADE"), index=True)
    port: Mapped[int] = mapped_column(Integer)
    protocol: Mapped[str] = mapped_column(String(8), default="tcp")
    state: Mapped[str] = mapped_column(String(16))  # open | closed | filtered
    response_ms: Mapped[float | None] = mapped_column(Float, nullable=True)
    device: Mapped[DiscoveredDevice] = relationship(back_populates="ports")


class InventoryComparison(Base):
    __tablename__ = "inventory_comparisons"
    id: Mapped[int] = mapped_column(primary_key=True)
    scan_id: Mapped[int] = mapped_column(ForeignKey("discovery_scans.id", ondelete="CASCADE"), index=True)
    server_id: Mapped[int | None] = mapped_column(ForeignKey("servers.id", ondelete="SET NULL"), nullable=True)
    device_id: Mapped[int | None] = mapped_column(ForeignKey("discovered_devices.id", ondelete="CASCADE"), nullable=True)
    ip_address: Mapped[str] = mapped_column(String(45), index=True)
    classification: Mapped[str] = mapped_column(String(48), index=True)
    detail: Mapped[str] = mapped_column(Text, default="")
    reviewed: Mapped[bool] = mapped_column(Boolean, default=False)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)


# --------------------------------------------------------------------------- audit, settings, reports

class AuditLog(Base):
    __tablename__ = "audit_logs"
    __table_args__ = (Index("ix_audit_ts_action", "timestamp", "action"),)
    id: Mapped[int] = mapped_column(primary_key=True)
    timestamp: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)
    username: Mapped[str] = mapped_column(String(64), default="")
    user_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    source_interface: Mapped[str] = mapped_column(String(32), default="web")
    client_ip: Mapped[str] = mapped_column(String(64), default="")
    action: Mapped[str] = mapped_column(String(64))  # e.g. diagnostic.ping, auth.login, inventory.update
    diagnostic_type: Mapped[str] = mapped_column(String(32), default="")
    destination: Mapped[str] = mapped_column(String(255), default="")
    port: Mapped[int | None] = mapped_column(Integer, nullable=True)
    source_network: Mapped[str] = mapped_column(String(255), default="")
    status: Mapped[str] = mapped_column(String(32), default="success")
    reference: Mapped[str] = mapped_column(String(32), default="")
    detail: Mapped[str] = mapped_column(Text, default="")


class ApplicationSetting(Base):
    __tablename__ = "application_settings"
    key: Mapped[str] = mapped_column(String(64), primary_key=True)
    value: Mapped[dict | list | str | int | float | bool | None] = mapped_column(JSON)
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow, onupdate=utcnow)


class AiSetting(Base):
    __tablename__ = "ai_settings"
    id: Mapped[int] = mapped_column(primary_key=True)
    provider: Mapped[str] = mapped_column(String(32), default="anthropic")
    enabled: Mapped[bool] = mapped_column(Boolean, default=False)
    model: Mapped[str] = mapped_column(String(64), default="claude-opus-5-5")
    # The key itself is encrypted with the installation secret; never returned by the API.
    api_key_encrypted: Mapped[str] = mapped_column(Text, default="")
    send_raw_output: Mapped[bool] = mapped_column(Boolean, default=False)
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow, onupdate=utcnow)


class ReportHistory(Base):
    __tablename__ = "report_history"
    id: Mapped[int] = mapped_column(primary_key=True)
    reference: Mapped[str] = mapped_column(String(32), unique=True, index=True)
    report_type: Mapped[str] = mapped_column(String(48))
    format: Mapped[str] = mapped_column(String(8))
    file_name: Mapped[str] = mapped_column(String(255))
    parameters: Mapped[dict] = mapped_column(JSON, default=dict)
    user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)
    size_bytes: Mapped[int] = mapped_column(Integer, default=0)


class SchemaMigration(Base):
    __tablename__ = "schema_migrations"
    version: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(128))
    applied_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
