"""Unit-тесты WS-сервера gateway (s1-03) — фейковый KeyDB + in-process WS-клиент.

KeyDB замокан (детерминизм): фейк отдаёт заскриптованные записи и пишет журнал
вызовов. Реальный WS-сервер поднимается на эфемерном порту, фейковый воркер —
`websockets`-клиент в том же процессе. Проверяем: auth-границу (close 1008),
маршрутизацию по типу (один conv.<type>), кредитный dispatch (фрейм job + мета,
запись pending/без ack), стабильный consumer=workerId и возобновление PEL.
"""

from __future__ import annotations

import asyncio
import json
from contextlib import asynccontextmanager

import pytest
from websockets.asyncio.client import connect
from websockets.asyncio.server import serve
from websockets.exceptions import ConnectionClosed

from workers.gateway.config import Config
from workers.gateway.ws_server import WsGateway

TOKEN = "test-token"
BLOCK_MS = 50


class FakeKeyDb:
    """Дак-тайп замена KeyDbGateway: скриптованные записи + журнал вызовов."""

    def __init__(self, *, new_entries=None, pending_entries=None, stale_entries=None):
        self._new = list(new_entries or [])
        self._pending = list(pending_entries or [])
        self._stale = list(stale_entries or [])
        self.read_new_calls: list[tuple] = []
        self.reclaim_calls: list[tuple] = []
        self.read_pending_calls: list[tuple] = []
        self.meta_writes: list[tuple] = []
        self.acks: list[tuple] = []
        self.order: list[str] = []
        self.status_writes: list[tuple] = []
        self.status_progress: list[tuple] = []
        self.status_clears: list[int] = []

    async def read_pending(self, stream, consumer, count=100):
        self.read_pending_calls.append((stream, consumer))
        out, self._pending = self._pending, []
        return out

    async def reclaim_stale(self, stream, consumer):
        self.reclaim_calls.append((stream, consumer))
        self.order.append("reclaim")
        return self._stale.pop(0) if self._stale else None

    async def read_new(self, stream, consumer, block_ms):
        self.read_new_calls.append((stream, consumer, block_ms))
        self.order.append("read_new")
        if self._new:
            return self._new.pop(0)
        await asyncio.sleep(0.02)  # эмулируем BLOCK, не спамим CPU
        return None

    async def write_job_meta(self, job_id, job, stream):
        self.meta_writes.append((job_id, job, stream))

    async def set_status_processing(self, job_id, job, worker="gw-reclaim"):
        self.status_writes.append((job_id, job, worker))

    async def update_status_progress(self, conv_id, percent, stage=None):
        self.status_progress.append((conv_id, percent, stage))

    async def clear_status(self, conv_id):
        self.status_clears.append(conv_id)

    async def get_job_meta(self, job_id):
        return {"conversionId": 0, "inputBucket": "", "inputKey": "", "stream": "", "targetFormat": ""}

    async def get_times_delivered(self, stream, job_id):
        return 1  # дефолт для тестов ws-сервера (fail-путь не тестируется здесь)

    async def add_to_dlq(self, stream, job_id, conv_id, reason):
        pass  # DLQ-тесты — в test_gateway_relay.py и test_gateway_reclaim_dlq.py

    async def ack(self, stream, job_id):
        self.acks.append((stream, job_id))


def _cfg(token=TOKEN, inline_max=None):
    kwargs = dict(
        redis_host="unused", redis_port=6379, redis_db=2, redis_password=None,
        ws_block_ms=BLOCK_MS, ws_host="localhost", ws_port=0, worker_api_token=token,
    )
    if inline_max is not None:
        kwargs["ws_result_inline_max"] = inline_max
    return Config(**kwargs)


@asynccontextmanager
async def _server(fake, token=TOKEN, inline_max=None):
    gw = WsGateway(_cfg(token, inline_max), fake)
    async with serve(gw.handle, "localhost", 0) as server:
        yield server.sockets[0].getsockname()[1]


def _auth(token=TOKEN):
    return {"Authorization": f"Bearer {token}"}


def _ready(worker_id="w-1", worker_type="image", slots=1):
    return json.dumps({
        "type": "ready", "workerId": worker_id, "workerType": worker_type,
        "slots": slots, "version": "0.1.0", "cpu": 0.1, "mem": 0.2, "load": 0.3,
    })


async def _close_code(uri, headers=None):
    """Подключиться, ожидать закрытия соединения; вернуть WS close-код."""
    try:
        async with connect(uri, additional_headers=headers) as c:
            await c.recv()
    except ConnectionClosed as e:
        return e.rcvd.code if e.rcvd else None
    return None


# --------------------------------------------------------------------------
# Auth-граница (§7 a)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_missing_bearer_closes_1008_before_any_read():
    fake = FakeKeyDb()
    async with _server(fake) as port:
        code = await _close_code(f"ws://localhost:{port}")  # без Authorization
    assert code == 1008
    assert fake.read_new_calls == [] and fake.read_pending_calls == []


@pytest.mark.asyncio
async def test_invalid_bearer_closes_1008():
    fake = FakeKeyDb()
    async with _server(fake) as port:
        code = await _close_code(f"ws://localhost:{port}", _auth("wrong-token"))
    assert code == 1008
    assert fake.read_new_calls == []


