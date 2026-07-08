"""Общие модули воркеров.

Экспорты WS-клиента (s1-08) даются ЛЕНИВО через PEP 562 `__getattr__`: сам
`import workers.common` (его дёргают stream_consumer/envelope и host-side
test-drift) НЕ тянет ws_client и его тяжёлые зависимости (httpx/websockets),
поэтому импорт пакета остаётся дешёвым и безопасным в «голых» окружениях.
Прямой `from workers.common.ws_client import WsClient` работает как обычно.
"""

from __future__ import annotations

from typing import TYPE_CHECKING

if TYPE_CHECKING:  # для тайп-чекеров/IDE — без рантайм-импорта тяжёлых зависимостей
    from workers.common.ws_client import (
        ProgressReporter,
        ResultSignal,
        WsClient,
        WsClientConfig,
    )

_WS_CLIENT_EXPORTS = frozenset(
    {"ProgressReporter", "ResultSignal", "WsClient", "WsClientConfig"}
)

__all__ = ["ProgressReporter", "ResultSignal", "WsClient", "WsClientConfig"]


def __getattr__(name: str):
    if name in _WS_CLIENT_EXPORTS:
        from workers.common import ws_client

        return getattr(ws_client, name)
    raise AttributeError(f"module {__name__!r} has no attribute {name!r}")
