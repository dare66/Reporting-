from datetime import date

from app.agents import viz
from app.agents.deterministic import parse_range, plan


def test_overview(index):
    p = plan("Show me this month's performance", index)
    assert p.intent == "overview" and p.range == "this_month"
    assert "decisions.sla_compliance" in p.metrics


def test_why_resolves_metric_by_synonym(index):
    p = plan("Why did SLA fall?", index)
    assert (p.intent, p.metrics) == ("why", ["decisions.sla_compliance"])


def test_follow_up_uses_conversation_context(index):
    p = plan("Show me the affected institutions", index, {"metrics": ["decisions.sla_compliance"]})
    assert p.intent == "breakdown" and p.dimension == "institution"
    assert p.metrics == ["decisions.sla_compliance"] and p.sort == "asc" and p.as_table


def test_comparison_of_members(index):
    p = plan("Compare China and India applications", index)
    assert p.intent == "breakdown" and p.dimension == "country"
    assert p.filters[0].value == ["China", "India"]


def test_dimension_only_question_defaults_to_headline_metric(index):
    p = plan("Show movement between pipeline stages", index)
    assert p.metrics == ["applications.total_applications"] and p.dimension == "stage"


def test_alert_threshold_for_percent_metric(index):
    p = plan("Alert me when SLA falls below 90%", index)
    assert p.intent == "alert" and p.alert.operator == "lt" and p.alert.threshold == 0.9


def test_what_if_and_forecast(index):
    assert plan("What happens if applications increase by 20%?", index).scenario.demand_change_pct == 20
    assert plan("What if we cut staff by 10%", index).scenario.officer_change_pct == -10
    f = plan("Forecast revenue for the next 12 months", index)
    assert (f.intent, f.metrics, f.horizon) == ("forecast", ["revenue.revenue"], "12m")


def test_reports_and_edits(index):
    r = plan("Create a monthly CEO report for student applications", index)
    assert r.intent == "report" and r.report_template == "ceo" and r.range == "last_month"
    e = plan("Add a 12-month forecast", index)
    assert e.intent == "report_edit" and e.report_edit.section_type == "forecast" and e.report_edit.horizon == 12
    assert plan("Make the report more executive", index).report_edit.action == "make_executive"


def test_sensitive_dimensions_are_never_planned(index):
    assert plan("Applications by applicant reference", index).dimension != "applicant_ref"


def test_time_phrases():
    today = date(2026, 10, 2)
    assert parse_range("last 7 days", today)[0] == "last_7_days"
    assert parse_range("in september", today)[0] == {"from": "2026-09-01", "to": "2026-09-30"}
    assert parse_range("in november", today)[0] == {"from": "2025-11-01", "to": "2025-11-30"}
    assert parse_range("ytd", today)[0] == "year_to_date"


def test_visualization_rules():
    assert viz.choose("trend", has_time=True, dimension=None)["type"] == "area"
    assert viz.choose("breakdown", has_time=False, dimension="stage", members=5)["type"] == "funnel"
    assert viz.choose("breakdown", has_time=False, dimension="country", members=20)["type"] == "map"
    assert viz.choose("breakdown", has_time=False, dimension="country", members=2, compare=True)["type"] == "bar"
    assert viz.choose("x", has_time=False, dimension=None, three_measures=True)["type"] == "scatter3d"
    assert "pie" not in {
        viz.choose("breakdown", has_time=False, dimension="channel", members=n)["type"] for n in range(1, 8)
    }
