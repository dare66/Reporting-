"""Target authorisation policy.

Every diagnostic and discovery request passes through ``TargetPolicy``:

* IP targets must fall inside an approved network with diagnostics enabled.
* Hostnames must match an approved domain (suffix match) or a registered
  inventory hostname, and every address they resolve to is checked too:
  private addresses must be inside an approved network; public addresses
  are accepted only for approved domains (e.g. a public website).
* Ports must be on the approved port list.
* Discovery ranges must be private, fully inside an approved network that
  allows discovery, and within the configured size limit.
* Sample policy rows (is_sample) only apply in Simulation mode.
"""
from __future__ import annotations

import ipaddress
from dataclasses import dataclass

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models import entities as m
from app.security.ranges import HARD_MAX_ADDRESSES, ParsedRange, is_private_scope
from app.security.validation import Target
from app.services.settings import get_setting


class PolicyError(PermissionError):
    """The request is valid but not authorised by local policy."""


@dataclass
class AuthorizedTarget:
    target: Target
    addresses: list[str]
    reason: str


class TargetPolicy:
    def __init__(self, db: Session, mode: str):
        self.db = db
        self.mode = mode
        include_sample = mode == "simulation"
        nets = db.scalars(select(m.ApprovedNetwork)).all()
        self.networks = [n for n in nets if include_sample or not n.is_sample]
        doms = db.scalars(select(m.ApprovedDomain)).all()
        self.domains = [d.domain.lower().rstrip(".") for d in doms if include_sample or not d.is_sample]
        self.ports = {p.port: p for p in db.scalars(select(m.ApprovedPort)).all() if p.enabled}
        servers = db.scalars(select(m.Server)).all()
        self.inventory_hosts = {
            s.hostname.lower() for s in servers if s.hostname and (include_sample or not s.is_sample)
        }

    # ------------------------------------------------------------------ helpers
    def _net_for(self, ip: str, *, discovery: bool = False) -> m.ApprovedNetwork | None:
        addr = ipaddress.ip_address(ip)
        for n in self.networks:
            net = ipaddress.ip_network(n.cidr, strict=False)
            if addr.version == net.version and addr in net:
                if discovery and not n.allow_discovery:
                    continue
                if not discovery and not n.allow_diagnostics:
                    continue
                return n
        return None

    def domain_approved(self, hostname: str) -> bool:
        h = hostname.lower().rstrip(".")
        return any(h == d or h.endswith("." + d) for d in self.domains)

    def ip_approved(self, ip: str) -> bool:
        return self._net_for(ip) is not None

    # ------------------------------------------------------------------ targets
    def authorize_target(self, target: Target, resolved: list[str] | None = None) -> AuthorizedTarget:
        if target.is_ip:
            addr = ipaddress.ip_address(target.value)
            if addr.is_loopback or addr.is_multicast or addr.is_unspecified:
                raise PolicyError(f"{target.value} is not a permitted diagnostic target.")
            net = self._net_for(target.value)
            if not net:
                raise PolicyError(
                    f"{target.value} is not inside an approved network. "
                    "An administrator can add the range under Settings > Approved networks."
                )
            return AuthorizedTarget(target, [target.value], f"inside approved network {net.cidr} ({net.name})")

        approved_domain = self.domain_approved(target.value)
        in_inventory = target.value in self.inventory_hosts
        if not (approved_domain or in_inventory):
            raise PolicyError(
                f"Hostname '{target.value}' is not an approved domain or a registered inventory host."
            )
        for ip in resolved or []:
            addr = ipaddress.ip_address(ip)
            if addr.is_loopback or addr.is_unspecified or addr.is_multicast:
                raise PolicyError(f"'{target.value}' resolves to a non-permitted address {ip}.")
            if addr.is_private and not self.ip_approved(ip):
                raise PolicyError(
                    f"'{target.value}' resolves to {ip}, which is outside the approved networks."
                )
            if not addr.is_private and not approved_domain:
                raise PolicyError(f"'{target.value}' resolves to public address {ip}; only approved domains may.")
        reason = "approved domain" if approved_domain else "registered inventory hostname"
        return AuthorizedTarget(target, list(resolved or []), reason)

    def authorize_port(self, port: int) -> None:
        if port not in self.ports:
            raise PolicyError(
                f"Port {port} is not on the approved port list. An administrator can add it in Settings."
            )

    def authorize_dns_name(self, name: str) -> None:
        try:
            ipaddress.ip_address(name)
            if not self.ip_approved(name):
                raise PolicyError(f"Reverse lookup of {name} is outside the approved networks.")
            return
        except ValueError:
            pass
        if not (self.domain_approved(name) or name.lower() in self.inventory_hosts):
            raise PolicyError(f"'{name}' is not an approved domain.")

    # ------------------------------------------------------------------ discovery
    def authorize_range(self, r: ParsedRange, *, confirm_large: bool = False) -> dict:
        if not is_private_scope(r):
            raise PolicyError("Discovery is limited to private EMGS on-premises address space. "
                              "Public networks can never be scanned.")
        if r.count > HARD_MAX_ADDRESSES:
            raise PolicyError(f"Range has {r.count} addresses; the absolute limit is {HARD_MAX_ADDRESSES}.")
        max_prefix = int(get_setting(self.db, "max_subnet_prefix") or 24)
        limit = 2 ** (32 - max_prefix)
        allow_large = bool(get_setting(self.db, "allow_large_scans"))
        large = r.count > limit
        if large and not allow_large:
            raise PolicyError(
                f"Range has {r.count} addresses; the configured maximum is {limit} (/{max_prefix}). "
                "An administrator must enable larger scans in Settings."
            )
        if large and not confirm_large:
            raise PolicyError(f"Range has {r.count} addresses (above /{max_prefix}). "
                              "Additional confirmation is required for large scans.")
        covering = None
        for n in self.networks:
            if not n.allow_discovery:
                continue
            net = ipaddress.ip_network(n.cidr, strict=False)
            if net.version == 4 and r.first in net and r.last in net:
                covering = n
                break
        if not covering:
            raise PolicyError(f"{r.normalized} is not fully inside an approved network that allows discovery.")
        return {"network": covering.cidr, "network_name": covering.name, "large": large,
                "os_fingerprint_allowed": covering.allow_os_fingerprint}
