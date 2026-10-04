"""Semantic catalog index: how business language maps onto governed metrics,
dimensions and dimension members. Built from the API (permission-aware)."""

from __future__ import annotations

import logging
import re
import time
from dataclasses import dataclass, field
from typing import Any

import httpx

from ..api_client import AixbiApi, ApiError
from ..types import JSON, JSONList

log = logging.getLogger("aixbi.ai.catalog")

# Members too generic to treat as filters when they appear in free text.
AMBIGUOUS_MEMBERS = {
    "none",
    "high",
    "low",
    "medium",
    "online",
    "pending",
    "approved",
    "rejected",
    "completed",
    "submitted",
    "issued",
    "refused",
    "cleared",
    "referred",
    "card",
    "agent",
    "partner",
    "university",
    "college",
}
MEMBER_DIMENSIONS = ("country", "region", "institution", "course", "field_of_study", "level", "state", "payment_type")
_member_cache: dict[tuple[str, str, str], tuple[float, list[str]]] = {}
MEMBER_TTL = 600


def norm(text: str) -> str:
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9%.\- ]", " ", text.lower())).strip()


def contains_phrase(text: str, phrase: str) -> bool:
    p = norm(phrase)
    return bool(p) and re.search(rf"(?<![a-z0-9]){re.escape(p)}(?![a-z0-9])", text) is not None


@dataclass
class MetricInfo:
    ref: str
    model: str
    key: str
    label: str
    format: str
    higher_is_better: bool
    target: float | None
    synonyms: list[str]
    description: str | None
    is_kpi: bool
    status: str = "approved"  # proposed | approved | certified (deprecated metrics are never indexed)


