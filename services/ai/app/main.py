"""AIXBI AI service: the AI analyst (LangGraph agents) and the statistical engine."""

from __future__ import annotations

import asyncio
import json
import logging
from collections.abc import AsyncIterator
from typing import Annotated, Any

from fastapi import Depends, FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import StreamingResponse
from pydantic import BaseModel, Field

from . import observability
from .agents import graph, llm
from .analytics import anomalies as anomaly_engine
from .analytics import forecast as forecast_engine
from .api_client import AixbiApi, ApiError
from .auth import Principal, current_user, internal_caller
from .config import settings
from .types import JSON

CurrentUser = Annotated[Principal, Depends(current_user)]

log = logging.getLogger("aixbi.ai")
app = FastAPI(title="AIXBI AI Service", version="1.0.0", docs_url="/ai/docs", openapi_url="/ai/openapi.json")
app.add_middleware(
    CORSMiddleware, allow_origins=settings().cors_origins.split(","), allow_methods=["*"], allow_headers=["*"]
)


class SeriesPoint(BaseModel):
    period: str
    value: float | None


class ForecastRequest(BaseModel):
    series: list[SeriesPoint]
    grain: str = "month"
    horizon: int = Field(6, ge=1, le=366)


class AnomalyRequest(BaseModel):
    series: list[SeriesPoint]
    grain: str = "day"
    threshold: float = Field(3.0, ge=1.5, le=10)


class ChatRequest(BaseModel):
    question: str = Field(min_length=1, max_length=2000)
    conversation_id: str | None = None


@app.get("/health")
async def health() -> JSON:
    s = settings()
    return {
        "status": "ok",
        "planner": f"llm ({s.model}) with deterministic fallback"
        if s.llm_available
        else "deterministic semantic planner",
        "langfuse": observability.enabled(),
        "gateway": {
            "planner_model": llm.model_for("planner") if s.llm_available else None,
            "narrator_model": llm.model_for("narrator") if s.llm_available else None,
            "circuit": llm.BREAKER.state(),
        },
    }


@app.post("/v1/analytics/forecast", dependencies=[Depends(internal_caller)])
async def forecast(req: ForecastRequest) -> JSON:
    try:
        return forecast_engine.forecast([p.model_dump() for p in req.series], req.grain, req.horizon)
    except ValueError as e:
        raise HTTPException(422, detail=str(e)) from e


@app.post("/v1/analytics/anomalies", dependencies=[Depends(internal_caller)])
async def anomalies(req: AnomalyRequest) -> JSON:
    return anomaly_engine.detect([p.model_dump() for p in req.series], req.grain, req.threshold)


async def _context(api: AixbiApi, conversation_id: str | None) -> JSON:
    if not conversation_id:
        return {}
    try:
        return (await api.conversation(conversation_id)).get("context") or {}
    except ApiError:
        return {}


async def _persist(api: AixbiApi, req: ChatRequest, result: JSON, error: str | None = None) -> JSON:
    body = {
        "conversation_id": req.conversation_id,
        "question": req.question,
        "answer": result.get("answer") or error or "",
        "blocks": [
            *result.get("blocks", []),
            {
                "type": "meta",
                "suggestions": result.get("suggestions", []),
                "evidence": result.get("evidence", []),
                "trace": result.get("trace", []),
                "planner": result.get("planner"),
                "narrator": result.get("narrator"),
            },
        ],
        "intent": result.get("intent"),
        "status": "failed" if error else result.get("status", "succeeded"),
        "planner": result.get("planner"),
        "model": result.get("model"),
        "trace": result.get("trace", []),
        "evidence": result.get("evidence", []),
        "tokens_in": result.get("usage", {}).get("tokens_in", 0),
        "tokens_out": result.get("usage", {}).get("tokens_out", 0),
        "cost_usd": result.get("usage", {}).get("cost_usd", 0),
        "latency_ms": result.get("latency_ms"),
        "error": error,
        "context": result.get("context", {}),
    }
    try:
        return await api.record_run(body)
    except ApiError as e:
        log.warning("run persistence failed: %s", e.message)
        return {}


@app.post("/v1/chat")
async def chat(req: ChatRequest, user: CurrentUser) -> JSON:
    api = AixbiApi(user.token, project_id=user.project_id)
    try:
        context = await _context(api, req.conversation_id)
        result = await graph.run(req.question, graph.Deps(api=api, organisation_id=user.organisation_id), context)
        saved = await _persist(api, req, result)
        return {**result, **saved}
    except ApiError as e:
        raise HTTPException(e.status if e.status < 500 else 502, detail=e.message) from e
    finally:
        await api.close()


@app.post("/v1/chat/stream")
async def chat_stream(req: ChatRequest, user: CurrentUser) -> StreamingResponse:
    """Server-sent events: step → plan → block* → answer → done. Starts streaming immediately."""
    queue: asyncio.Queue[tuple[str, Any] | None] = asyncio.Queue()

    async def emit(event: str, data: Any) -> None:
        await queue.put((event, data))

    async def worker() -> None:
        api = AixbiApi(user.token, project_id=user.project_id)
        try:
            await emit(
                "step",
                {"agent": "start", "label": "Understanding your question", "status": "running", "detail": "", "ms": 0},
            )
            context = await _context(api, req.conversation_id)
            result = await graph.run(
                req.question, graph.Deps(api=api, organisation_id=user.organisation_id, emit=emit), context
            )
            saved = await _persist(api, req, result)
            await emit("done", {**result, **saved})
        except ApiError as e:
            await emit("error", {"message": e.message, "status": e.status})
        except Exception as e:
            log.exception("chat failed")
            await emit("error", {"message": "The analysis could not be completed.", "detail": type(e).__name__})
        finally:
            await api.close()
            await queue.put(None)

    async def events() -> AsyncIterator[bytes]:
        task = asyncio.create_task(worker())
        try:
            while (item := await queue.get()) is not None:
                event, data = item
                yield f"event: {event}\ndata: {json.dumps(data, default=str)}\n\n".encode()
        finally:
            task.cancel()

    return StreamingResponse(
        events(), media_type="text/event-stream", headers={"Cache-Control": "no-cache", "X-Accel-Buffering": "no"}
    )
