"""The plan: the only thing the planning step may produce. It references
governed catalog keys and never contains SQL."""

from typing import Literal

from pydantic import BaseModel, Field

Intent = Literal["overview", "trend", "breakdown", "why", "forecast", "what_if", "report", "report_edit", "alert", "dashboard", "anomalies", "help"]


class Filter(BaseModel):
    dimension: str
    op: Literal["eq", "neq", "in", "not_in", "gt", "gte", "lt", "lte"] = "in"
    value: str | float | list[str] | list[float]


class Scenario(BaseModel):
    demand_change_pct: float = 0
    officer_change_pct: float = 0
    productivity_change_pct: float = 0


class AlertSpec(BaseModel):
    operator: Literal["lt", "lte", "gt", "gte", "change_pct_gt", "change_pct_lt"]
    threshold: float
    window: Literal["today", "last_7_days", "last_30_days", "this_month", "this_week"] = "last_7_days"
    frequency_minutes: int = 15


class ReportEdit(BaseModel):
    action: Literal["add_section", "make_executive", "regenerate"]
    section_type: Literal["forecast", "breakdown", "anomalies", "root_cause", "chart", "kpis", "text"] | None = None
    dimension: str | None = None
    horizon: int | None = None


class Plan(BaseModel):
    intent: Intent
    metrics: list[str] = Field(default_factory=list, description="metric refs model.metric")
    dimension: str | None = None
    filters: list[Filter] = Field(default_factory=list)
    range: str | dict = "last_30_days"
    grain: Literal["day", "week", "month", "quarter", "year"] | None = None
    sort: Literal["asc", "desc"] = "desc"
    limit: int = 10
    horizon: Literal["7d", "30d", "90d", "6m", "12m"] = "6m"
    scenario: Scenario | None = None
    alert: AlertSpec | None = None
    report_template: str | None = None
    report_edit: ReportEdit | None = None
    as_table: bool = False
    executive_tone: bool = False
    notes: list[str] = Field(default_factory=list, description="assumptions the planner made, shown to the user")
