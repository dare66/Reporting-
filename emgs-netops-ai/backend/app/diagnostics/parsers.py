"""Pure parsers for native command output (Windows and Linux/macOS).

Kept free of I/O so they can be unit-tested against captured output.
"""
from __future__ import annotations

import re

_FLOAT = r"(\d+(?:[.,]\d+)?)"


def _f(v: str | None) -> float | None:
    return float(v.replace(",", ".")) if v else None


# --------------------------------------------------------------------------- ping

def parse_ping_linux(output: str) -> dict:
    """iputils / BusyBox / macOS ping."""
    res: dict = {"transmitted": 0, "received": 0, "loss_pct": 100.0,
                 "min_ms": None, "avg_ms": None, "max_ms": None, "resolved_ip": None, "replies": []}
    m = re.search(r"PING\s+(\S+)\s+\(([\d.:a-fA-F]+)\)", output)
    if m:
        res["resolved_ip"] = m.group(2)
    for rm in re.finditer(r"from ([\d.:a-fA-F]+).*?time[=<]" + _FLOAT, output):
        res["replies"].append(_f(rm.group(2)))
    m = re.search(r"(\d+) packets transmitted, (\d+) (?:packets )?received", output)
    if m:
        res["transmitted"], res["received"] = int(m.group(1)), int(m.group(2))
    m = re.search(r"([\d.]+)% packet loss", output)
    if m:
        res["loss_pct"] = float(m.group(1))
    m = re.search(r"(?:rtt|round-trip)[^=]*=\s*" + _FLOAT + "/" + _FLOAT + "/" + _FLOAT, output)
    if m:
        res["min_ms"], res["avg_ms"], res["max_ms"] = _f(m.group(1)), _f(m.group(2)), _f(m.group(3))
    elif res["replies"]:
        r = res["replies"]
        res["min_ms"], res["avg_ms"], res["max_ms"] = min(r), round(sum(r) / len(r), 3), max(r)
    return res


def parse_ping_windows(output: str) -> dict:
    res: dict = {"transmitted": 0, "received": 0, "loss_pct": 100.0,
                 "min_ms": None, "avg_ms": None, "max_ms": None, "resolved_ip": None, "replies": []}
    m = re.search(r"Pinging\s+\S+\s+\[([\d.:a-fA-F]+)\]", output) or re.search(r"Pinging\s+([\d.]+)\s", output)
    if m:
        res["resolved_ip"] = m.group(1)
    # Only count genuine echo replies ("Reply from X: bytes=32 time=1ms TTL=..").
    # "Reply from <gw>: Destination host unreachable" is NOT a successful reply.
    for rm in re.finditer(r"Reply from [\d.:a-fA-F]+: bytes=\d+ time([=<])(\d+)ms", output):
        res["replies"].append(float(rm.group(2)) if rm.group(1) == "=" else 0.5)
    m = re.search(r"Sent = (\d+), Received = (\d+), Lost = (\d+) \((\d+)% loss\)", output)
    if m:
        res["transmitted"] = int(m.group(1))
        # Windows counts "destination unreachable" replies as received; trust echo replies instead.
        res["received"] = len(res["replies"]) if "unreachable" in output.lower() else int(m.group(2))
        res["loss_pct"] = round(100.0 * (res["transmitted"] - res["received"]) / res["transmitted"], 1) \
            if res["transmitted"] else 100.0
    m = re.search(r"Minimum = (\d+)ms, Maximum = (\d+)ms, Average = (\d+)ms", output)
    if m and res["received"]:
        res["min_ms"], res["max_ms"], res["avg_ms"] = float(m.group(1)), float(m.group(2)), float(m.group(3))
    return res


def classify_ping(parsed: dict) -> str:
    tx, rx = parsed.get("transmitted", 0), parsed.get("received", 0)
    if tx and rx == tx:
        return "Successful"
    if rx and rx < tx:
        return "Partial packet loss"
    return "No ICMP response"


# --------------------------------------------------------------------------- traceroute

