"""Conversational network assistant.

1. Understand the request (Claude if enabled, otherwise the rule parser).
2. Ask for missing essential information.
3. Validate target and execution source (via the diagnostics service).
4. Execute the approved diagnostic(s).
5. Explain results: confirmed / possible causes / recommendations.
6. Every execution is saved to history and the audit log by the service layer.

Network discovery is never started from chat: the assistant prepares the plan
and the user confirms it explicitly.
"""
from __future__ import annotations

import asyncio

from sqlalchemy import or_, select
from sqlalchemy.orm import Session

from app.ai import claude
from app.ai import intent as rules
from app.core.context import ExecutionContext
from app.discovery.engine import plan_scan
from app.models import entities as m
from app.reports.builder import REPORT_TYPES, generate, report_to_dict
from app.security.policy import PolicyError
from app.security.validation import ValidationError
from app.services import history
from app.services.diagnostics import ModeError, RateLimitError, RoleError, run_diagnostic
from app.services.inventory import server_to_dict
from app.services.settings import current_mode

HELP = ("I can run approved diagnostics from this laptop. Try:\n"
        "- Ping 10.1.57.10\n- Run tracert to 10.1.57.10\n- Check whether port 3306 is accessible on 10.1.57.15\n"
        "- Check DNS for emgs.com.my\n- Check SSL expiry for visa.emgs.com.my\n- Test connectivity to Visa Website\n"
        "- Discover all active servers in 10.1.57.xx\n- Compare 10.1.57.10 and 10.1.57.15\n"
        "- Explain why STARS is not accessible\n- Generate an infrastructure connectivity report")


def _find_servers(db: Session, name: str, mode: str) -> list[m.Server]:
    if not name:
        return []
    like = f"%{name}%"
    stmt = (select(m.Server).outerjoin(m.Application)
            .where(or_(m.Application.name.ilike(like), m.Server.name.ilike(like), m.Server.hostname.ilike(like),
                       m.Server.ip_address == name, m.Server.category.ilike(like)))
            .where(m.Server.status == "Active"))
    servers = list(db.scalars(stmt))
    if mode != "simulation":
        servers = [s for s in servers if not s.is_sample]
    return servers[:5]


async def _merge_explanation(db: Session, text: str, results: list[dict]) -> dict:
    """Rule-based explanation, enriched by Claude when available."""
    combined = {"summary": " ".join(r["assessment"].get("summary", "") for r in results if r.get("assessment")),
                "confirmed": [], "possible_causes": [], "recommendations": [], "source": "rules"}
    for r in results:
        a = r.get("assessment") or {}
        for k in ("confirmed", "possible_causes", "recommendations"):
            for item in a.get(k, []):
                if item and item not in combined[k]:
                    combined[k].append(item)
    if results and claude.is_enabled(db):
        ai = await asyncio.to_thread(claude.explain, db, text, results)  # blocking HTTP off the event loop
        if ai:
            return ai
    return combined


def _approved_ports_for(server: m.Server | None, db: Session) -> list[int]:
    approved = {p.port for p in db.scalars(select(m.ApprovedPort).where(m.ApprovedPort.enabled.is_(True)))}
    wanted = (server.approved_ports if server and server.approved_ports else [22, 80, 443, 3389])
    return [p for p in wanted if p in approved][:10] or sorted(approved)[:5]


