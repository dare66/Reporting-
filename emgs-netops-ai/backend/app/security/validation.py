"""Strict input validation. Nothing reaches a subprocess or socket unless it
passes these checks, so shell metacharacters and option injection
("-oX file") are impossible by construction."""
from __future__ import annotations

import ipaddress
import re
from dataclasses import dataclass

_HOST_LABEL = re.compile(r"^(?!-)[A-Za-z0-9-]{1,63}(?<!-)$")


class ValidationError(ValueError):
    pass


@dataclass(frozen=True)
class Target:
    value: str
    kind: str  # "ip" | "hostname"

    @property
    def is_ip(self) -> bool:
        return self.kind == "ip"


def is_valid_hostname(value: str) -> bool:
    if not value or len(value) > 253 or value.startswith("-"):
        return False
    labels = value.rstrip(".").split(".")
    return all(_HOST_LABEL.match(lbl) for lbl in labels)


def parse_target(raw: str) -> Target:
    value = (raw or "").strip()
    if not value:
        raise ValidationError("A target IP address or hostname is required.")
    if len(value) > 253:
        raise ValidationError("Target is too long.")
    try:
        ip = ipaddress.ip_address(value)
        return Target(str(ip), "ip")
    except ValueError:
        pass
    if re.fullmatch(r"[\d.]+", value):
        raise ValidationError(f"'{value}' is not a valid IPv4 address.")
    if not is_valid_hostname(value):
        raise ValidationError(f"'{value}' is not a valid IP address or hostname.")
    return Target(value.lower().rstrip("."), "hostname")


def validate_port(port: int | str) -> int:
    try:
        p = int(port)
    except (TypeError, ValueError):
        raise ValidationError(f"'{port}' is not a valid port number.") from None
    if not 1 <= p <= 65535:
        raise ValidationError(f"Port {p} is outside 1-65535.")
    return p


def validate_int(value, name: str, lo: int, hi: int) -> int:
    try:
        v = int(value)
    except (TypeError, ValueError):
        raise ValidationError(f"{name} must be a whole number.") from None
    if not lo <= v <= hi:
        raise ValidationError(f"{name} must be between {lo} and {hi}.")
    return v


DNS_RECORD_TYPES = ("A", "AAAA", "CNAME", "MX", "NS", "TXT", "PTR")


def validate_record_type(rtype: str) -> str:
    r = (rtype or "A").upper()
    if r not in DNS_RECORD_TYPES:
        raise ValidationError(f"Record type must be one of {', '.join(DNS_RECORD_TYPES)}.")
    return r
