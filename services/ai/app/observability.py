"""Langfuse tracing when configured (LANGFUSE_PUBLIC_KEY / LANGFUSE_SECRET_KEY / LANGFUSE_HOST); otherwise a no-op.
Run records are always persisted in the platform's own ai_runs table regardless."""

import os
from contextlib import contextmanager, nullcontext
from typing import Any, Iterator

_client: Any = None


def _langfuse() -> Any:
    global _client
    if _client is None and os.getenv("LANGFUSE_PUBLIC_KEY") and os.getenv("LANGFUSE_SECRET_KEY"):
        from langfuse import Langfuse

        _client = Langfuse()
    return _client


@contextmanager
def trace(name: str, **kwargs: Any) -> Iterator[Any]:
    lf = _langfuse()
    if lf is None:
        with nullcontext() as n:
            yield n
        return
    with lf.start_as_current_observation(name=name, **kwargs) as obs:
        yield obs


def enabled() -> bool:
    return _langfuse() is not None
