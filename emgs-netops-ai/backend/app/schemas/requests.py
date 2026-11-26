"""Request bodies. Field limits here are a first line of defence; services re-validate."""
from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field


class SetupIn(BaseModel):
    username: str = Field(min_length=3, max_length=64)
    password: str = Field(min_length=1, max_length=256)
    full_name: str = Field(default="", max_length=128)


class LoginIn(BaseModel):
    username: str = Field(max_length=64)
    password: str = Field(max_length=256)


class PasswordChangeIn(BaseModel):
    current_password: str = Field(max_length=256)
    new_password: str = Field(max_length=256)


class TokenIn(BaseModel):
    name: str = Field(min_length=1, max_length=64)
    days: int | None = Field(default=90, ge=1, le=365)


class UserIn(BaseModel):
    username: str = Field(min_length=3, max_length=64)
    password: str = Field(max_length=256)
    full_name: str = Field(default="", max_length=128)
    roles: list[Literal["admin", "operator", "viewer"]] = ["operator"]


class UserUpdateIn(BaseModel):
    roles: list[Literal["admin", "operator", "viewer"]] | None = None
    is_active: bool | None = None
    full_name: str | None = Field(default=None, max_length=128)
    new_password: str | None = Field(default=None, max_length=256)


class DiagnosticIn(BaseModel):
    target: str | None = Field(default=None, max_length=512)
    url: str | None = Field(default=None, max_length=512)
    domain: str | None = Field(default=None, max_length=253)
    port: int | None = None
    ports: list[int] | Literal["approved"] | None = None
    count: int | None = None
    timeout: int | None = None
    max_hops: int | None = None
    record_type: str | None = Field(default=None, max_length=8)
    dns_server: str | None = Field(default=None, max_length=45)


class DiscoveryPlanIn(BaseModel):
    range: str = Field(min_length=1, max_length=64)
    wildcard_octets: str | None = Field(default=None, max_length=9)
    methods: list[str] | None = None
    ports: list[int] | None = None
    confirm_large: bool = False


class DiscoveryStartIn(BaseModel):
    confirm: bool = False


class DiscoveryControlIn(BaseModel):
    action: Literal["pause", "resume", "cancel"]


class ServerIn(BaseModel):
    server_code: str | None = Field(default=None, max_length=32)
    name: str = Field(max_length=128)
    hostname: str = Field(default="", max_length=253)
    ip_address: str = Field(max_length=45)
    environment: str = Field(default="Production", max_length=32)
    operating_system: str = Field(default="", max_length=64)
    application: str | None = Field(default=None, max_length=64)
    category: str = Field(default="Other", max_length=64)
    technical_owner: str = Field(default="", max_length=128)
    business_owner: str = Field(default="", max_length=128)
    criticality: str = "Medium"
    approved_ports: list[int] = []
    description: str = Field(default="", max_length=2000)
    status: str = "Active"


class ServerUpdateIn(BaseModel):
    name: str | None = Field(default=None, max_length=128)
    hostname: str | None = Field(default=None, max_length=253)
    ip_address: str | None = Field(default=None, max_length=45)
    environment: str | None = Field(default=None, max_length=32)
    operating_system: str | None = Field(default=None, max_length=64)
    application: str | None = Field(default=None, max_length=64)
    category: str | None = Field(default=None, max_length=64)
    technical_owner: str | None = Field(default=None, max_length=128)
    business_owner: str | None = Field(default=None, max_length=128)
    criticality: str | None = None
    approved_ports: list[int] | None = None
    description: str | None = Field(default=None, max_length=2000)
    status: str | None = None


class RegisterDeviceIn(BaseModel):
    name: str | None = Field(default=None, max_length=128)
    category: str = "Other"
    environment: str = "Production"
    criticality: str = "Medium"
    application: str | None = None
    technical_owner: str = ""
    business_owner: str = ""
    description: str | None = None


class AssistantIn(BaseModel):
    message: str = Field(min_length=1, max_length=2000)
    pending: dict[str, Any] | None = None


class ReportIn(BaseModel):
    report_type: str = Field(max_length=32)
    format: Literal["pdf", "xlsx"] = "pdf"
    references: list[str] | None = None
    target: str = Field(default="", max_length=255)
    scan_reference: str | None = Field(default=None, max_length=32)
    days: int = Field(default=30, ge=1, le=3650)


class SettingsIn(BaseModel):
    values: dict[str, Any]


class NetworkIn(BaseModel):
    name: str = Field(min_length=1, max_length=64)
    cidr: str = Field(max_length=43)
    allow_diagnostics: bool = True
    allow_discovery: bool = True
    allow_os_fingerprint: bool = False
    description: str = Field(default="", max_length=255)


class DomainIn(BaseModel):
    domain: str = Field(min_length=3, max_length=253)
    description: str = Field(default="", max_length=255)


class PortIn(BaseModel):
    port: int = Field(ge=1, le=65535)
    service: str = Field(default="", max_length=64)
    enabled: bool = True
    use_in_discovery: bool = False


class AiSettingsIn(BaseModel):
    enabled: bool | None = None
    model: str | None = Field(default=None, max_length=64)
    api_key: str | None = Field(default=None, max_length=512)
    clear_api_key: bool = False
    send_raw_output: bool | None = None


class RestoreIn(BaseModel):
    file_name: str = Field(max_length=128)
    confirm: bool = False
