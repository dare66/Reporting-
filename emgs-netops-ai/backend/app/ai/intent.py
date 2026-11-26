"""Deterministic natural-language interpretation (works without any AI API).

Turns requests such as "check whether port 3306 is accessible on 10.1.57.15"
into a structured intent. Claude, when configured, produces the same shape.
"""
from __future__ import annotations

import re

INTENTS = ("ping", "traceroute", "port", "dns", "http", "https", "ssl", "discover", "compare", "explain",
           "report", "inventory", "history", "help", "unknown")

_IP = r"\b(?:\d{1,3}\.){3}\d{1,3}\b"
_RANGE = r"\b(?:\d{1,3}\.){3}(?:x{1,3}|\*|\d{1,3}/\d{1,2}|\d{1,3}\s*-\s*(?:(?:\d{1,3}\.){3})?\d{1,3})\b"
_HOST = r"\b(?=[a-z0-9-]*[a-z])[a-z0-9-]+(?:\.[a-z0-9-]+)+\b"
_STOP_HOSTS = {"e.g", "i.e"}

RECORD_TYPES = ("AAAA", "CNAME", "MX", "NS", "TXT", "PTR", "A")
PORT_NAMES = {"ssh": 22, "smtp": 25, "http": 80, "https": 443, "smb": 445, "mysql": 3306, "rdp": 3389,
              "postgres": 5432, "postgresql": 5432}


def _targets(text: str) -> list[str]:
    ips = re.findall(_IP, text)
    hosts = [h for h in re.findall(_HOST, text.lower()) if h not in _STOP_HOSTS and not re.fullmatch(r"[\d.]+", h)]
    seen: list[str] = []
    for t in ips + hosts:
        if t not in seen:
            seen.append(t)
    return seen


def _ports(text: str) -> list[int]:
    t = text.lower()
    ports = [int(p) for p in re.findall(r"\bports?\s+((?:\d{1,5}\s*(?:,|and|&)?\s*)+)", t) for p in re.findall(r"\d{1,5}", p)]
    ports += [int(p) for p in re.findall(r":(\d{2,5})\b", t)]
    for name, num in PORT_NAMES.items():
        if re.search(rf"\b{name}\s+port\b|\bport\s+{name}\b|\bhave\s+{name}\b", t):
            ports.append(num)
    return sorted(set(ports))


def parse(text: str, pending: dict | None = None) -> dict:
    """Return {intent, params, missing, question, source}."""
    raw = (text or "").strip()
    t = raw.lower()
    intent = "unknown"
    params: dict = {}
    # A reply to a clarifying question: merge into the pending intent.
    if pending and pending.get("intent"):
        intent = pending["intent"]
        params = dict(pending.get("params", {}))
        ts = _targets(raw)
        rng = re.findall(_RANGE, t)
        if intent == "discover" and rng:
            params["range"] = rng[0].replace(" ", "")
        elif ts and "target" in pending.get("missing", []):
            params["target"] = ts[0]
        ps = _ports(raw) or [int(x) for x in re.findall(r"^\s*(\d{1,5})\s*$", t)]
        if ps and "port" in pending.get("missing", []):
            params["ports"] = ps
        return _finish(intent, params, raw)

    targets = _targets(raw)
    ranges = re.findall(_RANGE, t)
    ports = _ports(raw)

    if re.search(r"\b(report|export)\b", t):
        intent = "report"
        params["report_type"] = next((k for k, words in {
            "discovery": ("discovery", "subnet", "scan"), "unknown_devices": ("unknown",),
            "inventory": ("inventory",), "ssl": ("ssl", "certificate"), "ping": ("ping",),
            "traceroute": ("traceroute", "tracert"), "ports": ("port ", "ports"), "dns": ("dns",)}.items()
            if any(re.search(rf"\b{w}", t) for w in words)), "consolidated")
        params["format"] = "xlsx" if re.search(r"excel|xlsx|spreadsheet", t) else "pdf"
    elif re.search(r"\b(discover|scan|sweep|active (servers|devices|hosts)|which servers have)\b", t) or ranges and \
            re.search(r"\b(find|list|all)\b", t):
        intent = "discover"
        if ranges:
            params["range"] = ranges[0].replace(" ", "")
        elif targets and re.fullmatch(_IP, targets[0]):
            params["range"] = targets[0]
        if ports:
            params["ports"] = ports
            params["filter_open_ports"] = ports
    elif re.search(r"\b(why|explain|diagnose|not accessible|cannot access|can't access|down)\b", t):
        intent = "explain"
        params["subject"] = _subject(raw, targets)
    elif re.search(r"\bcompare\b", t):
        intent = "compare"
        params["targets"] = targets[:2]
    elif re.search(r"\b(trace\s?route|tracert|tracepath|route to)\b", t):
        intent = "traceroute"
    elif re.search(r"\b(ssl|tls|certificate|cert)\b", t):
        intent = "ssl"
        if ports and ports[0] != 443:
            params["port"] = ports[0]
    elif re.search(r"\bdns\b|\bresolve\b|\blookup\b|\bnslookup\b|\bdig\b|\b(mx|ns|txt|cname|aaaa|ptr) records?\b", t):
        intent = "dns"
        params["record_type"] = next((r for r in RECORD_TYPES if re.search(rf"\b{r.lower()}\b", t)), "A")
        if re.search(r"\breverse\b", t):
            params["record_type"] = "PTR"
    elif re.search(r"\bports?\b|\btelnet\b|\baccessible on\b|\blistening\b", t):
        intent = "port"
        if ports:
            params["ports"] = ports
        if "all approved" in t or "approved ports" in t:
            params["ports"] = "approved"
    elif re.search(r"\bhttps\b|\bhttp\b|\bwebsite\b|\burl\b|\bweb\s?site\b|\bavailability\b|test connectivity", t):
        intent = "https" if "https" in t or "website" in t or "connectivity" in t else "http"
        url = re.search(r"https?://\S+", raw)
        if url:
            params["url"] = url.group(0).rstrip(".,)")
    elif re.search(r"\bping\b|\breachable\b|\bconnectivity\b|\bis .* up\b", t):
        intent = "ping"
        m = re.search(r"\b(\d{1,2})\s*(?:packets|times|pings)\b", t)
        if m:
            params["count"] = int(m.group(1))
    elif re.search(r"\b(inventory|registered|list servers|show servers|find server)\b", t):
        intent = "inventory"
        params["query"] = _subject(raw, targets)
    elif re.search(r"\b(history|recent|last (tests|checks|diagnostics))\b", t):
        intent = "history"
    elif re.search(r"\b(help|what can you do)\b", t) or not t:
        intent = "help"

    if intent in ("ping", "traceroute", "port", "dns", "ssl", "http", "https") and "target" not in params and targets:
        params["target"] = targets[0]
    if intent in ("ping", "port", "ssl", "https", "http", "traceroute") and "target" not in params and "url" not in params:
        app = _subject(raw, [])
        if app:
            params["application"] = app
    return _finish(intent, params, raw)


