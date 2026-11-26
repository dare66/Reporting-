"""Parse and validate discovery ranges.

Supported inputs:
  10.1.57.10                 single address
  10.1.57.xx / 10.1.57.*     wildcard final octet (final-octet range chosen separately)
  10.1.57.0/24               CIDR
  10.1.57.1-10.1.57.254      explicit range
  10.1.57.1-254              short explicit range
"""
from __future__ import annotations

import ipaddress
import re
from dataclasses import dataclass, field

from app.security.validation import ValidationError

HARD_MAX_ADDRESSES = 65536  # absolute ceiling regardless of admin configuration


@dataclass
class ParsedRange:
    input: str
    kind: str  # single | wildcard | cidr | range
    first: ipaddress.IPv4Address
    last: ipaddress.IPv4Address
    notes: list[str] = field(default_factory=list)

    @property
    def count(self) -> int:
        return int(self.last) - int(self.first) + 1

    @property
    def normalized(self) -> str:
        if self.first == self.last:
            return str(self.first)
        return f"{self.first}-{self.last}"

    def addresses(self):
        for i in range(int(self.first), int(self.last) + 1):
            yield ipaddress.IPv4Address(i)

    def networks(self) -> list[ipaddress.IPv4Network]:
        return list(ipaddress.summarize_address_range(self.first, self.last))


def _ip(v: str) -> ipaddress.IPv4Address:
    try:
        return ipaddress.IPv4Address(v.strip())
    except ValueError:
        raise ValidationError(f"'{v.strip()}' is not a valid IPv4 address.") from None


def parse_final_octet_range(spec: str) -> tuple[int, int]:
    m = re.fullmatch(r"\s*(\d{1,3})\s*-\s*(\d{1,3})\s*", spec or "")
    if not m:
        raise ValidationError("Final-octet range must look like '1-254'.")
    lo, hi = int(m.group(1)), int(m.group(2))
    if not (0 <= lo <= 255 and 0 <= hi <= 255 and lo <= hi):
        raise ValidationError("Final-octet range must be within 0-255 and start <= end.")
    return lo, hi


def parse_range(raw: str, wildcard_octets: str = "1-254") -> ParsedRange:
    text = (raw or "").strip().lower()
    if not text:
        raise ValidationError("Enter an IP address, wildcard (10.1.57.xx), CIDR (10.1.57.0/24) or range.")
    if len(text) > 64 or not re.fullmatch(r"[0-9a-z.*/\- ]+", text):
        raise ValidationError("Range contains unsupported characters.")

    m = re.fullmatch(r"(\d{1,3}\.\d{1,3}\.\d{1,3})\.(x{1,3}|\*)", text)
    if m:
        lo, hi = parse_final_octet_range(wildcard_octets)
        base = m.group(1)
        first, last = _ip(f"{base}.{lo}"), _ip(f"{base}.{hi}")
        return ParsedRange(raw, "wildcard", first, last,
                           [f"Wildcard expanded to final octets {lo}-{hi}."])

    if "/" in text:
        try:
            net = ipaddress.IPv4Network(text, strict=False)
        except ValueError:
            raise ValidationError(f"'{raw}' is not a valid IPv4 CIDR block.") from None
        notes = []
        if str(net) != text:
            notes.append(f"Normalised to network {net}.")
        if net.prefixlen <= 30:
            first, last = net.network_address + 1, net.broadcast_address - 1
            notes.append("Network and broadcast addresses excluded.")
        else:
            first, last = net.network_address, net.broadcast_address
        return ParsedRange(raw, "cidr", first, last, notes)

    if "-" in text:
        left, right = (p.strip() for p in text.split("-", 1))
        first = _ip(left)
        if re.fullmatch(r"\d{1,3}", right):
            right = ".".join(left.split(".")[:3] + [right])
        last = _ip(right)
        if int(last) < int(first):
            raise ValidationError("Range end must not be before range start.")
        return ParsedRange(raw, "range", first, last)

    ip = _ip(text)
    return ParsedRange(raw, "single", ip, ip)


def is_private_scope(r: ParsedRange) -> bool:
    """Discovery is restricted to private (RFC1918 / RFC6598) address space."""
    allowed = [ipaddress.IPv4Network(n) for n in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "100.64.0.0/10")]
    return any(r.first in n and r.last in n for n in allowed)
