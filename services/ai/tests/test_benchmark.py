"""Runs the AI regression benchmark (benchmark/questions.json) on every build:
a change that makes the planner misunderstand a known question fails CI."""

import pytest

from app.agents.deterministic import plan
from benchmark.run import QUESTIONS, check


@pytest.mark.parametrize("case", QUESTIONS, ids=[c["q"] for c in QUESTIONS])
def test_benchmark_question(index, case):
    assert check(plan(case["q"], index), case) == []
