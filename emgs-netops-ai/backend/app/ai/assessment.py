"""Rule-based assessment of diagnostic results.

Always available, with or without a Claude API key. It separates:

* confirmed        - facts the measurement proves
* possible_causes  - hypotheses consistent with the evidence (never stated as fact)
* recommendations  - next tests that would confirm or rule out a cause

A firewall is never reported as the confirmed cause: a timeout is consistent
with a firewall, a down host, a routing problem or ICMP rate limiting.
"""
from __future__ import annotations


def _a(summary: str, confirmed=None, possible=None, recs=None, severity="info") -> dict:
    return {"summary": summary, "confirmed": confirmed or [], "possible_causes": possible or [],
            "recommendations": recs or [], "severity": severity, "source": "rules"}


def assess_ping(r: dict) -> dict:
    t, st = r["target"], r["status"]
    if st == "DNS resolution failure":
        return _a(f"'{t}' could not be resolved to an IP address.",
                  [f"DNS lookup for {t} returned no address from this laptop."],
                  ["The hostname is misspelt or not registered in DNS.",
                   "This laptop is using a DNS server that does not know the internal zone (e.g. not on VPN)."],
                  [f"Run a DNS check for {t} (A record).", "Ping the server by IP address instead."], "warning")
    if st == "Successful":
        return _a(f"{t} answered all {r['packets_transmitted']} ICMP echo requests (avg {r['avg_latency_ms']} ms).",
                  [f"{r['packets_received']}/{r['packets_transmitted']} replies received; ICMP path is working.",
                   f"Latency min/avg/max {r['min_latency_ms']}/{r['avg_latency_ms']}/{r['max_latency_ms']} ms."],
                  [], ["If an application on this server still fails, check its TCP port (e.g. 443) next."]
                  if (r.get("avg_latency_ms") or 0) < 100 else
                  ["Latency is high for an on-premises path; run a traceroute to see where delay is added."])
    if st == "Partial packet loss":
        return _a(f"{t} is reachable but {r['packet_loss_pct']}% of ICMP packets were lost.",
                  [f"{r['packets_received']}/{r['packets_transmitted']} replies received."],
                  ["Congested or faulty link (duplex mismatch, Wi-Fi interference).",
                   "ICMP rate limiting on the target or an intermediate device."],
                  ["Repeat with a higher packet count (e.g. 20) to measure loss reliably.",
                   "Run a traceroute to identify the hop where loss starts.",
                   "Test the application port with a TCP check; TCP may be unaffected."], "warning")
    if st == "No ICMP response":
        return _a(f"{t} did not answer ICMP echo. This does not prove the server is offline.",
                  [f"0/{r['packets_transmitted']} ICMP replies received from {t}."],
                  ["ICMP is blocked by a host or network firewall while the server is otherwise up.",
                   "The server is powered off or disconnected.",
                   "There is no route from this laptop's current network to the target."],
                  [f"Check a TCP port the server should offer (e.g. 443, 22 or 3389) on {t}.",
                   "Run a traceroute to see how far packets get.",
                   "Confirm this laptop is on the EMGS network or VPN."], "warning")
    return _a(f"The ping could not be executed: {r.get('error', 'unknown error')}",
              [r.get("error", "")], [], ["Check that the ping utility is available on this laptop."], "error")


def assess_traceroute(r: dict) -> dict:
    hops = r.get("hops", [])
    reached = r.get("destination_reached")
    timeouts = [h["hop"] for h in hops if h["status"] == "timeout"]
    last_ok = max((h for h in hops if h["ip"]), key=lambda h: h["hop"], default=None)
    confirmed = [f"{len(hops)} hop(s) recorded."]
    if timeouts:
        confirmed.append(f"Hop(s) {', '.join(map(str, timeouts))} did not answer traceroute probes.")
    if reached:
        msg = f"The route to {r['target']} completes in {len(hops)} hops."
        possible = ["Intermediate hops that time out usually just ignore or rate-limit probes; "
                    "this is normal when the destination is reached."] if timeouts else []
        return _a(msg, confirmed, possible, [])
    possible = ["The destination or a device near it drops traceroute probes (ICMP/UDP) while TCP may still work.",
                "Traffic stops at a firewall or a missing route after the last responding hop."]
    recs = [f"Run a TCP port check against {r['target']} on the application port.",
            "Compare with a traceroute from another network segment."]
    if last_ok:
        confirmed.append(f"Last responding hop: {last_ok['hop']} ({last_ok['ip']}).")
        recs.insert(0, f"Ask the network team to check routing/firewall policy after {last_ok['ip']}.")
    return _a(f"The destination {r['target']} did not answer within {r.get('max_hops')} hops. "
              "This alone does not confirm it is unreachable.", confirmed, possible, recs, "warning")


