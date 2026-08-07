"""Тесты result/fail-роутинга + internal-relay + XACK-after-persist (s1-04, §5).

Фейковый KeyDb (детерминизм) + реальный WS-сервер на эфемерном порту + фейковый
воркер (`websockets`-клиент). Relay в Symfony замокан через `httpx.MockTransport`
(RelayClient сериализует по-настоящему — ловим точную форму тела и заголовки).

Проверяем инвариант §5: `XACK` делает ТОЛЬКО gateway и ТОЛЬКО после подтверждённого
persist (inline/fail — 2xx от relay; large — на доверии). Кредит освобождается
после ack → следующий `XREADGROUP`.
"""

from __future__ import annotations

import asyncio
import base64
import json
from contextlib import asynccontextmanager

import httpx
import pytest
from websockets.asyncio.client import connect
from websockets.asyncio.server import serve

from workers.gateway.config import Config
from workers.gateway.relay import RelayClient
from workers.gateway.ws_server import WsGateway

TOKEN = "test-token"
INTERNAL_TOKEN = "internal-tok"
BLOCK_MS = 50
BASE_URL = "http://symfony-test"


class FakeKeyDb:
    """Дак-тайп замена KeyDbGateway: скриптованные записи + журнал вызовов/ack."""

    def __init__(self, *, new_entries=None, pending_entries=None, times_delivered=1,
                 job_meta_attempt=0):
        self._new = list(new_entries or [])
        self._pending = list(pending_entries or [])
        self._times_delivered = times_delivered
        self._job_meta_attempt = job_meta_attempt
        self.read_new_calls: list[tuple] = []
        self.meta_writes: list[tuple] = []
        self.acks: list[tuple] = []
        self.dlq_writes: list[dict] = []
        self.status_clears: list[int] = []

    async def read_pending(self, stream, consumer, count=100):
        out, self._pending = self._pending, []
        return out

    async def reclaim_stale(self, stream, consumer):
        return None

    async def read_new(self, stream, consumer, block_ms):
        self.read_new_calls.append((stream, consumer, block_ms))
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
        self.status_clears.append(conv_id)

    async def get_job_meta(self, job_id):
        return {"conversionId": 1, "inputBucket": "", "inputKey": "", "stream": "",
                "targetFormat": "", "attempt": self._job_meta_attempt}

    async def get_times_delivered(self, stream, job_id):
        return self._times_delivered

    async def add_to_dlq(self, stream, job_id, conv_id, reason, processing_ms=None, attempt=0):
        self.dlq_writes.append({
            "stream": stream, "jobId": job_id, "convId": conv_id, "reason": reason,
            "processingMs": processing_ms, "attempt": attempt,
        })

    async def ack(self, stream, job_id):
        # Зеркалит реальный ack: XACK + DEL. Идемпотентность проверяем на уровне
        # gateway (дубликат не доходит сюда второй раз).
        self.acks.append((stream, job_id))


class RelayRecorder:
    """MockTransport-обработчик: пишет запросы, отдаёт настраиваемый статус."""

    def __init__(self, status=200):
        self.status = status
        self.requests: list[dict] = []

    def handler(self, request: httpx.Request) -> httpx.Response:
        self.requests.append({
            "path": request.url.path,
            "auth": request.headers.get("Authorization"),
            "body": json.loads(request.content.decode("utf-8")),
        })
        return httpx.Response(self.status)


def _cfg(inline_max=262144):
    return Config(
        redis_host="unused", redis_port=6379, redis_db=2, redis_password=None,
        ws_block_ms=BLOCK_MS, ws_host="localhost", ws_port=0, worker_api_token=TOKEN,
        ws_result_inline_max=inline_max, gateway_internal_token=INTERNAL_TOKEN,
        symfony_internal_url=BASE_URL,
    )


def _relay(recorder: RelayRecorder) -> RelayClient:
    client = httpx.AsyncClient(transport=httpx.MockTransport(recorder.handler))
    return RelayClient(BASE_URL, INTERNAL_TOKEN, client=client)


@asynccontextmanager
async def _server(fake, recorder, inline_max=262144):
    gw = WsGateway(_cfg(inline_max), fake, relay=_relay(recorder))
    async with serve(gw.handle, "localhost", 0) as server:
        yield server.sockets[0].getsockname()[1]


