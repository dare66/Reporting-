"""AI regression benchmark (brief Test 12).

A fixed set of business questions, each with what a correct plan must contain
(intent, metrics, dimension, period…). Run on every build by
tests/test_benchmark.py; run by hand for a score card:

    python -m benchmark.run            # deterministic planner
    python -m benchmark.run --llm      # the LLM planner, when ANTHROPIC_API_KEY is set

A question passes only if every expected field matches. The planner never
sees the expected answers.
"""

from __future__ import annotations

import asyncio
import json
import sys
from pathlib import Path
from typing import Any

from app.agents.catalog import CatalogIndex
from app.agents.deterministic import plan as deterministic_plan
from app.agents.plan import Plan

QUESTIONS: list[dict[str, Any]] = json.loads((Path(__file__).parent / "questions.json").read_text())


def check(p: Plan, case: dict[str, Any]) -> list[str]:
    """The fields of the plan that differ from what the case expects."""
    fields = ("intent", "metrics", "dimension", "range", "grain", "horizon", "limit", "report_template")
    misses = [
        f"{f}: expected {case[f]!r}, got {getattr(p, f)!r}" for f in fields if f in case and getattr(p, f) != case[f]
    ]
    if "not_dimension" in case and p.dimension == case["not_dimension"]:
        misses.append(f"dimension must not be {case['not_dimension']!r}")
    return misses


async def score(index: CatalogIndex, use_llm: bool = False) -> tuple[int, list[tuple[str, list[str]]]]:
    failures = []
    for case in QUESTIONS:
        if use_llm:
            from app.agents import llm

            p = await llm.plan(case["q"], index, {}, llm.Usage())
        else:
            p = deterministic_plan(case["q"], index)
        if misses := check(p, case):
            failures.append((case["q"], misses))
    return len(QUESTIONS) - len(failures), failures


if __name__ == "__main__":
    from tests.conftest import CATALOG, MEMBERS

    idx = CatalogIndex.from_catalog(CATALOG)
    idx.members = MEMBERS
    passed, failures = asyncio.run(score(idx, "--llm" in sys.argv))
    for q, misses in failures:
        print(f"✗ {q}\n    " + "\n    ".join(misses))
    print(f"{passed}/{len(QUESTIONS)} questions planned correctly ({passed / len(QUESTIONS):.0%})")
    sys.exit(0 if not failures else 1)
