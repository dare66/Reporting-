"""Deterministic semantic planner.

Rule-based but catalog-driven: intents from verbs, metrics/dimensions from the
governed catalog's labels and synonyms, members from live dimension values,
time from business phrases. Used when no LLM is configured and as the
validated fallback when the LLM's plan is unusable."""

from __future__ import annotations

import re
from calendar import month_name
from datetime import date

from ..types import JSON
from .catalog import CatalogIndex, norm
from .plan import AlertSpec, Filter, Grain, Intent, Plan, ReportEdit, Scenario

OVERVIEW_METRICS = [
    "revenue.revenue",
    "applications.total_applications",
    "decisions.sla_compliance",
    "decisions.approval_rate",
    "applications.high_risk_applications",
]

INTENT_RULES: list[tuple[Intent, str]] = [
    ("incident", r"\b(create|open|raise|log|file|start)\b.{0,30}\b(incident|ticket|case)\b"),
    ("alert", r"\b(alert|notify|warn|tell) me\b|\balert when\b|\bsend (me )?an alert\b"),
    ("what_if", r"\bwhat (happens|would happen) if\b|\bwhat if\b|\bscenario\b|\bsimulat"),
    (
        "report_edit",
        "|".join(
            [
                r"\b(make|turn) (the |this |it )?(report )?more executive\b",
                r"\badd (a |an )?(\d+[- ]month )?(forecast|comparison|anomal|root cause|section)\b.*\b(report|it)?\b",
                r"\bto the report\b",
            ]
        ),
    ),
    ("report", r"\b(report|brief|board pack|management pack|summary deck|ppt|presentation)\b"),
    ("dashboard", r"\bdashboard\b"),
    ("forecast", r"\b(forecast|predict|projection|project|outlook|next \d+ (months?|weeks?|days?))\b"),
    ("why", r"\bwhy\b|\broot cause\b|\bdrivers?\b|\bexplain\b|\bwhat caused\b|\breason\b|\binvestigate\b"),
    ("anomalies", r"\banomal|\bunusual\b|\boutliers?\b|\bspikes?\b"),
    ("trend", r"\btrend\b|\bover time\b|\b(daily|weekly|monthly|quarterly)\b|\bmonth by month\b|\bhistory\b"),
    (
        "breakdown",
        "|".join(
            rf"\b{word}\b"
            for word in (
                "by",
                "per",
                r"break ?down",
                "top",
                "bottom",
                "which",
                "affected",
                "compare",
                "comparison",
                r"vs\.?",
                "versus",
                "ranking",
                "distribution",
                "contribution",
                "movement between",
            )
        ),
    ),
]


# "below RM 1.2m", "drops to 450", "exceeds 20k" → amount and optional k/m suffix.
THRESHOLD_AMOUNT = (
    r"(?:below|under|above|over|exceeds?|less than|more than|drops? to|falls? to)"
    r"\s+(?:rm\s*)?([\d,.]+)\s*(k|m)?"
)


def parse_range(t: str, today: date | None = None) -> tuple[str | JSON | None, list[str]]:
    today = today or date.today()
    notes: list[str] = []
    m = re.search(r"\b(?:last|past|previous) (\d+) (day|week|month)s?\b", t)
    if m:
        n, unit = int(m.group(1)), m.group(2)
        if unit == "day":
            return {7: "last_7_days", 30: "last_30_days", 90: "last_90_days"}.get(n) or {
                "from": date.fromordinal(today.toordinal() - n + 1).isoformat(),
                "to": today.isoformat(),
            }, notes
        if unit == "week":
            return {"from": date.fromordinal(today.toordinal() - 7 * n + 1).isoformat(), "to": today.isoformat()}, notes
        return {6: "last_6_months", 12: "last_12_months", 24: "last_24_months"}.get(
            n, "last_12_months" if n > 6 else "last_6_months"
        ), notes
    phrases = [
        (r"\btoday\b", "today"),
        (r"\byesterday\b", "yesterday"),
        (r"\bthis week\b", "this_week"),
        (r"\blast week\b", "last_week"),
        (r"\b(this|current) month\b|\bmonth to date\b|\bmtd\b", "this_month"),
        (r"\blast month\b|\bprevious month\b", "last_month"),
        (r"\bthis quarter\b|\bqtd\b", "this_quarter"),
        (r"\blast quarter\b", "last_quarter"),
        (r"\bthis year\b|\bytd\b|\byear to date\b", "year_to_date"),
        (r"\blast year\b", "last_year"),
        (r"\blast 12 months\b|\bpast year\b", "last_12_months"),
    ]
    for pattern, preset in phrases:
        if re.search(pattern, t):
            return preset, notes
    for i in range(1, 13):
        if re.search(rf"\b(in |for |during )?{month_name[i].lower()}\b", t):
            year = today.year if i <= today.month else today.year - 1
            end = date(year + (i == 12), i % 12 + 1, 1)
            return {
                "from": date(year, i, 1).isoformat(),
                "to": date.fromordinal(min(end.toordinal() - 1, today.toordinal())).isoformat(),
            }, notes
    return None, notes