@dataclass
class CatalogIndex:
    models: dict[str, JSON]
    metrics: dict[str, MetricInfo]
    dimensions: dict[str, dict[str, JSON]]  # model -> key -> dim
    members: dict[str, list[str]] = field(default_factory=dict)  # dim key -> members

    @classmethod
    def from_catalog(cls, catalog: JSONList) -> CatalogIndex:
        models, metrics, dims = {}, {}, {}
        for m in catalog:
            models[m["key"]] = m
            dims[m["key"]] = {d["key"]: d for d in m["dimensions"] if d.get("accessible", True)}
            for x in m["metrics"]:
                # A deprecated metric keeps existing dashboards working but is never chosen for new answers.
                if x.get("status") == "deprecated":
                    continue
                metrics[x["ref"]] = MetricInfo(
                    x["ref"],
                    m["key"],
                    x["key"],
                    x["label"],
                    x["format"],
                    x["higher_is_better"],
                    x.get("target"),
                    x.get("synonyms") or [],
                    x.get("description"),
                    x.get("is_kpi", False),
                    x.get("status", "approved"),
                )
        return cls(models, metrics, dims)

    def metric(self, ref: str) -> MetricInfo | None:
        return self.metrics.get(ref)

    def metric_label(self, ref: str) -> str:
        """The metric's business label, or the raw ref when it is not in the catalog."""
        info = self.metrics.get(ref)
        return info.label if info else ref

    def require_metric(self, ref: str) -> MetricInfo:
        """For refs already validated by the planner and governance node."""
        info = self.metrics.get(ref)
        if info is None:
            raise KeyError(f"Metric {ref!r} is not in the governed catalog for this user.")
        return info

    def models_with_dimension(self, dim: str) -> list[str]:
        return [m for m, d in self.dimensions.items() if dim in d]

    def dimension_label(self, dim: str) -> str:
        for d in self.dimensions.values():
            if dim in d:
                return str(d[dim]["label"])
        return dim.replace("_", " ").title()

    def find_metrics(self, text: str) -> list[str]:
        """Metrics mentioned in text, longest phrase first, de-duplicated by span."""
        t = norm(text)
        candidates: list[tuple[int, int, str]] = []
        for ref, m in self.metrics.items():
            for phrase in [m.label, *m.synonyms]:
                p = norm(phrase)
                if p:
                    pattern = rf"(?<![a-z0-9]){re.escape(p)}(?![a-z0-9])"
                    candidates.extend((match.start(), match.end(), ref) for match in re.finditer(pattern, t))
        # Prefer longer spans; certified, then KPI metrics win ties (e.g. "applications").
        candidates.sort(
            key=lambda c: (-(c[1] - c[0]), self.metrics[c[2]].status != "certified", not self.metrics[c[2]].is_kpi)
        )
        taken: list[tuple[int, int]] = []
        found: list[tuple[int, str]] = []
        for s, e, ref in candidates:
            if any(s < te and e > ts for ts, te in taken):
                continue
            taken.append((s, e))
            if ref not in [r for _, r in found]:
                found.append((s, ref))
        return [ref for _, ref in sorted(found)]

    def find_dimension(self, text: str, model: str | None = None) -> str | None:
        t = norm(text)
        best: tuple[int, str] | None = None
        scope = [self.dimensions[model]] if model and model in self.dimensions else list(self.dimensions.values())
        for dims in scope:
            for key, d in dims.items():
                if d["type"] == "time" or key.endswith("_code") or not d.get("accessible", True):
                    continue
                for phrase in [d["label"], key.replace("_", " "), *d.get("synonyms", [])]:
                    p = norm(phrase)
                    for form in {p, p + "s", p + "es", re.sub(r"y$", "ies", p)}:
                        if form and contains_phrase(t, form) and (best is None or len(form) > best[0]):
                            best = (len(form), key)
        return best[1] if best else None

    def find_members(self, text: str) -> dict[str, list[str]]:
        t = norm(text)
        out: dict[str, list[str]] = {}
        for dim, members in self.members.items():
            for m in members:
                if m and len(m) >= 4 and m.lower() not in AMBIGUOUS_MEMBERS and contains_phrase(t, m):
                    out.setdefault(dim, []).append(m)
        # Drop members that are substrings of a longer matched member in the same dimension.
        for dim, ms in out.items():
            out[dim] = [m for m in ms if not any(m != o and m.lower() in o.lower() for o in ms)]
        return out

    async def load_members(self, api: AixbiApi, organisation_id: str) -> None:
        for model_key, dims in self.dimensions.items():
            metric = next((m.key for m in self.metrics.values() if m.model == model_key), None)
            if not metric:
                continue
            for dim in MEMBER_DIMENSIONS:
                if dim not in dims or dim in self.members:
                    continue
                key = (organisation_id, model_key, dim)
                hit = _member_cache.get(key)
                if hit and time.time() - hit[0] < MEMBER_TTL:
                    self.members[dim] = hit[1]
                    continue
                try:
                    res = await api.query(
                        {
                            "model": model_key,
                            "metrics": [metric],
                            "dimensions": [dim],
                            "time": {"range": "last_24_months"},
                            "limit": 300,
                        }
                    )
                except (ApiError, httpx.HTTPError) as e:
                    # Members only sharpen entity matching; planning still works without them.
                    log.warning("member lookup failed for %s.%s: %s", model_key, dim, e)
                    continue
                values = [str(r[dim]) for r in res["rows"] if r.get(dim) is not None]
                _member_cache[key] = (time.time(), values)
                self.members[dim] = values

    def describe_for_llm(self) -> dict[str, Any]:
        """Compact, deterministic catalog description (stable ordering keeps prompt caching effective)."""
        return {
            "metrics": [
                {
                    "ref": m.ref,
                    "label": m.label,
                    "format": m.format,
                    "synonyms": m.synonyms[:6],
                    "description": m.description,
                }
                for m in sorted(self.metrics.values(), key=lambda x: x.ref)
            ],
            "dimensions": {
                model: sorted(k for k, d in dims.items() if d["type"] != "time")
                for model, dims in sorted(self.dimensions.items())
            },
            "members": {k: v[:40] for k, v in sorted(self.members.items())},
        }
