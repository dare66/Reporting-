"""“Create an incident for the biggest issue”: the agent finds the issue in governed
KPIs, gathers evidence, and only *proposes* the incident — it never approves it."""

import json

import httpx
import pytest

from app.agents import graph
from app.agents.deterministic import plan
from app.api_client import AixbiApi
from app.config import settings

from .conftest import CATALOG

PERIOD = {"label": "Last 30 days", "from": "2026-09-05", "to": "2026-10-04"}


def card(ref: str, label: str, value: float, change: float, sentiment: str, target_status: str = "none") -> dict:
    return {
        "ref": ref,
        "model": ref.split(".")[0],
        "metric": ref.split(".")[1],
        "label": label,
        "value": value,
        "format": "percent",
        "change": change,
        "change_pct": change / value,
        "sentiment": sentiment,
        "target": 0.9 if target_status != "none" else None,
        "target_status": target_status,
        "period": PERIOD,
        "sparkline": [1, 2],
        "evidence": {"current": {"query_hash": "h-" + ref, "sql": "SELECT …"}},
    }


def make_api(cards: list[dict], duplicate: bool = False) -> tuple[AixbiApi, list[tuple[str, str, dict]]]:
    calls: list[tuple[str, str, dict]] = []

    def handler(request: httpx.Request) -> httpx.Response:
        body = json.loads(request.content or b"{}")
        path = request.url.path.removeprefix("/api/v1")
        calls.append((request.method, path, body))
        if path == "/semantic-catalog":
            return httpx.Response(200, json={"data": CATALOG})
        if path == "/kpis":
            return httpx.Response(200, json={"data": cards})
        if path == "/analysis/root-cause":
            drivers = [{"dimension_label": "Institution", "member": "Meridian University", "impact_share": 0.62}]
            return httpx.Response(200, json={"data": {"drivers": drivers}})
        if path == "/actions" and duplicate:
            return httpx.Response(409, json={"message": "INC-0003 is already open for this metric: “SLA”."})
        if path == "/actions":
            return httpx.Response(
                201,
                json={"data": {"id": "a-1", "title": body["title"], "summary": body["summary"], "status": "proposed"}},
            )
        return httpx.Response(404, json={"error": {"message": "unexpected " + path}})

    return AixbiApi("token", transport=httpx.MockTransport(handler)), calls


def test_planner_routes_incident_requests(index):
    p = plan("Create an incident for the biggest issue", index, {"metrics": ["revenue.revenue"]})
    assert p.intent == "incident" and p.metrics == []
    assert plan("Open a ticket for the SLA drop", index).metrics == ["decisions.sla_compliance"]


@pytest.mark.asyncio
async def test_biggest_issue_is_proposed_not_opened(monkeypatch):
    monkeypatch.setattr(settings(), "anthropic_api_key", None)
    api, calls = make_api(
        [
            card("decisions.approval_rate", "Approval rate", 0.71, -0.01, "negative"),
            card("decisions.sla_compliance", "Processing SLA", 0.84, -0.07, "negative", "missed"),
            card("revenue.revenue", "Revenue", 0.5, 0.02, "positive"),
        ]
    )
    result = await graph.run("Create an incident for the biggest issue", graph.Deps(api=api, organisation_id="org"))
    assert result["intent"] == "incident"
    proposal = next(b for m, p, b in calls if m == "POST" and p == "/actions")
    assert proposal["kind"] == "incident" and proposal["source"] == "ai"
    assert proposal["payload"] == {
        "severity": "high",
        "metric_ref": "decisions.sla_compliance",
    }  # missed target, 7 points
    assert "Meridian University (62% of the change)" in proposal["summary"]
    assert "sparkline" not in proposal["evidence"]["card"] and "evidence" not in proposal["evidence"]["card"]
    assert len(result["evidence"]) == 3, "every KPI checked is cited"
    block = next(b for b in result["blocks"] if b["type"] == "action")
    assert block["action"]["status"] == "proposed" and "Nothing has been opened yet" in block["note"]
    # The agent proposes; it never approves or runs anything.
    assert not [p for _, p, _ in calls if p.endswith(("/approve", "/retry")) or p.startswith("/incidents")]
    assert any(t["agent"] == "action" for t in result["trace"])


@pytest.mark.asyncio
async def test_nothing_is_proposed_when_every_kpi_is_healthy(monkeypatch):
    monkeypatch.setattr(settings(), "anthropic_api_key", None)
    api, calls = make_api([card("revenue.revenue", "Revenue", 0.5, 0.02, "positive")])
    result = await graph.run("Create an incident for the biggest issue", graph.Deps(api=api, organisation_id="org"))
    assert not [p for m, p, _ in calls if m == "POST" and p == "/actions"]
    assert "no issue that needs an incident" in result["answer"]


@pytest.mark.asyncio
async def test_an_issue_already_handled_is_not_proposed_again(monkeypatch):
    monkeypatch.setattr(settings(), "anthropic_api_key", None)
    api, _ = make_api(
        [card("decisions.sla_compliance", "Processing SLA", 0.84, -0.07, "negative", "missed")], duplicate=True
    )
    result = await graph.run("Create an incident for the biggest issue", graph.Deps(api=api, organisation_id="org"))
    assert "INC-0003 is already open" in result["answer"]
    assert not [b for b in result["blocks"] if b["type"] == "action"]
