"""LangGraph orchestration of the AI analyst.

intent → semantic → planner → governance ─┬→ execute (SQL · validation · analytics · anomaly · root cause) → visualization → narrative
                                          └→ narrative (refusal / clarification)

Every node appends to a trace that is persisted with the run and streamed to the UI."""

from __future__ import annotations

import time
from dataclasses import dataclass, field
from typing import Any, Awaitable, Callable, TypedDict

from langchain_core.runnables import RunnableConfig
from langgraph.graph import END, StateGraph

from .. import observability
from ..api_client import AixbiApi, ApiError
from ..config import settings
from . import actions, deterministic, handlers, llm
from .catalog import CatalogIndex
from .handlers import Outcome
from .narrative import compose
from .plan import Plan

AGENT_LABELS = {
    "intent": "Intent Agent", "semantic": "Semantic Model Agent", "planner": "Query Planner", "governance": "Governance Agent",
    "sql": "SQL Agent", "validation": "Data Validation", "analytics": "Analytics Agent", "anomaly": "Anomaly Agent",
    "root_cause": "Root Cause Agent", "forecast": "Forecast Agent", "report": "Report Agent", "action": "Action Agent",
    "visualization": "Visualization Agent", "narrative": "Narrative Agent",
}


class State(TypedDict, total=False):
    question: str
    context: dict
    plan: dict
    planner: str
    refusal: str | None
    outcome: dict
    answer: str
    narrator: str
    trace: list[dict]


@dataclass
class Deps:
    api: AixbiApi
    organisation_id: str
    emit: Callable[[str, dict], Awaitable[None]] | None = None
    index: CatalogIndex | None = None
    usage: llm.Usage = field(default_factory=llm.Usage)
    plan_obj: Plan | None = None
    outcome_obj: Outcome | None = None


def _deps(config: RunnableConfig) -> Deps:
    return config["configurable"]["deps"]


async def _step(deps: Deps, state: State, agent: str, detail: str, started: float, status: str = "done") -> list[dict]:
    entry = {"agent": agent, "label": AGENT_LABELS.get(agent, agent), "status": status, "detail": detail, "ms": int((time.perf_counter() - started) * 1000)}
    if deps.emit:
        await deps.emit("step", entry)
    return [*state.get("trace", []), entry]


async def semantic_node(state: State, config: RunnableConfig) -> State:
    deps, t0 = _deps(config), time.perf_counter()
    deps.index = CatalogIndex.from_catalog(await deps.api.catalog())
    await deps.index.load_members(deps.api, deps.organisation_id)
    detail = f"{len(deps.index.metrics)} governed metrics across {len(deps.index.models)} semantic models; {sum(len(v) for v in deps.index.members.values())} known members"
    return {"trace": await _step(deps, state, "semantic", detail, t0)}


async def intent_node(state: State, config: RunnableConfig) -> State:
    deps, t0 = _deps(config), time.perf_counter()
    plan, planner = None, "deterministic"
    if settings().llm_available:
        try:
            plan = await llm.plan(state["question"], deps.index, state.get("context", {}), deps.usage)
            planner = "llm"
        except llm.LlmUnavailable as e:
            planner = f"deterministic (fallback: {e})"
    if plan is None:
        plan = deterministic.plan(state["question"], deps.index, state.get("context", {}))
    deps.plan_obj = plan
    trace = await _step(deps, state, "intent", f"Intent: {plan.intent} · planner: {planner}", t0)
    labels = [deps.index.metric(m).label for m in plan.metrics if deps.index.metric(m)]
    entry_state = {**state, "trace": trace}
    trace = await _step(deps, entry_state, "planner", "Metrics: " + (", ".join(labels) or "—")
                        + (f" · by {deps.index.dimension_label(plan.dimension)}" if plan.dimension else "")
                        + (f" · filters: {', '.join(f'{f.dimension}={f.value}' for f in plan.filters)}" if plan.filters else "")
                        + f" · period: {plan.range if isinstance(plan.range, str) else plan.range['from'] + '…' + plan.range['to']}", t0)
    if deps.emit:
        await deps.emit("plan", plan.model_dump())
    return {"plan": plan.model_dump(), "planner": planner, "trace": trace}