def _auth():
    return {"Authorization": f"Bearer {TOKEN}"}


def _ready(worker_id="w-1", worker_type="image", slots=1):
    return json.dumps({
        "type": "ready", "workerId": worker_id, "workerType": worker_type, "slots": slots,
    })


async def _recv_job(c):
    while True:
        frame = json.loads(await asyncio.wait_for(c.recv(), timeout=1.0))
        if frame.get("type") != "ready-ack":
            return frame


def _b64(nbytes: int) -> str:
    return base64.b64encode(b"x" * nbytes).decode("ascii")


# --------------------------------------------------------------------------
# inline result → relay → ack (only on 2xx) → credit released → next XREADGROUP
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_inline_result_relayed_then_acked_and_credit_released():
    payload = _b64(10)
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    job2 = {"conversionId": 2, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1), ("2-0", job2)])
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            first = await _recv_job(c)
            assert first["jobId"] == "1-0"
            # результат по job1 → relay result → ack → кредит освобождён
            await c.send(json.dumps({
                "type": "result", "jobId": "1-0", "inline": payload,
                "mime": "text/plain", "processingMs": 42,
            }))
            # следующий XREADGROUP выдаёт job2 (доказывает release кредита)
            second = await _recv_job(c)
            assert second["jobId"] == "2-0"

    # relay вызван на /result с точной формой тела + bearer internal-токена
    assert len(rec.requests) == 1
    r = rec.requests[0]
    assert r["path"] == "/api/v1/internal/worker/result"
    assert r["auth"] == f"Bearer {INTERNAL_TOKEN}"
    assert r["body"] == {
        "jobId": "1-0", "data": payload, "mime": "text/plain", "processingMs": 42,
    }
    # ack ровно один — по job1 (XACK+DEL), stream = conv.image
    assert fake.acks == [("conv.image", "1-0")]


@pytest.mark.asyncio
async def test_inline_result_non_2xx_no_ack_but_credit_released():
    # nit#1 (no-wedge): 5xx при times_delivered≤MAX_RETRIES → retryable, НЕ ack,
    # НО кредит освобождён → диспетчер идёт к следующему `>` (job2 выдаётся).
    payload = _b64(10)
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    job2 = {"conversionId": 2, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1), ("2-0", job2)], times_delivered=1)
    rec = RelayRecorder(status=500)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            first = await _recv_job(c)
            assert first["jobId"] == "1-0"
            await c.send(json.dumps({"type": "result", "jobId": "1-0", "inline": payload}))
            # кредит освобождён (без ack) → следующий job приходит, соединение не клинит
            second = await _recv_job(c)
            assert second["jobId"] == "2-0"

    assert len(rec.requests) == 1  # relay попытались вызвать
    assert fake.acks == []          # но НЕ ack'нули — запись 1-0 остаётся pending
    assert fake.dlq_writes == []    # retryable 5xx — не DLQ


@pytest.mark.asyncio
async def test_inline_result_4xx_goes_to_dlq():
    """HTTP 400 от Symfony (пустой data и т.п.) → немедленный DLQ, без бесконечного retry."""
    payload = ""  # base64("") → Symfony 400 «data required»
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    job2 = {"conversionId": 2, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1), ("2-0", job2)], times_delivered=1)
    rec = RelayRecorder(status=400)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            first = await _recv_job(c)
            assert first["jobId"] == "1-0"
            await c.send(json.dumps({"type": "result", "jobId": "1-0", "inline": payload}))
            second = await _recv_job(c)
            assert second["jobId"] == "2-0"

    assert len(rec.requests) == 1
    assert fake.acks == []
    assert len(fake.dlq_writes) == 1
    assert "inline relay rejected HTTP 400" in fake.dlq_writes[0]["reason"]


@pytest.mark.asyncio
async def test_inline_result_5xx_max_retries_goes_to_dlq():
    """HTTP 5xx на result-path при times_delivered>MAX_RETRIES → DLQ (symmetric с fail)."""
    payload = _b64(10)
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1)], times_delivered=4)
    rec = RelayRecorder(status=503)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            await _recv_job(c)
            await c.send(json.dumps({"type": "result", "jobId": "1-0", "inline": payload}))
            await asyncio.sleep(0.1)

    assert len(rec.requests) == 1
    assert fake.acks == []
    assert len(fake.dlq_writes) == 1
    assert "inline relay failed (times_delivered=4)" in fake.dlq_writes[0]["reason"]


