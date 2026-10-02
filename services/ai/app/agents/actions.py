"""Action agents: they change things (reports, alerts, dashboards) through the
same permission-checked API as the UI, and say exactly what they did."""

from __future__ import annotations

from ..api_client import AixbiApi
from ..types import JSON
from . import formatting
from .catalog import CatalogIndex
from .handlers import Outcome
from .plan import Plan

TEMPLATE_NAMES = {
    "ceo": "CEO Report",
    "board": "Board Report",
    "monthly_management": "Monthly Management Report",
    "coo": "COO Operations Report",
    "operations": "Operational Report",
    "cfo": "CFO Financial Report",
    "finance": "Financial Report",
    "risk": "Risk Report",
    "compliance": "Compliance Report",
}


def _report_block(r: JSON, note: str) -> JSON:
    return {
        "type": "report",
        "title": r["title"],
        "report_id": r["id"],
        "status": r["status"],
        "version": r.get("current_version"),
        "sections": [
            {"id": s["id"], "type": s["type"], "title": s["title"], "error": s["content"].get("error")}
            for s in r.get("sections", [])
        ],
        "note": note,
        "actions": [
            {"label": "Open report", "link": f"/reports/{r['id']}"},
            {"label": "Generate PDF", "export": "pdf"},
            {"label": "Generate PPT", "export": "pptx"},
            {"label": "Excel", "export": "xlsx"},
        ],
    }


async def report(api: AixbiApi, index: CatalogIndex, plan: Plan) -> Outcome:
    o = Outcome()
    template = plan.report_template or "monthly_management"
    body: JSON = {"template": template, "range": plan.range}
    if plan.metrics and plan.metrics != [
        "revenue.revenue",
        "applications.total_applications",
        "decisions.sla_compliance",
        "decisions.approval_rate",
        "applications.high_risk_applications",
    ]:
        body["focus_metrics"] = plan.metrics[:3]
    r = await api.generate_report(body)
    failed = [s for s in r["sections"] if "error" in s["content"]]
    o.blocks.append(
        _report_block(r, f"Built from the {TEMPLATE_NAMES.get(template, template)} template with live, governed data.")
    )
    summary = next((s for s in r["sections"] if s["type"] == "summary"), None)
    o.facts.append(
        f"Created report “{r['title']}” with {len(r['sections'])} sections (version {r['current_version']}, draft)."
    )
    for p in (summary["content"].get("paragraphs", []) if summary else [])[:3]:
        o.facts.append(p)
    if failed:
        o.caveats.append(
            f"{len(failed)} section(s) could not be generated: " + "; ".join(s["title"] for s in failed) + "."
        )
    o.headline = r["title"]
    o.suggestions += [
        "Make the report more executive",
        "Add a 12-month forecast",
        "Add China and India comparison",
        "Generate a PPT",
    ]
    o.context = {"report_id": r["id"], "metrics": plan.metrics[:1]}
    return o


