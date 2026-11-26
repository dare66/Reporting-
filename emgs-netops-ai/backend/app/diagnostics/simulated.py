"""Simulation mode: a fixed, clearly-labelled lab network.

Used for development, testing and demonstrations away from the EMGS network.
It never opens a socket or runs a command. Every result carries
``simulated: true`` and the UI shows a SIMULATION banner.

The lab model deliberately includes awkward cases: an ICMP-blocked database,
a host with packet loss, an unknown device, a hostname mismatch, an expiring
and a mismatched certificate, and a registered server that no longer answers.
"""
from __future__ import annotations

import asyncio
import datetime as dt
import hashlib
import ipaddress

from app.diagnostics.base import NetworkBackend

SUBNET = ipaddress.ip_network("10.1.57.0/24")


def _h(ip: str, name: str | None, *, icmp=True, loss=0, ports=(), mac=True, os="", latency=1.2, cert=None):
    return {"ip": ip, "hostname": name, "icmp": icmp, "loss": loss, "ports": set(ports), "mac": mac,
            "os": os, "latency": latency, "cert": cert}


LAB_HOSTS: dict[str, dict] = {h["ip"]: h for h in [
    _h("10.1.57.1", "sample-core-sw01.lab.local", ports={22, 443}, os="Network OS (simulated)", latency=0.6),
    _h("10.1.57.10", "sample-stars-app01.lab.local", ports={22, 443}, os="Linux 5.x (simulated)",
       cert={"days": 210, "cn": "sample-stars-app01.lab.local"}),
    # ICMP blocked by host firewall but the service answers on TCP.
    _h("10.1.57.15", "sample-stars-db01.lab.local", icmp=False, ports={3306}, os="Linux 5.x (simulated)"),
    _h("10.1.57.20", "sample-visa-web01.lab.local", ports={80, 443}, os="Linux 5.x (simulated)",
       cert={"days": 21, "cn": "visa.emgs.com.my", "san": ["visa.emgs.com.my", "emgs.com.my", "sample-visa-web01.lab.local"]}),
    _h("10.1.57.25", "sample-flaky-ap.lab.local", loss=2, ports={80}, os="Embedded (simulated)", latency=18.0),
    _h("10.1.57.30", "sample-hub01.lab.local", ports={443, 3389}, os="Windows Server 2022 (simulated)",
       cert={"days": 95, "cn": "old-hub.lab.local"}),
    # Answers with a different hostname from the one in the inventory.
    _h("10.1.57.40", "sample-crm02.lab.local", ports={443, 3389}, os="Windows Server 2019 (simulated)",
       cert={"days": 330, "cn": "sample-crm02.lab.local"}),
    _h("10.1.57.50", "sample-fs01.lab.local", ports={445}, os="Windows Server 2019 (simulated)"),
    # 10.1.57.60 (registered legacy app) intentionally absent: "Registered but Not Responding".
    _h("10.1.57.77", None, ports={80}, os="", latency=3.4),                       # unknown device
    _h("10.1.57.88", "sample-printer-3f.lab.local", ports={80, 443}, os="Printer firmware (simulated)",
       cert={"days": -12, "cn": "sample-printer-3f.lab.local", "self_signed": True}),
    _h("10.1.57.91", None, icmp=False, ports={22, 3389}, mac=True, os="Windows 10 (simulated)"),  # admin ports, unregistered
]}

DNS_ZONE = {
    "emgs.com.my": {"A": ["10.1.57.20"], "MX": ["10 mail.emgs.com.my."], "NS": ["ns1.emgs.com.my.", "ns2.emgs.com.my."],
                    "TXT": ['"v=spf1 include:sample.invalid -all"'], "AAAA": [], "CNAME": []},
    "visa.emgs.com.my": {"A": ["10.1.57.20"], "CNAME": [], "AAAA": []},
    "www.emgs.com.my": {"CNAME": ["visa.emgs.com.my."], "A": ["10.1.57.20"]},
    "stars.emgs.com.my": {"A": ["10.1.57.10"]},
    "hub.emgs.com.my": {"A": ["10.1.57.30"]},
}
for _ip, _host in LAB_HOSTS.items():
    if _host["hostname"]:
        DNS_ZONE.setdefault(_host["hostname"], {})["A"] = [_ip]


def _mac(ip: str) -> str:
    d = hashlib.sha256(ip.encode()).hexdigest()
    return "02:" + ":".join(d[i:i + 2] for i in range(0, 10, 2))  # locally-administered => obviously not real


def _jitter(ip: str, i: int, base: float) -> float:
    d = int(hashlib.md5(f"{ip}:{i}".encode()).hexdigest()[:4], 16) / 65535
    return round(base * (0.8 + 0.4 * d), 3)


