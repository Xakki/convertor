"""Тесты idle-reclaim (s1-06, §6.3) + poison-DLQ (§6.4) для WS-Gateway.

KeyDB-слой — на РЕАЛЬНОМ keydb (как в test_gateway_keydb.py).
WS-уровень — фейковый KeyDb + in-process сервер (как в test_gateway_relay.py).

Покрытие:
  [A] Seed pending entry, idle > threshold → reclaim_idle возвращает запись.
  [B] reclaim_idle НЕ возвращает ничего ниже idle-порога.
  [C] Handoff-очередь → dispatch через обычный job-путь (job-фрейм + мета).
  [D] Reclaim-цикл НЕ вызывает reclaim_stale в ws_server (source-inspection).
  [E] get_times_delivered для свежей записи = 1.
  [F] add_to_dlq: XADD conv.dead + XACK + DEL меты (форма поля `data`).
  [G] DLQ stream = conv.dead (не conv.result.dead, не другой).
"""

from __future__ import annotations

import asyncio
import inspect
import json
import uuid
from contextlib import asynccontextmanager

import pytest
from websockets.asyncio.client import connect
from websockets.asyncio.server import serve

from workers.gateway import ws_server
from workers.gateway.config import Config
from workers.gateway.keydb import (
    DLQ_STREAM,
    GROUP,
    KeyDbGateway,
    build_client,
    stream_for,
)
from workers.gateway.ws_server import WsGateway

# ---------------------------------------------------------------------------
# Helpers: real keydb
# ---------------------------------------------------------------------------

TOKEN = "test-token"

GOLDEN_JOB = {
    "conversionId": 9999,
    "sourceFormat": "pdf",
    "targetFormat": "docx",
    "inputKey": "inputs/test.pdf",
    "inputBucket": "convertor-inputs",
}


async def _new_real_kv():
    from workers.gateway.config import load_config
    client = build_client(load_config())
    return client, KeyDbGateway(client)


def _unique_type() -> str:
    return "testrdq_" + uuid.uuid4().hex[:10]


async def _pending_count(client, stream: str) -> int:
    res = await client.xpending(stream, GROUP)
    return int(res["pending"])


async def _cleanup(client, stream: str) -> None:
    await client.delete(stream)


