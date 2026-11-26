#!/usr/bin/env python3
"""EMGS NetOps AI command-line interface.

A local tool interface for Codex, scripts or a terminal. It uses the same
validated services as the dashboard and MCP server and prints JSON.

  python scripts/netops_cli.py create-token --username admin       # one-time, prompts for password
  export NETOPS_API_TOKEN=nop_...
  python scripts/netops_cli.py ping 10.1.57.10
  python scripts/netops_cli.py port 10.1.57.15 3306 443
  python scripts/netops_cli.py dns emgs.com.my --type MX
  python scripts/netops_cli.py scan 10.1.57.0/24                    # shows plan
  python scripts/netops_cli.py scan 10.1.57.0/24 --confirm DS-...   # executes the plan
"""
from __future__ import annotations

import argparse
import asyncio
import getpass
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "mcp-server"))
from tools import netops_tools as t  # noqa: E402


def _create_token(username: str, name: str, days: int) -> dict:
    from app.database.migrations import run_migrations
    from app.database.session import session_scope
    from app.services import audit, auth
    run_migrations()
    password = getpass.getpass(f"Password for {username}: ")
    with session_scope() as db:
        user = auth.authenticate(db, username, password, "cli")
        token, row = auth.create_api_token(db, user, name, days)
        audit.record(db, auth.context_for(user, "cli"), "auth.token_create", reference=row.prefix, detail=name)
    return {"ok": True, "token": token, "note": "Shown once. Store it in your MCP / Codex configuration."}


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="netops", description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    s = sub.add_parser("create-token")
    s.add_argument("--username", required=True)
    s.add_argument("--name", default="cli")
    s.add_argument("--days", type=int, default=90)
    s = sub.add_parser("ping")
    s.add_argument("target")
    s.add_argument("--count", type=int, default=4)
    s = sub.add_parser("traceroute")
    s.add_argument("target")
    s.add_argument("--max-hops", type=int, default=None)
    s = sub.add_parser("port")
    s.add_argument("target")
    s.add_argument("ports", nargs="*", type=int, help="omit to test all approved ports")
    s = sub.add_parser("dns")
    s.add_argument("domain")
    s.add_argument("--type", default="A")
    s.add_argument("--server", default=None)
    for name in ("http", "https"):
        s = sub.add_parser(name)
        s.add_argument("url")
    s = sub.add_parser("ssl")
    s.add_argument("host")
    s.add_argument("--port", type=int, default=443)
    s = sub.add_parser("scan")
    s.add_argument("range")
    s.add_argument("--confirm", metavar="PLAN_REFERENCE", default=None)
    s.add_argument("--octets", default=None, help="final-octet range for wildcard input, e.g. 1-100")
    s = sub.add_parser("inventory")
    s.add_argument("query", nargs="?", default="")
    s = sub.add_parser("history")
    s.add_argument("--type", default="")
    s.add_argument("--limit", type=int, default=20)
    s = sub.add_parser("report")
    s.add_argument("type", default="consolidated", nargs="?")
    s.add_argument("--format", choices=["pdf", "xlsx"], default="pdf")
    a = ap.parse_args(argv)

    c = "cli"
    if a.cmd == "create-token":
        out = _create_token(a.username, a.name, a.days)
    else:
        coro = {
            "ping": lambda: t.run_ping(a.target, a.count, interface=c),
            "traceroute": lambda: t.run_traceroute(a.target, a.max_hops, interface=c),
            "port": lambda: t.check_multiple_ports(a.target, a.ports or None, interface=c),
            "dns": lambda: t.check_dns(a.domain, a.type, a.server, interface=c),
            "http": lambda: t.check_http(a.url, interface=c),
            "https": lambda: t.check_https(a.url, interface=c),
            "ssl": lambda: t.check_ssl_certificate(a.host, a.port, interface=c),
            "scan": lambda: t.scan_ip_range(a.range, bool(a.confirm), a.confirm, a.octets, interface=c),
            "inventory": lambda: (t.search_server_inventory(a.query, interface=c) if a.query
                                  else t.get_server_inventory(interface=c)),
            "history": lambda: t.get_diagnostic_history(a.type, "", a.limit, interface=c),
            "report": lambda: t.generate_diagnostic_report(a.type, a.format, interface=c),
        }[a.cmd]
        out = asyncio.run(coro())
    print(json.dumps(out, indent=2, default=str))
    return 0 if out.get("ok", True) else 1


if __name__ == "__main__":
    raise SystemExit(main())