def parse_traceroute_linux(output: str) -> list[dict]:
    """traceroute (Linux/macOS) output -> hops."""
    hops: list[dict] = []
    for line in output.splitlines():
        m = re.match(r"^\s*(\d+)\s+(.*)$", line)
        if not m:
            continue
        num, rest = int(m.group(1)), m.group(2)
        times = [_f(t) for t in re.findall(_FLOAT + r"\s*ms", rest)]
        host_m = re.search(r"([A-Za-z0-9.\-_]+)\s+\(([\d.:a-fA-F]+)\)", rest)
        if host_m:
            hostname, ip = host_m.group(1), host_m.group(2)
        else:
            ip_m = re.search(r"\b(\d{1,3}(?:\.\d{1,3}){3})\b", rest)
            hostname, ip = None, ip_m.group(1) if ip_m else None
        if hostname == ip:
            hostname = None
        timeouts = rest.count("*")
        hops.append(_hop(num, ip, hostname, times, timeouts))
    return hops


def parse_tracert_windows(output: str) -> list[dict]:
    hops: list[dict] = []
    for line in output.splitlines():
        m = re.match(r"^\s*(\d+)\s+(.+)$", line)
        if not m or "Tracing route" in line:
            continue
        num, rest = int(m.group(1)), m.group(2)
        cols = re.findall(r"(<?\d+)\s*ms|(\*)", rest)
        times, timeouts = [], 0
        for t, star in cols:
            if star:
                timeouts += 1
            else:
                times.append(0.5 if t.startswith("<") else float(t))
        if not cols:
            continue
        tail = re.sub(r"(<?\d+\s*ms|\*)", "", rest).strip()
        host_m = re.search(r"([A-Za-z0-9.\-_]+)\s+\[([\d.:a-fA-F]+)\]", tail)
        if host_m:
            hostname, ip = host_m.group(1), host_m.group(2)
        else:
            ip_m = re.search(r"\b(\d{1,3}(?:\.\d{1,3}){3})\b", tail)
            hostname, ip = None, ip_m.group(1) if ip_m else None
        hops.append(_hop(num, ip, hostname, times, timeouts))
    return hops


def parse_tracepath(output: str) -> list[dict]:
    hops: dict[int, dict] = {}
    for line in output.splitlines():
        m = re.match(r"^\s*(\d+)\??:\s+(.*)$", line)
        if not m:
            continue
        num, rest = int(m.group(1)), m.group(2)
        if "[LOCALHOST]" in rest:
            continue
        if "no reply" in rest:
            hops.setdefault(num, _hop(num, None, None, [], 1))
            continue
        ip_m = re.search(r"(\d{1,3}(?:\.\d{1,3}){3})", rest)
        t_m = re.search(_FLOAT + r"ms", rest)
        hops[num] = _hop(num, ip_m.group(1) if ip_m else None, None, [_f(t_m.group(1))] if t_m else [], 0)
    return [hops[k] for k in sorted(hops)]


def _hop(num: int, ip, hostname, times: list, timeouts: int) -> dict:
    return {
        "hop": num,
        "ip": ip,
        "hostname": hostname,
        "times_ms": times,
        "avg_ms": round(sum(times) / len(times), 3) if times else None,
        "timeouts": timeouts,
        "status": "timeout" if (not times and ip is None) else ("partial" if timeouts else "ok"),
    }


# --------------------------------------------------------------------------- ARP

_MAC = r"([0-9a-fA-F]{2}[:-]){5}[0-9a-fA-F]{2}"


def normalize_mac(mac: str) -> str:
    return mac.replace("-", ":").lower()


def parse_proc_net_arp(text: str) -> dict[str, str]:
    table = {}
    for line in text.splitlines()[1:]:
        parts = line.split()
        if len(parts) >= 4 and re.fullmatch(_MAC, parts[3]) and parts[3] != "00:00:00:00:00:00":
            table[parts[0]] = normalize_mac(parts[3])
    return table


def parse_ip_neigh(text: str) -> dict[str, str]:
    table = {}
    for line in text.splitlines():
        m = re.match(r"^(\S+)\s.*lladdr\s+(" + _MAC + r")\s+(\S+)", line)
        if m and m.group(4) not in ("FAILED", "INCOMPLETE"):
            table[m.group(1)] = normalize_mac(m.group(2))
    return table


def parse_arp_windows(text: str) -> dict[str, str]:
    table = {}
    for line in text.splitlines():
        m = re.match(r"^\s*(\d{1,3}(?:\.\d{1,3}){3})\s+(" + _MAC + r")\s+(\w+)", line)
        if m and not m.group(2).lower().startswith("ff-ff-ff"):
            table[m.group(1)] = normalize_mac(m.group(2))
    return table
