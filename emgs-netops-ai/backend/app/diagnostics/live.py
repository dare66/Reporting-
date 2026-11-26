"""Live diagnostics from the laptop's current network connection.

All commands run via ``asyncio.create_subprocess_exec`` with an argument list
(never a shell) and only after the target passed ``validation`` and
``policy``. Every call has a hard timeout; on timeout the process is killed.
"""
from __future__ import annotations

import asyncio
import datetime as dt
import ipaddress
import platform
import shutil
import socket
import ssl
import time
import xml.etree.ElementTree as ET
from pathlib import Path

import dns.asyncresolver
import dns.exception
import dns.resolver
import dns.reversename
import httpx

from app.diagnostics import parsers
from app.diagnostics.base import NetworkBackend
from app.security.sanitize import clean_output


class CommandUnavailable(RuntimeError):
    pass


async def run_command(argv: list[str], timeout: float) -> tuple[int, str]:
    """Run a fixed argv with a hard timeout. Returns (returncode, combined output)."""
    if shutil.which(argv[0]) is None:
        raise CommandUnavailable(f"'{argv[0]}' is not installed on this laptop.")
    proc = await asyncio.create_subprocess_exec(
        *argv, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.STDOUT, stdin=asyncio.subprocess.DEVNULL
    )
    try:
        out, _ = await asyncio.wait_for(proc.communicate(), timeout=timeout)
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        raise TimeoutError(f"'{argv[0]}' exceeded the {timeout:.0f}s execution limit and was stopped.") from None
    text = out.decode("utf-8", errors="replace") if out else ""
    return proc.returncode or 0, clean_output(text)


