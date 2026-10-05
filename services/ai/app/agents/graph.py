"""LangGraph orchestration of the AI analyst.

intent → semantic → planner → governance ─┬→ execute → visualization → narrative
                                          └→ narrative (refusal / clarification)

execute covers SQL generation, validation, analytics, anomaly detection and root cause.

Every node appends to a trace that is persisted with the run and streamed to the UI."""

from __future__ import annotations

import time
from collections.abc import Awaitable, Callable
from dataclasses import dataclass, field
from typing import Any, TypedDict

from langchain_core.runnables import RunnableConfig
from langgraph.graph import END, StateGraph

from .. import observability
from ..api_client import AixbiApi, ApiError
from ..config import settings
from ..types import JSON, JSONList
from . import actions, deterministic, handlers, llm
from .catalog import CatalogIndex
from .handlers import Outcome
from .narrative import compose
from .plan import Plan

AGENT_LABELS = {
    "intent": "Intent Agent",
    "semantic": "Semantic Model Agent",
    "planner": "Query Planner",
    "governance": "Governance Agent",
    "sql": "SQL Agent",
    "validation": "Data Validation",
    "analytics": "Analytics Agent",
    "anomaly": "Anomaly Agent",
    "root_cause": "Root Cause Agent",
    "forecast": "Forecast Agent",
    "report": "Report Agent",
    "action": "Action Agent",
    "visualization": "Visualization Agent",
    "narrative": "Narrative Agent",
}


class State(TypedDict, total=False):
    question: str
    context: JSON
    plan: JSON
    planner: str
    refusal: str | None
    outcome: JSON
    answer: str
    narrator: str
    trace: JSONList


@dataclass
class Deps:
    """Per-run dependencies plus the objects each node hands to the next.

    The graph runs semantic → intent → governance → execute → visualization →
    narrative; the require_* accessors make that ordering explicit.
    """

    api: AixbiApi
    organisation_id: str
    emit: Callable[[str, JSON], Awaitable[None]] | None = None
    usage: llm.Usage = field(default_factory=llm.Usage)
    index: CatalogIndex | None = None
    plan_obj: Plan | None = None
    outcome_obj: Outcome | None = None

    def require_index(self) -> CatalogIndex:
        if self.index is None:
            raise RuntimeError("The semantic node must run before the catalog index is used.")
        return self.index

    def require_plan(self) -> Plan:
        if self.plan_obj is None:
            raise RuntimeError("The intent node must run before the plan is used.")
        return self.plan_obj

    def require_outcome(self) -> Outcome:
        if self.outcome_obj is None:
            raise RuntimeError("The execute node must run before its outcome is used.")
        return self.outcome_obj


def _deps(config: RunnableConfig) -> Deps:
    deps = config.get("configurable", {}).get("deps")
    if not isinstance(deps, Deps):
        raise TypeError("The graph must be invoked with configurable.deps.")
    return deps


async def _step(deps: Deps, trace: JSONList, agent: str, detail: str, started: float, status: str = "done") -> JSONList:
    """Appends one agent step to the trace and streams it to the client."""
    entry = {
        "agent": agent,
        "label": AGENT_LABELS.get(agent, agent),
        "status": status,
        "detail": detail,
        "ms": int((time.perf_counter() - started) * 1000),
    }
    if deps.emit:
        await deps.emit("step", entry)
    return [*trace, entry]


def _plan_summary(plan: Plan, index: CatalogIndex) -> str:
    """One line describing what the planner resolved, shown in the trace."""
    labels = [info.label for m in plan.metrics if (info := index.metric(m))]
    parts = ["Metrics: " + (", ".join(labels) or "—")]
    if plan.dimension:
        parts.append(f"by {index.dimension_label(plan.dimension)}")
    if plan.filters:
        parts.append("filters: " + ", ".join(f"{f.dimension}={f.value}" for f in plan.filters))
    period = plan.range if isinstance(plan.range, str) else f"{plan.range['from']}…{plan.range['to']}"
    parts.append(f"period: {period}")
    return " · ".join(parts)


async def semantic_node(state: State, config: RunnableConfig) -> State:
    deps, t0 = _deps(config), time.perf_counter()
    index = deps.index = CatalogIndex.from_catalog(await deps.api.catalog())
    await index.load_members(deps.api, deps.organisation_id)
    members = sum(len(v) for v in index.members.values())
    detail = (
        f"{len(index.metrics)} governed metrics across {len(index.models)} semantic models; {members} known members"
    )
    return {"trace": await _step(deps, state.get("trace", []), "semantic", detail, t0)}


