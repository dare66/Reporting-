"""Intent handlers: plan → governed API calls → structured blocks + facts.

Blocks are what the UI renders (KPI cards, charts, driver trees, tables…).
Facts are short sentences containing every number the narrative may use.
Evidence references the exact queries behind each fact."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from ..analytics.anomalies import detect
from ..api_client import AixbiApi
from . import formatting as F
from . import viz
from .catalog import CatalogIndex
from .plan import Plan


@dataclass
class Outcome:
    blocks: list[dict] = field(default_factory=list)
    facts: list[str] = field(default_factory=list)
    evidence: list[dict] = field(default_factory=list)
    suggestions: list[str] = field(default_factory=list)
    caveats: list[str] = field(default_factory=list)
    headline: str = ""
    summary: str = ""  # deterministic executive prose; facts remain the LLM's only source of numbers
    context: dict = field(default_factory=dict)


def _ev(meta: dict, label: str) -> dict:
    return {"label": label, "query_hash": meta.get("query_hash"), "sql": meta.get("sql"), "query": meta.get("query"),
            "executed_at": meta.get("executed_at"), "row_count": meta.get("row_count"), "cached": meta.get("cached")}


def _range_label(r: Any) -> str:
    if isinstance(r, dict):
        return f"{r['from']} to {r['to']}"
    return r.replace("_", " ").capitalize()


def _filters_for(index: CatalogIndex, model: str, plan: Plan) -> list[dict]:
    dims = index.dimensions.get(model, {})
    return [f.model_dump() for f in plan.filters if f.dimension in dims]


def _grain_for(range_: Any) -> str:
    if isinstance(range_, dict):
        return "day"
    return {"last_7_days": "day", "last_30_days": "day", "this_month": "day", "last_month": "day", "this_week": "day", "last_week": "day",
            "last_90_days": "week", "this_quarter": "week", "last_quarter": "week", "last_6_months": "week"}.get(range_, "month")


async def overview(api: AixbiApi, index: CatalogIndex, plan: Plan) -> Outcome:
    o = Outcome()
    cards = await api.kpis(plan.metrics, plan.range)
    o.blocks.append({"type": "kpis", "title": "Performance", "period": cards[0]["period"] if cards else None, "cards": cards})
    for c in cards:
        o.evidence.append(_ev(c["evidence"]["current"], c["label"]))
        o.facts.append(f"{c['label']} was {F.value(c['value'], c['format'])} over {F.period(c['period']['label'])} "
                       f"({F.change(c['change'], c['change_pct'], c['format'])} vs previous period; {c['sentiment']}).")
        if c.get("target") is not None:
            o.facts.append(f"{c['label']} target {F.value(c['target'], c['format'])}: {c['target_status']}.")
    improving = [c for c in cards if c["sentiment"] != "negative"]
    o.facts.insert(0, f"{len(improving)} of {len(cards)} headline KPIs are holding or improving.")
    o.headline = f"{len(improving)} of {len(cards)} KPIs holding or improving"
    state = "healthy" if len(improving) == len(cards) else "stable" if len(improving) * 2 >= len(cards) else "under pressure"
    fmt_card = lambda c: f"{c['label']} {F.value(c['value'], c['format'])} ({F.change(c['change'], c['change_pct'], c['format'])})"  # noqa: E731
    down = [c for c in cards if c["sentiment"] == "negative"]
    up = [c for c in cards if c["sentiment"] == "positive"]
    parts = [f"Overall performance is {state} for {F.period(cards[0]['period']['label']) if cards else 'the period'}, with {len(improving)} of {len(cards)} headline KPIs holding or improving."]
    if up:
        parts.append("Improving: " + ", ".join(fmt_card(c) for c in up[:3]) + ".")
    if down:
        parts.append("Under pressure: " + ", ".join(fmt_card(c) for c in down[:3]) + ".")
    missed = [c for c in cards if c["target_status"] == "missed"]
    if missed:
        parts.append(" ".join(f"{c['label']} is below its {F.value(c['target'], c['format'])} target." for c in missed))
    o.summary = " ".join(parts)
    if cards and cards[0]["period"]["from"] and cards[0]["period"]["to"]:
        from datetime import date as _d
        days = (_d.fromisoformat(cards[0]["period"]["to"]) - _d.fromisoformat(cards[0]["period"]["from"])).days + 1
        if days < 7:
            o.caveats.append(f"This period has only {days} day(s) of data; changes compare with the same days of the previous period and can be volatile.")

    # Trend for the first KPI, complete periods only.
    if cards:
        lead = cards[0]
        trend = await api.query({"model": lead["model"], "metrics": [lead["metric"]], "time": {"grain": "month", "range": "last_12_months"}})
        o.blocks.append(_series_block(trend, lead["metric"], lead["label"], lead["format"], "Trend · last 12 months"))
        o.evidence.append(_ev(trend["meta"], f"{lead['label']} trend"))

    def severity(c: dict) -> tuple[bool, float]:
        magnitude = abs(c["change"] or 0) * 10 if c["format"] == "percent" else abs(c["change_pct"] or 0)
        return (c["target_status"] != "missed", -magnitude)

    worst = sorted([c for c in cards if c["sentiment"] == "negative"], key=severity)
    if worst:
        rc = await api.root_cause(worst[0]["ref"], plan.range)
        _drivers(o, rc, index)
        o.suggestions.append(f"Why did {worst[0]['label']} change?")
    o.suggestions += ["Show me the affected institutions", "Create a management report", "Forecast applications for the next 6 months"]
    o.context = {"metrics": [worst[0]["ref"]] if worst else plan.metrics[:1]}
    return o


def _series_block(result: dict, key: str, label: str, fmt: str, title: str, target: float | None = None) -> dict:
    partial_from = result["meta"].get("partial_from")
    points = [{"period": r["period"], "value": r[key], "partial": bool(partial_from and r["period"] >= partial_from)} for r in result["rows"]]
    return {"type": "chart", "title": title, "viz": viz.choose("trend", has_time=True, dimension=None, metric_format=fmt),
            "series": [{"key": key, "label": label, "format": fmt, "points": points}], "target": target, "query_hash": result["meta"].get("query_hash")}


def _drivers(o: Outcome, rc: dict, index: CatalogIndex) -> None:
    fmt = rc["format"]
    o.blocks.append({"type": "drivers", "title": f"What moved {rc['label']}", "metric": rc["ref"], "label": rc["label"], "format": fmt,
                     "current": rc["current"], "previous": rc["previous"], "change": rc["change"], "change_pct": rc["change_pct"],
                     "sentiment": rc["sentiment"], "drivers": rc["drivers"][:5], "dimensions": rc["dimensions"][:4],
                     "onset": {k: v for k, v in (rc.get("onset") or {}).items() if k != "series"} or None, "method": rc["method"]})
    if rc.get("onset") and rc["onset"].get("series"):
        pts = rc["onset"]["series"]
        o.blocks.append({"type": "chart", "title": f"{rc['label']} — daily", "viz": {"type": "line", "reason": "Shows when the shift began."},
                         "series": [{"key": rc["metric"], "label": rc["label"], "format": fmt, "points": [{"period": p["date"], "value": p["value"]} for p in pts]}],
                         "markers": [{"period": rc["onset"]["date"], "label": "Shift began"}] if rc["onset"].get("significant") else []})
    o.facts.append(f"{rc['label']} moved from {F.value(rc['previous']['value'], fmt)} to {F.value(rc['current']['value'], fmt)} "
                   f"({F.change(rc['change'], rc['change_pct'], fmt)}).")
    for d in rc["drivers"][:4]:
        o.facts.append(f"Driver: {d['dimension_label']} {d['member']} went from {F.value(d['previous_value'], fmt)} to {F.value(d['current_value'], fmt)}, "
                       f"accounting for {round((d['impact_share'] or 0) * 100)}% of the change.")
    if rc.get("onset") and rc["onset"].get("significant"):
        o.facts.append(f"The shift began around {rc['onset']['date']}.")
    lead = rc["drivers"][:3]
    text = f"{rc['label']} moved {F.change(rc['change'], rc['change_pct'], fmt)} to {F.value(rc['current']['value'], fmt)}."
    if lead:
        text += " The main driver is " + f"{lead[0]['dimension_label'].lower()} {lead[0]['member']} ({F.value(lead[0]['previous_value'], fmt)} → {F.value(lead[0]['current_value'], fmt)}, {round((lead[0]['impact_share'] or 0) * 100)}% of the change)"
        if len(lead) > 1:
            text += ", followed by " + " and ".join(f"{d['dimension_label'].lower()} {d['member']} ({round((d['impact_share'] or 0) * 100)}%)" for d in lead[1:])
        text += "."
    if rc.get("onset") and rc["onset"].get("significant"):
        text += f" The shift began around {rc['onset']['date']}."
    o.summary = (o.summary + " " + text).strip() if o.summary else text
    for e in rc.get("evidence", [])[:3]:
        o.evidence.append(_ev(e, f"{rc['label']} decomposition"))


async def why(api: AixbiApi, index: CatalogIndex, plan: Plan) -> Outcome:
    o = Outcome()
    metric = plan.metrics[0]
    rc = await api.root_cause(metric, plan.range, [f.model_dump() for f in plan.filters], [plan.dimension] if plan.dimension else None)
    _drivers(o, rc, index)
    o.headline = f"{rc['label']} {F.change(rc['change'], rc['change_pct'], rc['format'])}"
    top = rc["drivers"][0] if rc["drivers"] else None
    if not top:
        o.caveats.append("No single segment stands out; the change is broad-based.")
    else:
        dim_plural = index.dimension_label(top["dimension"]).lower()
        o.suggestions.append(f"Show me the affected {'institutions' if top['dimension'] != 'institution' else 'courses'}")
        o.suggestions.append(f"Show {rc['label']} by {dim_plural} over time")
    o.suggestions += ["Create a management report", f"Alert me when {rc['label']} moves further"]
    o.context = {"metrics": [metric], "range": plan.range, "drivers": [{"dimension": d["dimension"], "member": d["member"]} for d in rc["drivers"][:3]]}
    return o


async def breakdown(api: AixbiApi, index: CatalogIndex, plan: Plan) -> Outcome:
    o = Outcome()
    ref = plan.metrics[0]
    info = index.metric(ref)
    dim = plan.dimension or "country"
    filters = _filters_for(index, info.model, plan)
    compare = any(f["dimension"] == dim and isinstance(f["value"], list) and len(f["value"]) > 1 for f in filters)
    metrics = [m for m in plan.metrics if index.metric(m) and index.metric(m).model == info.model][:3]
    extra_dims = ["country_code"] if dim == "country" and "country_code" in index.dimensions.get(info.model, {}) else []
    keys = [index.metric(m).key for m in metrics]
    res = await api.query({"model": info.model, "metrics": keys, "dimensions": [dim, *extra_dims], "filters": filters,
                           "time": {"range": plan.range}, "sort": [{"key": info.key, "dir": plan.sort}], "limit": plan.limit if not compare else 50})
    # Rows are keyed by metric key; expose them under refs too so blocks are self-describing.
    rows = [{**r, **{m: r.get(index.metric(m).key) for m in metrics}} for r in res["rows"]]
    o.evidence.append(_ev(res["meta"], f"{info.label} by {index.dimension_label(dim)}"))
    choice = viz.choose("breakdown", has_time=False, dimension=dim, members=len(rows), metric_format=info.format, compare=compare, as_table=plan.as_table)
    block = {"type": "chart" if choice["type"] != "table" else "table", "title": f"{info.label} by {index.dimension_label(dim)}", "viz": choice,
             "dimension": {"key": dim, "label": index.dimension_label(dim)}, "columns": res["columns"], "rows": rows,
             "series": [{"key": index.metric(m).key, "label": index.metric(m).label, "format": index.metric(m).format} for m in metrics]}
    o.blocks.append(block)
    if choice["type"] == "map":
        o.blocks.append({**block, "type": "table", "viz": {"type": "table", "reason": "Exact values."}})

    total = sum((r[ref] or 0) for r in rows) if info.format in ("number", "currency") else None
    o.headline = f"{info.label} by {index.dimension_label(dim).lower()}"
    for r in rows[:5]:
        share = f", {round(r[ref] / total * 100)}% of the listed total" if total else ""
        o.facts.append(f"{index.dimension_label(dim)} {r[dim]}: {info.label} {F.value(r[ref], info.format)}{share} ({_range_label(plan.range)}).")
    if rows:
        lead = ", ".join(f"{r[dim]} {F.value(r[ref], info.format)}" for r in rows[:3])
        o.summary = f"{info.label} by {index.dimension_label(dim).lower()} for {F.period(_range_label(plan.range))}: {'lowest' if plan.sort == 'asc' else 'highest'} are {lead}."
        if total and rows[0][ref]:
            o.summary += f" {rows[0][dim]} accounts for {round(rows[0][ref] / total * 100)}% of the listed total."
    if res["meta"].get("truncated"):
        o.caveats.append("Only the top results are shown.")
    if not rows:
        o.caveats.append("No data matched — your data access may be limited to certain segments, or the filters exclude everything.")
    o.suggestions += [f"Why did {info.label} change?", f"Show {info.label} trend for {rows[0][dim]}" if rows else "Show the trend", "Add this to a report"]
    o.context = {"metrics": [ref], "dimension": dim, "range": plan.range}
    return o


async def trend(api: AixbiApi, index: CatalogIndex, plan: Plan) -> Outcome:
    o = Outcome()
    ref = plan.metrics[0]
    info = index.metric(ref)
    grain = plan.grain or _grain_for(plan.range)
    filters = _filters_for(index, info.model, plan)
    res = await api.query({"model": info.model, "metrics": [ref.split(".")[1]], "filters": filters, "time": {"grain": grain, "range": plan.range}, "limit": 1000})
    key = ref.split(".")[1]
    block = _series_block(res, key, info.label, info.format, f"{info.label} · {grain}ly" if grain != "day" else f"{info.label} · daily", info.target)
    complete = [p for p in block["series"][0]["points"] if not p["partial"] and p["value"] is not None]
    if grain == "day" and len(complete) >= 35:
        flagged = detect([{"period": p["period"], "value": p["value"]} for p in complete])["anomalies"]
        block["markers"] = [{"period": a["period"], "label": f"{a['score']:+.1f}σ", "kind": "anomaly"} for a in flagged]
        for a in flagged[-3:]:
            o.facts.append(f"Anomaly on {a['period']}: {F.value(a['actual'], info.format)} vs expected {F.value(a['expected'], info.format)} ({abs(a['score']):.1f}σ).")
    o.blocks.append(block)
    o.evidence.append(_ev(res["meta"], f"{info.label} trend"))
    if len(complete) >= 2:
        first, last = complete[0], complete[-1]
        peak = max(complete, key=lambda p: p["value"])
        o.facts.append(f"{info.label} moved from {F.value(first['value'], info.format)} ({first['period']}) to {F.value(last['value'], info.format)} ({last['period']}), "
                       f"peaking at {F.value(peak['value'], info.format)} ({peak['period']}).")
        o.headline = f"{info.label}: {F.value(last['value'], info.format)} latest complete {grain}"
        o.summary = (f"{info.label} was {F.value(last['value'], info.format)} in the latest complete {grain} ({last['period']}), "
                     f"versus {F.value(first['value'], info.format)} at the start of the period; the peak was {F.value(peak['value'], info.format)} ({peak['period']}).")
    if any(p["partial"] for p in block["series"][0]["points"]):
        o.caveats.append(f"The current {grain} is still in progress and is shown as partial.")
    o.suggestions += [f"Why did {info.label} change?", f"Forecast {info.label}", f"Show {info.label} by institution"]
    o.context = {"metrics": [ref], "range": plan.range}
    return o


async def forecast(api: AixbiApi, index: CatalogIndex, plan: Plan) -> Outcome:
    o = Outcome()
    ref = plan.metrics[0]
    f = await api.forecast(ref, plan.horizon)
    fmt = f["diagnostics"]["format"]
    o.blocks.append({"type": "forecast", "title": f"{f['diagnostics']['label']} forecast", "metric": ref, "format": fmt, "grain": f["grain"],
                     "history": f["history"], "points": f["points"], "method": f["method"], "diagnostics": f["diagnostics"]})
    o.evidence.append(_ev(f["diagnostics"]["evidence"], "Forecast training data"))
    last = f["points"][-1]
    o.facts.append(f"{f['diagnostics']['label']} is projected at {F.value(last['value'], fmt)} for {last['period'][:7]} "
                   f"(80% interval {F.value(last['lower'], fmt)} to {F.value(last['upper'], fmt)}), method {f['method'].replace('_', ' ')}.")
    if (mape := f["diagnostics"].get("backtest_mape")) is not None:
        o.facts.append(f"Backtest error (MAPE) on recent periods: {mape * 100:.1f}%.")
        if mape > 0.15:
            o.caveats.append("Backtest error is high; treat the projection as directional.")
    o.headline = f"{f['diagnostics']['label']}: {F.value(last['value'], fmt)} by {last['period'][:7]}"
    o.suggestions += ["What happens if applications increase by 20%?", "Add a 12-month forecast to the report"]
    o.context = {"metrics": [ref]}
    return o


async def what_if(api: AixbiApi, index: CatalogIndex, plan: Plan) -> Outcome:
    o = Outcome()
    s = await api.scenario((plan.scenario.model_dump() if plan.scenario else {}))
    b, p = s["baseline"], s["projected"]
    o.blocks.append({"type": "scenario", "title": "What-if simulation", **s})
    o.facts += [
        f"Baseline (last 30 days): {F.value(b['applications'], 'number')} applications, capacity {F.value(b['capacity'], 'number')}, SLA {F.value(b['sla_compliance'], 'percent')}, revenue {F.value(b['revenue'], 'currency')}.",
        f"Scenario: {F.value(p['applications'], 'number')} applications, utilisation {F.value(p['utilisation'], 'percent')}, projected SLA {F.value(p['sla_compliance'], 'percent')}, revenue {F.value(p['revenue'], 'currency')}.",
    ]
    if p.get("additional_officers_needed") is not None:
        o.facts.append(f"About {p['additional_officers_needed']} additional officers would be needed to restore 90.0% SLA.")
    m = s["model"]
    if m.get("r_squared") is not None:
        o.facts.append(f"SLA sensitivity fitted on {m['observations']} weeks (R² {m['r_squared']:.2f}).")
    o.caveats += s.get("caveats", [])
    o.headline = f"Projected SLA {F.value(p['sla_compliance'], 'percent')}"
    o.suggestions += ["What if we also add 10% more officers?", "Forecast applications for the next 6 months"]
    return o


async def anomalies(api: AixbiApi, index: CatalogIndex, plan: Plan) -> Outcome:
    o = Outcome()
    items = await api.anomalies()
    if plan.metrics:
        items = [a for a in items if a["metric_key"] in plan.metrics] or items
    items = items[:8]
    o.blocks.append({"type": "anomalies", "title": "Open anomalies", "items": items})
    for a in items[:4]:
        ev = a["evidence"]
        o.facts.append(f"{ev['label']} on {a['period'][:10]}: {F.value(a['actual'], ev['format'])} vs expected {F.value(a['expected'], ev['format'])} ({abs(a['score']):.1f}σ {ev['direction']} normal).")
    if not items:
        o.facts.append("No open anomalies were found.")
    o.headline = f"{len(items)} open anomalies"
    if items:
        o.suggestions.append(f"Why did {items[0]['evidence']['label']} change?")
        o.context = {"metrics": [items[0]["metric_key"]]}
    return o