class LiveBackend(NetworkBackend):
    name = "live"
    simulated = False

    def __init__(self) -> None:
        self.os = platform.system().lower()

    # ------------------------------------------------------------------ resolution
    async def resolve(self, hostname: str, timeout: float) -> list[str]:
        loop = asyncio.get_running_loop()
        try:
            infos = await asyncio.wait_for(
                loop.getaddrinfo(hostname, None, type=socket.SOCK_STREAM), timeout=timeout
            )
        except (socket.gaierror, asyncio.TimeoutError, UnicodeError):
            return []
        seen: list[str] = []
        for info in infos:
            ip = info[4][0]
            if ip not in seen:
                seen.append(ip)
        return seen

    async def reverse_dns(self, ip: str, timeout: float) -> str | None:
        loop = asyncio.get_running_loop()
        try:
            name, *_ = await asyncio.wait_for(loop.run_in_executor(None, socket.gethostbyaddr, ip), timeout)
            return name
        except (OSError, asyncio.TimeoutError):
            return None

    # ------------------------------------------------------------------ ping
    def _ping_argv(self, target: str, count: int, timeout_s: int) -> list[str]:
        v6 = ":" in target
        if self.os == "windows":
            argv = ["ping", "-n", str(count), "-w", str(timeout_s * 1000)]
            return argv + (["-6"] if v6 else []) + [target]
        if self.os == "darwin":
            return ["ping6" if v6 else "ping", "-c", str(count), "-W", str(timeout_s * 1000), "-n", target]
        return ["ping", "-c", str(count), "-W", str(timeout_s), "-n"] + (["-6"] if v6 else []) + [target]

    async def ping(self, target: str, count: int, timeout_s: int, total_timeout: float) -> dict:
        argv = self._ping_argv(target, count, timeout_s)
        try:
            code, out = await run_command(argv, total_timeout)
        except CommandUnavailable:
            # Some locked-down laptops lack ping; there is no safe substitute for ICMP.
            raise
        parsed = parsers.parse_ping_windows(out) if self.os == "windows" else parsers.parse_ping_linux(out)
        if not parsed["transmitted"]:
            parsed["transmitted"] = count
        parsed.update(raw_output=out, method=" ".join(argv), exit_code=code)
        return parsed

    # ------------------------------------------------------------------ traceroute
    async def traceroute(self, target: str, max_hops: int, timeout_s: int, total_timeout: float) -> dict:
        if self.os == "windows":
            argv = ["tracert", "-h", str(max_hops), "-w", str(timeout_s * 1000), target]
            _, out = await run_command(argv, total_timeout)
            hops = parsers.parse_tracert_windows(out)
        elif shutil.which("traceroute"):
            argv = ["traceroute", "-m", str(max_hops), "-w", str(timeout_s), "-q", "2", target]
            _, out = await run_command(argv, total_timeout)
            hops = parsers.parse_traceroute_linux(out)
        elif shutil.which("tracepath"):
            argv = ["tracepath", "-m", str(max_hops), "-n", target]
            _, out = await run_command(argv, total_timeout)
            hops = parsers.parse_tracepath(out)
        else:
            raise CommandUnavailable("Neither 'traceroute' nor 'tracepath' is installed on this laptop.")
        return {"hops": hops, "raw_output": out, "method": " ".join(argv)}

    # ------------------------------------------------------------------ TCP
    async def tcp_connect(self, ip: str, port: int, timeout: float) -> dict:
        start = time.perf_counter()
        try:
            reader, writer = await asyncio.wait_for(asyncio.open_connection(ip, port), timeout=timeout)
            elapsed = (time.perf_counter() - start) * 1000
            writer.close()
            try:
                await writer.wait_closed()
            except OSError:
                pass
            return {"state": "open", "response_ms": round(elapsed, 2), "error": ""}
        except asyncio.TimeoutError:
            return {"state": "filtered", "response_ms": None,
                    "error": f"No response within {timeout:.0f}s (filtered, dropped or host down)"}
        except ConnectionRefusedError:
            elapsed = (time.perf_counter() - start) * 1000
            return {"state": "closed", "response_ms": round(elapsed, 2), "error": "Connection refused (RST received)"}
        except OSError as exc:
            return {"state": "unreachable", "response_ms": None, "error": exc.strerror or str(exc)}

    # ------------------------------------------------------------------ DNS
    async def dns_query(self, name: str, rtype: str, server: str | None, timeout: float) -> dict:
        resolver = dns.asyncresolver.Resolver()
        if server:
            resolver.nameservers = [server]
        resolver.lifetime = timeout
        qname = dns.reversename.from_address(name) if rtype == "PTR" and _is_ip(name) else name
        start = time.perf_counter()
        try:
            answer = await resolver.resolve(qname, rtype, raise_on_no_answer=False)
            elapsed = (time.perf_counter() - start) * 1000
            values = [r.to_text() for r in answer] if answer.rrset else []
            return {"status": "Resolved" if values else "No records", "values": values,
                    "dns_server": answer.nameserver if hasattr(answer, "nameserver") else
                    (resolver.nameservers[0] if resolver.nameservers else ""),
                    "ttl": answer.rrset.ttl if answer.rrset else None, "duration_ms": round(elapsed, 2)}
        except dns.resolver.NXDOMAIN:
            status = "NXDOMAIN (name does not exist)"
        except dns.resolver.NoNameservers:
            status = "No DNS server answered (SERVFAIL/refused)"
        except dns.exception.Timeout:
            status = "DNS timeout"
        except dns.exception.DNSException as exc:
            status = f"DNS error: {exc.__class__.__name__}"
        return {"status": status, "values": [], "dns_server": resolver.nameservers[0] if resolver.nameservers else "",
                "ttl": None, "duration_ms": round((time.perf_counter() - start) * 1000, 2)}

    # ------------------------------------------------------------------ HTTP / TLS
    async def http_check(self, url: str, timeout: float) -> dict:
        start = time.perf_counter()
        redirects: list[dict] = []
        try:
            async with httpx.AsyncClient(follow_redirects=True, timeout=timeout, verify=True,
                                         max_redirects=10, trust_env=False) as client:
                resp = await client.get(url, headers={"User-Agent": "EMGS-NetOps-AI/1.0 (diagnostic)"})
                for h in resp.history:
                    redirects.append({"status": h.status_code, "url": str(h.url),
                                      "location": h.headers.get("location", "")})
                tls_version = None
                stream = resp.extensions.get("network_stream")
                if stream is not None:
                    ssl_obj = stream.get_extra_info("ssl_object")
                    tls_version = ssl_obj.version() if ssl_obj else None
                return {"reachable": True, "status_code": resp.status_code, "reason": resp.reason_phrase,
                        "final_url": str(resp.url), "redirects": redirects, "tls_version": tls_version,
                        "tls_ok": True if str(resp.url).startswith("https") else None,
                        "duration_ms": round((time.perf_counter() - start) * 1000, 2),
                        "server_header": resp.headers.get("server", ""), "error": ""}
        except httpx.ConnectError as exc:
            msg = str(exc)
            tls_fail = "CERTIFICATE_VERIFY_FAILED" in msg or "SSL" in msg
            return _http_fail(start, redirects, ("TLS negotiation failed: " if tls_fail else "Connection failed: ") + msg,
                              tls_ok=False if tls_fail else None)
        except httpx.TimeoutException:
            return _http_fail(start, redirects, f"Timed out after {timeout:.0f}s")
        except httpx.TooManyRedirects:
            return _http_fail(start, redirects, "Too many redirects")
        except httpx.HTTPError as exc:
            return _http_fail(start, redirects, f"HTTP error: {exc.__class__.__name__}: {exc}")

    async def ssl_certificate(self, host: str, port: int, timeout: float) -> dict:
        loop = asyncio.get_running_loop()
        return await asyncio.wait_for(loop.run_in_executor(None, _fetch_cert, host, port, timeout), timeout + 2)

    # ------------------------------------------------------------------ ARP / local network
    async def arp_table(self) -> dict[str, str]:
        if self.os == "linux":
            p = Path("/proc/net/arp")
            if p.exists():
                table = parsers.parse_proc_net_arp(p.read_text())
                if table:
                    return table
            if shutil.which("ip"):
                _, out = await run_command(["ip", "neigh", "show"], 10)
                return parsers.parse_ip_neigh(out)
            return {}
        if shutil.which("arp"):
            _, out = await run_command(["arp", "-a"], 10)
            if self.os == "windows":
                return parsers.parse_arp_windows(out)
            return {m.group(1): parsers.normalize_mac(m.group(2)) for m in
                    __import__("re").finditer(r"\(([\d.]+)\) at ([0-9a-fA-F:]{11,17})", out)}
        return {}

    def source_network(self, target_ip: str | None = None) -> str:
        host = socket.gethostname()
        probe = target_ip or "10.255.255.255"
        try:
            fam = socket.AF_INET6 if ":" in probe else socket.AF_INET
            with socket.socket(fam, socket.SOCK_DGRAM) as s:
                s.connect((probe, 9))  # UDP connect sends no packets; it only selects a route
                local = s.getsockname()[0]
        except OSError:
            local = "no route"
        return f"{host} ({local})"

    # ------------------------------------------------------------------ nmap
    def nmap_available(self) -> bool:
        return shutil.which("nmap") is not None

    async def nmap_discover(self, targets: list[str], ports: list[int], os_detect: bool, timeout: float) -> dict:
        """Host discovery (+ optional OS fingerprint) restricted to approved ports. No scripts, no vuln checks."""
        argv = ["nmap", "-n", "-oX", "-", "--max-retries", "1", "--host-timeout", "30s", "-T3"]
        argv += ["-p", ",".join(str(p) for p in ports)] if ports else ["-sn"]
        if os_detect:
            argv.append("-O")
        argv += targets
        _, out = await run_command(argv, timeout)
        return parse_nmap_xml(out)


