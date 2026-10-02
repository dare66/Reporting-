"""Visualization agent: picks the chart that answers the question. Rules, not taste —
decorative choices are not available."""

from __future__ import annotations

from ..types import JSON

GEO_DIMENSIONS = {"country", "country_code", "region"}
FLOW_DIMENSIONS = {"stage"}


def choose(
    intent: str,
    *,
    has_time: bool,
    dimension: str | None,
    members: int = 0,
    metric_format: str = "number",
    compare: bool = False,
    three_measures: bool = False,
    as_table: bool = False,
) -> JSON:
    if three_measures:
        return {
            "type": "scatter3d",
            "fallback": "scatter",
            "reason": "Three continuous measures: position encodes all three.",
        }
    if as_table and dimension:
        return {"type": "table", "reason": "You asked for the underlying list."}
    if has_time:
        return {
            "type": "line" if metric_format == "percent" else "area",
            "reason": "Change over time reads best as a continuous line.",
        }
    if dimension in FLOW_DIMENSIONS:
        return {"type": "funnel", "reason": "Movement between stages is a funnel."}
    if dimension in GEO_DIMENSIONS and not compare and members > 5:
        return {
            "type": "map",
            "fallback": "bar",
            "reason": "Geographic distribution is shown on a map with a ranked bar alternative.",
        }
    if dimension:
        if metric_format != "percent" and 2 <= members <= 5 and not compare:
            return {"type": "bar", "reason": "Few categories: bars compare magnitudes more accurately than slices."}
        return {
            "type": "bar",
            "orientation": "horizontal",
            "reason": "Ranked categories compare best as sorted horizontal bars.",
        }
    return {"type": "kpi", "reason": "A single value is best shown as a number."}
