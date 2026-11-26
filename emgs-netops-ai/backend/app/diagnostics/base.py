"""Network backend interface.

Two implementations share this interface:

* ``LiveBackend``      runs real diagnostics from the laptop (OS-aware adapters).
* ``SimulatedBackend`` answers from a fixed lab model and never sends a packet.

Services pick the backend from the operating mode, so the API, MCP tools and
dashboard behave identically in both modes.
"""
from __future__ import annotations

from abc import ABC, abstractmethod


class NetworkBackend(ABC):
    name = "base"
    simulated = False

    @abstractmethod
    async def resolve(self, hostname: str, timeout: float) -> list[str]:
        """Forward-resolve a hostname to addresses. Empty list = resolution failure."""

    @abstractmethod
    async def reverse_dns(self, ip: str, timeout: float) -> str | None: ...

    @abstractmethod
    async def ping(self, target: str, count: int, timeout_s: int, total_timeout: float) -> dict:
        """Returns parsed ping dict + raw_output + method."""

    @abstractmethod
    async def traceroute(self, target: str, max_hops: int, timeout_s: int, total_timeout: float) -> dict: ...

    @abstractmethod
    async def tcp_connect(self, ip: str, port: int, timeout: float) -> dict:
        """{'state': open|closed|filtered|unreachable, 'response_ms': float|None, 'error': str}"""

    @abstractmethod
    async def dns_query(self, name: str, rtype: str, server: str | None, timeout: float) -> dict: ...

    @abstractmethod
    async def http_check(self, url: str, timeout: float) -> dict: ...

    @abstractmethod
    async def ssl_certificate(self, host: str, port: int, timeout: float) -> dict: ...

    @abstractmethod
    async def arp_table(self) -> dict[str, str]: ...

    @abstractmethod
    def source_network(self, target_ip: str | None = None) -> str:
        """Describe which local interface/address the laptop would use."""

    def nmap_available(self) -> bool:
        return False

    async def nmap_discover(self, targets: list[str], ports: list[int], os_detect: bool, timeout: float) -> dict:
        return {}
