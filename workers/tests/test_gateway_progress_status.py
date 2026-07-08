"""Тесты s1-07 — progress-фрейм + gateway-owned conv:status.

Владелец живого статуса `conv:status:{conversionId}` — ТОЛЬКО gateway (D5/§4):
воркеры KeyDB не трогают. Покрытие:
  [A] keydb-слой (РЕАЛЬНЫЙ keydb, как test_gateway_keydb.py):
      set_status_processing → HSET state=processing + TTL;
      update_status_progress → percent/stage + refresh TTL;
      clear_status → DEL (читатель падает на строку БД);
      conversionId<=0 — no-op.
  [B] _clamp_percent — чистая функция, зажим 0..100.
  [C] WS-уровень (fake KeyDb + in-process сервер, как test_gateway_reclaim_dlq.py):
      dispatch → set_status_processing(worker=workerId);
      progress → update_status_progress (+clamp); unknown jobId → игнор;
      terminal (result / fail→DLQ) → clear_status.
  [D] source-inspection: единственный писатель conv:status — keydb-хелперы
      (ws_server пишет только через них, без прямого hset/литерала ключа).
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

from workers.gateway import keydb as keydb_mod
from workers.gateway import ws_server
from workers.gateway.config import Config
from workers.gateway.keydb import (
    CONV_STATUS_TTL_S,
    KeyDbGateway,
    build_client,
)
from workers.gateway.ws_server import WsGateway, _clamp_percent

TOKEN = "test-token"


# ---------------------------------------------------------------------------
# [A] keydb-слой на РЕАЛЬНОМ keydb
# ---------------------------------------------------------------------------

async def _new_real_kv():
    from workers.gateway.config import load_config
    client = build_client(load_config())
    return client, KeyDbGateway(client)


def _conv_key(conv_id: int) -> str:
    return f"conv:status:{conv_id}"


def _job(conv_id: int) -> dict:
    return {"conversionId": conv_id, "sourceFormat": "pdf", "targetFormat": "docx",
            "inputKey": "inputs/x.pdf", "inputBucket": "convertor-inputs"}


@pytest.mark.asyncio
async def test_set_status_processing_hset_and_ttl():
    """dispatch: HSET state=processing worker=<id> + TTL≈CONV_STATUS_TTL_S."""
    client, gw = await _new_real_kv()
    conv_id = 800_000 + int(uuid.uuid4().int % 100_000)
    key = _conv_key(conv_id)
    try:
        await client.delete(key)
        await gw.set_status_processing("1-0", _job(conv_id), worker="img-7")
        data = await client.hgetall(key)
        assert data["state"] == "processing"
        assert data["worker"] == "img-7"
        ttl = await client.ttl(key)
        assert CONV_STATUS_TTL_S - 10 < ttl <= CONV_STATUS_TTL_S
    finally:
        await client.delete(key)
        await client.aclose()


@pytest.mark.asyncio
async def test_update_status_progress_sets_fields_and_refreshes_ttl():
    """progress: HSET percent/stage; TTL восстанавливается до CONV_STATUS_TTL_S."""
    client, gw = await _new_real_kv()
    conv_id = 810_000 + int(uuid.uuid4().int % 100_000)
    key = _conv_key(conv_id)
    try:
        await client.delete(key)
        await gw.set_status_processing("1-0", _job(conv_id), worker="img-7")
        await client.expire(key, 5)  # искусственно уронить TTL
        await gw.update_status_progress(conv_id, 42, "extract")
        data = await client.hgetall(key)
        assert data["state"] == "processing"
        assert data["percent"] == "42"
        assert data["stage"] == "extract"
        ttl = await client.ttl(key)
        assert ttl > 5  # TTL освежён (не остался 5)
        assert ttl > CONV_STATUS_TTL_S - 10
    finally:
        await client.delete(key)
        await client.aclose()


@pytest.mark.asyncio
async def test_update_status_progress_no_stage_omits_field():
    """stage=None → поле stage не пишется (percent — есть)."""
    client, gw = await _new_real_kv()
    conv_id = 815_000 + int(uuid.uuid4().int % 100_000)
    key = _conv_key(conv_id)
    try:
        await client.delete(key)
        await gw.update_status_progress(conv_id, 10, None)
        data = await client.hgetall(key)
        assert data["percent"] == "10"
        assert "stage" not in data
    finally:
        await client.delete(key)
        await client.aclose()


@pytest.mark.asyncio
async def test_clear_status_deletes_hash():
    """terminal: DEL conv:status → hgetall пуст (читатель падает на строку БД)."""
    client, gw = await _new_real_kv()
    conv_id = 820_000 + int(uuid.uuid4().int % 100_000)
    key = _conv_key(conv_id)
    try:
        await gw.set_status_processing("1-0", _job(conv_id))
        assert await client.hgetall(key) != {}
        await gw.clear_status(conv_id)
        assert await client.hgetall(key) == {}
    finally:
        await client.delete(key)
        await client.aclose()


@pytest.mark.asyncio
async def test_status_helpers_noop_for_nonpositive_conv_id():
    """conversionId<=0 → ни один хелпер не создаёт ключа."""
    client, gw = await _new_real_kv()
    try:
        await gw.set_status_processing("1-0", _job(0))
        await gw.update_status_progress(0, 50, "x")
        await gw.clear_status(-3)
        assert await client.exists(_conv_key(0)) == 0
        assert await client.exists(_conv_key(-3)) == 0
    finally:
        await client.aclose()


# ---------------------------------------------------------------------------
# [B] _clamp_percent — чистая функция
# ---------------------------------------------------------------------------

@pytest.mark.parametrize("raw,expected", [
    (-5, 0), (0, 0), (50, 50), (100, 100), (150, 100),
    ("50", 50), (3.9, 4), (None, 0), ("abc", 0), ([], 0),
])
def test_clamp_percent(raw, expected):
    assert _clamp_percent(raw) == expected


# ---------------------------------------------------------------------------
# [C] WS-уровень: fake KeyDb + in-process сервер
# ---------------------------------------------------------------------------

class FakeKeyDbStatus:
    """Дак-тайп KeyDbGateway: один job на read_new + журнал conv:status-вызовов."""

    def __init__(self, *, job=None, times_delivered=1, dlq_raises=False):
        self._new = [job] if job is not None else []
        self._times_delivered = times_delivered
        self._dlq_raises = dlq_raises
        self.status_writes: list[tuple] = []
        self.status_progress: list[tuple] = []
        self.status_clears: list[int] = []
        self.acks: list[tuple] = []
        self.dlq_writes: list[dict] = []
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
        self.status_writes.append((job_id, int(job.get("conversionId", 0) or 0), worker))

    async def update_status_progress(self, conv_id, percent, stage=None):
        self.status_progress.append((conv_id, percent, stage))

    async def clear_status(self, conv_id):
        self.status_clears.append(conv_id)

    async def get_job_meta(self, job_id):
        return {"conversionId": 777, "inputBucket": "", "inputKey": "",
                "stream": "", "targetFormat": ""}

    async def get_times_delivered(self, stream, job_id):
        return self._times_delivered

    async def add_to_dlq(self, stream, job_id, conv_id, reason):
        if self._dlq_raises:
            raise RuntimeError("simulated DLQ write failure")
        self.dlq_writes.append({"jobId": job_id, "convId": conv_id, "reason": reason})

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


def _ready(worker_id="img-1", worker_type="image", slots=1):
    return json.dumps({"type": "ready", "workerId": worker_id,
                       "workerType": worker_type, "slots": slots})


async def _recv_job(c, timeout=1.5):
    while True:
        frame = json.loads(await asyncio.wait_for(c.recv(), timeout=timeout))
        if frame.get("type") != "ready-ack":
            return frame


_JOB = {"conversionId": 777, "sourceFormat": "png", "targetFormat": "jpg",
        "inputBucket": "b", "inputKey": "k"}


@pytest.mark.asyncio
async def test_dispatch_writes_conv_status_processing():
    """dispatch → set_status_processing(convId, worker=workerId), НЕ gw-reclaim."""
    fake = FakeKeyDbStatus(job=("777-0", dict(_JOB)))
    async with _server_with_gw(fake) as (gw, port):
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1"))
            frame = await _recv_job(c)
            assert frame["jobId"] == "777-0"
    assert fake.status_writes
    job_id, conv_id, worker = fake.status_writes[0]
    assert job_id == "777-0"
    assert conv_id == 777
    assert worker == "img-1"  # обычный dispatch → workerId, не gw-reclaim


@pytest.mark.asyncio
async def test_progress_frame_updates_conv_status():
    """progress{percent,stage} между job и терминалом → update_status_progress."""
    fake = FakeKeyDbStatus(job=("777-0", dict(_JOB)))
    async with _server_with_gw(fake) as (gw, port):
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1"))
            await _recv_job(c)
            await c.send(json.dumps({"type": "progress", "jobId": "777-0",
                                     "percent": 55, "stage": "encode"}))
            await asyncio.sleep(0.1)
    assert fake.status_progress == [(777, 55, "encode")]


@pytest.mark.asyncio
async def test_progress_percent_clamped():
    """percent=150 → зажат до 100 при записи conv:status."""
    fake = FakeKeyDbStatus(job=("777-0", dict(_JOB)))
    async with _server_with_gw(fake) as (gw, port):
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1"))
            await _recv_job(c)
            await c.send(json.dumps({"type": "progress", "jobId": "777-0",
                                     "percent": 150}))
            await asyncio.sleep(0.1)
    assert fake.status_progress == [(777, 100, None)]


@pytest.mark.asyncio
async def test_progress_unknown_job_ignored_safely():
    """progress для не-in-flight jobId → игнор: нет записи, соединение живо."""
    fake = FakeKeyDbStatus(job=("777-0", dict(_JOB)))
    async with _server_with_gw(fake) as (gw, port):
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1"))
            await _recv_job(c)
            await c.send(json.dumps({"type": "progress", "jobId": "does-not-exist",
                                     "percent": 30}))
            # ping после progress должен вернуть pong — reader не упал.
            await c.send(json.dumps({"type": "ping"}))
            pong = json.loads(await asyncio.wait_for(c.recv(), timeout=1.5))
            assert pong["type"] == "pong"
    assert fake.status_progress == []


@pytest.mark.asyncio
async def test_result_terminal_clears_conv_status():
    """result (большой путь) → ack + clear_status(convId)."""
    fake = FakeKeyDbStatus(job=("777-0", dict(_JOB)))
    async with _server_with_gw(fake) as (gw, port):
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1"))
            await _recv_job(c)
            await c.send(json.dumps({"type": "result", "jobId": "777-0",
                                     "resultKey": "results/777.jpg"}))
            await asyncio.sleep(0.1)
    assert fake.acks == [("conv.image", "777-0")]
    assert fake.status_clears == [777]


@pytest.mark.asyncio
async def test_fail_permanent_dlq_clears_conv_status():
    """fail permanent → DLQ + clear_status(convId)."""
    fake = FakeKeyDbStatus(job=("777-0", dict(_JOB)))
    async with _server_with_gw(fake) as (gw, port):
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1"))
            await _recv_job(c)
            await c.send(json.dumps({"type": "fail", "jobId": "777-0",
                                     "permanent": True, "error": "bad input"}))
            await asyncio.sleep(0.1)
    assert fake.dlq_writes and fake.dlq_writes[0]["jobId"] == "777-0"
    assert fake.status_clears == [777]


@pytest.mark.asyncio
async def test_dlq_write_failure_keeps_conv_status_and_pending():
    """add_to_dlq упал → НЕ терминал: conv:status НЕ чистится, XACK нет (pending),
    кредит освобождён. Регрессия на at-least-once контракт add_to_dlq."""
    fake = FakeKeyDbStatus(job=("777-0", dict(_JOB)), dlq_raises=True)
    async with _server_with_gw(fake) as (gw, port):
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1"))
            await _recv_job(c)
            # dispatch уже записал conv:status=processing.
            assert fake.status_writes and fake.status_writes[0][1] == 777
            await c.send(json.dumps({"type": "fail", "jobId": "777-0",
                                     "permanent": True, "error": "boom"}))
            await asyncio.sleep(0.1)
    # DLQ-запись не прошла → запись остаётся pending (нет XACK), conv:status жив.
    assert fake.dlq_writes == []
    assert fake.status_clears == []   # НЕ вычищен — задача ещё в работе
    assert fake.acks == []            # нет XACK → запись pending для reclaim


# ---------------------------------------------------------------------------
# [D] Единственный писатель conv:status — keydb-хелперы (source-inspection)
# ---------------------------------------------------------------------------

def test_only_keydb_helpers_write_conv_status():
    """ws_server НЕ пишет conv:status напрямую — только через keydb-хелперы.

    Зеркалит test_no_additional_reclaim_stale_in_ws_server: доказывает единый
    write-путь. ws_server не содержит ни прямого `.hset(`, ни литерала ключа
    `conv:status:`; все три хелпера-писателя объявлены в keydb.py.
    """
    ws_src = inspect.getsource(ws_server)
    assert ".hset(" not in ws_src
    assert 'f"conv:status:' not in ws_src  # ключ строит только keydb._status_key

    keydb_src = inspect.getsource(keydb_mod)
    for helper in ("async def set_status_processing",
                   "async def update_status_progress",
                   "async def clear_status"):
        assert helper in keydb_src
    # Ключ conv:status строится ровно в одном месте — приватном _status_key.
    assert keydb_src.count('f"conv:status:{conv_id}"') == 1