_APP_WORDS = ("stars", "visa website", "visa", "emgs hub", "hub", "crm", "file server", "database")


def _subject(raw: str, targets: list[str]) -> str:
    if targets:
        return targets[0]
    t = raw.lower()
    for w in _APP_WORDS:
        if re.search(rf"\b{w}\b", t):
            return w
    return ""


def _finish(intent: str, params: dict, raw: str) -> dict:
    missing: list[str] = []
    question = ""
    if intent in ("ping", "traceroute", "ssl") and not (params.get("target") or params.get("application")):
        missing, question = ["target"], f"Which server, IP address or hostname should I {_verb(intent)}?"
    elif intent == "port":
        if not params.get("ports"):
            missing.append("port")
        if not (params.get("target") or params.get("application")):
            missing.append("target")
        if missing == ["port"]:
            question = f"Which port should I check on {params.get('target') or params.get('application')}?"
        elif missing == ["target"]:
            question = f"Which server or IP address should I check port(s) {_fmt_ports(params.get('ports'))} on?"
        elif missing:
            question = "Which server and which port should I check? (for example: 10.1.57.15 port 3306)"
    elif intent == "dns" and not params.get("target"):
        missing, question = ["target"], "Which domain or IP address should I look up?"
    elif intent in ("http", "https") and not (params.get("target") or params.get("url") or params.get("application")):
        missing, question = ["target"], "Which website URL or hostname should I test?"
    elif intent == "discover" and not params.get("range"):
        missing, question = ["range"], ("Which IP range should I discover? Use 10.1.57.xx, 10.1.57.0/24 or "
                                        "10.1.57.1-10.1.57.254.")
    elif intent == "compare" and len(params.get("targets", [])) < 2:
        missing, question = ["targets"], "Which two servers should I compare? (for example: 10.1.57.10 and 10.1.57.15)"
    elif intent == "explain" and not params.get("subject"):
        missing, question = ["subject"], "Which server or application is not accessible?"
    return {"intent": intent, "params": params, "missing": missing, "question": question, "source": "rules",
            "text": raw}


def _verb(intent: str) -> str:
    return {"ping": "ping", "traceroute": "trace the route to", "ssl": "check the certificate of"}.get(intent, "test")


def _fmt_ports(p) -> str:
    return ", ".join(map(str, p)) if isinstance(p, list) else str(p or "")