def _http_fail(start: float, redirects: list, error: str, tls_ok=None) -> dict:
    return {"reachable": False, "status_code": None, "reason": "", "final_url": "", "redirects": redirects,
            "tls_version": None, "tls_ok": tls_ok, "duration_ms": round((time.perf_counter() - start) * 1000, 2),
            "server_header": "", "error": error}


def _is_ip(v: str) -> bool:
    try:
        ipaddress.ip_address(v)
        return True
    except ValueError:
        return False


def _fetch_cert(host: str, port: int, timeout: float) -> dict:
    """Fetch the server's public certificate and validate it separately.

    Only public certificate fields are returned. Private keys are never
    accessible from a TLS client, and the DER bytes are not stored."""
    from cryptography import x509
    from cryptography.x509.oid import ExtensionOID, NameOID

    raw_ctx = ssl.create_default_context()
    raw_ctx.check_hostname = False
    raw_ctx.verify_mode = ssl.CERT_NONE
    start = time.perf_counter()
    with socket.create_connection((host, port), timeout=timeout) as sock:
        with raw_ctx.wrap_socket(sock, server_hostname=host) as tls:
            der = tls.getpeercert(binary_form=True)
            tls_version = tls.version()
    cert = x509.load_der_x509_certificate(der)

    def _name(n, oid):
        vals = n.get_attributes_for_oid(oid)
        return vals[0].value if vals else ""

    try:
        sans = cert.extensions.get_extension_for_oid(ExtensionOID.SUBJECT_ALTERNATIVE_NAME).value.get_values_for_type(x509.DNSName)
    except x509.ExtensionNotFound:
        sans = []
    not_before = cert.not_valid_before_utc
    not_after = cert.not_valid_after_utc
    now = dt.datetime.now(dt.timezone.utc)

    verify_error = ""
    hostname_match = False
    chain_valid = False
    try:
        ctx = ssl.create_default_context()
        with socket.create_connection((host, port), timeout=timeout) as sock:
            with ctx.wrap_socket(sock, server_hostname=host):
                chain_valid = hostname_match = True
    except ssl.SSLCertVerificationError as exc:
        verify_error = exc.verify_message or str(exc)
        hostname_match = "hostname" not in verify_error.lower() and "IP address mismatch" not in verify_error
        if hostname_match:
            hostname_match = _match_host(host, sans, _name(cert.subject, NameOID.COMMON_NAME))
    except (ssl.SSLError, OSError) as exc:
        verify_error = str(exc)
        hostname_match = _match_host(host, sans, _name(cert.subject, NameOID.COMMON_NAME))

    return {
        "subject": _name(cert.subject, NameOID.COMMON_NAME) or cert.subject.rfc4514_string(),
        "subject_full": cert.subject.rfc4514_string(),
        "issuer": _name(cert.issuer, NameOID.ORGANIZATION_NAME) or cert.issuer.rfc4514_string(),
        "issuer_full": cert.issuer.rfc4514_string(),
        "serial_number": format(cert.serial_number, "X"),
        "valid_from": not_before.isoformat(),
        "valid_until": not_after.isoformat(),
        "days_remaining": (not_after - now).days,
        "san": sans[:50],
        "hostname_match": hostname_match,
        "chain_valid": chain_valid,
        "verify_error": verify_error,
        "tls_version": tls_version,
        "self_signed": cert.issuer == cert.subject,
        "duration_ms": round((time.perf_counter() - start) * 1000, 2),
    }


