"""AI gateway: per-task model routing and the circuit breaker that keeps answers fast
(deterministic) while the LLM provider is failing."""

import anthropic
import httpx
import pytest

from app.agents import llm
from app.config import settings


def test_models_are_routed_per_task(monkeypatch):
    monkeypatch.setattr(settings(), "model", "default-model")
    monkeypatch.setattr(settings(), "planner_model", "small-model")
    monkeypatch.setattr(settings(), "narrator_model", None)
    assert llm.model_for("planner") == "small-model"
    assert llm.model_for("narrator") == "default-model"


@pytest.mark.asyncio
async def test_breaker_opens_after_failures_and_skips_the_provider(monkeypatch):
    monkeypatch.setattr(settings(), "breaker_failures", 2)
    monkeypatch.setattr(settings(), "breaker_cooldown_s", 60.0)
    monkeypatch.setattr(llm, "BREAKER", llm.Breaker())
    calls = 0

    class Failing:
        class beta:  # noqa: N801 - mirrors the SDK's attribute layout
            class messages:  # noqa: N801
                @staticmethod
                async def create(**_):
                    nonlocal calls
                    calls += 1
                    raise anthropic.APIConnectionError(request=httpx.Request("POST", "https://api.anthropic.com"))

    monkeypatch.setattr(llm, "_client", lambda: Failing)
    for _ in range(2):
        text, how = await llm.narrate("q", ["Revenue was RM 5"], "fallback", llm.Usage())
        assert (text, how) == ("fallback", "deterministic (LLM unavailable)")
    assert llm.BREAKER.is_open()
    text, how = await llm.narrate("q", ["Revenue was RM 5"], "fallback", llm.Usage())
    assert text == "fallback" and "retrying shortly" in how
    assert calls == 2, "no call while the circuit is open"
    llm.BREAKER.succeeded()
    assert not llm.BREAKER.is_open()