# ---------------------------------------------------------------------------
# [A] reclaim_idle возвращает stale-запись
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_reclaim_idle_returns_stale_entry():
    """XAUTOCLAIM с min_idle=1ms возвращает запись, простаивающую >1ms в PEL."""
    client, gw = await _new_real_kv()
    stream = stream_for(_unique_type())
    consumer = "rtest-consumer"
    try:
        job = dict(GOLDEN_JOB)
        await client.xadd(stream, {"message": json.dumps(job)})
        # Claim → запись переходит в PEL consumer'а
        assert await gw.read_new(stream, consumer, block_ms=2000) is not None
        # Ждём >1ms, чтобы idle-счётчик накопился
        await asyncio.sleep(0.02)
        # reclaim_idle с 1ms порогом → должен вернуть запись
        entries = await gw.reclaim_idle(stream, "gw-reclaim", min_idle_ms=1, count=10)
        assert len(entries) == 1
        job_id, decoded = entries[0]
        assert decoded["conversionId"] == job["conversionId"]
    finally:
        await _cleanup(client, stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# [A2] gw.ack после reclaim_idle очищает PEL группы (group-scoped XACK)
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_ack_after_reclaim_clears_group_pel():
    """XACK по jobId освобождает PEL группы независимо от owning consumer.

    Сценарий: запись claimed worker'ом → reclaim_idle переводит в gw-reclaim →
    gw.ack(stream, job_id) (group-scoped XACK) → XPENDING shows 0 pending.
    Гарантирует, что gateway-ack корректно работает для переклеймленных записей.
    """
    client, gw = await _new_real_kv()
    stream = stream_for(_unique_type())
    consumer = "rtest-orig-consumer"
    try:
        job = dict(GOLDEN_JOB)
        await client.xadd(stream, {"message": json.dumps(job)})
        result = await gw.read_new(stream, consumer, block_ms=2000)
        assert result is not None
        job_id, _ = result

        # Переклеймить в gw-reclaim
        await asyncio.sleep(0.02)
        entries = await gw.reclaim_idle(stream, "gw-reclaim", min_idle_ms=1, count=10)
        assert len(entries) == 1

        # Убедиться, что запись pending под gw-reclaim
        pending_before = await client.xpending(stream, GROUP)
        assert int(pending_before["pending"]) == 1

        # XACK group-scoped: освобождает PEL независимо от owning consumer
        await gw.ack(stream, job_id)

        pending_after = await client.xpending(stream, GROUP)
        assert int(pending_after["pending"]) == 0
    finally:
        await _cleanup(client, stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# [B] reclaim_idle молчит ниже threshold
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_reclaim_idle_silent_below_threshold():
    """reclaim_idle с порогом 999 999ms НЕ трогает свежую pending-запись."""
    client, gw = await _new_real_kv()
    stream = stream_for(_unique_type())
    consumer = "rtest-consumer-b"
    try:
        job = dict(GOLDEN_JOB)
        await client.xadd(stream, {"message": json.dumps(job)})
        assert await gw.read_new(stream, consumer, block_ms=2000) is not None
        # Без sleep — запись только что в PEL (idle ≈ 0ms << 999 999ms)
        entries = await gw.reclaim_idle(stream, "gw-reclaim", min_idle_ms=999_999, count=10)
        assert entries == []
    finally:
        await _cleanup(client, stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# [E] get_times_delivered для свежей записи = 1
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_get_times_delivered_fresh_is_1():
    """Только что claimed запись имеет times_delivered=1 (первый кредит)."""
    client, gw = await _new_real_kv()
    stream = stream_for(_unique_type())
    consumer = "rtest-td"
    try:
        await client.xadd(stream, {"message": json.dumps(GOLDEN_JOB)})
        result = await gw.read_new(stream, consumer, block_ms=2000)
        assert result is not None
        job_id, _ = result
        td = await gw.get_times_delivered(stream, job_id)
        assert td == 1
    finally:
        await _cleanup(client, stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# [F] add_to_dlq: форма записи + XACK
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_add_to_dlq_shape_and_acks():
    """add_to_dlq: запись в conv.dead с правильной формой data; оригинал acked."""
    client, gw = await _new_real_kv()
    stream = stream_for(_unique_type())
    consumer = "rtest-dlq"
    try:
        await client.xadd(stream, {"message": json.dumps(GOLDEN_JOB)})
        result = await gw.read_new(stream, consumer, block_ms=2000)
        assert result is not None
        job_id, decoded = result

        dlq_len_before = await client.xlen(DLQ_STREAM)
        await gw.add_to_dlq(stream, job_id, decoded["conversionId"], "unit test reason")
        dlq_len_after = await client.xlen(DLQ_STREAM)

        # Одна запись добавлена в conv.dead
        assert dlq_len_after == dlq_len_before + 1
        # Оригинал acked → PEL пуст
        assert await _pending_count(client, stream) == 0

        # Форма поля `data` (gateway DLQ shape — queue-streams.md §4)
        recent = await client.xrevrange(DLQ_STREAM, count=1)
        data = json.loads(recent[0][1]["data"])
        assert data["conversionId"] == decoded["conversionId"]
        assert data["reason"] == "unit test reason"
        assert data["originalStream"] == stream
        assert data["originalEntryId"] == job_id
        assert data["state"] == "failed"
    finally:
        await _cleanup(client, stream)
        await client.aclose()


# ---------------------------------------------------------------------------
# [G] DLQ stream constant = "conv.dead"
# ---------------------------------------------------------------------------

def test_dlq_stream_is_conv_dead():
    """DLQ_STREAM === 'conv.dead'; ни один файл gateway не использует conv.result.dead."""
    assert DLQ_STREAM == "conv.dead"

    import workers.gateway.keydb as _keydb
    import workers.gateway.reclaim as _reclaim
    import workers.gateway.ws_server as _ws
    src = (
        inspect.getsource(_keydb)
        + inspect.getsource(_ws)
        + inspect.getsource(_reclaim)
    )
    assert "conv.result.dead" not in src


# ---------------------------------------------------------------------------
# Fake KeyDB для WS-уровневых тестов
# ---------------------------------------------------------------------------

class FakeKeyDbDlq:
    """FakeKeyDb с поддержкой DLQ-методов для WS-уровневых тестов."""

    def __init__(self, *, new_entries=None, times_delivered=1):
        self._new = list(new_entries or [])
        self._times_delivered = times_delivered
        self.dlq_writes: list[dict] = []
        self.acks: list[tuple] = []
        self.meta_writes: list[tuple] = []

    async def read_pending(self, stream, consumer, count=100):
        return []

    async def reclaim_stale(self, stream, consumer):
        return None

    async def read_new(self, stream, consumer, block_ms):
        if self._new:
            return self._new.pop(0)
        await asyncio.sleep(0.02)
        return None

    async def write_job_meta(self, job_id, job, stream):
        self.meta_writes.append((job_id, job, stream))

    async def set_status_processing(self, job_id, job, worker="gw-reclaim"):
        pass

    async def update_status_progress(self, conv_id, percent, stage=None):
        pass

    async def clear_status(self, conv_id):
        pass

    async def get_job_meta(self, job_id):
        return {"conversionId": 9999, "inputBucket": "", "inputKey": "",
                "stream": "", "targetFormat": ""}

    async def get_times_delivered(self, stream, job_id):
        return self._times_delivered

    async def add_to_dlq(self, stream, job_id, conv_id, reason):
        self.dlq_writes.append({
            "stream": stream, "jobId": job_id, "convId": conv_id, "reason": reason,
        })

    async def ack(self, stream, job_id):
        self.acks.append((stream, job_id))


def _cfg():
    return Config(
        redis_host="unused", redis_port=6379, redis_db=2, redis_password=None,
        ws_block_ms=50, ws_host="localhost", ws_port=0, worker_api_token=TOKEN,
    )


@asynccontextmanager
async def _server_with_gw(fake):
    gw = WsGateway(_cfg(), fake)
    async with serve(gw.handle, "localhost", 0) as server:
        yield gw, server.sockets[0].getsockname()[1]


def _auth():
    return {"Authorization": f"Bearer {TOKEN}"}


def _ready(worker_id="w-1", worker_type="image", slots=1):
    return json.dumps({
        "type": "ready", "workerId": worker_id, "workerType": worker_type, "slots": slots,
    })


async def _recv_job(c, timeout=1.5):
    while True:
        frame = json.loads(await asyncio.wait_for(c.recv(), timeout=timeout))
        if frame.get("type") != "ready-ack":
            return frame


# ---------------------------------------------------------------------------
# [C] Handoff → dispatch через нормальный job-путь
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_handoff_entry_dispatched_via_job_path():
    """Запись в handoff-очереди диспетчеризуется через обычный путь: job-фрейм + мета."""
    job = {"conversionId": 42, "sourceFormat": "png", "targetFormat": "jpg",
           "inputBucket": "b", "inputKey": "k"}
    fake = FakeKeyDbDlq()

    async with _server_with_gw(fake) as (gw, port):
        # Кладём запись напрямую в handoff-очередь (имитация reclaim-цикла)
        gw.get_handoff_queues()["image"].put_nowait(("42-0", job))

        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            frame = await _recv_job(c)

    assert frame["type"] == "job"
    assert frame["jobId"] == "42-0"
    assert frame["conversionId"] == 42
    # write_job_meta вызывался → обычный dispatch-путь
    assert fake.meta_writes
    assert fake.meta_writes[0][0] == "42-0"
    # Запись не acked (pending до завершения task'а воркером)
    assert fake.acks == []
    # DLQ не трогался
    assert fake.dlq_writes == []


# ---------------------------------------------------------------------------
# [D] Reclaim-цикл не вызывает reclaim_stale в ws_server (source-inspection)
# ---------------------------------------------------------------------------

def test_no_additional_reclaim_stale_in_ws_server():
    """Добавление handoff не увеличивает число .reclaim_stale( в ws_server."""
    src = inspect.getsource(ws_server)
    # Ровно один вызов: в _dispatch (nit #1 из s1-02, не нарушен s1-06).
    assert src.count(".reclaim_stale(") == 1
