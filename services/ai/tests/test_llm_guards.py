import pytest

from app.agents.llm import LlmFilter, LlmPlan, LlmUnavailableError, grounded, to_plan


def base(**kw):
    d = {
        "intent": "breakdown",
        "metrics": ["decisions.sla_compliance"],
        "dimension": "institution",
        "filters": [],
        "range_preset": "last_30_days",
        "range_from": None,
        "range_to": None,
        "grain": None,
        "sort": "asc",
        "limit": 10,
        "horizon": "6m",
        "demand_change_pct": None,
        "officer_change_pct": None,
        "productivity_change_pct": None,
        "alert_operator": None,
        "alert_threshold": None,
        "report_template": None,
        "report_edit_action": None,
        "report_edit_section": None,
        "as_table": False,
        "notes": [],
    }
    d.update(kw)
    return LlmPlan(**d)


def test_llm_plan_is_validated_against_catalog(index):
    p = to_plan(
        base(filters=[LlmFilter(dimension="country", values=["China"]), LlmFilter(dimension="salary", values=["x"])]),
        index,
    )
    assert p.metrics == ["decisions.sla_compliance"]
    assert [f.dimension for f in p.filters] == ["country"]


def test_llm_plan_with_invented_metric_is_rejected(index):
    with pytest.raises(LlmUnavailableError):
        to_plan(base(metrics=["finance.ebitda"]), index)


def test_unknown_dimension_dropped(index):
    assert to_plan(base(dimension="password"), index).dimension is None


def test_grounding_accepts_restatement_and_rejects_invented_numbers():
    facts = (
        "[F1] Processing SLA was 85.9% (−5.0 pts). "
        "[F2] Driver: China accounts for 42% of the change. "
        "[F3] Revenue RM 23.42M."
    )
    assert grounded("SLA fell 5.0 pts to 85.9%, with China behind 42% of the move; revenue was RM 23.42M.", facts)
    assert not grounded("SLA fell to 84.1%.", facts)
    assert not grounded("Revenue grew to RM 25M.", facts)
    assert grounded("In September 2026 the SLA was 85.9%.", facts)  # years are not claims