@pytest.mark.asyncio
async def test_post_result_returns_status_tuple():
    """post_result возвращает (ok, status) для различения 4xx vs 5xx/сеть."""
    rec = RelayRecorder(status=400)
    relay = _relay(rec)
    ok, status = await relay.post_result("1-0", _b64(5), "text/plain", 42)
    assert ok is False
    assert status == 400

    rec2 = RelayRecorder(status=200)
    relay2 = _relay(rec2)
    ok2, status2 = await relay2.post_result("1-0", _b64(5), None, None)
    assert ok2 is True
    assert status2 == 200


@pytest.mark.asyncio
async def test_post_result_network_error_status_none():
    """Сетевая ошибка relay → (False, None) — result-path трактует как retryable."""

    def _raise(request):
        raise httpx.ConnectError("boom", request=request)

    client = httpx.AsyncClient(transport=httpx.MockTransport(_raise))
    relay = RelayClient(BASE_URL, INTERNAL_TOKEN, client=client)
    ok, status = await relay.post_result("1-0", _b64(5), None, None)
    assert ok is False
    assert status is None


# --------------------------------------------------------------------------
# large (resultKey) → ack на доверии, без relay
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_large_result_trust_acked_without_relay():
    job1 = {"conversionId": 1, "targetFormat": "mp4"}
    job2 = {"conversionId": 2, "targetFormat": "mp4"}
    fake = FakeKeyDb(new_entries=[("1-0", job1), ("2-0", job2)])
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="vid-1", worker_type="video", slots=1))
            first = await _recv_job(c)
            assert first["jobId"] == "1-0"
            await c.send(json.dumps({
                "type": "result", "jobId": "1-0", "resultKey": "results/2026/07-03/1.mp4",
            }))
            second = await _recv_job(c)
            assert second["jobId"] == "2-0"

    assert rec.requests == []                    # relay НЕ вызывался
    assert fake.acks == [("conv.video", "1-0")]  # ack на доверии


# --------------------------------------------------------------------------
# fail (s1-06): retryable → no relay, no ack; permanent/max-retries → DLQ
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_fail_retryable_no_relay_no_ack_credit_released():
    """Retryable fail (times_delivered<=MAX_RETRIES, не permanent): relay НЕ вызывается,
    запись остаётся unacked (idle-reclaim подберёт), кредит освобождается (не клинит).
    Поведение изменено s1-06 (было: relay+ack)."""
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    job2 = {"conversionId": 2, "targetFormat": "txt"}
    # times_delivered=1 ≤ MAX_RETRIES=3 → retryable
    fake = FakeKeyDb(new_entries=[("1-0", job1), ("2-0", job2)], times_delivered=1)
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="d-1", worker_type="data", slots=1))
            first = await _recv_job(c)
            assert first["jobId"] == "1-0"
            await c.send(json.dumps({"type": "fail", "jobId": "1-0", "error": "transient"}))
            # Кредит освобождён → job2 диспетчеризуется (соединение не клинит)
            second = await _recv_job(c)
            assert second["jobId"] == "2-0"

    # Relay НЕ вызывался (retryable — PHP узнает через conv.dead или успешный retry)
    assert rec.requests == []
    # Запись оставлена unacked — нет ни ack, ни dlq
    assert fake.acks == []
    assert fake.dlq_writes == []


@pytest.mark.asyncio
async def test_fail_permanent_goes_to_dlq():
    """permanent:true → немедленный DLQ (conv.dead + ack), без relay."""
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1)], times_delivered=1)
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="d-1", worker_type="data", slots=1))
            await _recv_job(c)
            await c.send(json.dumps({
                "type": "fail", "jobId": "1-0", "error": "unsupported format",
                "permanent": True,
            }))
            await asyncio.sleep(0.1)

    assert rec.requests == []  # relay НЕ вызывался
    assert fake.acks == []     # ack идёт через add_to_dlq
    assert len(fake.dlq_writes) == 1
    dlq = fake.dlq_writes[0]
    assert dlq["jobId"] == "1-0"
    assert "unsupported format" in dlq["reason"]
    # requeue-attempt-generation-marker: job-мета без attempt (legacy) → дефолт 0
    assert dlq["attempt"] == 0


