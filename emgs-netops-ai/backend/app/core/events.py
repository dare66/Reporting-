"""Tiny in-process pub/sub used to stream progress to WebSocket clients."""
from __future__ import annotations

import asyncio
from collections import defaultdict


class EventBus:
    def __init__(self) -> None:
        self._subs: dict[str, set[asyncio.Queue]] = defaultdict(set)

    def subscribe(self, channel: str) -> asyncio.Queue:
        q: asyncio.Queue = asyncio.Queue(maxsize=500)
        self._subs[channel].add(q)
        return q

    def unsubscribe(self, channel: str, q: asyncio.Queue) -> None:
        self._subs[channel].discard(q)
        if not self._subs[channel]:
            self._subs.pop(channel, None)

    def publish(self, channel: str, event: dict) -> None:
        for q in list(self._subs.get(channel, ())):
            try:
                q.put_nowait(event)
            except asyncio.QueueFull:
                pass  # slow consumer: drop progress ticks rather than block the scan


bus = EventBus()