async def intent_node(state: State, config: RunnableConfig) -> State:
    deps, t0 = _deps(config), time.perf_counter()
    index, context = deps.require_index(), state.get("context", {})
    plan, planner = None, "deterministic"
    if settings().llm_available:
        try:
            plan = await llm.plan(state["question"], index, context, deps.usage)
            planner = "llm"
        except llm.LlmUnavailableError as e:
            planner = f"deterministic (fallback: {e})"
    if plan is None:
        plan = deterministic.plan(state["question"], index, context)
    deps.plan_obj = plan
    trace = await _step(deps, state.get("trace", []), "intent", f"Intent: {plan.intent} · planner: {planner}", t0)
    trace = await _step(deps, trace, "planner", _plan_summary(plan, index), t0)
    if deps.emit:
        await deps.emit("plan", plan.model_dump())
    return {"plan": plan.model_dump(), "planner": planner, "trace": trace}


async def governance_node(state: State, config: RunnableConfig) -> State:
    deps, t0 = _deps(config), time.perf_counter()
    plan, index = deps.require_plan(), deps.require_index()
    refusal = None
    needs_metric = plan.intent in ("trend", "breakdown", "why", "forecast", "alert")
    if needs_metric and not plan.metrics:
        refusal = (
            "I couldn't match that to a governed metric you can access. "
            "Try naming a metric such as applications, revenue, SLA or approval rate."
        )
    for f in plan.filters:
        if not any(f.dimension in d for d in index.dimensions.values()):
            refusal = f"You don't have access to “{f.dimension}”, or it doesn't exist in the semantic model."
    if plan.intent == "incident":
        detail = "Proposal only: the action engine runs nothing until someone allowed to approve it does"
    elif plan.intent in ("report", "report_edit", "dashboard", "alert"):
        detail = "Action permitted only through the API under your own permissions"
    else:
        detail = (
            "All references resolve to governed, accessible catalog entries; row-level security applies server-side"
        )
    status = "blocked" if refusal else "done"
    trace = await _step(deps, state.get("trace", []), "governance", refusal or detail, t0, status)
    return {"refusal": refusal, "trace": trace}


async def execute_node(state: State, config: RunnableConfig) -> State:
    deps, t0 = _deps(config), time.perf_counter()
    plan, index = deps.require_plan(), deps.require_index()
    context = state.get("context", {})
    try:
        match plan.intent:
            case "overview":
                o = await handlers.overview(deps.api, index, plan)
            case "why":
                o = await handlers.why(deps.api, index, plan)
            case "breakdown":
                o = await handlers.breakdown(deps.api, index, plan)
            case "trend":
                o = await handlers.trend(deps.api, index, plan)
            case "forecast":
                o = await handlers.forecast(deps.api, index, plan)
            case "what_if":
                o = await handlers.what_if(deps.api, index, plan)
            case "anomalies":
                o = await handlers.anomalies(deps.api, index, plan)
            case "report":
                o = await actions.report(deps.api, index, plan)
            case "report_edit":
                o = await actions.report_edit(deps.api, index, plan, context)
            case "alert":
                o = await actions.alert(deps.api, index, plan)
            case "incident":
                o = await actions.incident(deps.api, index, plan, context)
            case "dashboard":
                o = await actions.dashboard(deps.api, index, plan, state["question"])
            case _:
                o = actions.help_outcome()
    except ApiError as e:
        o = Outcome(facts=[], caveats=[e.message])
        o.headline = "I couldn't complete that"
        trace = await _step(deps, state.get("trace", []), "sql", f"Blocked by the API: {e.message}", t0, "failed")
        deps.outcome_obj = o
        return {"trace": trace}

    deps.outcome_obj = o
    trace = state.get("trace", [])
    agents = {
        "overview": [
            ("sql", "KPI, trend and decomposition queries"),
            ("analytics", "Period-over-period changes"),
            ("root_cause", "Decomposed the most concerning KPI"),
        ],
        "why": [
            ("sql", "Component measures by candidate dimensions"),
            ("root_cause", "Leave-one-out attribution and change-point onset"),
        ],
        "breakdown": [("sql", "Grouped, sorted semantic query"), ("analytics", "Shares and ranking")],
        "trend": [("sql", "Time-bucketed semantic query"), ("anomaly", "Seasonal robust z-scores on complete periods")],
        "forecast": [("sql", "Complete-period history"), ("forecast", "Exponential smoothing with backtest")],
        "what_if": [("sql", "Baseline and 52-week fit data"), ("analytics", "Utilisation → SLA sensitivity")],
        "anomalies": [("anomaly", "Open anomalies from the detector")],
        "report": [("report", "Template sections computed from governed queries")],
        "report_edit": [("report", "Report updated")],
        "alert": [("action", "Alert rule created")],
        "incident": [
            ("sql", "Headline KPIs against the previous period"),
            ("root_cause", "Decomposed the biggest issue"),
            ("action", "Incident proposed; awaiting approval"),
        ],
        "dashboard": [("action", "Dashboard created")],
    }.get(plan.intent, [])
    for agent, detail in agents:
        trace = await _step(deps, trace, agent, detail, t0)
    caveat = "; ".join(o.caveats) if o.caveats else f"{len(o.evidence)} evidence queries, no data issues"
    trace = await _step(deps, trace, "validation", caveat, t0)
    if deps.emit:
        for block in o.blocks:
            await deps.emit("block", block)
    return {"trace": trace}