# --------------------------------------------------------------------------
# Handshake / маршрутизация по типу (§6.2)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_unknown_worker_type_rejected_no_stream_read():
    fake = FakeKeyDb()
    async with _server(fake) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_type="bogus"))
            code = None
            try:
                await c.recv()
            except ConnectionClosed as e:
                code = e.rcvd.code if e.rcvd else None
    assert code == 1008
    assert fake.read_new_calls == []  # неизвестный тип → ни один stream не читался


# --------------------------------------------------------------------------
# Кредитный dispatch (§4)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_job_dispatched_routing_meta_written_stays_pending():
    job = {
        "conversionId": 123, "sourceFormat": "pdf", "targetFormat": "docx",
        "inputKey": "inputs/x.pdf", "inputBucket": "convertor-inputs",
        "model": None,
    }
    fake = FakeKeyDb(new_entries=[("11-0", job)])
    async with _server(fake) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            ack = json.loads(await c.recv())
            assert ack == {"type": "ready-ack", "inlineMax": 262144}
            frame = json.loads(await c.recv())

    # фрейм job с ожидаемыми полями
    assert frame["type"] == "job"
    assert frame["jobId"] == "11-0"
    assert frame["conversionId"] == 123
    assert frame["sourceFormat"] == "pdf"
    assert frame["targetFormat"] == "docx"
    assert frame["inputBucket"] == "convertor-inputs"

    # маршрутизация: читался ТОЛЬКО conv.image; consumer = workerId дословно (без PID)
    assert fake.read_new_calls
    assert all(s == "conv.image" for s, _c, _b in fake.read_new_calls)
    assert all(c_ == "img-1" for _s, c_, _b in fake.read_new_calls)

    # nit #1: reclaim_stale ПЕРЕД read_new
    assert fake.order[:2] == ["reclaim", "read_new"]

    # мета записана при выдаче job (jobId + stream)
    assert fake.meta_writes and fake.meta_writes[0][0] == "11-0"
    assert fake.meta_writes[0][2] == "conv.image"

    # запись остаётся pending — ack НЕ вызывался (release = s1-04)
    assert fake.acks == []


@pytest.mark.asyncio
async def test_single_slot_dispatches_only_one_job():
    jobs = [("1-0", {"conversionId": 1}), ("2-0", {"conversionId": 2})]
    fake = FakeKeyDb(new_entries=list(jobs))
    async with _server(fake) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="d-1", worker_type="data", slots=1))
            assert json.loads(await c.recv())["type"] == "ready-ack"
            first = json.loads(await c.recv())
            assert first["jobId"] == "1-0"
            # slots=1 → второй job НЕ проталкивается, пока кредит не освобождён (s1-04)
            with pytest.raises(asyncio.TimeoutError):
                await asyncio.wait_for(c.recv(), timeout=0.3)


# --------------------------------------------------------------------------
# Возобновление PEL при (пере)подключении (§6.6 путь «a»)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_pending_resumed_first_on_connect():
    pjob = {"conversionId": 55, "targetFormat": "mp4", "inputBucket": "convertor-inputs"}
    # Дополнительно кладём НОВУЮ запись: при slots=1 возобновлённая pending должна
    # занять кредит → новую читать НЕЛЬЗЯ (иначе воркер получит 2 задачи на 1 слот).
    fake = FakeKeyDb(
        pending_entries=[("9-0", pjob)], new_entries=[("10-0", {"conversionId": 56})]
    )
    async with _server(fake) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="ai-1", worker_type="ai", slots=1))
            assert json.loads(await c.recv())["type"] == "ready-ack"
            frame = json.loads(await c.recv())
            # второй фрейм не приходит — кредит занят возобновлённой задачей
            with pytest.raises(asyncio.TimeoutError):
                await asyncio.wait_for(c.recv(), timeout=0.3)

    # первый протолкнутый фрейм = возобновлённая pending-запись
    assert frame["jobId"] == "9-0"
    assert frame["conversionId"] == 55
    # read_pending под тем же consumer=workerId, stream=conv.ai
    assert fake.read_pending_calls and fake.read_pending_calls[0] == ("conv.ai", "ai-1")
    assert fake.meta_writes and fake.meta_writes[0][0] == "9-0"
    # кредит-аккаунтинг: возобновление заняло слот → read_new НЕ вызывался
    assert fake.read_new_calls == []
    assert fake.acks == []


# --------------------------------------------------------------------------
# ready-ack: gateway сообщает воркеру авторитетный порог inline (s1-08)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_handshake_sends_ready_ack_with_default_inline_max():
    fake = FakeKeyDb()
    async with _server(fake) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready())
            ack = json.loads(await c.recv())
    assert ack == {"type": "ready-ack", "inlineMax": 262144}


@pytest.mark.asyncio
async def test_handshake_ready_ack_reflects_custom_inline_max():
    fake = FakeKeyDb()
    async with _server(fake, inline_max=50000) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready())
            ack = json.loads(await c.recv())
    assert ack == {"type": "ready-ack", "inlineMax": 50000}
