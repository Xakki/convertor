"""Тесты DLQ-consumer'а `conv.dead` (hardening: conv-dead-no-consumer).

KeyDB-слой — на РЕАЛЬНОМ keydb (как в `test_gateway_reclaim_dlq.py`). Relay —
фейковый duck-type (без httpx: `run_dlq_consumer_loop` принимает любой объект с
`post_dlq_fail`, зеркалит стиль `FakeKeyDb*` из соседних тестов).

Покрытие:
  [A] Seed XADD conv.dead → consumer вызывает relay.post_dlq_fail с точными
      conversionId/reason/processingMs/attempt из payload'а (requeue-attempt-
      generation-marker: attempt присутствует → forwarded дословно).
  [B] Relay 2xx (True) → запись XACKed (PEL пуст).
  [C] Relay провал (False) → запись остаётся unacked (PEL непустой).
  [D] `processingMs` отсутствует в payload → передаётся как None (null-shape).
  [D2] `attempt`-ключ ОТСУТСТВУЕТ в payload (legacy conv.dead-запись, до
      раскатки маркера) → forwarded как None (НЕ 0) — PHP пропускает stale-guard.
  [E] Транзиентный провал НЕ хоронит запись — reclaim_dlq_idle (XAUTOCLAIM)
      переклеймивает unacked-запись и повторно её обрабатывает; второй успешный
      relay-вызов её наконец XACK'ает (доказывает, что "retry на следующем
      sweep'е" — не пустые слова, см. dlq_consumer.py docstring).
  [F] Poison-запись (`conversionId<=0`) дропается (ack) БЕЗ вызова relay.
"""

from __future__ import annotations

import asyncio
import json

import pytest

from workers.gateway.config import Config
from workers.gateway.dlq_consumer import DLQ_CONSUMER_NAME, run_dlq_consumer_loop
from workers.gateway.keydb import DLQ_STREAM, GROUP, KeyDbGateway, build_client

# ---------------------------------------------------------------------------
# Helpers: real keydb (зеркалит test_gateway_reclaim_dlq.py)
# ---------------------------------------------------------------------------


async def _new_real_kv():
    from workers.gateway.config import load_config
    client = build_client(load_config())
    return client, KeyDbGateway(client)


def _cfg(block_ms: int = 100, retry_idle_ms: int = 30_000, reclaim_batch: int = 10) -> Config:
    return Config(
        redis_host="unused", redis_port=6379, redis_db=2, redis_password=None,
        ws_block_ms=50, ws_host="localhost", ws_port=0, worker_api_token="t",
        dlq_consumer_block_ms=block_ms,
        dlq_consumer_retry_idle_ms=retry_idle_ms,
        dlq_consumer_reclaim_batch=reclaim_batch,
    )


async def _seed_dlq(client, conv_id: int, reason: str, processing_ms=None, attempt=0) -> None:
    payload = {
        "conversionId": conv_id,
        "state": "failed",
        "reason": reason,
        "originalStream": "conv.testdlq",
        "originalEntryId": "0-0",
        "processingMs": processing_ms,
        "attempt": attempt,
    }
    await client.xadd(DLQ_STREAM, {"data": json.dumps(payload)})


async def _pending_count(client) -> int:
    res = await client.xpending(DLQ_STREAM, GROUP)
    return int(res["pending"])


class FakeRelay:
    """Duck-type relay: журнал вызовов post_dlq_fail + настраиваемый результат.

    `results` — последовательность возвратов по номеру вызова (1-й, 2-й, ...);
    после исчерпания повторяет последнее значение. `ok` — константный результат,
    если `results` не задан.
    """

    def __init__(self, *, ok: bool = True, results: list[bool] | None = None) -> None:
        self.ok = ok
        self._results = list(results) if results is not None else None
        self.calls: list[tuple[int, str, int | None, int | None]] = []

    async def post_dlq_fail(self, conversion_id, reason, processing_ms, attempt=None):
        self.calls.append((conversion_id, reason, processing_ms, attempt))
        if self._results is not None:
            idx = min(len(self.calls) - 1, len(self._results) - 1)
            return self._results[idx]
        return self.ok


async def _run_one_sweep(keydb, relay, cfg, *, wait_for_calls: int = 1, timeout: float = 3.0):
    """Запустить run_dlq_consumer_loop, дождаться N вызовов relay, затем остановить.

    stop_event проверяется ПЕРЕД каждым блокирующим XREADGROUP — выставляем его
    сразу после наблюдения нужного числа вызовов, следующая проверка (не позже
    cfg.dlq_consumer_block_ms) завершает задачу.
    """
    stop_event = asyncio.Event()
    task = asyncio.create_task(run_dlq_consumer_loop(keydb, relay, cfg, stop_event=stop_event))
    try:
        async with asyncio.timeout(timeout):
            while len(relay.calls) < wait_for_calls:
                await asyncio.sleep(0.01)
    finally:
        stop_event.set()
        await asyncio.wait_for(task, timeout=timeout)


# ---------------------------------------------------------------------------
# [A]+[B] relay вызван с точными полями, 2xx → XACK
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_consumer_calls_relay_and_acks_on_success():
    """Также покрывает requeue-attempt-generation-marker: attempt присутствует
    в payload → forwarded в post_dlq_fail дословно (не 0/None по умолчанию)."""
    client, keydb = await _new_real_kv()
    try:
        await client.delete(DLQ_STREAM)  # stale entries from other suites would be read first
        await _seed_dlq(client, conv_id=777, reason="worker exploded", processing_ms=321, attempt=3)
        relay = FakeRelay(ok=True)

        await _run_one_sweep(keydb, relay, _cfg())

        assert relay.calls == [(777, "worker exploded", 321, 3)]
        assert await _pending_count(client) == 0
    finally:
        await client.delete(DLQ_STREAM)
        await client.aclose()


