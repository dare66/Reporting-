"""Runtime settings stored in ``application_settings``."""
from __future__ import annotations

from typing import Any

from sqlalchemy.orm import Session

from app.database.migrations import DEFAULT_SETTINGS
from app.models import entities as m
from app.security.validation import ValidationError

MODES = ("simulation", "live", "review")

# key -> (type, min, max) for numeric validation
_NUMERIC = {
    "default_ping_count": (1, 20),
    "ping_timeout_s": (1, 10),
    "execution_timeout_s": (5, 600),
    "traceroute_max_hops": (1, 30),
    "tcp_timeout_s": (1, 15),
    "scan_concurrency": (1, 128),
    "scan_rate_per_second": (1, 500),
    "max_subnet_prefix": (16, 32),
    "rate_limit_per_minute": (5, 1000),
    "retention_days": (7, 3650),
    "session_hours": (1, 24),
}


def get_setting(db: Session, key: str) -> Any:
    row = db.get(m.ApplicationSetting, key)
    return row.value if row is not None else DEFAULT_SETTINGS.get(key)


def all_settings(db: Session) -> dict[str, Any]:
    data = dict(DEFAULT_SETTINGS)
    for row in db.query(m.ApplicationSetting).all():
        data[row.key] = row.value
    return data


def validate_setting(key: str, value: Any) -> Any:
    if key not in DEFAULT_SETTINGS:
        raise ValidationError(f"Unknown setting '{key}'.")
    if key == "operating_mode":
        if value not in MODES:
            raise ValidationError(f"operating_mode must be one of {', '.join(MODES)}.")
        return value
    if key in _NUMERIC:
        lo, hi = _NUMERIC[key]
        try:
            v = int(value)
        except (TypeError, ValueError):
            raise ValidationError(f"{key} must be a number.") from None
        if not lo <= v <= hi:
            raise ValidationError(f"{key} must be between {lo} and {hi}.")
        return v
    if key == "allow_large_scans":
        return bool(value)
    if key == "discovery_methods":
        allowed = {"icmp", "arp", "tcp", "reverse_dns", "nmap", "os_fingerprint"}
        if not isinstance(value, list) or not set(value) <= allowed:
            raise ValidationError(f"discovery_methods must be a subset of {sorted(allowed)}.")
        return value
    if key == "mobile_access":
        if not isinstance(value, dict):
            raise ValidationError("mobile_access must be an object.")
        return {"enabled": bool(value.get("enabled")), "bind_ip": str(value.get("bind_ip", ""))[:45],
                "https": bool(value.get("https", True)), "note": str(value.get("note", ""))[:255]}
    if key == "default_wildcard_range":
        from app.security.ranges import parse_final_octet_range
        parse_final_octet_range(str(value))
        return str(value)
    return str(value)[:512]


def set_setting(db: Session, key: str, value: Any) -> Any:
    value = validate_setting(key, value)
    row = db.get(m.ApplicationSetting, key)
    if row is None:
        db.add(m.ApplicationSetting(key=key, value=value))
    else:
        row.value = value
    db.flush()
    return value


def current_mode(db: Session) -> str:
    return get_setting(db, "operating_mode") or "simulation"
