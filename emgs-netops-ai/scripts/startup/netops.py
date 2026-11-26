#!/usr/bin/env python3
"""EMGS NetOps AI launcher (used by start_netops.sh / start_netops.bat).

  netops.py start [--mobile] [--dev] [--port 8750]
  netops.py stop
  netops.py status
  netops.py check

Default: HTTP on 127.0.0.1 only (this laptop). Nothing is exposed to the network.
--mobile: additionally serves HTTPS on the private address an administrator
          approved in Settings > Mobile access (Tailscale / VPN / office Wi-Fi).
          A local self-signed certificate is generated on first use.
--dev:    also starts the Vite dev server on 127.0.0.1:5173 with hot reload.
"""
from __future__ import annotations

import argparse
import asyncio
import datetime as dt
import ipaddress
import os
import platform
import shutil
import signal
import socket
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "backend"))
os.chdir(ROOT / "backend")

MOBILE_PORT_OFFSET = 1  # mobile HTTPS port = backend port + 1 (default 8751)
MIN_PY = (3, 11)


def info(msg: str) -> None:
    print(f"[netops] {msg}", flush=True)


def fail(msg: str, code: int = 1) -> None:
    print(f"[netops] ERROR: {msg}", file=sys.stderr, flush=True)
    raise SystemExit(code)


# ------------------------------------------------------------------ checks

def check_environment(verbose: bool = True) -> dict:
    report = {"python": platform.python_version(), "os": platform.system(), "ok": True, "missing": [], "tools": {}}
    if sys.version_info < MIN_PY:
        report["ok"] = False
        report["missing"].append(f"Python {MIN_PY[0]}.{MIN_PY[1]}+ (found {platform.python_version()})")
    for mod in ("fastapi", "uvicorn", "sqlalchemy", "dns", "httpx", "openpyxl", "reportlab", "cryptography"):
        try:
            __import__(mod)
        except ImportError:
            report["ok"] = False
            report["missing"].append(f"Python package '{mod}' (run: pip install -r backend/requirements.txt)")
    tools = ["ping", "tracert"] if platform.system() == "Windows" else ["ping", "traceroute", "tracepath", "ip"]
    for t in tools + ["nmap", "node", "npm"]:
        report["tools"][t] = bool(shutil.which(t))
    if not (ROOT / "frontend" / "dist" / "index.html").exists():
        report["missing"].append("Built dashboard (frontend/dist). The start script builds it when Node.js is installed.")
    if verbose:
        info(f"Python {report['python']} on {report['os']}")
        for t, ok in report["tools"].items():
            note = ""
            if t == "nmap" and not ok:
                note = " (optional: native ICMP/TCP/ARP discovery is used instead)"
            if t in ("traceroute", "tracepath") and not ok:
                note = " (needed for traceroute on Linux; install 'traceroute' or 'iputils-tracepath')"
            info(f"  {t:<10} {'found' if ok else 'not found'}{note}")
        for m in report["missing"]:
            info(f"  MISSING: {m}")
    return report


def pid_file() -> Path:
    from app.core.config import get_settings
    return get_settings().data_dir / "netops.pid"


def _running_pid() -> int | None:
    p = pid_file()
    if not p.exists():
        return None
    try:
        pid = int(p.read_text().strip())
        os.kill(pid, 0)
        return pid
    except (ValueError, ProcessLookupError, PermissionError, OSError):
        p.unlink(missing_ok=True)
        return None


# ------------------------------------------------------------------ TLS for mobile

def ensure_certificate(bind_ip: str) -> tuple[Path, Path]:
    from cryptography import x509
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import ec
    from cryptography.x509.oid import NameOID

    from app.core.config import get_settings
    cert_dir = get_settings().data_dir / "certs"
    cert_dir.mkdir(parents=True, exist_ok=True)
    cert_path, key_path = cert_dir / "netops-mobile.crt", cert_dir / "netops-mobile.key"
    if cert_path.exists() and key_path.exists():
        cert = x509.load_pem_x509_certificate(cert_path.read_bytes())
        sans = cert.extensions.get_extension_for_class(x509.SubjectAlternativeName).value
        if ipaddress.ip_address(bind_ip) in sans.get_values_for_type(x509.IPAddress) and \
                cert.not_valid_after_utc > dt.datetime.now(dt.timezone.utc) + dt.timedelta(days=7):
            return cert_path, key_path
    info("Generating a local HTTPS certificate for mobile access…")
    key = ec.generate_private_key(ec.SECP256R1())
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, f"EMGS NetOps AI ({socket.gethostname()})")])
    now = dt.datetime.now(dt.timezone.utc)
    cert = (x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key())
            .serial_number(x509.random_serial_number()).not_valid_before(now - dt.timedelta(minutes=5))
            .not_valid_after(now + dt.timedelta(days=365))
            .add_extension(x509.SubjectAlternativeName([x509.IPAddress(ipaddress.ip_address(bind_ip)),
                                                        x509.DNSName(socket.gethostname())]), critical=False)
            .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
            .sign(key, hashes.SHA256()))
    key_path.write_bytes(key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8,
                                           serialization.NoEncryption()))
    try:
        os.chmod(key_path, 0o600)
    except OSError:
        pass
    cert_path.write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    return cert_path, key_path


def validate_mobile_bind(ip: str) -> str:
    try:
        addr = ipaddress.ip_address(ip)
    except ValueError:
        fail(f"Mobile bind address '{ip}' is not a valid IP. Set it in Settings > Mobile access.")
    if addr.is_unspecified:
        fail("Refusing to bind to 0.0.0.0/:: (all interfaces). Use the laptop's private VPN/Wi-Fi address.")
    private = addr.is_private or addr in ipaddress.ip_network("100.64.0.0/10")
    if not private or addr.is_loopback:
        fail(f"{ip} is not a private address. Mobile access must use a private network (VPN, Tailscale or office Wi-Fi).")
    try:
        with socket.socket(socket.AF_INET6 if addr.version == 6 else socket.AF_INET) as s:
            s.bind((ip, 0))
    except OSError:
        fail(f"{ip} is not assigned to this laptop. Connect to the VPN / Wi-Fi first, or update Settings > Mobile access.")
    return ip