@pytest.mark.asyncio
async def test_fail_permanent_dlq_carries_attempt_from_job_meta():
    """attempt из job-меты (записанной write_job_meta при диспетче) протянут в
    add_to_dlq тем же путём, что и conv_id — через get_job_meta на fail-пути."""
    job1 = {"conversionId": 1, "targetFormat": "txt", "attempt": "2"}
    fake = FakeKeyDb(new_entries=[("1-0", job1)], times_delivered=1, job_meta_attempt=2)
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="d-1", worker_type="data", slots=1))
            await _recv_job(c)
            await c.send(json.dumps({
                "type": "fail", "jobId": "1-0", "error": "boom", "permanent": True,
            }))
            await asyncio.sleep(0.1)

    assert len(fake.dlq_writes) == 1
    assert fake.dlq_writes[0]["attempt"] == 2


@pytest.mark.asyncio
async def test_fail_permanent_dlq_carries_processing_ms():
    """hardening-06: processingMs из fail-фрейма протянут в add_to_dlq (не дропается)."""
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1)], times_delivered=1)
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="d-1", worker_type="data", slots=1))
            await _recv_job(c)
            await c.send(json.dumps({
                "type": "fail", "jobId": "1-0", "error": "boom",
                "permanent": True, "processingMs": 555,
            }))
            await asyncio.sleep(0.1)

    assert len(fake.dlq_writes) == 1
    assert fake.dlq_writes[0]["processingMs"] == 555


@pytest.mark.asyncio
async def test_fail_dlq_processing_ms_null_when_absent():
    """Без processingMs в fail-фрейме add_to_dlq получает None (null-shape)."""
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1)], times_delivered=1)
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="d-1", worker_type="data", slots=1))
            await _recv_job(c)
            await c.send(json.dumps({
                "type": "fail", "jobId": "1-0", "error": "boom", "permanent": True,
            }))
            await asyncio.sleep(0.1)

    assert len(fake.dlq_writes) == 1
    assert fake.dlq_writes[0]["processingMs"] is None


@pytest.mark.asyncio
async def test_fail_max_retries_exceeded_goes_to_dlq():
    """times_delivered > MAX_RETRIES → DLQ (conv.dead), без relay, кредит освобождён."""
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    # times_delivered=4 > MAX_RETRIES=3 → DLQ
    fake = FakeKeyDb(new_entries=[("1-0", job1)], times_delivered=4)
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="d-1", worker_type="data", slots=1))
            await _recv_job(c)
            await c.send(json.dumps({"type": "fail", "jobId": "1-0", "error": "timeout"}))
            await asyncio.sleep(0.1)

    assert rec.requests == []
    assert fake.acks == []
    assert len(fake.dlq_writes) == 1
    dlq = fake.dlq_writes[0]
    assert "timeout" in dlq["reason"]
    assert "times_delivered=4" in dlq["reason"]


# --------------------------------------------------------------------------
# RelayClient.post_fail — payload shape (processingMs), тестируется напрямую:
# ws_server НЕ вызывает post_fail ни в одной ветке (см. тесты выше — rec.requests
# == [] на всех fail-сценариях; fail всегда идёт либо retryable-pending, либо
# в DLQ — post_fail остаётся невостребованным путём gateway, отдельным от DLQ-
# consumer'а, который зовёт post_dlq_fail, см. test_gateway_dlq_consumer.py).
# Контракт формы тела фиксируем здесь напрямую — тот же null-shape, что и у
# post_result (mime/processingMs).
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_post_fail_includes_processing_ms():
    rec = RelayRecorder(status=200)
    relay = _relay(rec)
    ok = await relay.post_fail("1-0", "boom", processing_ms=789)
    assert ok is True
    assert rec.requests[0]["body"] == {"jobId": "1-0", "error": "boom", "processingMs": 789}


@pytest.mark.asyncio
async def test_post_fail_processing_ms_null_when_absent():
    rec = RelayRecorder(status=200)
    relay = _relay(rec)
    await relay.post_fail("1-0", "boom")
    assert rec.requests[0]["body"] == {"jobId": "1-0", "error": "boom", "processingMs": None}


