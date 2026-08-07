"""Тесты expiry-sweep'а принятых, но никем не взятых задач (CNV-71-03).

KeyDB-слой — на РЕАЛЬНОМ keydb (как `test_gateway_reclaim_dlq.py`/
`test_gateway_dlq_consumer.py`). Relay — фейковый duck-type (без httpx, как
`FakeRelay` в `test_gateway_dlq_consumer.py`).

Каждый тест сидирует ОДИН уникальный стрим `conv.<random>` (не пересекается с
`WORKER_TYPES`) явным `XADD` с ПРОСТАВЛЕННЫМ id (`<ms>-<seq>`), чтобы
контролировать возраст записи напрямую (age = f(id), не payload'а — см.
`workers/gateway/expiry.py` module docstring).

Покрытие:
  [A] never-delivered + старше таймаута → expired (relay.post_expire вызван,
      затем XDEL).
  [B] never-delivered + младше таймаута → нетронуто (relay не вызван, запись
      на месте).
  [C] already-delivered (id <= last-delivered-id) → нетронуто ДАЖЕ если
      старше таймаута (детекция по last-delivered-id, не только по возрасту).
  [D] claim-race: между чтением backlog'а и действием воркер успевает
      подключиться и забрать запись → re-check ловит это, relay НЕ вызывается,
      запись не удаляется expiry-путём.
  [E] PHP не-2xx retryable (400/500) → запись НЕ удаляется, relay вызван
      (retry на следующем sweep'е).
  [E2] PHP отвечает 404 → RelayClient.post_expire трактует как terminal
      (ok=True) → та же ветка, что успешный expire: запись удалена.
  [F] Payload не парсится (`message` — не JSON / отсутствует) → relay НЕ
      вызывается, запись уходит в DLQ (conv.dead, KeyDbGateway.add_to_dlq) +
      ERROR-лог, ЗАТЕМ удаляется (XDEL) из живого стрима сразу, чтобы не
      заклинить sweep.
  [F2] conversionId<=0 в валидном JSON → тот же путь, что [F].
"""

from __future__ import annotations

import asyncio
import json
import time
import uuid

import pytest

from workers.gateway.config import Config
from workers.gateway.expiry import EXPIRE_REASON, run_expiry_loop, sweep_stream
from workers.gateway.keydb import DLQ_STREAM, GROUP, KeyDbGateway, build_client

GOLDEN_JOB = {
    "conversionId": 9999,
    "sourceFormat": "pdf",
    "targetFormat": "docx",
    "inputKey": "inputs/test.pdf",
    "inputBucket": "convertor-inputs",
}

ONE_MIN_MS = 60_000


async def _new_real_kv():
    from workers.gateway.config import load_config
    client = build_client(load_config())
    return client, KeyDbGateway(client)


def _unique_stream() -> str:
    return "conv.testexp_" + uuid.uuid4().hex[:10]


async def _xadd_at(client, stream: str, ms: int, seq: int, job: dict) -> str:
    """XADD с явным id `<ms>-<seq>` — контролируемый возраст записи."""
    entry_id = f"{ms}-{seq}"
    await client.xadd(stream, {"message": json.dumps(job)}, id=entry_id)
    return entry_id


async def _xlen(client, stream: str) -> int:
    return int(await client.xlen(stream))


class FakeRelay:
    """Duck-type relay: журнал вызовов post_expire + настраиваемый (ok, status)."""

    def __init__(self, *, ok: bool = True, status: int | None = 200) -> None:
        self.ok = ok
        self.status = status
        self.calls: list[tuple[int, str]] = []

    async def post_expire(self, conversion_id: int, reason: str):
        self.calls.append((conversion_id, reason))
        return self.ok, self.status