# ---------------------------------------------------------------------------
# [C] relay провал → запись остаётся unacked (pending)
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_consumer_leaves_entry_unacked_on_relay_failure():
    client, keydb = await _new_real_kv()
    try:
        await client.delete(DLQ_STREAM)  # stale entries from other suites would be read first
        await _seed_dlq(client, conv_id=888, reason="transient boom")
        relay = FakeRelay(ok=False)

        await _run_one_sweep(keydb, relay, _cfg())

        assert relay.calls == [(888, "transient boom", None, 0)]
        # НЕ acked — запись осталась в PEL DLQ_CONSUMER_NAME
        assert await _pending_count(client) == 1
        pending = await client.xpending_range(DLQ_STREAM, GROUP, min="-", max="+", count=10)
        assert pending[0]["consumer"] == DLQ_CONSUMER_NAME
    finally:
        await client.delete(DLQ_STREAM)
        await client.aclose()


# ---------------------------------------------------------------------------
# [D] processingMs отсутствует в payload → None (null-shape)
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_consumer_processing_ms_null_when_absent():
    client, keydb = await _new_real_kv()
    try:
        await client.delete(DLQ_STREAM)  # stale entries from other suites would be read first
        payload = {
            "conversionId": 999,
            "state": "failed",
            "reason": "no processingMs field at all",
            "originalStream": "conv.testdlq",
            "originalEntryId": "0-0",
            "attempt": 4,
        }
        await client.xadd(DLQ_STREAM, {"data": json.dumps(payload)})
        relay = FakeRelay(ok=True)

        await _run_one_sweep(keydb, relay, _cfg())

        assert relay.calls == [(999, "no processingMs field at all", None, 4)]
    finally:
        await client.delete(DLQ_STREAM)
        await client.aclose()


# ---------------------------------------------------------------------------
# [D2] requeue-attempt-generation-marker: attempt-ключ ОТСУТСТВУЕТ в payload
# (DLQ-запись, написанная ДО этого изменения — дренируется на первом деплое)
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_consumer_forwards_missing_attempt_as_none():
    """Legacy-запись `conv.dead` без ключа `attempt` вовсе (не 0!) → consumer
    форвардит `None` в post_dlq_fail, чтобы PHP пропустил stale-guard и
    финализировал её как обычно (см. dlq_consumer._coerce_attempt)."""
    client, keydb = await _new_real_kv()
    try:
        await client.delete(DLQ_STREAM)  # stale entries from other suites would be read first
        payload = {
            "conversionId": 1000,
            "state": "failed",
            "reason": "legacy entry pre-dating attempt field",
            "originalStream": "conv.testdlq",
            "originalEntryId": "0-0",
            "processingMs": None,
        }
        await client.xadd(DLQ_STREAM, {"data": json.dumps(payload)})
        relay = FakeRelay(ok=True)

        await _run_one_sweep(keydb, relay, _cfg())

        assert relay.calls == [(1000, "legacy entry pre-dating attempt field", None, None)]
    finally:
        await client.delete(DLQ_STREAM)
        await client.aclose()


# ---------------------------------------------------------------------------
# [E] Транзиентный провал → retry через reclaim_dlq_idle → в итоге XACKed
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_consumer_retries_after_transient_failure_then_acks():
    """relay возвращает False на 1-м вызове, True на 2-м. Малый retry_idle_ms
    заставляет reclaim_dlq_idle переклеймить unacked-запись в течение теста —
    без этого механизма 2-й вызов не случился бы никогда (см. dlq_consumer.py)."""
    client, keydb = await _new_real_kv()
    try:
        await client.delete(DLQ_STREAM)  # stale entries from other suites would be read first
        await _seed_dlq(client, conv_id=555, reason="flaky relay")
        relay = FakeRelay(results=[False, True])

        await _run_one_sweep(
            keydb, relay, _cfg(retry_idle_ms=10), wait_for_calls=2, timeout=5.0
        )

        assert len(relay.calls) == 2
        assert relay.calls[0] == (555, "flaky relay", None, 0)
        assert relay.calls[1] == (555, "flaky relay", None, 0)
        # 2-й вызов успешен → запись наконец XACKed
        assert await _pending_count(client) == 0
    finally:
        await client.delete(DLQ_STREAM)
        await client.aclose()


# ---------------------------------------------------------------------------
# [F] Poison-запись (conversionId<=0) дропается без вызова relay
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_consumer_drops_poison_conversion_id_without_calling_relay():
    client, keydb = await _new_real_kv()
    try:
        await client.delete(DLQ_STREAM)  # stale entries from other suites would be read first
        payload = {
            "conversionId": 0,
            "state": "failed",
            "reason": "corrupt entry",
            "originalStream": "conv.testdlq",
            "originalEntryId": "0-0",
        }
        await client.xadd(DLQ_STREAM, {"data": json.dumps(payload)})
        relay = FakeRelay(ok=True)

        stop_event = asyncio.Event()
        task = asyncio.create_task(
            run_dlq_consumer_loop(keydb, relay, _cfg(block_ms=50), stop_event=stop_event)
        )
        try:
            # Poison-drop happens inside read_dlq itself (XACK before returning to
            # the loop) — one blocking read cycle (block_ms=50) is enough; give it slack.
            await asyncio.sleep(0.3)
        finally:
            stop_event.set()
            await asyncio.wait_for(task, timeout=3.0)

        assert relay.calls == []  # poison entry never reaches relay
        assert await _pending_count(client) == 0  # dropped via XACK, not stuck in PEL
    finally:
        await client.delete(DLQ_STREAM)
        await client.aclose()
