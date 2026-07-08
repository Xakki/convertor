"""Тесты liveness ping/pong (сервер) + reconnect-resume PEL без reclaim (s1-05).

Серверная сторона: gateway отвечает `pong` на `ping`, не занимая кредит и не
трогая stream/ack. Reconnect того же `workerId` возобновляет его PEL через
`read_pending` (id `0`, §6.6 путь «a») — БЕЗ reclaim по WS-дисконнекту (§6.3).

`PelFakeKeyDb` моделирует PEL consumer'а: `read_new` двигает запись в pending,
`read_pending` отдаёт текущий PEL без очистки (до ack), `reclaim_stale` всегда
None (свежая запись <5 мин) — так resume может прийти ТОЛЬКО через read_pending.
"""

from __future__ import annotations

import asyncio
import inspect
import json
from contextlib import asynccontextmanager

import pytest
from websockets.asyncio.client import connect
from websockets.asyncio.server import serve

from workers.gateway import ws_server
from workers.gateway.config import Config
from workers.gateway.ws_server import WsGateway

TOKEN = "test-token"
BLOCK_MS = 20


class PelFakeKeyDb:
    """Дак-тайп KeyDbGateway с моделью PEL (для reconnect-resume)."""

    def __init__(self, new_entries=None):
        self._new = list(new_entries or [])
        self.pending: dict[str, dict] = {}  # PEL consumer'а: jobId -> job
        self.read_new_calls: list[tuple] = []
        self.reclaim_calls: list[tuple] = []
        self.read_pending_calls: list[tuple] = []
        self.meta_writes: list[tuple] = []
        self.acks: list[tuple] = []

    async def read_pending(self, stream, consumer, count=100):
        self.read_pending_calls.append((stream, consumer))
        return list(self.pending.items())

    async def reclaim_stale(self, stream, consumer):
        self.reclaim_calls.append((stream, consumer))
        return None  # свежая запись — XAUTOCLAIM ничего не отдаёт

    async def read_new(self, stream, consumer, block_ms):
        self.read_new_calls.append((stream, consumer, block_ms))
        if self._new:
            job_id, job = self._new.pop(0)
            self.pending[job_id] = job  # запись уходит в PEL до ack
            return (job_id, job)
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

    async def ack(self, stream, job_id):
        self.acks.append((stream, job_id))
        self.pending.pop(job_id, None)


def _cfg(token=TOKEN):
    return Config(
        redis_host="unused", redis_port=6379, redis_db=2, redis_password=None,
        ws_block_ms=BLOCK_MS, ws_host="localhost", ws_port=0, worker_api_token=token,
    )


@asynccontextmanager
async def _server(fake):
    gw = WsGateway(_cfg(), fake)
    async with serve(gw.handle, "localhost", 0) as server:
        yield server.sockets[0].getsockname()[1]


def _auth():
    return {"Authorization": f"Bearer {TOKEN}"}


def _ready(worker_id="w-1", worker_type="image", slots=1):
    return json.dumps({
        "type": "ready", "workerId": worker_id, "workerType": worker_type, "slots": slots,
    })


async def _recv(c, timeout=1.0):
    while True:
        frame = json.loads(await asyncio.wait_for(c.recv(), timeout))
        if frame.get("type") != "ready-ack":
            return frame


# --------------------------------------------------------------------------
# ping → pong (§4/§6.6): без кредита, без ack, без чтения stream
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_ping_gets_pong():
    fake = PelFakeKeyDb()  # нет новых записей → job не диспетчеризуется
    async with _server(fake) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready())
            await c.send(json.dumps({"type": "ping", "cpu": 0.5, "mem": 0.4, "load": 0.3}))
            reply = await _recv(c)
    assert reply == {"type": "pong"}
    assert fake.acks == []          # ping не делает XACK
    assert fake.meta_writes == []   # ping не пишет job-мету (не job)


@pytest.mark.asyncio
async def test_ping_does_not_consume_credit():
    # Кредит держит job 1-0; ping НЕ должен ни освободить, ни занять кредит →
    # job2 по-прежнему не проходит (slots=1).
    fake = PelFakeKeyDb(new_entries=[("1-0", {"conversionId": 1}), ("2-0", {"conversionId": 2})])
    async with _server(fake) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", slots=1))
            first = await _recv(c)
            assert first["jobId"] == "1-0"
            await c.send(json.dumps({"type": "ping", "cpu": 0.9}))
            assert (await _recv(c)) == {"type": "pong"}
            # кредит всё ещё занят 1-0 → job2 не приходит (ping не тронул учёт)
            with pytest.raises(asyncio.TimeoutError):
                await asyncio.wait_for(c.recv(), timeout=0.3)
    assert fake.acks == []


# --------------------------------------------------------------------------
# Reconnect того же workerId → resume PEL через read_pending, БЕЗ reclaim (§6.6 a)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_reconnect_resumes_pel_without_reclaim():
    fake = PelFakeKeyDb(new_entries=[("9-0", {"conversionId": 9, "targetFormat": "txt"})])
    async with _server(fake) as port:
        # conn1: воркер получает новую запись (уходит в PEL), затем роняет WS без ack
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c1:
            await c1.send(_ready(worker_id="ai-1", worker_type="ai", slots=1))
            first = await _recv(c1)
            assert first["jobId"] == "9-0"
        await asyncio.sleep(0.1)  # дать серверу свернуть conn1
        reclaim_after_conn1 = len(fake.reclaim_calls)
        read_new_after_conn1 = len(fake.read_new_calls)

        # conn2: тот же workerId переподключается ДО idle-порога
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c2:
            await c2.send(_ready(worker_id="ai-1", worker_type="ai", slots=1))
            resumed = await _recv(c2)
            assert resumed["jobId"] == "9-0"       # возобновлён тот же job
            with pytest.raises(asyncio.TimeoutError):  # без дубликата
                await asyncio.wait_for(c2.recv(), timeout=0.3)
        await asyncio.sleep(0.1)

    # resume пришёл через read_pending (id 0) для conn2 — тот же consumer/stream
    assert ("conv.ai", "ai-1") in fake.read_pending_calls
    # НЕТ reclaim/read_new на conn2: возобновлённая запись заняла слот → цикл
    # заблокирован на acquire_slot. Reclaim по WS-дисконнекту НЕ добавлялся (§6.3).
    assert len(fake.reclaim_calls) == reclaim_after_conn1
    assert len(fake.read_new_calls) == read_new_after_conn1
    assert fake.acks == []  # запись всё ещё pending (не потеряна, не задвоена)


# --------------------------------------------------------------------------
# Гвардия исходника: reclaim не триггерится событием WS-дисконнекта (§6.3)
# --------------------------------------------------------------------------

def test_no_reclaim_triggered_by_ws_disconnect():
    src = inspect.getsource(ws_server)
    # единственный call reclaim_stale — в кредитном цикле _dispatch
    assert src.count(".reclaim_stale(") == 1
    # ни teardown-хендлер, ни reader/ping не зовут reclaim
    assert "reclaim" not in inspect.getsource(WsGateway.handle)
    assert "reclaim" not in inspect.getsource(WsGateway._read_frames)
    assert "reclaim" not in inspect.getsource(WsGateway._handle_ping)