async def governance_node(state: State, config: RunnableConfig) -> State:
    deps, t0 = _deps(config), time.perf_counter()
    plan, index = deps.plan_obj, deps.index
    refusal = None
    needs_metric = plan.intent in ("trend", "breakdown", "why", "forecast", "alert")
    if needs_metric and not plan.metrics:
        refusal = "I couldn't match that to a governed metric you can access. Try naming a metric such as applications, revenue, SLA or approval rate."
    for f in plan.filters:
        if not any(f.dimension in d for d in index.dimensions.values()):
            refusal = f"You don't have access to “{f.dimension}”, or it doesn't exist in the semantic model."
    if plan.intent in ("report", "report_edit", "dashboard", "alert"):
        detail = "Action permitted only through the API under your own permissions"
    else:
        detail = "All references resolve to governed, accessible catalog entries; row-level security applies server-side"
    trace = await _step(deps, state, "governance", refusal or detail, t0, "blocked" if refusal else "done")
    return {"refusal": refusal, "trace": trace}


async def execute_node(state: State, config: RunnableConfig) -> State:
    deps, t0 = _deps(config), time.perf_counter()
    plan, index = deps.plan_obj, deps.index
    context = state.get("context", {})
    try:
        match plan.intent:
            case "overview": o = await handlers.overview(deps.api, index, plan)
            case "why": o = await handlers.why(deps.api, index, plan)
            case "breakdown": o = await handlers.breakdown(deps.api, index, plan)
            case "trend": o = await handlers.trend(deps.api, index, plan)
            case "forecast": o = await handlers.forecast(deps.api, index, plan)
            case "what_if": o = await handlers.what_if(deps.api, index, plan)
            case "anomalies": o = await handlers.anomalies(deps.api, index, plan)
            case "report": o = await actions.report(deps.api, index, plan)
            case "report_edit": o = await actions.report_edit(deps.api, index, plan, context)
            case "alert": o = await actions.alert(deps.api, index, plan)
            case "dashboard": o = await actions.dashboard(deps.api, index, plan, state["question"])
            case _: o = actions.help_outcome()
    except ApiError as e:
        o = Outcome(facts=[], caveats=[e.message])
        o.headline = "I couldn't complete that"
        trace = await _step(deps, state, "sql", f"Blocked by the API: {e.message}", t0, "failed")
        deps.outcome_obj = o
        return {"trace": trace}

    deps.outcome_obj = o
    trace = state.get("trace", [])
    agents = {
        "overview": [("sql", "KPI, trend and decomposition queries"), ("analytics", "Period-over-period changes"), ("root_cause", "Decomposed the most concerning KPI")],
        "why": [("sql", "Component measures by candidate dimensions"), ("root_cause", "Leave-one-out attribution and change-point onset")],
        "breakdown": [("sql", "Grouped, sorted semantic query"), ("analytics", "Shares and ranking")],
        "trend": [("sql", "Time-bucketed semantic query"), ("anomaly", "Seasonal robust z-scores on complete periods")],
        "forecast": [("sql", "Complete-period history"), ("forecast", "Exponential smoothing with backtest")],
        "what_if": [("sql", "Baseline and 52-week fit data"), ("analytics", "Utilisation → SLA sensitivity")],
        "anomalies": [("anomaly", "Open anomalies from the detector")],
        "report": [("report", "Template sections computed from governed queries")],
        "report_edit": [("report", "Report updated")],
        "alert": [("action", "Alert rule created")],
        "dashboard": [("action", "Dashboard created")],
    }.get(plan.intent, [])
    for agent, detail in agents:
        trace = await _step(deps, {**state, "trace": trace}, agent, detail, t0)
    caveat = "; ".join(o.caveats) if o.caveats else f"{len(o.evidence)} evidence queries, no data issues"
    trace = await _step(deps, {**state, "trace": trace}, "validation", caveat, t0)
    if deps.emit:
        for block in o.blocks:
            await deps.emit("block", block)
    return {"trace": trace}