class SimulatedBackend(NetworkBackend):
    name = "simulation"
    simulated = True

    def __init__(self, delay: float = 0.0):
        self.delay = delay

    async def _pause(self, factor: float = 1.0) -> None:
        if self.delay:
            await asyncio.sleep(self.delay * factor)

    async def resolve(self, hostname: str, timeout: float) -> list[str]:
        await self._pause()
        rec = DNS_ZONE.get(hostname.lower())
        return list(rec.get("A", [])) if rec else []

    async def reverse_dns(self, ip: str, timeout: float) -> str | None:
        h = LAB_HOSTS.get(ip)
        return h["hostname"] if h else None

    async def ping(self, target: str, count: int, timeout_s: int, total_timeout: float) -> dict:
        await self._pause(count)
        h = LAB_HOSTS.get(target)
        replies = []
        if h and h["icmp"]:
            replies = [_jitter(target, i, h["latency"]) for i in range(max(0, count - min(h["loss"], count - 1)))]
        lines = [f"PING {target} ({target}) 56(84) bytes of data.  [SIMULATED]"]
        lines += [f"64 bytes from {target}: icmp_seq={i + 1} ttl=63 time={t} ms" for i, t in enumerate(replies)]
        loss = round(100 * (count - len(replies)) / count, 1)
        lines.append(f"--- {target} ping statistics ---")
        lines.append(f"{count} packets transmitted, {len(replies)} received, {loss}% packet loss")
        res = {"transmitted": count, "received": len(replies), "loss_pct": loss, "replies": replies,
               "resolved_ip": target, "min_ms": None, "avg_ms": None, "max_ms": None}
        if replies:
            res.update(min_ms=min(replies), max_ms=max(replies), avg_ms=round(sum(replies) / len(replies), 3))
            lines.append(f"rtt min/avg/max/mdev = {res['min_ms']}/{res['avg_ms']}/{res['max_ms']}/0.1 ms")
        res.update(raw_output="\n".join(lines), method="simulated ping", exit_code=0 if replies else 1)
        return res

    async def traceroute(self, target: str, max_hops: int, timeout_s: int, total_timeout: float) -> dict:
        await self._pause(3)
        path = [("192.168.88.1", "sample-laptop-gw.lab.local", 1.1), ("10.0.0.1", "sample-core-rtr.lab.local", 2.4),
                (None, None, None), ("10.1.57.1", "sample-core-sw01.lab.local", 3.0)]
        hops = []
        for i, (ip, name, t) in enumerate(path[:max_hops], start=1):
            if ip is None:
                hops.append({"hop": i, "ip": None, "hostname": None, "times_ms": [], "avg_ms": None,
                             "timeouts": 2, "status": "timeout"})
            else:
                times = [_jitter(ip, 1, t), _jitter(ip, 2, t)]
                hops.append({"hop": i, "ip": ip, "hostname": name, "times_ms": times,
                             "avg_ms": round(sum(times) / 2, 3), "timeouts": 0, "status": "ok"})
        h = LAB_HOSTS.get(target)
        n = len(hops) + 1
        if target != "10.1.57.1" and n <= max_hops:
            if h and h["icmp"]:
                times = [_jitter(target, 1, h["latency"] + 3), _jitter(target, 2, h["latency"] + 3)]
                hops.append({"hop": n, "ip": target, "hostname": h["hostname"], "times_ms": times,
                             "avg_ms": round(sum(times) / 2, 3), "timeouts": 0, "status": "ok"})
            else:
                for j in range(n, min(n + 3, max_hops + 1)):
                    hops.append({"hop": j, "ip": None, "hostname": None, "times_ms": [], "avg_ms": None,
                                 "timeouts": 2, "status": "timeout"})
        raw = [f"traceroute to {target} ({target}), {max_hops} hops max  [SIMULATED]"]
        for hp in hops:
            raw.append(f"{hp['hop']:>2}  " + (f"{hp['hostname'] or hp['ip']} ({hp['ip']})  " +
                       "  ".join(f"{t} ms" for t in hp["times_ms"]) if hp["ip"] else "* *"))
        return {"hops": hops, "raw_output": "\n".join(raw), "method": "simulated traceroute"}

    async def tcp_connect(self, ip: str, port: int, timeout: float) -> dict:
        await self._pause(0.2)
        h = LAB_HOSTS.get(ip)
        if not h:
            return {"state": "filtered", "response_ms": None,
                    "error": f"No response within {timeout:.0f}s (filtered, dropped or host down) [simulated]"}
        if port in h["ports"]:
            return {"state": "open", "response_ms": _jitter(ip, port, h["latency"] + 0.4), "error": ""}
        if ip == "10.1.57.15" and port == 22:
            return {"state": "filtered", "response_ms": None, "error": "No response (filtered) [simulated]"}
        return {"state": "closed", "response_ms": _jitter(ip, port, h["latency"]),
                "error": "Connection refused (RST received) [simulated]"}

    async def dns_query(self, name: str, rtype: str, server: str | None, timeout: float) -> dict:
        await self._pause()
        if rtype == "PTR":
            h = LAB_HOSTS.get(name)
            vals = [h["hostname"] + "."] if h and h["hostname"] else []
            return {"status": "Resolved" if vals else "NXDOMAIN (name does not exist)", "values": vals,
                    "dns_server": server or "10.1.57.2 (simulated)", "ttl": 3600 if vals else None, "duration_ms": 2.1}
        rec = DNS_ZONE.get(name.lower())
        if rec is None:
            return {"status": "NXDOMAIN (name does not exist)", "values": [], "dns_server": server or "10.1.57.2 (simulated)",
                    "ttl": None, "duration_ms": 3.2}
        vals = rec.get(rtype, [])
        return {"status": "Resolved" if vals else "No records", "values": vals,
                "dns_server": server or "10.1.57.2 (simulated)", "ttl": 300 if vals else None, "duration_ms": 1.8}

    async def http_check(self, url: str, timeout: float) -> dict:
        from urllib.parse import urlparse
        await self._pause()
        u = urlparse(url)
        host = u.hostname or ""
        ip = host if host in LAB_HOSTS else (DNS_ZONE.get(host, {}).get("A") or [None])[0]
        h = LAB_HOSTS.get(ip or "")
        port = u.port or (443 if u.scheme == "https" else 80)
        if not h or port not in h["ports"]:
            return {"reachable": False, "status_code": None, "reason": "", "final_url": "", "redirects": [],
                    "tls_version": None, "tls_ok": None, "duration_ms": timeout * 1000, "server_header": "",
                    "error": "Connection failed: no listener on port %d [simulated]" % port}
        redirects = []
        final = url
        if u.scheme == "http" and 443 in h["ports"]:
            final = url.replace("http://", "https://", 1)
            redirects.append({"status": 301, "url": url, "location": final})
        tls_ok = None
        if final.startswith("https"):
            c = h.get("cert") or {}
            tls_ok = c.get("days", 0) > 0 and _cert_matches(host, c)
            if not tls_ok:
                return {"reachable": False, "status_code": None, "reason": "", "final_url": final, "redirects": redirects,
                        "tls_version": "TLSv1.3", "tls_ok": False, "duration_ms": 41.0, "server_header": "",
                        "error": "TLS negotiation failed: certificate verify failed [simulated]"}
        return {"reachable": True, "status_code": 200, "reason": "OK", "final_url": final, "redirects": redirects,
                "tls_version": "TLSv1.3" if final.startswith("https") else None, "tls_ok": tls_ok,
                "duration_ms": _jitter(ip, 80, 35.0), "server_header": "sample-server (simulated)", "error": ""}

    async def ssl_certificate(self, host: str, port: int, timeout: float) -> dict:
        await self._pause()
        ip = host if host in LAB_HOSTS else (DNS_ZONE.get(host, {}).get("A") or [None])[0]
        h = LAB_HOSTS.get(ip or "")
        if not h or port not in h["ports"] or not h.get("cert"):
            raise ConnectionRefusedError(f"No TLS service on {host}:{port} [simulated]")
        c = h["cert"]
        now = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
        until = now + dt.timedelta(days=c["days"])
        match = _cert_matches(host, c)
        expired = c["days"] < 0
        err = "certificate has expired" if expired else ("" if match else f"Hostname mismatch, certificate is not valid for '{host}'")
        if c.get("self_signed"):
            err = (err + "; " if err else "") + "self-signed certificate"
        return {"subject": c["cn"], "subject_full": f"CN={c['cn']},O=SAMPLE Lab", "issuer": "SAMPLE Lab CA (simulated)",
                "issuer_full": "CN=SAMPLE Lab Issuing CA,O=SAMPLE Lab", "serial_number": hashlib.md5(c["cn"].encode()).hexdigest()[:16].upper(),
                "valid_from": (until - dt.timedelta(days=397)).isoformat(), "valid_until": until.isoformat(),
                "days_remaining": c["days"], "san": c.get("san", [c["cn"]]), "hostname_match": match,
                "chain_valid": not expired and not c.get("self_signed"), "verify_error": err, "tls_version": "TLSv1.3",
                "self_signed": bool(c.get("self_signed")), "duration_ms": 22.5}

    async def arp_table(self) -> dict[str, str]:
        # The simulated laptop sits on the lab subnet, so ARP sees every live host.
        return {ip: _mac(ip) for ip, h in LAB_HOSTS.items() if h["mac"]}

    def source_network(self, target_ip: str | None = None) -> str:
        return "SIMULATED-LAPTOP (10.1.57.200, simulated lab interface)"

    def nmap_available(self) -> bool:
        return True

    async def nmap_discover(self, targets: list[str], ports: list[int], os_detect: bool, timeout: float) -> dict:
        out = {}
        for t in targets:
            h = LAB_HOSTS.get(t)
            if h:
                out[t] = {"mac": _mac(t) if h["mac"] else "", "vendor": "SAMPLE vendor",
                          "ports": [{"port": p, "state": "open" if p in h["ports"] else "closed"} for p in ports],
                          "os_guess": h["os"] if os_detect else "", "reason": "simulated"}
        return out


def _cert_matches(host: str, c: dict) -> bool:
    names = [n.lower() for n in c.get("san", [c["cn"]])]
    if host in LAB_HOSTS:
        return True  # IP-based checks in the lab skip name matching
    return host.lower() in names
