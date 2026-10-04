"""The analyst only plans with governed metrics: deprecated ones are invisible, certified ones win ties."""

import copy

from app.agents.catalog import CatalogIndex
from tests.conftest import CATALOG


def _metric(ref: str, label: str, synonyms: list[str], status: str) -> dict[str, object]:
    return {
        "ref": ref,
        "key": ref.split(".")[1],
        "label": label,
        "format": "number",
        "higher_is_better": True,
        "synonyms": synonyms,
        "is_kpi": False,
        "status": status,
    }


def test_deprecated_metrics_are_never_indexed() -> None:
    catalog = copy.deepcopy(CATALOG)
    catalog[0]["metrics"].append(_metric("applications.old_intake", "Old Intake", ["intake"], "deprecated"))
    index = CatalogIndex.from_catalog(catalog)
    assert index.metric("applications.old_intake") is None
    assert index.find_metrics("show intake by country") == []


def test_certified_metric_wins_a_tie_over_an_uncertified_one() -> None:
    catalog = copy.deepcopy(CATALOG)
    catalog[0]["metrics"].append(_metric("applications.draft_volume", "Throughput", ["throughput"], "proposed"))
    catalog[1]["metrics"].append(_metric("decisions.certified_volume", "Throughput", ["throughput"], "certified"))
    index = CatalogIndex.from_catalog(catalog)
    assert index.find_metrics("throughput last month")[0] == "decisions.certified_volume"