def _match_host(host: str, sans: list[str], cn: str) -> bool:
    names = sans or ([cn] if cn else [])
    host = host.lower()
    for n in names:
        n = n.lower()
        if n == host or (n.startswith("*.") and host.split(".", 1)[-1] == n[2:] and host.count(".") == n.count(".")):
            return True
    return False


def parse_nmap_xml(xml_text: str) -> dict:
    hosts: dict[str, dict] = {}
    if not xml_text.strip().startswith("<?xml") and "<nmaprun" not in xml_text:
        return hosts
    root = ET.fromstring(xml_text[xml_text.find("<nmaprun"):] if "<nmaprun" in xml_text else xml_text)
    for h in root.findall("host"):
        status = h.find("status")
        if status is None or status.get("state") != "up":
            continue
        ip = mac = vendor = ""
        for a in h.findall("address"):
            if a.get("addrtype") in ("ipv4", "ipv6"):
                ip = a.get("addr", "")
            elif a.get("addrtype") == "mac":
                mac, vendor = a.get("addr", "").lower(), a.get("vendor", "")
        ports = []
        for p in h.findall("ports/port"):
            st = p.find("state")
            ports.append({"port": int(p.get("portid", 0)), "state": st.get("state") if st is not None else "unknown"})
        osm = h.find("os/osmatch")
        hosts[ip] = {"mac": mac, "vendor": vendor, "ports": ports,
                     "os_guess": f"{osm.get('name')} ({osm.get('accuracy')}% confidence)" if osm is not None else "",
                     "reason": status.get("reason", "")}
    return hosts
