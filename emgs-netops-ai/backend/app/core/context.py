"""Who is asking, from where. Passed to every service call."""
from __future__ import annotations

import secrets
from dataclasses import dataclass, field
from datetime import datetime, timezone


@dataclass
class ExecutionContext:
    user_id: int | None
    username: str
    roles: list[str] = field(default_factory=list)
    interface: str = "web"  # web | mobile | mcp | cli | assistant
    client_ip: str = ""

    def has_role(self, *roles: str) -> bool:
        return "admin" in self.roles or any(r in self.roles for r in roles)


def new_reference(prefix: str) -> str:
    return f"{prefix}-{datetime.now(timezone.utc):%Y%m%d}-{secrets.token_hex(3).upper()}"
