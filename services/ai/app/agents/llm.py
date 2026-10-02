"""Claude-backed planning and narrative.

Claude never sees raw rows and never writes SQL. The planner maps a question
onto governed catalog keys (validated afterwards); the narrator rewrites
already-computed facts into executive language and is checked so that every
number it states exists in those facts — otherwise the deterministic text is used."""

from __future__ import annotations

import json
import re
from dataclasses import dataclass, field
from typing import Literal

import anthropic
from pydantic import BaseModel, Field

from ..config import settings
from .catalog import CatalogIndex
from .plan import AlertSpec, Filter, Plan, ReportEdit, Scenario

PLANNER_SYSTEM = """You are the planning agent of AIXBI, an enterprise analytics platform.
Translate the user's business question into a plan over the governed semantic catalog below.

Rules:
- Use only metric refs, dimension keys and member values that appear in the catalog. Never invent names.
- A dimension or filter may only be used with metrics whose model lists that dimension.
- You never write SQL and never state numbers; the platform computes everything.
- intent meanings: overview = headline performance; trend = metric over time; breakdown = metric split by a dimension (also comparisons, rankings, "which/affected X"); why = explain a change (root cause); forecast; what_if = scenario on demand/officers/productivity in percent; report = create a report; report_edit = change the report in this conversation; alert = notify on a threshold; dashboard = build a dashboard; anomalies = unusual behaviour; help.
- Percent thresholds for percent-format metrics are fractions (90% -> 0.9).
- Use conversation context for follow-ups (e.g. "show me the affected institutions" continues the previous metric).
- range_preset is one of: today, yesterday, this_week, last_week, this_month, last_month, this_quarter, last_quarter, year_to_date, last_year, last_7_days, last_30_days, last_90_days, last_6_months, last_12_months, last_24_months; or give range_from/range_to (YYYY-MM-DD).
- Record any assumption you make in notes, briefly.

CATALOG:
"""

NARRATIVE_SYSTEM = """You write for executives at an enterprise using AIXBI.
You receive FACTS computed by the platform. Write a concise answer (2-5 sentences, plain prose, no headings, no bullet lists).
Hard rules:
- Every number you write must appear verbatim in FACTS (same formatting). Do not compute new numbers, totals or percentages.
- Do not speculate about causes beyond the drivers listed in FACTS. If FACTS include caveats, mention the most important one.
- Lead with what matters most to the business, then why, then what to look at next."""


class LlmFilter(BaseModel):
    dimension: str
    values: list[str]


class LlmPlan(BaseModel):
    intent: Literal["overview", "trend", "breakdown", "why", "forecast", "what_if", "report", "report_edit", "alert", "dashboard", "anomalies", "help"]
    metrics: list[str]
    dimension: str | None
    filters: list[LlmFilter]
    range_preset: str | None
    range_from: str | None
    range_to: str | None
    grain: Literal["day", "week", "month", "quarter", "year"] | None
    sort: Literal["asc", "desc"]
    limit: int
    horizon: Literal["7d", "30d", "90d", "6m", "12m"]
    demand_change_pct: float | None
    officer_change_pct: float | None
    productivity_change_pct: float | None
    alert_operator: Literal["lt", "lte", "gt", "gte", "change_pct_gt", "change_pct_lt"] | None
    alert_threshold: float | None
    report_template: Literal["ceo", "board", "monthly_management", "coo", "operations", "cfo", "finance", "risk", "compliance"] | None
    report_edit_action: Literal["add_section", "make_executive", "regenerate"] | None
    report_edit_section: Literal["forecast", "breakdown", "anomalies", "root_cause", "chart", "kpis", "text"] | None
    as_table: bool
    notes: list[str] = Field(default_factory=list)


@dataclass
class Usage:
    tokens_in: int = 0
    tokens_out: int = 0
    calls: list[str] = field(default_factory=list)

    def add(self, response, purpose: str) -> None:
        u = response.usage
        self.tokens_in += (u.input_tokens or 0) + (getattr(u, "cache_read_input_tokens", 0) or 0) + (getattr(u, "cache_creation_input_tokens", 0) or 0)
        self.tokens_out += u.output_tokens or 0
        self.calls.append(purpose)

    @property
    def cost_usd(self) -> float:
        s = settings()
        return round(self.tokens_in / 1e6 * s.price_in_per_mtok + self.tokens_out / 1e6 * s.price_out_per_mtok, 6)


class LlmUnavailable(Exception):
    """LLM could not produce a usable result; callers fall back to deterministic logic."""


def _client() -> anthropic.AsyncAnthropic:
    return anthropic.AsyncAnthropic(api_key=settings().anthropic_api_key, max_retries=2, timeout=60)


