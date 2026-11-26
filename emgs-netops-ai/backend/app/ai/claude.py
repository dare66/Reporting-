"""Optional Claude provider.

Used for (1) interpreting free-form requests and (2) explaining results.
Never required: every caller falls back to the rule-based parser and
assessment if Claude is disabled, unconfigured, offline or refuses.

What is sent: the user's request text, and for explanations the structured
result summary (target, status, timings, hop list). Raw command output is
only sent if an administrator enables ``send_raw_output``. The API key is
stored encrypted in SQLite (or read from ANTHROPIC_API_KEY) and is never
returned by the API.

Claude never executes anything itself: it only proposes an intent, which then
goes through the same validation and policy as a manual request.
"""
from __future__ import annotations

import json
import logging
import os

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.ai.intent import INTENTS
from app.models import entities as m
from app.security.tokens import decrypt_secret

log = logging.getLogger("netops.ai")
DEFAULT_MODEL = "claude-opus-5-5"

INTENT_SCHEMA = {
    "type": "object",
    "properties": {
        "intent": {"type": "string", "enum": list(INTENTS)},
        "target": {"type": "string", "description": "IP address, hostname or URL; empty if not given"},
        "targets": {"type": "array", "items": {"type": "string"}},
        "application": {"type": "string", "description": "Application name such as STARS or Visa Website; empty if none"},
        "ports": {"type": "array", "items": {"type": "integer"}},
        "range": {"type": "string", "description": "IP range for discovery, e.g. 10.1.57.xx or 10.1.57.0/24; empty if none"},
        "record_type": {"type": "string", "enum": ["A", "AAAA", "CNAME", "MX", "NS", "TXT", "PTR"]},
        "report_type": {"type": "string"},
        "format": {"type": "string", "enum": ["pdf", "xlsx"]},
        "question": {"type": "string", "description": "Clarifying question if essential information is missing; else empty"},
    },
    "required": ["intent", "target", "targets", "application", "ports", "range", "record_type", "report_type",
                 "format", "question"],
    "additionalProperties": False,
}

EXPLAIN_SCHEMA = {
    "type": "object",
    "properties": {
        "summary": {"type": "string"},
        "confirmed": {"type": "array", "items": {"type": "string"}},
        "possible_causes": {"type": "array", "items": {"type": "string"}},
        "recommendations": {"type": "array", "items": {"type": "string"}},
    },
    "required": ["summary", "confirmed", "possible_causes", "recommendations"],
    "additionalProperties": False,
}

INTENT_SYSTEM = """You interpret requests for EMGS NetOps AI, a local network diagnostic tool.
Map the request to exactly one intent:
ping, traceroute, port (TCP port check), dns, http, https, ssl (certificate), discover (IP range discovery),
compare (two targets), explain (why something is not accessible), report, inventory, history, help, unknown.
Extract only values that appear in the request; never invent IP addresses, hostnames, ports or ranges.
Use empty strings / empty lists for anything not stated. If an essential value (target, port, range) is
missing, set "question" to one short clarifying question. You cannot run anything yourself."""

EXPLAIN_SYSTEM = """You are a senior network engineer explaining diagnostic results from EMGS NetOps AI.
Rules:
- "confirmed" may only contain facts directly shown by the measurements provided.
- Put hypotheses in "possible_causes", worded as possibilities ("may", "could").
- Never state that a firewall is the cause unless the data proves it; a timeout is also consistent with a host
  being down, a routing problem or ICMP filtering.
- A failed ping does not mean a server is offline. An intermediate traceroute hop timing out is normal.
- "recommendations" are concrete next tests or checks. Keep every item to one sentence. Plain English."""


def _settings(db: Session) -> m.AiSetting | None:
    return db.scalar(select(m.AiSetting))


def api_key(db: Session) -> str:
    s = _settings(db)
    return (decrypt_secret(s.api_key_encrypted) if s else "") or os.environ.get("ANTHROPIC_API_KEY", "")


def is_enabled(db: Session) -> bool:
    s = _settings(db)
    return bool(s and s.enabled and api_key(db))


def status(db: Session) -> dict:
    s = _settings(db)
    key = api_key(db)
    return {"enabled": bool(s and s.enabled), "configured": bool(key), "active": is_enabled(db),
            "model": (s.model if s else DEFAULT_MODEL) or DEFAULT_MODEL,
            "key_source": "settings" if s and s.api_key_encrypted else ("environment" if key else None),
            "send_raw_output": bool(s and s.send_raw_output)}


def _client(db: Session):
    import anthropic
    return anthropic.Anthropic(api_key=api_key(db), timeout=30.0, max_retries=1)


def _call(db: Session, system: str, user: str, schema: dict, max_tokens: int = 2000) -> dict | None:
    import anthropic
    s = _settings(db)
    model = (s.model if s else DEFAULT_MODEL) or DEFAULT_MODEL
    try:
        resp = _client(db).beta.messages.create(
            model=model,
            max_tokens=max_tokens,
            system=system,
            messages=[{"role": "user", "content": user}],
            output_config={"effort": "low", "format": {"type": "json_schema", "schema": schema}},
            betas=["server-side-fallback-2026-07-01"],
            fallbacks="default",
        )
    except anthropic.APIConnectionError:
        log.warning("Claude API unreachable; using rule-based engine")
        return None
    except anthropic.RateLimitError:
        log.warning("Claude API rate limited; using rule-based engine")
        return None
    except anthropic.APIStatusError as exc:
        log.warning("Claude API error %s; using rule-based engine", exc.status_code)
        return None
    if resp.stop_reason in ("refusal", "max_tokens"):
        return None
    text = "".join(b.text for b in resp.content if b.type == "text")
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        return None


def interpret(db: Session, text: str) -> dict | None:
    data = _call(db, INTENT_SYSTEM, text[:2000], INTENT_SCHEMA, 1000)
    if not data or data.get("intent") not in INTENTS:
        return None
    params: dict = {}
    for k in ("target", "application", "range", "report_type", "format"):
        if data.get(k):
            params[k] = data[k]
    if data.get("ports"):
        params["ports"] = [p for p in data["ports"] if isinstance(p, int)]
    if data.get("targets"):
        params["targets"] = data["targets"][:2]
    if data["intent"] == "dns":
        params["record_type"] = data.get("record_type") or "A"
    if data["intent"] == "explain":
        params["subject"] = data.get("target") or data.get("application") or ""
    return {"intent": data["intent"], "params": params, "question": data.get("question", ""), "source": "claude"}


def explain(db: Session, request: str, results: list[dict]) -> dict | None:
    s = _settings(db)
    send_raw = bool(s and s.send_raw_output)
    payload = []
    for r in results:
        item = {k: r.get(k) for k in ("type", "target", "status", "outcome", "simulated", "source_network")}
        item["result"] = r.get("result", {})
        item["rule_based_assessment"] = r.get("assessment", {})
        if send_raw:
            item["raw_output"] = (r.get("raw_output") or "")[:4000]
        payload.append(item)
    user = f"User request: {request[:1000]}\n\nDiagnostic results (JSON):\n{json.dumps(payload, default=str)[:30000]}"
    data = _call(db, EXPLAIN_SYSTEM, user, EXPLAIN_SCHEMA, 2000)
    if data:
        data["source"] = "claude"
    return data