def assess_ports(r: dict) -> dict:
    res = r["results"]
    opened = [x for x in res if x["status"] == "open"]
    closed = [x for x in res if x["status"] == "closed"]
    filtered = [x for x in res if x["status"] in ("filtered", "unreachable")]
    confirmed = []
    if opened:
        confirmed.append("Open (TCP handshake completed): " + ", ".join(f"{x['port']}/{x['service'] or 'tcp'}" for x in opened))
    if closed:
        confirmed.append("Closed (host answered with a reset; host is up, nothing listening): " +
                         ", ".join(str(x["port"]) for x in closed))
    if filtered:
        confirmed.append("No answer: " + ", ".join(str(x["port"]) for x in filtered))
    possible, recs = [], []
    if closed:
        possible.append("The service on the closed port(s) is stopped or listens on a different port.")
        recs.append("Check the service status on the server.")
    if filtered:
        possible.append("A firewall drops traffic to the unanswered port(s), or the host is down / unreachable.")
        recs.append("Check whether other ports on the same host answer; if none do, verify the host is up.")
        recs.append("Ask the firewall team to check for a rule between this laptop's network and the target.")
    sev = "info" if not (closed or filtered) else "warning"
    summary = f"{len(opened)} of {len(res)} port(s) open on {r['target']}."
    return _a(summary, confirmed, possible, recs, sev)


def assess_dns(r: dict) -> dict:
    if r["values"]:
        return _a(f"{r['domain']} {r['record_type']} resolved to {', '.join(r['values'][:5])}.",
                  [f"{len(r['values'])} {r['record_type']} record(s) returned by {r['dns_server']} in {r['duration_ms']} ms."])
    return _a(f"No {r['record_type']} record for {r['domain']}: {r['status']}.",
              [f"{r['dns_server']} answered: {r['status']}."],
              ["The record does not exist for this type.", "This laptop uses a DNS server outside the internal zone."],
              ["Try record type A or CNAME.", "Repeat against the internal DNS server address."], "warning")


def assess_http(r: dict) -> dict:
    if r["reachable"]:
        conf = [f"HTTP {r['status_code']} {r['reason']} in {r['duration_ms']} ms."]
        if r["redirects"]:
            conf.append(f"{len(r['redirects'])} redirect(s), final URL {r['final_url']}.")
        if r.get("tls_version"):
            conf.append(f"TLS negotiated ({r['tls_version']}) with a trusted certificate.")
        sev = "info" if r["status_code"] < 400 else "warning"
        possible = ["The application returned an error page; the web server itself is reachable."] if r["status_code"] >= 500 else []
        return _a(f"{r['url']} responded with HTTP {r['status_code']}.", conf, possible, [], sev)
    if r.get("tls_ok") is False:
        return _a(f"TLS negotiation with {r['url']} failed.", [r["error"]],
                  ["Expired, self-signed or hostname-mismatched certificate.", "TLS inspection proxy in the path."],
                  ["Run an SSL certificate check on the same host."], "error")
    return _a(f"{r['url']} could not be reached.", [r["error"]],
              ["Web service down, port blocked, or no route from this network."],
              ["Run a TCP port check on 443/80.", "Ping and traceroute the host."], "error")


def assess_ssl(r: dict) -> dict:
    conf = [f"Certificate for '{r['subject']}' issued by {r['issuer']}, valid until {r['valid_until'][:10]} "
            f"({r['days_remaining']} days remaining)."]
    warnings = []
    if r["days_remaining"] < 0:
        warnings.append("The certificate has EXPIRED.")
    elif r["days_remaining"] <= 30:
        warnings.append(f"The certificate expires in {r['days_remaining']} days.")
    if not r["hostname_match"]:
        warnings.append(f"The certificate does not match hostname '{r['host']}'.")
    if r.get("self_signed"):
        warnings.append("The certificate is self-signed.")
    if r.get("verify_error") and not warnings:
        warnings.append(f"Validation error: {r['verify_error']}.")
    if not warnings:
        return _a("Certificate is valid, trusted and matches the hostname.", conf)
    sev = "error" if r["days_remaining"] < 0 or not r["hostname_match"] else "warning"
    return _a(" ".join(warnings), conf + warnings, [],
              ["Renew or replace the certificate before expiry." if r["days_remaining"] <= 30 else
               "Reissue the certificate with the correct subject alternative names."], sev)


def assess_error(dtype: str, target: str, error: str) -> dict:
    return _a(f"The {dtype} check against {target} could not be completed.", [error],
              ["A required network utility is missing, the time limit was reached, or the laptop has no route."],
              ["Check the laptop's network connection and retry.", "Try a simpler test (TCP port check) first."],
              "error")


ASSESSORS = {"ping": assess_ping, "traceroute": assess_traceroute, "ports": assess_ports, "dns": assess_dns,
             "http": assess_http, "ssl": assess_ssl}