async def handle(db: Session, ctx: ExecutionContext, text: str, pending: dict | None = None) -> dict:
    ctx.interface = "assistant" if ctx.interface == "web" else ctx.interface
    mode = current_mode(db)
    parsed = None
    if claude.is_enabled(db) and not pending:
        parsed = await asyncio.to_thread(claude.interpret, db, text)
        if parsed:
            merged = rules._finish(parsed["intent"], parsed["params"], text)
            merged["source"] = "claude"
            if parsed.get("question") and not merged["missing"]:
                merged["question"] = ""
            parsed = merged
    if not parsed:
        parsed = rules.parse(text, pending)
    intent, params = parsed["intent"], parsed["params"]
    base = {"intent": intent, "params": params, "source": parsed["source"], "mode": mode,
            "simulated": mode == "simulation", "results": [], "pending": None}

    if parsed["missing"]:
        return {**base, "reply": parsed["question"], "pending": {"intent": intent, "params": params,
                                                                 "missing": parsed["missing"]}}
    try:
        if intent == "help" or intent == "unknown":
            reply = HELP if intent == "help" else "I could not map that to a supported diagnostic.\n\n" + HELP
            return {**base, "reply": reply}

        if intent == "history":
            jobs = history.list_jobs(db, limit=10)
            lines = [f"- {j['reference']} {j['type']} {j['target']} -> {j['outcome'] or j['status']}" for j in jobs]
            return {**base, "reply": "Most recent diagnostics:\n" + ("\n".join(lines) or "none yet"), "jobs": jobs}

        if intent == "inventory":
            servers = _find_servers(db, params.get("query", ""), mode) if params.get("query") else \
                list(db.scalars(select(m.Server).limit(20)))
            return {**base, "reply": f"{len(servers)} matching server(s) in the local inventory.",
                    "servers": [server_to_dict(s) for s in servers]}

        if intent == "report":
            rtype = params.get("report_type", "consolidated")
            row = generate(db, ctx, rtype if rtype in REPORT_TYPES else "consolidated", params.get("format", "pdf"))
            db.commit()
            rep = report_to_dict(row)
            return {**base, "reply": f"{rep['title']} {rep['reference']} is ready ({rep['format'].upper()}).",
                    "report": rep}

        if intent == "discover":
            plan = plan_scan(db, ctx, params["range"], ports=params.get("ports"))
            reply = (f"Discovery plan {plan['reference']}: {plan['address_count']} address(es) in "
                     f"{plan['normalized_range']} (approved network {plan['approved_network']}).\n"
                     f"Methods: {', '.join(x['id'] for x in plan['methods'])}. Ports: {', '.join(map(str, plan['ports']))}.\n"
                     f"Estimated time about {plan['estimated_seconds']:.0f}s. Review and confirm to start.")
            return {**base, "reply": reply, "plan": plan, "action": "confirm_discovery"}

        if intent == "compare":
            results = []
            for t in params["targets"][:2]:
                srv = (_find_servers(db, t, mode) or [None])[0]
                results.append(await run_diagnostic(db, ctx, "ping", {"target": t}))
                results.append(await run_diagnostic(db, ctx, "port", {"target": t, "ports": _approved_ports_for(srv, db)}))
            table = []
            for t in params["targets"][:2]:
                p = next(r for r in results if r["target"] == t and r["type"] == "ping")
                pt = next(r for r in results if r["target"] == t and r["type"] == "port")
                table.append({"target": t, "ping": p["outcome"], "avg_ms": p["result"].get("avg_latency_ms"),
                              "open_ports": [x["port"] for x in pt["result"].get("results", []) if x["status"] == "open"],
                              "not_open": [x["port"] for x in pt["result"].get("results", []) if x["status"] != "open"]})
            expl = await _merge_explanation(db, text, results)
            return {**base, "reply": "Comparison complete.", "results": results, "comparison": table,
                    "explanation": expl}

        if intent == "explain":
            subject = params.get("subject", "")
            servers = _find_servers(db, subject, mode)
            targets = [(s.ip_address, s) for s in servers] or [(subject, None)]
            results = []
            for ip, srv in targets[:3]:
                results.append(await run_diagnostic(db, ctx, "ping", {"target": ip}))
                results.append(await run_diagnostic(db, ctx, "port", {"target": ip, "ports": _approved_ports_for(srv, db)}))
            expl = await _merge_explanation(db, text, results)
            names = ", ".join(f"{s.name} ({s.ip_address})" for s in servers) or subject
            return {**base, "reply": f"I tested {names} from this laptop.", "results": results, "explanation": expl}

        # Single diagnostics, possibly addressed by application name.
        target = params.get("target") or params.get("url")
        servers = []
        if not target and params.get("application"):
            servers = _find_servers(db, params["application"], mode)
            if not servers:
                return {**base, "reply": f"I could not find an active server for '{params['application']}' in the "
                                         "inventory. Which IP address or hostname should I test?",
                        "pending": {"intent": intent, "params": {k: v for k, v in params.items() if k != "application"},
                                    "missing": ["target"]}}
        results = []
        for srv in servers[:3] or [None]:
            t = target or (srv.hostname or srv.ip_address)
            if intent == "port":
                results.append(await run_diagnostic(db, ctx, "port", {"target": t if target else srv.ip_address,
                                                                      "ports": params.get("ports")}))
            elif intent in ("http", "https"):
                results.append(await run_diagnostic(db, ctx, intent, {"url": params.get("url") or t}))
            elif intent == "dns":
                results.append(await run_diagnostic(db, ctx, "dns", {"domain": t, "record_type": params.get("record_type", "A")}))
            elif intent == "ssl":
                results.append(await run_diagnostic(db, ctx, "ssl", {"target": t, "port": params.get("port", 443)}))
            elif intent == "ping":
                results.append(await run_diagnostic(db, ctx, "ping", {"target": t if target else srv.ip_address,
                                                                      **({"count": params["count"]} if params.get("count") else {})}))
            elif intent == "traceroute":
                results.append(await run_diagnostic(db, ctx, "traceroute", {"target": t if target else srv.ip_address}))
        expl = await _merge_explanation(db, text, results)
        return {**base, "reply": expl.get("summary") or "Done.", "results": results, "explanation": expl}
    except (ValidationError, PolicyError, ModeError, RoleError, RateLimitError) as exc:
        return {**base, "reply": f"I can't run that: {exc}", "error": str(exc),
                "error_type": exc.__class__.__name__}
