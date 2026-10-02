"""Shared type aliases.

Payloads exchanged with the AIXBI API and the web client are JSON objects whose
shape is owned by the API; inside the service they are handled as JSON.
"""

from typing import Any

JSON = dict[str, Any]
JSONList = list[JSON]