async def plan(question: str, index: CatalogIndex, context: dict, usage: Usage) -> Plan:
    catalog = json.dumps(index.describe_for_llm(), separators=(",", ":"), sort_keys=True)
    ctx = json.dumps({k: context.get(k) for k in ("intent", "metrics", "dimension", "range", "report_id") if context.get(k)}, sort_keys=True)
    try:
        response = await _client().beta.messages.parse(
            model=settings().model,
            max_tokens=4000,
            betas=["server-side-fallback-2026-07-01"],
            fallbacks="default",
            output_config={"effort": "low"},
            system=[{"type": "text", "text": PLANNER_SYSTEM + catalog, "cache_control": {"type": "ephemeral"}}],
            messages=[{"role": "user", "content": f"Conversation context: {ctx}\n\nQuestion: {question}"}],
            output_format=LlmPlan,
        )
    except (anthropic.APIConnectionError, anthropic.RateLimitError, anthropic.APIStatusError) as e:
        raise LlmUnavailable(f"planner call failed: {type(e).__name__}") from e
    usage.add(response, "planner")
    if response.stop_reason == "refusal" or response.parsed_output is None:
        raise LlmUnavailable("planner declined or returned no plan")
    return to_plan(response.parsed_output, index)


def to_plan(p: LlmPlan, index: CatalogIndex) -> Plan:
    """Validate every reference against the catalog — the LLM proposes, the catalog disposes."""
    metrics = [m for m in p.metrics if m in index.metrics]
    if p.metrics and not metrics:
        raise LlmUnavailable("planner referenced unknown metrics")
    known_dims = {d for dims in index.dimensions.values() for d in dims}
    dimension = p.dimension if p.dimension in known_dims else None
    filters = [Filter(dimension=f.dimension, op="in", value=f.values) for f in p.filters if f.dimension in known_dims and f.values]
    rng: str | dict = p.range_preset or "last_30_days"
    if p.range_from and p.range_to:
        rng = {"from": p.range_from, "to": p.range_to}
    plan_ = Plan(intent=p.intent, metrics=metrics, dimension=dimension, filters=filters, range=rng, grain=p.grain, sort=p.sort,
                 limit=max(1, min(p.limit or 10, 50)), horizon=p.horizon, as_table=p.as_table, notes=p.notes[:3],
                 report_template=p.report_template)
    if p.intent == "what_if":
        plan_.scenario = Scenario(demand_change_pct=p.demand_change_pct or 0, officer_change_pct=p.officer_change_pct or 0,
                                  productivity_change_pct=p.productivity_change_pct or 0)
    if p.intent == "alert" and p.alert_operator and p.alert_threshold is not None:
        plan_.alert = AlertSpec(operator=p.alert_operator, threshold=p.alert_threshold)
    if p.intent == "report_edit" and p.report_edit_action:
        plan_.report_edit = ReportEdit(action=p.report_edit_action, section_type=p.report_edit_section, dimension=dimension)
    return plan_


NUMBER = re.compile(r"(?<![\w.])[-−+]?(?:RM\s?)?\d[\d,]*(?:\.\d+)?\s?(?:%|pts|K|M|B|σ|days)?")


def numbers_in(text: str) -> set[str]:
    return {re.sub(r"\s", "", n).replace("−", "").replace("-", "").lstrip("+") for n in NUMBER.findall(text)}


def grounded(text: str, facts_text: str) -> bool:
    """Every number in the narrative must appear in the facts (years and small ordinals excepted)."""
    allowed = numbers_in(facts_text)
    for n in numbers_in(text):
        bare = n.rstrip("%").replace("RM", "")
        if n in allowed or (bare.isdigit() and (int(bare) <= 12 or 1990 <= int(bare) <= 2100)):
            continue
        return False
    return True


async def narrate(question: str, facts: list[str], fallback: str, usage: Usage, executive: bool = False) -> tuple[str, str]:
    facts_text = "\n".join(f"[F{i + 1}] {f}" for i, f in enumerate(facts))
    tone = " Use the most concise board-level language." if executive else ""
    try:
        response = await _client().beta.messages.create(
            model=settings().model,
            max_tokens=2000,
            betas=["server-side-fallback-2026-07-01"],
            fallbacks="default",
            output_config={"effort": "low"},
            system=NARRATIVE_SYSTEM + tone,
            messages=[{"role": "user", "content": f"QUESTION: {question}\n\nFACTS:\n{facts_text}"}],
        )
    except (anthropic.APIConnectionError, anthropic.RateLimitError, anthropic.APIStatusError):
        return fallback, "deterministic (LLM unavailable)"
    usage.add(response, "narrative")
    if response.stop_reason == "refusal":
        return fallback, "deterministic (LLM declined)"
    text = "".join(b.text for b in response.content if b.type == "text").strip()
    if not text or not grounded(text, facts_text):
        return fallback, "deterministic (LLM narrative failed number grounding check)"
    return text, "llm"
