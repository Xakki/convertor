"""WS-статистика dev-сервера (s1-09).

Единый объект Stats, общий между WsRunner (пишет) и routes_stats (читает).
Однопоточный event loop — локинг не нужен. Не персистируется; сбрасывается при рестарте.
Форма snapshot() соответствует GET /api/stats в контракте devserver-api-contract.
"""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Any


def _utcnow_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


class Stats:
    """WS-stats: состояние соединения + in-flight счётчик + последний pong."""

    def __init__(self) -> None:
        self._connected = False
        self._inflight = 0
        self._last_pong: str | None = None  # null до первого pong от gateway

    # --- жизненный цикл runner'а -------------------------------------------

    def on_connected(self) -> None:
        self._connected = True

    def on_disconnected(self) -> None:
        self._connected = False

    def on_pong(self) -> None:
        self._last_pong = _utcnow_iso()

    # --- счётчик задач -------------------------------------------------------

    def on_job_start(self) -> None:
        self._inflight += 1

    def on_job_done(self) -> None:
        self._inflight = max(0, self._inflight - 1)

    # --- читающая сторона ----------------------------------------------------

    def snapshot(self) -> dict[str, Any]:
        return {
            "connected": self._connected,
            "inflight": self._inflight,
            "lastPong": self._last_pong,
        }