async def report_edit(api: AixbiApi, index: CatalogIndex, plan: Plan, context: JSON) -> Outcome:
    o = Outcome()
    report_id = context.get("report_id")
    if not report_id:
        o.facts.append("There is no report in this conversation yet. Ask me to create one first.")
        o.suggestions.append("Create a monthly CEO report")
        return o
    edit = plan.report_edit
    done = ""
    if edit and edit.action == "make_executive":
        r = await api.report(report_id)
        technical = [
            s
            for s in r["sections"]
            if s["type"] in ("anomalies", "breakdown") or (s["type"] == "chart" and "Revenue" not in s["title"])
        ]
        for s in technical[1:]:
            await api.delete_report_section(report_id, s["id"])
        await api.update_report(report_id, {"theme": "executive"})
        removed = max(0, len(technical) - 1)
        done = (
            f"Streamlined the report for executives: removed {removed} detailed section(s) "
            "and applied the Executive theme."
        )
    elif edit and edit.section_type == "forecast":
        horizon = edit.horizon or 6
        metric = (plan.metrics or ["applications.total_applications"])[0]
        await api.add_report_section(
            report_id,
            {
                "type": "forecast",
                "title": f"{horizon}-Month Forecast",
                "blueprint": {"metric": metric, "horizon": horizon},
            },
        )
        done = f"Added a {horizon}-month forecast of {index.metric_label(metric)}."
    elif edit and edit.section_type == "breakdown":
        dim = edit.dimension or "country"
        metric = next(
            (m for m in plan.metrics if dim in index.dimensions.get(index.require_metric(m).model, {})),
            "applications.total_applications",
        )
        members = next((f.value for f in plan.filters if f.dimension == dim), None)
        title = (
            f"{' and '.join(map(str, members))} comparison"
            if isinstance(members, list) and members
            else f"{index.metric_label(metric)} by {index.dimension_label(dim)}"
        )
        await api.add_report_section(
            report_id,
            {
                "type": "breakdown",
                "title": title.title() if members else title,
                "blueprint": {"metric": metric, "dimension": dim, "limit": 10},
            },
        )
        done = f"Added a section: {title}."
        if members:
            o.caveats.append("The comparison section ranks all members; the named ones are highlighted in the title.")
    elif edit and edit.section_type in ("anomalies", "root_cause"):
        metric = (plan.metrics or ["decisions.rejection_rate"])[0]
        bp = (
            {"metrics": [metric, "decisions.sla_compliance"]}
            if edit.section_type == "anomalies"
            else {"metric": metric}
        )
        await api.add_report_section(
            report_id,
            {
                "type": edit.section_type,
                "title": "Unusual Changes" if edit.section_type == "anomalies" else "Root Cause",
                "blueprint": bp,
            },
        )
        done = (
            "Added a section highlighting unusual changes."
            if edit.section_type == "anomalies"
            else "Added a root-cause section."
        )
    r = await api.report(report_id)
    o.blocks.append(_report_block(r, done))
    o.facts.append(done)
    o.headline = done
    o.suggestions += ["Generate a PPT", "Publish the report"]
    o.context = {"report_id": report_id}
    return o


async def alert(api: AixbiApi, index: CatalogIndex, plan: Plan) -> Outcome:
    o = Outcome()
    if not plan.metrics or not plan.alert:
        o.facts.append("I need a metric and a threshold, for example “Alert me when SLA falls below 90%”.")
        return o
    info = index.require_metric(plan.metrics[0])
    a = plan.alert
    op_word = {
        "lt": "falls below",
        "lte": "is at or below",
        "gt": "rises above",
        "gte": "is at or above",
        "change_pct_gt": "increases by more than",
        "change_pct_lt": "decreases by more than",
    }[a.operator]
    shown = f"{a.threshold:g}%" if a.operator.startswith("change_pct") else formatting.value(a.threshold, info.format)
    rule = await api.create_alert(
        {
            "name": f"{info.label} {op_word} {shown}",
            "model": info.model,
            "metric_key": info.key,
            "operator": a.operator,
            "threshold": a.threshold,
            "window": a.window,
            "frequency_minutes": a.frequency_minutes,
            "channels": ["in_app", "push", "email"],
            "created_via": "conversation",
        }
    )
    o.blocks.append(
        {
            "type": "alert",
            "title": "Alert created",
            "rule": {
                "id": rule["id"],
                "name": rule["name"],
                "metric": info.label,
                "condition": f"{op_word} {shown}",
                "window": a.window.replace("_", " "),
                "frequency": f"every {a.frequency_minutes} minutes",
                "channels": ["App", "Push", "Email"],
            },
        }
    )
    o.facts.append(
        f"Created alert “{rule['name']}”: checks {info.label} over the {a.window.replace('_', ' ')} "
        f"every {a.frequency_minutes} minutes and notifies you in the app, by push and by email."
    )
    o.headline = rule["name"]
    return o