# --------------------------------------------------------------------------
# RelayClient.post_dlq_fail — DLQ-финализация (conv-dead-no-consumer): body
# shape + 2xx/4xx/5xx dispositions (dlq_consumer.py relies on this to decide
# ack vs leave-unacked-for-retry).
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_post_dlq_fail_body_shape_and_path():
    rec = RelayRecorder(status=200)
    relay = _relay(rec)
    ok = await relay.post_dlq_fail(42, "worker exploded", processing_ms=999, attempt=3)
    assert ok is True
    assert rec.requests[0]["path"] == "/api/v1/internal/worker/dlq-fail"
    assert rec.requests[0]["auth"] == f"Bearer {INTERNAL_TOKEN}"
    assert rec.requests[0]["body"] == {
        "conversionId": 42, "reason": "worker exploded", "processingMs": 999,
        "attempt": 3,
    }


@pytest.mark.asyncio
async def test_post_dlq_fail_processing_ms_null_when_absent():
    rec = RelayRecorder(status=200)
    relay = _relay(rec)
    await relay.post_dlq_fail(42, "worker exploded")
    assert rec.requests[0]["body"] == {
        "conversionId": 42, "reason": "worker exploded", "processingMs": None,
        "attempt": None,
    }


@pytest.mark.asyncio
async def test_post_dlq_fail_400_and_404_are_terminal_true():
    """400 (bad conversionId) / 404 (Conversion not found) от InternalWorkerController::
    dlqFail — retry не поможет (тот же запрос даст тот же ответ навсегда) → ack (True)."""
    for status in (400, 404):
        rec = RelayRecorder(status=status)
        relay = _relay(rec)
        ok = await relay.post_dlq_fail(42, "boom")
        assert ok is True, f"status={status} should be treated as terminal (ack)"


@pytest.mark.asyncio
async def test_post_dlq_fail_auth_and_other_4xx_are_retryable_false():
    """401/403 (GATEWAY_INTERNAL_TOKEN мисконфиг на firewall internal_api) и
    408/429 — ВСЁ ЕЩЁ retryable, НЕ terminal. Узкий whitelist {400,404} — намеренно:
    трактовать произвольный 4xx как terminal значило бы тихо ack'ать (терять) DLQ-
    записи при протухшем/неверном токене — хуже исходного бага (conv.dead без
    потребителя вообще не терял записи, только не финализировал их)."""
    for status in (401, 403, 408, 429):
        rec = RelayRecorder(status=status)
        relay = _relay(rec)
        ok = await relay.post_dlq_fail(42, "boom")
        assert ok is False, f"status={status} must stay retryable (not ack)"


@pytest.mark.asyncio
async def test_post_dlq_fail_5xx_is_retryable_false():
    """5xx (Symfony down/erroring) — transient → НЕ ack, dlq_consumer оставит unacked."""
    rec = RelayRecorder(status=503)
    relay = _relay(rec)
    ok = await relay.post_dlq_fail(42, "boom")
    assert ok is False


@pytest.mark.asyncio
async def test_post_dlq_fail_network_error_is_retryable_false():
    """Сетевая ошибка (нет ответа вообще) — тоже retryable, не terminal."""
    def _raise(request):
        raise httpx.ConnectError("boom", request=request)

    client = httpx.AsyncClient(transport=httpx.MockTransport(_raise))
    relay = RelayClient(BASE_URL, INTERNAL_TOKEN, client=client)
    ok = await relay.post_dlq_fail(42, "boom")
    assert ok is False


# --------------------------------------------------------------------------
# post_expire (CNV-71-03): accepted-but-never-claimed timeout → /expire
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_post_expire_body_shape_and_path():
    rec = RelayRecorder(status=200)
    relay = _relay(rec)
    ok, status = await relay.post_expire(42, "worker_timeout")
    assert ok is True
    assert status == 200
    assert rec.requests[0]["path"] == "/api/v1/internal/worker/expire"
    assert rec.requests[0]["auth"] == f"Bearer {INTERNAL_TOKEN}"
    assert rec.requests[0]["body"] == {"conversionId": 42, "reason": "worker_timeout"}