# ------------------------------------------------------------------ start / stop

def build_frontend_if_needed() -> None:
    dist = ROOT / "frontend" / "dist" / "index.html"
    if dist.exists():
        return
    npm = shutil.which("npm") or shutil.which("npm.cmd")
    if not npm:
        fail("The dashboard is not built and Node.js/npm is not installed. Install Node.js 20+ and run the start script again.")
    info("Building the dashboard (first run only)…")
    fe = ROOT / "frontend"
    subprocess.run([npm, "ci" if (fe / "package-lock.json").exists() else "install", "--no-audit", "--no-fund"], cwd=fe, check=True)
    subprocess.run([npm, "run", "build"], cwd=fe, check=True)


def start(args) -> None:
    import uvicorn

    from app.core.config import get_settings
    from app.database.migrations import run_migrations, schema_version
    from app.database.session import session_scope
    from app.services.settings import get_setting

    if _running_pid():
        fail(f"EMGS NetOps AI is already running (pid {_running_pid()}). Use the stop script first.")
    rep = check_environment(verbose=args.verbose)
    if not rep["ok"]:
        fail("Dependencies missing: " + "; ".join(rep["missing"]))
    if not args.dev:
        build_frontend_if_needed()
    settings = get_settings()
    port = args.port or settings.backend_port
    applied = run_migrations()
    info(f"Database ready at {settings.database_path} (schema v{schema_version()}{', applied ' + str(applied) if applied else ''})")
    with session_scope() as db:
        mode = get_setting(db, "operating_mode")
        mobile = get_setting(db, "mobile_access") or {}

    from app.main import app
    servers = [uvicorn.Server(uvicorn.Config(app, host="127.0.0.1", port=port, log_level="warning", proxy_headers=False))]
    mobile_url = None
    if args.mobile:
        if not mobile.get("enabled") or not mobile.get("bind_ip"):
            fail("Mobile access is not enabled. An administrator must set it in Settings > Mobile access first.")
        ip = validate_mobile_bind(mobile["bind_ip"])
        cert, key = ensure_certificate(ip)
        mport = port + MOBILE_PORT_OFFSET
        servers.append(uvicorn.Server(uvicorn.Config(app, host=ip, port=mport, log_level="warning",
                                                     ssl_certfile=str(cert), ssl_keyfile=str(key), proxy_headers=False)))
        host = f"[{ip}]" if ":" in ip else ip
        mobile_url = f"https://{host}:{mport}"

    vite = None
    if args.dev:
        npm = shutil.which("npm") or shutil.which("npm.cmd")
        if not npm:
            fail("--dev needs Node.js/npm.")
        vite = subprocess.Popen([npm, "run", "dev"], cwd=ROOT / "frontend")

    pid_file().write_text(str(os.getpid()))
    print()
    info("EMGS NetOps AI is running.")
    info(f"  Operating mode : {mode.upper()}" + ("  (lab data only, no network traffic)" if mode == "simulation" else ""))
    info(f"  Dashboard      : http://127.0.0.1:{port}" + (f"   (dev UI: http://127.0.0.1:{settings.frontend_port})" if args.dev else ""))
    info(f"  API docs       : http://127.0.0.1:{port}/api/docs")
    if mobile_url:
        info(f"  Mobile (HTTPS) : {mobile_url}  - open on the phone over VPN/Tailscale/office Wi-Fi, accept the certificate once, sign in.")
    else:
        info("  Mobile access  : off. To use a phone, enable it in Settings > Mobile access, then restart with --mobile.")
        info("                   Instructions: docs/deployment/mobile-access.md")
    info("  Stop           : press Ctrl+C, or run the stop script.")
    print(flush=True)

    async def serve_all():
        await asyncio.gather(*(s.serve() for s in servers))

    try:
        asyncio.run(serve_all())
    except KeyboardInterrupt:
        pass
    finally:
        if vite:
            vite.terminate()
        pid_file().unlink(missing_ok=True)
        info("Stopped.")


def stop() -> None:
    pid = _running_pid()
    if not pid:
        info("EMGS NetOps AI is not running.")
        return
    info(f"Stopping (pid {pid})…")
    if platform.system() == "Windows":
        subprocess.run(["taskkill", "/PID", str(pid), "/T", "/F"], check=False, capture_output=True)
    else:
        os.kill(pid, signal.SIGINT)  # uvicorn shuts down cleanly on SIGINT
    pid_file().unlink(missing_ok=True)
    info("Stop signal sent.")


def main() -> None:
    ap = argparse.ArgumentParser(description="EMGS NetOps AI launcher")
    ap.add_argument("command", nargs="?", default="start", choices=["start", "stop", "status", "check"])
    ap.add_argument("--mobile", action="store_true", help="also serve HTTPS on the approved private address")
    ap.add_argument("--dev", action="store_true", help="also run the Vite dev server")
    ap.add_argument("--port", type=int, default=None)
    ap.add_argument("--verbose", action="store_true")
    args = ap.parse_args()
    if args.command == "check":
        raise SystemExit(0 if check_environment()["ok"] else 1)
    if args.command == "stop":
        stop()
    elif args.command == "status":
        pid = _running_pid()
        info(f"running (pid {pid})" if pid else "not running")
    else:
        start(args)


if __name__ == "__main__":
    main()