async def dashboard(api: AixbiApi, index: CatalogIndex, plan: Plan, question: str) -> Outcome:
    o = Outcome()
    kpis = [
        m
        for m in [
            "applications.total_applications",
            "decisions.approval_rate",
            "decisions.sla_compliance",
            "revenue.revenue",
        ]
        if m in index.metrics
    ]
    w = []
    for i, ref in enumerate(kpis):
        m = index.require_metric(ref)
        w.append(
            {
                "type": "kpi",
                "title": m.label,
                "section": "pulse",
                "query": {"model": m.model, "metrics": [m.key], "time": {"range": "last_30_days"}},
                "viz": {"compare": "previous_period"},
                "position": {"x": i * 3, "y": 0, "w": 3, "h": 2},
                "priority": 10 + i,
            }
        )
    spec: list[tuple[str, str, JSON, JSON, tuple[int, int, int, int]]] = [
        (
            "chart",
            "Application trend",
            {
                "model": "applications",
                "metrics": ["total_applications"],
                "time": {"grain": "month", "range": "last_24_months"},
            },
            {"type": "area"},
            (0, 2, 8, 4),
        ),
        ("insight", "AI insights", {}, {"source": "insights"}, (8, 2, 4, 4)),
        (
            "globe",
            "Country distribution",
            {
                "model": "applications",
                "metrics": ["total_applications"],
                "dimensions": ["country", "country_code"],
                "time": {"range": "last_12_months"},
            },
            {"type": "globe", "fallback": "bar"},
            (0, 6, 6, 5),
        ),
        (
            "chart",
            "Institution ranking",
            {
                "model": "applications",
                "metrics": ["total_applications"],
                "dimensions": ["institution"],
                "time": {"range": "last_12_months"},
                "sort": [{"key": "total_applications", "dir": "desc"}],
                "limit": 10,
            },
            {"type": "bar", "orientation": "horizontal"},
            (6, 6, 6, 5),
        ),
        (
            "chart",
            "Course distribution",
            {
                "model": "applications",
                "metrics": ["total_applications"],
                "dimensions": ["field_of_study"],
                "time": {"range": "last_12_months"},
                "sort": [{"key": "total_applications", "dir": "desc"}],
            },
            {"type": "bar"},
            (0, 11, 4, 5),
        ),
        (
            "chart",
            "Approval rate",
            {"model": "decisions", "metrics": ["approval_rate"], "time": {"grain": "week", "range": "last_6_months"}},
            {"type": "line"},
            (4, 11, 4, 5),
        ),
        (
            "chart",
            "Processing SLA",
            {"model": "decisions", "metrics": ["sla_compliance"], "time": {"grain": "week", "range": "last_6_months"}},
            {"type": "line", "target": 0.9},
            (8, 11, 4, 5),
        ),
        ("anomalies", "Anomalies", {"model": "decisions", "metrics": ["rejection_rate"]}, {}, (0, 16, 6, 4)),
        (
            "forecast",
            "Applications forecast",
            {"model": "applications", "metrics": ["total_applications"]},
            {"horizon": "6m"},
            (6, 16, 6, 4),
        ),
    ]
    for i, (t, title, q, v, (x, y, wd, h)) in enumerate(spec):
        if q.get("model") and q["model"] not in index.models:
            continue
        w.append(
            {
                "type": t,
                "title": title,
                "section": "detail",
                "query": q,
                "viz": v,
                "position": {"x": x, "y": y, "w": wd, "h": h},
                "priority": 30 + i * 10,
            }
        )
    title = "Student Application Performance" if "application" in question.lower() else "Performance Overview"
    d = await api.create_dashboard(
        {
            "title": title,
            "description": f"Generated by the AI analyst from: “{question}”",
            "visibility": "private",
            "sections": [{"key": "pulse", "label": "Pulse"}, {"key": "detail", "label": "Detail"}],
            "widgets": w,
        }
    )
    o.blocks.append(
        {
            "type": "dashboard",
            "title": d["title"],
            "dashboard_id": d["id"],
            "widgets": [{"type": x["type"], "title": x["title"]} for x in w],
            "actions": [{"label": "Open dashboard", "link": f"/dashboards/{d['id']}"}],
        }
    )
    o.facts.append(
        f"Created dashboard “{d['title']}” with {len(w)} widgets: KPI cards, trend, geography, "
        "institution ranking, course mix, approval rate, SLA, anomalies, forecast and AI insights."
    )
    o.headline = d["title"]
    o.suggestions += ["Add a revenue by market chart", "Share it with the executive team"]
    return o


def help_outcome() -> Outcome:
    o = Outcome()
    o.facts.append(
        "I can answer questions about your governed metrics, explain changes, find anomalies, forecast, "
        "simulate scenarios, and create reports, dashboards and alerts."
    )
    o.blocks.append(
        {
            "type": "capabilities",
            "items": [
                {"title": "Ask", "example": "Show me this month's performance"},
                {"title": "Explain", "example": "Why did SLA fall?"},
                {"title": "Compare", "example": "Compare China and India applications"},
                {"title": "Predict", "example": "Forecast revenue for 12 months"},
                {"title": "Simulate", "example": "What happens if applications increase by 20%?"},
                {"title": "Report", "example": "Create a monthly CEO report"},
                {"title": "Watch", "example": "Alert me when SLA falls below 90%"},
                {"title": "Build", "example": "Create a dashboard for student application performance"},
            ],
        }
    )
    o.suggestions = ["Show me this month's performance", "Why did SLA fall?", "Create a monthly CEO report"]
    return o