@pytest.mark.asyncio
async def test_post_expire_404_is_terminal_true(caplog):
    """404 ("Conversion not found" — строка удалена раньше, чем задачу кто-то
    забрал) — узкий terminal-whitelist (только 404, НЕ 400, в отличие от
    post_dlq_fail's {400,404}): отмечать нечего, повтор даст тот же 404
    навсегда → ok=True (ack-worthy), WARNING залогирован внутри relay.py."""
    rec = RelayRecorder(status=404)
    relay = _relay(rec)
    with caplog.at_level("WARNING", logger="workers.gateway.relay"):
        ok, status = await relay.post_expire(42, "worker_timeout")
    assert ok is True
    assert status == 404
    assert any(
        "expire relay 404" in r.message and "not retrying" in r.message
        for r in caplog.records
    )


@pytest.mark.asyncio
async def test_post_expire_400_is_retryable_false():
    """400 НЕ в whitelist'е (в отличие от post_dlq_fail) — здесь это баг самого
    запроса gateway, не факт об удалённой Conversion; не должен тихо ack'аться."""
    rec = RelayRecorder(status=400)
    relay = _relay(rec)
    ok, status = await relay.post_expire(42, "worker_timeout")
    assert ok is False
    assert status == 400


@pytest.mark.asyncio
async def test_post_expire_5xx_is_retryable_false():
    rec = RelayRecorder(status=503)
    relay = _relay(rec)
    ok, status = await relay.post_expire(42, "worker_timeout")
    assert ok is False
    assert status == 503


@pytest.mark.asyncio
async def test_post_expire_network_error_status_none():
    def _raise(request):
        raise httpx.ConnectError("boom", request=request)

    client = httpx.AsyncClient(transport=httpx.MockTransport(_raise))
    relay = RelayClient(BASE_URL, INTERNAL_TOKEN, client=client)
    ok, status = await relay.post_expire(42, "worker_timeout")
    assert ok is False
    assert status is None


# --------------------------------------------------------------------------
# pre-relay permanent (CNV-37): malformed / oversize / decode → DLQ сразу
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_oversized_inline_goes_to_dlq():
    """Свыше порога → немедленный DLQ (permanent), без relay и без бесконечного reclaim."""
    over = _b64(101)  # декодированных 101 байт > порога 100
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    job2 = {"conversionId": 2, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1), ("2-0", job2)])
    rec = RelayRecorder(status=200)
    async with _server(fake, rec, inline_max=100) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            first = await _recv_job(c)
            assert first["jobId"] == "1-0"
            await c.send(json.dumps({"type": "result", "jobId": "1-0", "inline": over}))
            # DLQ + кредит освобождён → job2 диспетчеризуется
            second = await _recv_job(c)
            assert second["jobId"] == "2-0"

    assert rec.requests == []  # relay НЕ вызывался (отклонено до relay)
    assert fake.acks == []
    assert len(fake.dlq_writes) == 1
    assert "WS_RESULT_INLINE_MAX" in fake.dlq_writes[0]["reason"]


@pytest.mark.asyncio
async def test_invalid_base64_inline_goes_to_dlq():
    """Невалидный base64 → немедленный DLQ (decode error, permanent)."""
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    job2 = {"conversionId": 2, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1), ("2-0", job2)])
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            await _recv_job(c)
            await c.send(json.dumps({
                "type": "result", "jobId": "1-0", "inline": "!!!not-base64!!!",
            }))
            second = await _recv_job(c)
            assert second["jobId"] == "2-0"

    assert rec.requests == []
    assert fake.acks == []
    assert len(fake.dlq_writes) == 1
    assert "not valid base64" in fake.dlq_writes[0]["reason"]


@pytest.mark.asyncio
async def test_malformed_result_neither_field_goes_to_dlq():
    """result без inline и без resultKey → немедленный DLQ (malformed, permanent)."""
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    job2 = {"conversionId": 2, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1), ("2-0", job2)])
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            await _recv_job(c)
            await c.send(json.dumps({"type": "result", "jobId": "1-0"}))
            second = await _recv_job(c)
            assert second["jobId"] == "2-0"

    assert rec.requests == []
    assert fake.acks == []
    assert len(fake.dlq_writes) == 1
    assert "neither inline nor resultKey" in fake.dlq_writes[0]["reason"]


