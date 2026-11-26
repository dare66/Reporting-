"""EMGS NetOps AI MCP server (stdio) for Claude Code and Codex.

Run:   python mcp-server/server.py
Needs: NETOPS_API_TOKEN (create in the dashboard: Settings > API tokens)

The tools only call the validated backend services; there is no tool for
arbitrary command execution.
"""
from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from mcp.server.mcpserver import MCPServer  # noqa: E402

from tools import netops_tools as t  # noqa: E402

INSTRUCTIONS = """EMGS NetOps AI runs approved network diagnostics from the user's laptop.
Only approved networks, domains and ports can be tested; requests outside policy return an error that explains why.
A failed ping does not prove a server is offline (ICMP may be blocked): follow up with check_tcp_port.
Intermediate traceroute timeouts are normal. Never present a suspected firewall as confirmed.
Network discovery is two-step: call scan_ip_range without confirm to get the plan, show the user the address
count and ports, and only then call it again with plan_reference and confirm=true after the user agrees.
Results where simulated=true come from the built-in lab model, not from EMGS infrastructure."""

mcp = MCPServer("emgs-netops-ai", instructions=INSTRUCTIONS, version="1.0.0")


@mcp.tool(description="Ping an approved IP or hostname from the laptop. Returns packets sent/received, loss, latency and an assessment.")
async def run_ping(target: str, count: int = 4) -> dict:
    return await t.run_ping(target, count)


@mcp.tool(description="Trace the route (tracert/traceroute) to an approved target with a bounded hop count.")
async def run_traceroute(target: str, max_hops: int = 20) -> dict:
    return await t.run_traceroute(target, max_hops)


@mcp.tool(description="Test TCP connectivity to one approved port (open / closed / filtered).")
async def check_tcp_port(target: str, port: int) -> dict:
    return await t.check_tcp_port(target, port)


@mcp.tool(description="Test several approved TCP ports on one target. Omit ports to test every approved port.")
async def check_multiple_ports(target: str, ports: list[int] | None = None) -> dict:
    return await t.check_multiple_ports(target, ports)


@mcp.tool(description="DNS lookup (A, AAAA, CNAME, MX, NS, TXT, PTR) for an approved domain or an approved IP (PTR).")
async def check_dns(domain: str, record_type: str = "A", dns_server: str | None = None) -> dict:
    return await t.check_dns(domain, record_type, dns_server)


@mcp.tool(description="HTTP availability check: status code, response time, redirects.")
async def check_http(url: str) -> dict:
    return await t.check_http(url)


@mcp.tool(description="HTTPS availability check including TLS negotiation.")
async def check_https(url: str) -> dict:
    return await t.check_https(url)


@mcp.tool(description="Inspect a TLS certificate: subject, issuer, validity dates, days remaining, hostname match.")
async def check_ssl_certificate(host: str, port: int = 443) -> dict:
    return await t.check_ssl_certificate(host, port)


@mcp.tool(description="Discover devices in an approved private IP range (10.1.57.xx, CIDR or a-b range). "
                      "Step 1 (confirm=false) returns the plan only. Step 2 needs plan_reference and confirm=true, "
                      "after the user has agreed to the scope.")
async def scan_ip_range(ip_range: str, confirm: bool = False, plan_reference: str | None = None,
                        wildcard_octets: str | None = None) -> dict:
    return await t.scan_ip_range(ip_range, confirm, plan_reference, wildcard_octets)


@mcp.tool(description="List live hosts: from an existing scan (scan_reference) or via the same plan/confirm flow.")
async def discover_hosts(ip_range: str = "", confirm: bool = False, plan_reference: str | None = None,
                         scan_reference: str | None = None) -> dict:
    return await t.discover_hosts(ip_range, confirm, plan_reference, scan_reference)


@mcp.tool(description="Return the local server inventory (optionally filtered by status or category).")
async def get_server_inventory(status: str = "", category: str = "") -> dict:
    return await t.get_server_inventory(status, category)


@mcp.tool(description="Search the server inventory by name, hostname, IP, application or owner.")
async def search_server_inventory(query: str) -> dict:
    return await t.search_server_inventory(query)


@mcp.tool(description="Recent diagnostic executions, optionally filtered by type (ping, traceroute, port, dns, http, https, ssl) or target.")
async def get_diagnostic_history(diagnostic_type: str = "", target: str = "", limit: int = 20) -> dict:
    return await t.get_diagnostic_history(diagnostic_type, target, limit)


@mcp.tool(description="Generate a PDF or Excel report. Types: ping, traceroute, ports, dns, ssl, discovery, inventory, "
                      "unknown_devices, consolidated.")
async def generate_diagnostic_report(report_type: str = "consolidated", format: str = "pdf", target: str = "",
                                     scan_reference: str | None = None, days: int = 30) -> dict:
    return await t.generate_diagnostic_report(report_type, format, target, scan_reference, days)


if __name__ == "__main__":
    mcp.run()
