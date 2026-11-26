"""Output sanitisation for anything that came from a subprocess or the network."""
from __future__ import annotations

import re

_CTRL = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")
_ANSI = re.compile(r"\x1b\[[0-9;]*[A-Za-z]")
MAX_RAW = 20_000


def clean_output(text: str, limit: int = MAX_RAW) -> str:
    text = _ANSI.sub("", text or "")
    text = _CTRL.sub("", text).replace("\r\n", "\n").replace("\r", "\n")
    if len(text) > limit:
        text = text[:limit] + f"\n... [truncated {len(text) - limit} characters]"
    return text


def clean_field(value: str, limit: int = 255) -> str:
    return clean_output(str(value or ""), limit).strip()