@pytest.mark.asyncio
async def test_malformed_result_both_inline_and_resultkey_goes_to_dlq():
    """inline + resultKey вместе → DLQ (malformed), НЕ trust-ack large-path (CNV-53)."""
    over = _b64(101)  # oversize inline — без dual-reject ушёл бы в large-path
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    job2 = {"conversionId": 2, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1), ("2-0", job2)])
    rec = RelayRecorder(status=200)
    async with _server(fake, rec, inline_max=100) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            await _recv_job(c)
            await c.send(json.dumps({
                "type": "result",
                "jobId": "1-0",
                "inline": over,
                "resultKey": "jobs/1-0/result",
            }))
            second = await _recv_job(c)
            assert second["jobId"] == "2-0"

    assert rec.requests == []  # relay не вызывался
    assert fake.acks == []  # trust-ack large-path НЕ сработал
    assert len(fake.dlq_writes) == 1
    assert "both inline and resultKey" in fake.dlq_writes[0]["reason"]


@pytest.mark.asyncio
async def test_malformed_result_inline_not_string_goes_to_dlq():
    """inline не строка → немедленный DLQ (malformed, permanent)."""
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    job2 = {"conversionId": 2, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1), ("2-0", job2)])
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            await _recv_job(c)
            await c.send(json.dumps({
                "type": "result", "jobId": "1-0", "inline": 12345,
            }))
            second = await _recv_job(c)
            assert second["jobId"] == "2-0"

    assert rec.requests == []
    assert fake.acks == []
    assert len(fake.dlq_writes) == 1
    assert "not a string" in fake.dlq_writes[0]["reason"]


@pytest.mark.asyncio
async def test_inline_at_limit_accepted():
    exact = _b64(100)  # ровно порог
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1)])
    rec = RelayRecorder(status=200)
    async with _server(fake, rec, inline_max=100) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            await _recv_job(c)
            await c.send(json.dumps({"type": "result", "jobId": "1-0", "inline": exact}))
            await asyncio.sleep(0.1)

    assert len(rec.requests) == 1
    # null-shape контракта: mime/processingMs присутствуют как null при отсутствии
    assert rec.requests[0]["body"] == {
        "jobId": "1-0", "data": exact, "mime": None, "processingMs": None,
    }
    assert fake.acks == [("conv.image", "1-0")]


# --------------------------------------------------------------------------
# slots≥2: возобновление раньше новых + Condition-gating N>1 (nit-test #2 s1-03)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_two_slots_resume_before_new_and_credit_gating():
    payload = _b64(10)
    pjob = {"conversionId": 9, "targetFormat": "txt"}      # возобновляемая (PEL)
    new1 = {"conversionId": 10, "targetFormat": "txt"}
    new2 = {"conversionId": 11, "targetFormat": "txt"}
    fake = FakeKeyDb(
        pending_entries=[("9-0", pjob)], new_entries=[("10-0", new1), ("11-0", new2)]
    )
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=2))
            # порядок: возобновлённая (read_pending) ПЕРЕД новой (read_new)
            assert (await _recv_job(c))["jobId"] == "9-0"
            assert (await _recv_job(c))["jobId"] == "10-0"
            # 2 слота заняты → третья (11-0) НЕ проталкивается
            with pytest.raises(asyncio.TimeoutError):
                await asyncio.wait_for(c.recv(), timeout=0.3)
            # освобождаем один кредит → gating (len<slots) пускает 11-0
            await c.send(json.dumps({"type": "result", "jobId": "9-0", "inline": payload}))
            assert (await _recv_job(c))["jobId"] == "11-0"

    assert fake.acks == [("conv.image", "9-0")]


# --------------------------------------------------------------------------
# Идемпотентность: двойной result по одному jobId → ровно один ack (carry-forward)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_double_result_same_job_acked_once():
    payload = _b64(10)
    job1 = {"conversionId": 1, "targetFormat": "txt"}
    fake = FakeKeyDb(new_entries=[("1-0", job1)])
    rec = RelayRecorder(status=200)
    async with _server(fake, rec) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(_ready(worker_id="img-1", worker_type="image", slots=1))
            await _recv_job(c)
            frame = json.dumps({"type": "result", "jobId": "1-0", "inline": payload})
            await c.send(frame)
            await c.send(frame)  # дубликат (полу-живой сокет / повтор)
            await asyncio.sleep(0.15)

    # relay и ack ровно по одному разу — второй result no-op (не в inflight)
    assert len(rec.requests) == 1
    assert fake.acks == [("conv.image", "1-0")]