def parse_percent(t: str) -> float | None:
    m = re.search(r"(-?\d+(?:\.\d+)?)\s*(%|percent|per cent)", t)
    return float(m.group(1)) if m else None


def plan(question: str, index: CatalogIndex, context: JSON | None = None) -> Plan:
    context = context or {}
    t = norm(question)
    intent: Intent | None = next((name for name, pattern in INTENT_RULES if re.search(pattern, t)), None)
    metrics = index.find_metrics(question)
    members = index.find_members(question)
    dimension = index.find_dimension(question)
    range_, notes = parse_range(t)
    prev_metrics = context.get("metrics") or []

    if (
        re.search(r"\b(performance|how are we doing|business health|overview|kpis?|how did we do)\b", t)
        and not metrics
        and intent in (None, "breakdown")
    ):
        intent = "overview"
    if intent is None:
        intent = (
            "breakdown"
            if dimension
            else ("overview" if not metrics else "trend" if re.search(r"\bmonthly|over\b", t) else "overview")
        )
    if intent == "breakdown" and not dimension and members:
        dimension = next(iter(members))
    if intent == "breakdown" and not dimension:
        intent = "overview" if not metrics else "trend"
    if not metrics and prev_metrics and intent not in ("overview", "report", "dashboard", "help"):
        metrics = prev_metrics[:1]
        notes.append(f"Continuing with {index.metric_label(metrics[0])} from the conversation.")

    if not metrics and dimension and intent in ("breakdown", "trend", "why"):
        # A dimension alone ("movement between stages") implies the headline metric of a model that has it.
        model = next((m for m in index.models if dimension in index.dimensions.get(m, {})), None)
        lead = next(
            (
                r
                for r, m in index.metrics.items()
                if m.model == model and m.is_kpi and m.higher_is_better and m.format in ("number", "currency")
            ),
            None,
        )
        if lead:
            metrics = [lead]
            notes.append(f"Using {index.metrics[lead].label} as the measure.")
    # Keep only metrics whose model can answer the requested dimension/filters.
    needed = set(members) | ({dimension} if dimension else set())
    if needed and metrics:
        usable = [m for m in metrics if all(d in index.dimensions.get(index.metrics[m].model, {}) for d in needed)]
        if not usable:
            wanted = ", ".join(index.dimension_label(d) for d in needed)
            notes.append(f"{index.metric_label(metrics[0])} cannot be split by {wanted}.")
        metrics = usable or metrics

    # Named members become filters; with a matching breakdown dimension this is a comparison ("China vs India").
    filters = [Filter(dimension=d, op="in", value=v) for d, v in members.items()]
    p = Plan(
        intent=intent,
        metrics=metrics,
        dimension=dimension,
        filters=filters,
        range=range_ or context.get("range") or "last_30_days",
        notes=notes,
    )

    p.as_table = bool(re.search(r"\b(table|list|affected|which)\b", t))
    p.executive_tone = bool(re.search(r"\bexecutive\b", t))
    if re.search(r"\b(lowest|worst|bottom|least)\b", t):
        p.sort = "asc"
    elif re.search(r"\baffected\b", t) and metrics and (first := index.metric(metrics[0])) and first.higher_is_better:
        p.sort = "asc"  # most affected = worst performers
    if m := re.search(r"\btop (\d+)\b|\bbottom (\d+)\b", t):
        p.limit = int(m.group(1) or m.group(2))
    if m := re.search(r"\b(daily|weekly|monthly|quarterly)\b", t):
        grains: dict[str, Grain] = {"daily": "day", "weekly": "week", "monthly": "month", "quarterly": "quarter"}
        p.grain = grains[m.group(1)]

    if intent == "trend" and not range_:
        p.range = "last_12_months"
    if intent == "overview" and not p.metrics:
        p.metrics = OVERVIEW_METRICS
    if intent in ("why", "anomalies") and not range_:
        p.range = context.get("range") or "last_30_days"

    if intent == "forecast":
        m = re.search(r"(\d+)[- ]?(day|week|month)", t)
        if m:
            n, unit = int(m.group(1)), m.group(2)
            p.horizon = (
                "7d"
                if unit == "day" and n <= 7
                else "30d"
                if unit == "day"
                else "90d"
                if unit == "week" or n <= 3
                else "12m"
                if n > 6
                else "6m"
            )
        if not p.metrics:
            p.metrics = ["applications.total_applications"]

    if intent == "what_if":
        pct = parse_percent(t) or 0
        if re.search(r"\b(decrease|drop|fall|reduce|lose|cut)\b", t):
            pct = -abs(pct)
        s = Scenario()
        if re.search(r"\b(staff|officers?|headcount|hire|team)\b", t):
            s.officer_change_pct = pct
        elif re.search(r"\bproductiv|efficien", t):
            s.productivity_change_pct = pct
        else:
            s.demand_change_pct = pct
        p.scenario = s

    if intent == "incident" and re.search(r"\b(biggest|worst|main|top|largest|most (serious|urgent|important))\b", t):
        p.metrics = []  # the agent finds the biggest issue itself, rather than reusing the last metric discussed

    if intent == "alert":
        if not p.metrics:
            p.notes.append("I couldn't tell which metric to watch.")
        else:
            info = index.require_metric(p.metrics[0])
            value = parse_percent(t)
            if value is None and (m := re.search(THRESHOLD_AMOUNT, t)):
                value = float(m.group(1).replace(",", "")) * {"k": 1e3, "m": 1e6}.get(m.group(2) or "", 1)
            below = bool(re.search(r"\b(below|under|less than|falls?|drops?|lower than)\b", t))
            if value is not None and info:
                threshold = value / 100 if info.format == "percent" and value > 1 else value
                if (
                    re.search(r"\b(spike|increase|rises?|grows?)\b", t)
                    and info.format != "percent"
                    and parse_percent(t) is not None
                ):
                    p.alert = AlertSpec(operator="change_pct_gt", threshold=value)
                else:
                    p.alert = AlertSpec(operator="lt" if below else "gt", threshold=threshold)
                if re.search(r"\bdaily\b|\btoday\b", t):
                    p.alert.window = "today"

    if intent == "report":
        p.report_template = next(
            (
                tpl
                for tpl, pattern in [
                    ("board", r"\bboard\b"),
                    ("ceo", r"\bceo\b|\bexecutive\b"),
                    ("cfo", r"\bcfo\b"),
                    ("coo", r"\bcoo\b"),
                    ("finance", r"\bfinanc|revenue\b"),
                    ("risk", r"\brisk\b"),
                    ("compliance", r"\bcomplian"),
                    ("operations", r"\boperation|sla\b|processing\b"),
                ]
                if re.search(pattern, t)
            ),
            "monthly_management",
        )
        if not range_:
            p.range = "last_month"
        if re.search(r"\bmonthly\b", t):
            p.range = "last_month"

    if intent == "report_edit":
        if re.search(r"more executive", t):
            p.report_edit = ReportEdit(action="make_executive")
        elif re.search(r"forecast", t):
            months = re.search(r"(\d+)[- ]month", t)
            p.report_edit = ReportEdit(
                action="add_section", section_type="forecast", horizon=int(months.group(1)) if months else 6
            )
        elif re.search(r"anomal|unusual", t):
            p.report_edit = ReportEdit(action="add_section", section_type="anomalies")
        elif re.search(r"root cause|driver", t):
            p.report_edit = ReportEdit(action="add_section", section_type="root_cause")
        else:
            p.report_edit = ReportEdit(
                action="add_section",
                section_type="breakdown",
                dimension=dimension or next(iter(members), None) or "country",
            )

    if re.search(r"^(help|what can you do|hi|hello)\b", t):
        p.intent = "help"
    return p
