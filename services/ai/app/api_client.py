"""Client for the AIXBI API. Agents never touch databases: every read goes
through the governed semantic API as the end user, so RBAC, row-level
security, column-level security, caching and auditing all apply."""

from typing import Any

import httpx

from .config import settings


class ApiError(Exception):
    def __init__(self, status: int, message: str, code: str | None = None):
        super().__init__(message)
        self.status = status
        self.message = message
        self.code = code


class AixbiApi:
    def __init__(self, token: str, transport: httpx.AsyncBaseTransport | None = None):
        self._client = httpx.AsyncClient(
            base_url=settings().api_url,
            headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
            timeout=settings().request_timeout,
            transport=transport,
        )

    async def close(self) -> None:
        await self._client.aclose()

    async def _call(self, method: str, path: str, **kwargs: Any) -> Any:
        res = await self._client.request(method, path, **kwargs)
        if res.status_code >= 400:
            try:
                err = res.json().get("error", {})
            except ValueError:
                err = {}
            raise ApiError(res.status_code, err.get("message") or f"API error {res.status_code}", err.get("code"))
        return res.json() if res.content else None

    async def catalog(self) -> list[dict]:
        return (await self._call("GET", "/semantic-catalog"))["data"]

    async def query(self, payload: dict) -> dict:
        return await self._call("POST", "/query", json=payload)

    async def kpis(self, metrics: list[str], range_: Any, filters: list | None = None, compare: str = "previous_period") -> list[dict]:
        body = {"metrics": metrics, "range": range_, "filters": filters or [], "compare": compare}
        return (await self._call("POST", "/kpis", json=body))["data"]

    async def root_cause(self, metric: str, range_: Any, filters: list | None = None, dimensions: list | None = None) -> dict:
        body: dict = {"metric": metric, "range": range_, "filters": filters or []}
        if dimensions:
            body["dimensions"] = dimensions
        return (await self._call("POST", "/analysis/root-cause", json=body))["data"]

    async def forecast(self, metric: str, horizon: str) -> dict:
        return (await self._call("POST", "/analysis/forecast", json={"metric": metric, "horizon": horizon}))["data"]

    async def scenario(self, assumptions: dict) -> dict:
        return (await self._call("POST", "/analysis/scenario", json=assumptions))["data"]

    async def anomalies(self) -> list[dict]:
        return (await self._call("GET", "/anomalies", params={"status": "open"}))["data"]

    async def generate_report(self, body: dict) -> dict:
        return (await self._call("POST", "/reports/generate", json=body))["data"]

    async def add_report_section(self, report_id: str, body: dict) -> dict:
        return (await self._call("POST", f"/reports/{report_id}/sections", json=body))["data"]

    async def delete_report_section(self, report_id: str, section_id: str) -> None:
        await self._call("DELETE", f"/reports/{report_id}/sections/{section_id}")

    async def update_report(self, report_id: str, body: dict) -> dict:
        return (await self._call("PATCH", f"/reports/{report_id}", json=body))["data"]

    async def report(self, report_id: str) -> dict:
        return (await self._call("GET", f"/reports/{report_id}"))["data"]

    async def create_alert(self, body: dict) -> dict:
        return (await self._call("POST", "/alert-rules", json=body))["data"]

    async def create_dashboard(self, body: dict) -> dict:
        return (await self._call("POST", "/dashboards", json=body))["data"]

    async def conversation(self, conversation_id: str) -> dict:
        return (await self._call("GET", f"/ai/conversations/{conversation_id}"))["data"]

    async def record_run(self, body: dict) -> dict:
        return (await self._call("POST", "/ai/runs", json=body))["data"]
