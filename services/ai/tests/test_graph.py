"""End-to-end graph run against a mocked API: verifies agents, evidence and
that the AI only ever calls governed endpoints."""

import json

import httpx
import jwt
import pytest
from fastapi.testclient import TestClient

from app.agents import graph
from app.api_client import AixbiApi
from app.config import settings
from app.main import app

from .conftest import CATALOG

CALLS: list[tuple[str, str]] = []


def handler(request: httpx.Request) -> httpx.Response:
    CALLS.append((request.method, request.url.path))
    path = request.url.path.removeprefix("/api/v1")
    body = json.loads(request.content or b"{}")
    meta = {"query_hash": "h-" + str(len(CALLS)), "sql": "SELECT …", "executed_at": "2026-10-02T00:00:00Z", "row_count": 3, "partial_from": None}
    if path == "/semantic-catalog":
        return httpx.Response(200, json={"data": CATALOG})
    if path == "/query":
        dims = body.get("dimensions", [])
        key = body["metrics"][0]
        if dims and dims[0] == "institution":
            rows = [{"institution": "Meridian University", key: 0.717}, {"institution": "Klang Valley Institute", key: 0.778}]
        elif dims:
            rows = [{dims[0]: "China", key: 3447}, {dims[0]: "India", key: 2064}]
        else:
            rows = [{"period": f"2026-0{m}-01", key: 0.9 - m / 100} for m in range(1, 10)]
        return httpx.Response(200, json={"columns": [], "rows": rows, "meta": meta})
    if path == "/analysis/root-cause":
        return httpx.Response(200, json={"data": {
            "ref": body["metric"], "metric": "sla_compliance", "label": "Processing SLA", "format": "percent", "higher_is_better": True,
            "current": {"value": 0.859, "period": {}}, "previous": {"value": 0.909, "period": {}}, "change": -0.05, "change_pct": -0.055,
            "direction": "down", "sentiment": "negative", "method": "leave_one_out_counterfactual",
            "drivers": [{"dimension": "institution", "dimension_label": "Institution", "member": "Meridian University", "current_value": 0.717,
                         "previous_value": 0.93, "impact": -0.014, "impact_share": 0.28, "volume_share": 0.1, "excess_impact": -0.01}],
            "dimensions": [], "onset": {"date": "2026-08-19", "significant": True, "series": [{"date": "2026-08-01", "value": 0.9}]},
            "evidence": [meta]}})
    return httpx.Response(404, json={"error": {"message": "not mocked"}})


@pytest.fixture
def api():
    CALLS.clear()
    return AixbiApi("token", transport=httpx.MockTransport(handler))


@pytest.mark.asyncio
async def test_why_question_runs_every_agent_and_cites_evidence(api, monkeypatch):
    monkeypatch.setattr(settings(), "anthropic_api_key", None)
    events = []

    async def emit(e, d):
        events.append(e)

    result = await graph.run("Why did SLA fall?", graph.Deps(api=api, organisation_id="org", emit=emit))
    agents = [t["agent"] for t in result["trace"]]
    assert agents[:4] == ["semantic", "intent", "planner", "governance"]
    assert {"sql", "root_cause", "validation", "visualization", "narrative"} <= set(agents)
    assert result["intent"] == "why" and result["planner"] == "deterministic"
    assert "Meridian University" in result["answer"] and "85.9%" in result["answer"]
    assert result["evidence"] and all(e["query_hash"] for e in result["evidence"])
    assert events[0] == "step" and "answer" in events and "block" in events
    # The AI only reaches governed API endpoints — never a database.
    assert {p for _, p in CALLS} <= {"/api/v1/semantic-catalog", "/api/v1/query", "/api/v1/analysis/root-cause"}


@pytest.mark.asyncio
async def test_follow_up_breakdown_uses_metric_keys(api, monkeypatch):
    monkeypatch.setattr(settings(), "anthropic_api_key", None)
    result = await graph.run("Show me the affected institutions", graph.Deps(api=api, organisation_id="org"), {"metrics": ["decisions.sla_compliance"]})
    block = result["blocks"][0]
    assert block["type"] == "table" and block["rows"][0]["institution"] == "Meridian University"
    assert result["context"]["dimension"] == "institution"


@pytest.mark.asyncio
async def test_unmatched_question_is_refused_not_guessed(api, monkeypatch):
    monkeypatch.setattr(settings(), "anthropic_api_key", None)
    result = await graph.run("Why did the weather change?", graph.Deps(api=api, organisation_id="org"))
    assert result["status"] == "refused" and "governed metric" in result["answer"]
    assert not any(p.endswith("/query") for _, p in CALLS)


def token(**claims):
    s = settings()
    return jwt.encode({"sub": "u", "org": "o", "typ": "access", "iss": s.jwt_issuer, "exp": 9999999999, **claims}, s.jwt_secret, algorithm="HS256")


def test_endpoints_require_authentication():
    c = TestClient(app)
    assert c.post("/v1/chat", json={"question": "hi"}).status_code == 401
    assert c.post("/v1/chat", json={"question": "hi"}, headers={"Authorization": "Bearer " + token(typ="mfa")}).status_code == 401
    assert c.post("/v1/analytics/forecast", json={"series": [], "horizon": 3}).status_code == 401
    ok = c.post("/v1/analytics/anomalies", json={"series": [{"period": "2026-01-01", "value": 1}]}, headers={"Authorization": f"Bearer {settings().internal_token}"})
    assert ok.status_code == 200
    assert c.get("/health").json()["status"] == "ok"
