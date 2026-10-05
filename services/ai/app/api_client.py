"""Client for the AIXBI API. Agents never touch databases: every read goes
through the governed semantic API as the end user, so RBAC, row-level
security, column-level security, caching and auditing all apply."""

from typing import Any

import httpx

from .config import settings
from .types import JSON, JSONList


class ApiError(Exception):
    def __init__(self, status: int, message: str, code: str | None = None):
        super().__init__(message)
        self.status = status
        self.message = message
        self.code = code


class AixbiApi:
    def __init__(self, token: str, transport: httpx.AsyncBaseTransport | None = None, project_id: str | None = None):
        headers = {"Authorization": f"Bearer {token}", "Accept": "application/json"}
        if project_id:
            # Keeps the agent inside the project the person is working in.
            headers["X-Project-Id"] = project_id
        self._client = httpx.AsyncClient(
            base_url=settings().api_url,
            headers=headers,
            timeout=settings().request_timeout,
            transport=transport,
        )

    async def close(self) -> None:
        await self._client.aclose()

    async def _call(self, method: str, path: str, **kwargs: Any) -> Any:
        res = await self._client.request(method, path, **kwargs)
        if res.status_code >= 400:
            try:
                body = res.json()
            except ValueError:
                body = {}
            # Errors come as {"error": {"code", "message"}}, or as {"message"} for plain HTTP errors.
            err = body.get("error") or {}
            message = err.get("message") or body.get("message") or f"API error {res.status_code}"
            raise ApiError(res.status_code, message, err.get("code"))
        return res.json() if res.content else None

    async def _object(self, method: str, path: str, **kwargs: Any) -> JSON:
        """Calls an endpoint whose `data` is a JSON object."""
        data = (await self._call(method, path, **kwargs) or {}).get("data")
        if not isinstance(data, dict):
            raise ApiError(502, f"Unexpected response from {path}: expected an object.")
        return data

    async def _list(self, method: str, path: str, **kwargs: Any) -> JSONList:
        """Calls an endpoint whose `data` is a list of JSON objects."""
        data = (await self._call(method, path, **kwargs) or {}).get("data")
        if not isinstance(data, list):
            raise ApiError(502, f"Unexpected response from {path}: expected a list.")
        return data

    async def catalog(self) -> JSONList:
        return await self._list("GET", "/semantic-catalog")

    async def query(self, payload: JSON) -> JSON:
        """Runs a semantic query; the response carries rows, columns and meta at the top level."""
        result = await self._call("POST", "/query", json=payload)
        if not isinstance(result, dict):
            raise ApiError(502, "Unexpected response from /query.")
        return result

    async def kpis(
        self, metrics: list[str], range_: Any, filters: list[Any] | None = None, compare: str = "previous_period"
    ) -> JSONList:
        body = {"metrics": metrics, "range": range_, "filters": filters or [], "compare": compare}
        return await self._list("POST", "/kpis", json=body)

    async def root_cause(
        self, metric: str, range_: Any, filters: list[Any] | None = None, dimensions: list[Any] | None = None
    ) -> JSON:
        body: JSON = {"metric": metric, "range": range_, "filters": filters or []}
        if dimensions:
            body["dimensions"] = dimensions
        return await self._object("POST", "/analysis/root-cause", json=body)

    async def forecast(self, metric: str, horizon: str) -> JSON:
        return await self._object("POST", "/analysis/forecast", json={"metric": metric, "horizon": horizon})

    async def scenario(self, assumptions: JSON) -> JSON:
        return await self._object("POST", "/analysis/scenario", json=assumptions)

    async def anomalies(self) -> JSONList:
        return await self._list("GET", "/anomalies", params={"status": "open"})

    async def generate_report(self, body: JSON) -> JSON:
        return await self._object("POST", "/reports/generate", json=body)

    async def add_report_section(self, report_id: str, body: JSON) -> JSON:
        return await self._object("POST", f"/reports/{report_id}/sections", json=body)

    async def delete_report_section(self, report_id: str, section_id: str) -> None:
        await self._call("DELETE", f"/reports/{report_id}/sections/{section_id}")

    async def update_report(self, report_id: str, body: JSON) -> JSON:
        return await self._object("PATCH", f"/reports/{report_id}", json=body)

    async def report(self, report_id: str) -> JSON:
        return await self._object("GET", f"/reports/{report_id}")

    async def create_alert(self, body: JSON) -> JSON:
        return await self._object("POST", "/alert-rules", json=body)

    async def propose_action(self, body: JSON) -> JSON:
        """Proposes an action. The API runs nothing until a person allowed to approve it does."""
        return await self._object("POST", "/actions", json=body)

    async def create_dashboard(self, body: JSON) -> JSON:
        return await self._object("POST", "/dashboards", json=body)

    async def conversation(self, conversation_id: str) -> JSON:
        return await self._object("GET", f"/ai/conversations/{conversation_id}")

    async def record_run(self, body: JSON) -> JSON:
        return await self._object("POST", "/ai/runs", json=body)