async def visualization_node(state: State, config: RunnableConfig) -> State:
    deps, t0 = _deps(config), time.perf_counter()
    blocks = deps.require_outcome().blocks
    reasons = [f"{b.get('title', b['type'])}: {b['viz']['type']}" for b in blocks if isinstance(b.get("viz"), dict)]
    detail = "; ".join(reasons) or "Structured blocks"
    return {"trace": await _step(deps, state.get("trace", []), "visualization", detail, t0)}


async def narrative_node(state: State, config: RunnableConfig) -> State:
    deps, t0 = _deps(config), time.perf_counter()
    plan = deps.require_plan()
    refusal = state.get("refusal")
    if refusal:
        deps.outcome_obj = Outcome(headline="Not answered")
        answer, narrator = refusal, "deterministic"
    else:
        o = deps.require_outcome()
        base = compose(plan.intent, o, plan.executive_tone)
        if plan.notes:
            base += "\n\n_Assumptions: " + " ".join(plan.notes) + "_"
        answer, narrator = base, "deterministic"
        if settings().llm_available and o.facts and plan.intent not in ("alert", "incident", "dashboard", "help"):
            answer, narrator = await llm.narrate(
                state["question"], o.facts + [f"Caveat: {c}" for c in o.caveats], base, deps.usage, plan.executive_tone
            )
    if deps.emit:
        await deps.emit("answer", {"text": answer})
    return {
        "answer": answer,
        "narrator": narrator,
        "trace": await _step(deps, state.get("trace", []), "narrative", f"Narrative: {narrator}", t0),
    }


def build_graph() -> Any:
    """Compiles the analyst graph (LangGraph's compiled type is not exported)."""
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
    g.add_conditional_edges(
        "governance",
        lambda s: "narrative" if s.get("refusal") else "execute",
        {"narrative": "narrative", "execute": "execute"},
    )
    g.add_edge("execute", "visualization")
    g.add_edge("visualization", "narrative")
    g.add_edge("narrative", END)
    return g.compile()


GRAPH = build_graph()


async def run(question: str, deps: Deps, context: JSON | None = None) -> dict[str, Any]:
    started = time.perf_counter()
    with observability.trace("ai-analyst", as_type="agent", input={"question": question}) as obs:
        final: State = await GRAPH.ainvoke(
            {"question": question, "context": context or {}, "trace": []}, config={"configurable": {"deps": deps}}
        )
        o = deps.outcome_obj or Outcome()
        plan = deps.plan_obj
        evidence = [e for e in o.evidence if e.get("query_hash")]
        result: JSON = {
            "answer": final.get("answer", ""),
            "headline": o.headline,
            "blocks": o.blocks,
            "evidence": evidence,
            "suggestions": list(dict.fromkeys(o.suggestions))[:4],
            "caveats": o.caveats,
            "plan": plan.model_dump() if plan else None,
            "intent": plan.intent if plan else None,
            "planner": final.get("planner"),
            "narrator": final.get("narrator"),
            "trace": final.get("trace", []),
            "status": "refused" if final.get("refusal") else "succeeded",
            "model": settings().model if deps.usage.calls else None,
            "usage": {
                "tokens_in": deps.usage.tokens_in,
                "tokens_out": deps.usage.tokens_out,
                "cost_usd": deps.usage.cost_usd,
                "calls": deps.usage.calls,
            },
            "latency_ms": int((time.perf_counter() - started) * 1000),
            "context": {
                **(context or {}),
                **({"intent": plan.intent, "range": plan.range} if plan else {}),
                **o.context,
            },
        }
        if obs is not None:
            obs.update(
                output={"answer": result["answer"], "intent": result["intent"]},
                metadata={"planner": result["planner"], "evidence": len(evidence)},
            )
    return result