# ---------------------------------------------------------------------------
# [A] never-delivered + старше таймаута → expired
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_old_undelivered_entry_expired():
    client, gw = await _new_real_kv()
    stream = _unique_stream()
    try:
        now_ms = int(time.time() * 1000)
        old_id = await _xadd_at(client, stream, now_ms - 2 * ONE_MIN_MS, 1, GOLDEN_JOB)
        relay = FakeRelay(ok=True, status=200)

        await sweep_stream(gw, relay, stream, timeout_ms=ONE_MIN_MS, batch=10)

        assert relay.calls == [(GOLDEN_JOB["conversionId"], EXPIRE_REASON)]
        assert await _xlen(client, stream) == 0
        assert old_id  # id использовался (документирует форму XADD)
    finally:
        await client.delete(stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# [B] never-delivered + младше таймаута → нетронуто
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_young_undelivered_entry_untouched():
    client, gw = await _new_real_kv()
    stream = _unique_stream()
    try:
        now_ms = int(time.time() * 1000)
        # 5с назад — заметно младше минутного таймаута.
        await _xadd_at(client, stream, now_ms - 5_000, 1, GOLDEN_JOB)
        relay = FakeRelay(ok=True, status=200)

        await sweep_stream(gw, relay, stream, timeout_ms=ONE_MIN_MS, batch=10)

        assert relay.calls == []
        assert await _xlen(client, stream) == 1
    finally:
        await client.delete(stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# [C] already-delivered (id <= last-delivered-id) → нетронуто, даже старое
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_delivered_old_entry_untouched_even_if_old():
    client, gw = await _new_real_kv()
    stream = _unique_stream()
    try:
        now_ms = int(time.time() * 1000)
        old_id = await _xadd_at(client, stream, now_ms - 2 * ONE_MIN_MS, 1, GOLDEN_JOB)
        # Доставляем запись обычным путём — last-delivered-id продвигается на неё.
        delivered = await gw.read_new(stream, "some-worker", block_ms=500)
        assert delivered is not None
        assert delivered[0] == old_id

        relay = FakeRelay(ok=True, status=200)
        await sweep_stream(gw, relay, stream, timeout_ms=ONE_MIN_MS, batch=10)

        # Уже доставленная запись — территория reclaim/PEL, expiry её не видит.
        assert relay.calls == []
        pending = await client.xpending(stream, GROUP)
        assert int(pending["pending"]) == 1  # всё ещё в PEL воркера, не в backlog
    finally:
        await client.delete(stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# [D] claim-race: воркер забирает запись МЕЖДУ scan и re-check → skip
# ---------------------------------------------------------------------------


class _RaceKeyDb:
    """Duck-type-обёртка над реальным `KeyDbGateway`: на ВТОРОЙ вызов
    `get_last_delivered_id` (re-check внутри `_expire_entry`) сначала
    имитирует подключение воркера (`read_new`), закрывая гонку — сама сверка
    видит уже-продвинутый `last-delivered-id` и обязана пропустить запись."""

    def __init__(self, real: KeyDbGateway, claim_stream: str, claim_consumer: str):
        self._real = real
        self._claim_stream = claim_stream
        self._claim_consumer = claim_consumer
        self._calls = 0

    async def get_last_delivered_id(self, stream: str) -> str:
        self._calls += 1
        if self._calls == 2:
            await self._real.read_new(self._claim_stream, self._claim_consumer, block_ms=500)
        return await self._real.get_last_delivered_id(stream)

    async def scan_undelivered_backlog(self, stream: str, after_id: str, count: int):
        return await self._real.scan_undelivered_backlog(stream, after_id, count)

    async def delete_entry(self, stream: str, entry_id: str) -> None:
        await self._real.delete_entry(stream, entry_id)


@pytest.mark.asyncio
async def test_claim_race_recheck_skips_delivered_entry():
    client, gw = await _new_real_kv()
    stream = _unique_stream()
    try:
        now_ms = int(time.time() * 1000)
        await _xadd_at(client, stream, now_ms - 2 * ONE_MIN_MS, 1, GOLDEN_JOB)
        race_gw = _RaceKeyDb(gw, stream, "race-worker")
        relay = FakeRelay(ok=True, status=200)

        await sweep_stream(race_gw, relay, stream, timeout_ms=ONE_MIN_MS, batch=10)

        # Re-check застал запись уже доставленной → PHP не вызывается, XDEL не идёт.
        assert relay.calls == []
        pending = await client.xpending(stream, GROUP)
        assert int(pending["pending"]) == 1  # забрана воркером в race-окне, осталась в PEL
    finally:
        await client.delete(stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# [E] PHP не-2xx retryable → запись НЕ удаляется (retry на следующем sweep'е)
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
@pytest.mark.parametrize("status", [400, 500, None])
async def test_relay_non_2xx_leaves_entry_for_retry(status):
    """sweep_stream доверяет `ok` от relay дословно (не переинтерпретирует
    `status`) — FakeRelay(ok=False) здесь эмулирует и генерическую 4xx/5xx
    ветку `RelayClient.post_expire`, и сетевую ошибку (`status=None`, тот же
    код пути, что и любой другой `ok=False`). 404 сюда НЕ входит — с реальным
    `RelayClient` (см. test_gateway_relay.py) 404 теперь terminal (ok=True),
    покрыто отдельно в [E2]."""
    client, gw = await _new_real_kv()
    stream = _unique_stream()
    try:
        now_ms = int(time.time() * 1000)
        await _xadd_at(client, stream, now_ms - 2 * ONE_MIN_MS, 1, GOLDEN_JOB)
        relay = FakeRelay(ok=False, status=status)

        await sweep_stream(gw, relay, stream, timeout_ms=ONE_MIN_MS, batch=10)

        assert relay.calls == [(GOLDEN_JOB["conversionId"], EXPIRE_REASON)]
        assert await _xlen(client, stream) == 1  # НЕ удалена
    finally:
        await client.delete(stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# [E2] PHP 404 → RelayClient трактует terminal (ok=True) → запись удаляется
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_relay_404_ok_true_deletes_entry_like_success():
    """sweep_stream/_expire_entry не знают о статусе, только о `ok` — с
    реальным RelayClient.post_expire (см. test_gateway_relay.py) `ok=True`
    для 404 гарантирован узким terminal-whitelist'ом там; здесь фиксируем,
    что expiry.py на `ok=True` идёт по обычной ветке удаления независимо от
    того, какой статус за ним стоит."""
    client, gw = await _new_real_kv()
    stream = _unique_stream()
    try:
        now_ms = int(time.time() * 1000)
        await _xadd_at(client, stream, now_ms - 2 * ONE_MIN_MS, 1, GOLDEN_JOB)
        relay = FakeRelay(ok=True, status=404)

        await sweep_stream(gw, relay, stream, timeout_ms=ONE_MIN_MS, batch=10)

        assert relay.calls == [(GOLDEN_JOB["conversionId"], EXPIRE_REASON)]
        assert await _xlen(client, stream) == 0  # удалена, не отличается от 2xx-успеха
    finally:
        await client.delete(stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# [F] Payload не парсится → relay НЕ вызывается, запись уходит в DLQ + XDEL
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_unparseable_payload_routed_to_dlq_and_removed(caplog):
    """Review-находка CNV-71-03: раньше запись тихо XDEL'илась, Conversion-
    строка застревала в Pending навсегда. Теперь — тот же DLQ-механизм, что
    poison-записи job-стрима (`KeyDbGateway.add_to_dlq`): payload остаётся
    greppable в `conv.dead`, ERROR-лог даёт контекст (stream/entryId), ЗАТЕМ
    backlog-запись сносится из живого стрима (иначе заклинила бы sweep)."""
    client, gw = await _new_real_kv()
    stream = _unique_stream()
    try:
        now_ms = int(time.time() * 1000)
        entry_id = f"{now_ms - 2 * ONE_MIN_MS}-1"
        # Нет поля 'message' вовсе → parse_message кинет KeyError.
        await client.xadd(stream, {"junk": "not-a-job"}, id=entry_id)
        relay = FakeRelay(ok=True, status=200)

        dlq_len_before = await client.xlen(DLQ_STREAM)
        with caplog.at_level("ERROR", logger="workers.gateway.expiry"):
            await sweep_stream(gw, relay, stream, timeout_ms=ONE_MIN_MS, batch=10)

        assert relay.calls == []  # звонить в PHP нечем — conversionId неизвестен
        assert await _xlen(client, stream) == 0  # снесена, иначе заклинила бы sweep навсегда
        assert await client.xlen(DLQ_STREAM) == dlq_len_before + 1

        recent = await client.xrevrange(DLQ_STREAM, count=1)
        data = json.loads(recent[0][1]["data"])
        assert data["conversionId"] == 0  # неизвестен — closest safe equivalent
        assert data["originalStream"] == stream
        assert data["originalEntryId"] == entry_id
        assert "unparseable" in data["reason"]

        assert any("unparseable backlog entry" in r.message for r in caplog.records)
    finally:
        await client.delete(stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# [F2] conversionId<=0 в валидном JSON → тот же путь, что [F]
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_non_positive_conversion_id_routed_to_dlq_and_removed(caplog):
    client, gw = await _new_real_kv()
    stream = _unique_stream()
    try:
        now_ms = int(time.time() * 1000)
        bad_job = dict(GOLDEN_JOB, conversionId=0)
        entry_id = await _xadd_at(client, stream, now_ms - 2 * ONE_MIN_MS, 1, bad_job)
        relay = FakeRelay(ok=True, status=200)

        dlq_len_before = await client.xlen(DLQ_STREAM)
        with caplog.at_level("ERROR", logger="workers.gateway.expiry"):
            await sweep_stream(gw, relay, stream, timeout_ms=ONE_MIN_MS, batch=10)

        assert relay.calls == []
        assert await _xlen(client, stream) == 0
        assert await client.xlen(DLQ_STREAM) == dlq_len_before + 1

        recent = await client.xrevrange(DLQ_STREAM, count=1)
        data = json.loads(recent[0][1]["data"])
        assert data["conversionId"] == 0
        assert data["originalStream"] == stream
        assert data["originalEntryId"] == entry_id
        assert "no positive conversionId" in data["reason"]

        assert any(
            "no positive conversionId" in r.message for r in caplog.records
        )
    finally:
        await client.delete(stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# Loop wiring: run_expiry_loop тикает и останавливается по stop_event
# ---------------------------------------------------------------------------


class _EmptyKeyDb:
    """Duck-type: backlog всегда пуст — только проверяем, что цикл жив и
    детерминированно останавливается (без реального keydb)."""

    def __init__(self):
        self.calls = 0

    async def get_last_delivered_id(self, stream: str) -> str:
        self.calls += 1
        return "0-0"

    async def scan_undelivered_backlog(self, stream: str, after_id: str, count: int):
        return []

    async def delete_entry(self, stream: str, entry_id: str) -> None:
        pass


def _loop_cfg(interval_s: float = 0.02) -> Config:
    return Config(
        redis_host="unused", redis_port=6379, redis_db=2, redis_password=None,
        ws_block_ms=50, ws_host="localhost", ws_port=0, worker_api_token="t",
        expiry_sweep_interval_s=interval_s, worker_claim_timeout_minutes=60,
    )


@pytest.mark.asyncio
async def test_run_expiry_loop_ticks_and_stops():
    fake = _EmptyKeyDb()
    relay = FakeRelay(ok=True, status=200)
    stop_event = asyncio.Event()
    task = asyncio.create_task(
        run_expiry_loop(fake, relay, _loop_cfg(), stop_event=stop_event)
    )
    try:
        async with asyncio.timeout(3.0):
            while fake.calls < 6:  # WORKER_TYPES has 6 entries — one full sweep
                await asyncio.sleep(0.01)
    finally:
        stop_event.set()
        await asyncio.wait_for(task, timeout=3.0)

    assert fake.calls >= 6
    assert relay.calls == []  # empty backlog everywhere — never called PHP