async def visualization_node(state: State, config: RunnableConfig) -> State:
    deps, t0 = _deps(config), time.perf_counter()
    o = deps.outcome_obj
    reasons = [f"{b.get('title', b['type'])}: {b['viz']['type']}" for b in o.blocks if isinstance(b.get("viz"), dict)]
    return {"trace": await _step(deps, state, "visualization", "; ".join(reasons) or "Structured blocks", t0)}


async def narrative_node(state: State, config: RunnableConfig) -> State:
    deps, t0 = _deps(config), time.perf_counter()
    plan = deps.plan_obj
    if state.get("refusal"):
        deps.outcome_obj = Outcome(headline="Not answered")
        answer, narrator = state["refusal"], "deterministic"
    else:
        o = deps.outcome_obj
        base = compose(plan.intent, o, plan.executive_tone)
        if plan.notes:
            base += "\n\n_Assumptions: " + " ".join(plan.notes) + "_"
        answer, narrator = base, "deterministic"
        if settings().llm_available and o.facts and plan.intent not in ("alert", "dashboard", "help"):
            answer, narrator = await llm.narrate(state["question"], o.facts + [f"Caveat: {c}" for c in o.caveats], base, deps.usage, plan.executive_tone)
    if deps.emit:
        await deps.emit("answer", {"text": answer})
    return {"answer": answer, "narrator": narrator, "trace": await _step(deps, state, "narrative", f"Narrative: {narrator}", t0)}


def build_graph():
    g = StateGraph(State)
    g.add_node("semantic", semantic_node)
    g.add_node("intent", intent_node)
    g.add_node("governance", governance_node)
    g.add_node("execute", execute_node)
    g.add_node("visualization", visualization_node)
    g.add_node("narrative", narrative_node)
    g.set_entry_point("semantic")
    g.add_edge("semantic", "intent")
    g.add_edge("intent", "governance")
    g.add_conditional_edges("governance", lambda s: "narrative" if s.get("refusal") else "execute", {"narrative": "narrative", "execute": "execute"})
    g.add_edge("execute", "visualization")
    g.add_edge("visualization", "narrative")
    g.add_edge("narrative", END)
    return g.compile()


GRAPH = build_graph()


async def run(question: str, deps: Deps, context: dict | None = None) -> dict[str, Any]:
    started = time.perf_counter()
    with observability.trace("ai-analyst", as_type="agent", input={"question": question}) as obs:
        final: State = await GRAPH.ainvoke({"question": question, "context": context or {}, "trace": []}, config={"configurable": {"deps": deps}})
        o = deps.outcome_obj or Outcome()
        plan = deps.plan_obj
        result = {
            "answer": final.get("answer", ""),
            "headline": o.headline,
            "blocks": o.blocks,
            "evidence": [e for e in o.evidence if e.get("query_hash")],
            "suggestions": list(dict.fromkeys(o.suggestions))[:4],
            "caveats": o.caveats,
            "plan": plan.model_dump() if plan else None,
            "intent": plan.intent if plan else None,
            "planner": final.get("planner"),
            "narrator": final.get("narrator"),
            "trace": final.get("trace", []),
            "status": "refused" if final.get("refusal") else "succeeded",
            "model": settings().model if deps.usage.calls else None,
            "usage": {"tokens_in": deps.usage.tokens_in, "tokens_out": deps.usage.tokens_out, "cost_usd": deps.usage.cost_usd, "calls": deps.usage.calls},
            "latency_ms": int((time.perf_counter() - started) * 1000),
            "context": {**(context or {}), **({"intent": plan.intent, "range": plan.range} if plan else {}), **o.context},
        }
        if obs is not None:
            obs.update(output={"answer": result["answer"], "intent": result["intent"]}, metadata={"planner": result["planner"], "evidence": len(result["evidence"])})
    return result
