"""Unit-тесты общего WS-клиента воркера (s1-08) — фейковый gateway + hermetic HTTP.

Инверсия харнесса test_gateway_ws.py: здесь РЕАЛЬНЫЙ WS-сервер (фейковый gateway на
эфемерном порту) скриптует фреймы, а тестируемый `WsClient` подключается к нему как
клиент с фейковым in-process `handle_job`. HTTP к Symfony (GET input / POST large
result) замокан через httpx.MockTransport — без сети, без S3/KeyDB.

Покрытие критериев приёмки:
- connect → auth (Bearer) → ready(version/cpu/mem/load) → job → handle_job(_localInput) → completion;
- inline при ≤ WS_RESULT_INLINE_MAX (валидный base64, decoded ≤ max), иначе large (POST + resultKey);
- fail-ветка (fail{error, permanent?});
- вход ТОЛЬКО через GET /jobs/{id}/input (Bearer), без S3/KeyDB-импортов (grep источника);
- progress ~1/сек ТОЛЬКО пока задача в работе (idle → тишина);
- ping периодически; N пропущенных pong → reconnect тем же workerId + backoff;
- non-blocking: медленный (asyncio.sleep / to_thread) обработчик не морит ping/progress;
- дубликат job-фрейма в одном соединении не крашит и не дублирует обработку.
"""

from __future__ import annotations

import asyncio
import base64
import json
import time
from contextlib import asynccontextmanager, suppress
from dataclasses import replace
from pathlib import Path

import httpx
import pytest
from websockets.asyncio.server import serve

from workers.common import ws_client as ws_client_mod
from workers.common.ws_client import ProgressReporter, ResultSignal, WsClient, WsClientConfig

TOKEN = "worker-secret"


# --------------------------------------------------------------------------
# Фейковый gateway (реальный WS-сервер)
# --------------------------------------------------------------------------

class FakeGateway:
    """Скриптует фреймы и журналирует принятое. Один handler-вызов = одно соединение."""

    def __init__(
        self, *, jobs=None, answer_ping=True, send_twice=False, close_after_ready=False,
        send_ack=False, ack_inline_max=None,
    ):
        self._jobs = list(jobs or [])
        self._answer_ping = answer_ping
        self._send_twice = send_twice
        self._close_after_ready = close_after_ready
        self._send_ack = send_ack
        self._ack_inline_max = ack_inline_max  # None → 262144 дефолт
        self.readys: list[dict] = []
        self.results: list[dict] = []
        self.fails: list[dict] = []
        self.progress: list[dict] = []
        self.pings: list[dict] = []
        self.auth_headers: list[str | None] = []
        self.conn_times: list[float] = []

    async def handler(self, ws) -> None:
        self.conn_times.append(time.monotonic())
        self.auth_headers.append(ws.request.headers.get("Authorization"))
        # Первый фрейм — ready (handshake).
        ready = json.loads(await ws.recv())
        self.readys.append(ready)
        # Имитация «upgrade принят, handshake отвергнут»: закрыть сразу, НЕ прислав фреймов
        # (клиент не должен сбрасывать backoff — ни одного входящего фрейма он не увидит).
        if self._close_after_ready:
            with suppress(Exception):
                await ws.close()
            return
        # Опциональный ready-ack с inlineMax (для тестов адопции порога).
        if self._send_ack:
            inline_max = self._ack_inline_max if self._ack_inline_max is not None else 262144
            await ws.send(json.dumps({"type": "ready-ack", "inlineMax": inline_max}))
        # Протолкнуть заскриптованные задачи.
        for job in self._jobs:
            await ws.send(json.dumps(job))
            if self._send_twice:
                await ws.send(json.dumps(job))  # дубликат того же jobId
        # Читать фреймы воркера до закрытия.
        with suppress(Exception):
            async for raw in ws:
                frame = json.loads(raw)
                ftype = frame.get("type")
                if ftype == "ping":
                    self.pings.append(frame)
                    if self._answer_ping:
                        await ws.send(json.dumps({"type": "pong"}))
                elif ftype == "result":
                    self.results.append(frame)
                elif ftype == "fail":
                    self.fails.append(frame)
                elif ftype == "progress":
                    self.progress.append(frame)


# --------------------------------------------------------------------------
# Фейковый Symfony (httpx.MockTransport — hermetic)
# --------------------------------------------------------------------------

class FakeSymfony:
    """Мок GET /jobs/{id}/input и POST /jobs/{id}/result. Журналирует запросы."""

    def __init__(self, *, result_response=None):
        self.requests: list[httpx.Request] = []
        self._result_response = result_response if result_response is not None else {"ok": True}

    def _handle(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        path = request.url.path
        if request.method == "GET" and path.endswith("/input"):
            return httpx.Response(200, content=b"INPUT-BYTES")
        if request.method == "POST" and path.endswith("/result"):
            return httpx.Response(200, json=self._result_response)
        if request.method == "POST" and path.endswith("/register"):
            return httpx.Response(200, json={"ok": True})
        return httpx.Response(404, json={"error": "unexpected path"})

    def client(self) -> httpx.AsyncClient:
        return httpx.AsyncClient(transport=httpx.MockTransport(self._handle))

    def gets(self) -> list[httpx.Request]:
        return [r for r in self.requests if r.method == "GET"]

    def posts(self) -> list[httpx.Request]:
        return [r for r in self.requests if r.method == "POST"]


# --------------------------------------------------------------------------
# Хелперы
# --------------------------------------------------------------------------

def _cfg(port: int, tmp_path: Path, **over) -> WsClientConfig:
    """Конфиг с крошечными таймингами — иначе ping/backoff-тесты тянутся/флапают."""
    base = dict(
        worker_id="w-img-1",
        worker_type="image",
        gateway_ws_url=f"ws://localhost:{port}",
        api_base_url="http://sym.local",
        worker_api_token=TOKEN,
        version="0.1.7",
        work_dir=tmp_path,
        slots=1,
        ws_result_inline_max=262144,
        ws_ping_interval_s=0.05,
        ws_progress_interval_s=0.05,
        ws_liveness_missed_pings=2,
        ws_reconnect_backoff_base_s=0.02,
        ws_reconnect_backoff_max_s=0.1,
        ws_reconnect_backoff_factor=2.0,
    )
    base.update(over)
    return WsClientConfig(**base)


@asynccontextmanager
async def _serve(gw: FakeGateway):
    async with serve(gw.handler, "localhost", 0) as server:
        yield server.sockets[0].getsockname()[1]


@asynccontextmanager
async def _running(gw, tmp_path, handle_job, sym, capabilities=None, **cfg_over):
    """Поднять фейковый gateway + запустить WsClient фоновой задачей."""
    async with _serve(gw) as port:
        cfg = _cfg(port, tmp_path, **cfg_over)
        client = WsClient(cfg, handle_job, http_client=sym.client(), capabilities=capabilities)
        task = asyncio.create_task(client.run())
        try:
            yield client
        finally:
            client.stop()
            task.cancel()
            with suppress(asyncio.CancelledError):
                await task


async def _wait_for(pred, timeout=5.0, interval=0.01):
    loop = asyncio.get_running_loop()
    end = loop.time() + timeout
    while loop.time() < end:
        if pred():
            return
        await asyncio.sleep(interval)
    raise AssertionError("condition not met within timeout")


def _job(job_id="10-0", **over):
    frame = {
        "type": "job", "jobId": job_id, "conversionId": 42,
        "sourceFormat": "png", "targetFormat": "txt",
        "inputKey": "inputs/x.png", "inputBucket": "convertor-inputs",
    }
    frame.update(over)
    return frame


# --------------------------------------------------------------------------
# Handshake / auth / ready
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_connect_auth_ready_carries_identity(tmp_path):
    gw = FakeGateway()
    sym = FakeSymfony()

    async def noop(job, progress):
        return ResultSignal.completed(data=b"", ext="txt")

    async with _running(gw, tmp_path, noop, sym):
        await _wait_for(lambda: len(gw.readys) >= 1)

    # Bearer в WS-upgrade (граница a, §7).
    assert gw.auth_headers[0] == f"Bearer {TOKEN}"
    ready = gw.readys[0]
    assert ready["type"] == "ready"
    assert ready["workerId"] == "w-img-1"
    assert ready["workerType"] == "image"
    assert ready["slots"] == 1
    assert ready["version"] == "0.1.7"
    for key in ("cpu", "mem", "load"):
        assert key in ready and isinstance(ready[key], (int, float))


# --------------------------------------------------------------------------
# Job → handle_job(_localInput) → inline completion
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_job_handled_input_injected_inline_result(tmp_path):
    gw = FakeGateway(jobs=[_job()])
    sym = FakeSymfony()
    seen = {}

    async def handler(job, progress):
        seen["localInput"] = job.get("_localInput")
        seen["content"] = Path(job["_localInput"]).read_bytes()
        progress.report(50, "working")
        return ResultSignal.completed(data=b"small-out", mime="text/plain", ext="txt", processing_ms=12)

    async with _running(gw, tmp_path, handler, sym):
        await _wait_for(lambda: len(gw.results) >= 1)

    # handle_job получил скачанный вход в job["_localInput"].
    assert seen["localInput"] and Path(seen["localInput"]).name.startswith("in-")
    assert seen["content"] == b"INPUT-BYTES"

    # inline-ветка: валидный base64, decoded == вывод, ≤ порога.
    res = gw.results[0]
    assert res["type"] == "result" and res["jobId"] == "10-0"
    assert "resultKey" not in res
    assert base64.b64decode(res["inline"]) == b"small-out"
    assert res["mime"] == "text/plain"
    assert res["processingMs"] == 12

    # Вход взят ТОЛЬКО через GET /jobs/{id}/input с Bearer; POST /result НЕ было.
    gets = sym.gets()
    assert len(gets) == 1
    assert gets[0].url.path == "/api/v1/worker/jobs/10-0/input"
    assert gets[0].headers.get("authorization") == f"Bearer {TOKEN}"
    assert sym.posts() == []

    # job_dir удалён → work_dir пуст (вход подчищен вместе с поддиректорией).
    assert list(tmp_path.iterdir()) == []


# --------------------------------------------------------------------------
# Large-ветка: POST /result → result{resultKey}
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_large_result_posts_multipart_then_result_key(tmp_path):
    gw = FakeGateway(jobs=[_job(job_id="7-0")])
    sym = FakeSymfony()  # ответ {ok:true} — без ключа

    async def handler(job, progress):
        return ResultSignal.completed(data=b"X" * 50, mime="video/mp4", ext="mp4")

    async with _running(gw, tmp_path, handler, sym, ws_result_inline_max=8):
        await _wait_for(lambda: len(gw.results) >= 1)

    res = gw.results[0]
    assert res["jobId"] == "7-0"
    assert "inline" not in res
    # {ok:true} без ключа → truthy-референс на джобу (см. отчёт-блокер).
    assert res["resultKey"] == "jobs/7-0/result"

    posts = sym.posts()
    assert len(posts) == 1
    assert posts[0].url.path == "/api/v1/worker/jobs/7-0/result"
    assert posts[0].headers.get("authorization") == f"Bearer {TOKEN}"


@pytest.mark.asyncio
async def test_large_result_from_file_path_streams(tmp_path):
    """Крупный выход (path) стримится с диска в POST; job_dir убирает вход + выход."""
    gw = FakeGateway(jobs=[_job(job_id="11-0")])
    sym = FakeSymfony()

    async def handler(job, progress):
        # Воркер пишет в job_dir — как в проде
        out = Path(job["_jobDir"]) / "big.mp4"
        out.write_bytes(b"Z" * 100)
        return ResultSignal.completed(path=str(out), mime="video/mp4", ext="mp4")

    async with _running(gw, tmp_path, handler, sym, ws_result_inline_max=8):
        await _wait_for(lambda: len(gw.results) >= 1)

    res = gw.results[0]
    assert "inline" not in res and res["resultKey"] == "jobs/11-0/result"
    assert len(sym.posts()) == 1
    # job_dir удалён → work_dir пуст (вход + выход подчищены)
    assert list(tmp_path.iterdir()) == []


@pytest.mark.asyncio
async def test_large_result_multipart_carries_processing_ms(tmp_path):
    """hardening-06: large-путь кладёт processingMs доп. form-полем в multipart-POST
    (то же имя ключа, что и на inline-пути)."""
    gw = FakeGateway(jobs=[_job(job_id="12-0")])
    sym = FakeSymfony()

    async def handler(job, progress):
        return ResultSignal.completed(
            data=b"X" * 50, mime="video/mp4", ext="mp4", processing_ms=321
        )

    async with _running(gw, tmp_path, handler, sym, ws_result_inline_max=8):
        await _wait_for(lambda: len(gw.results) >= 1)

    posts = sym.posts()
    assert len(posts) == 1
    body = posts[0].content
    assert b'name="processingMs"' in body
    assert b"\r\n321\r\n" in body


@pytest.mark.asyncio
async def test_large_result_prefers_output_key_from_response(tmp_path):
    gw = FakeGateway(jobs=[_job(job_id="9-0")])
    sym = FakeSymfony(result_response={"ok": True, "outputKey": "results/2026/07-06/42.mp4"})

    async def handler(job, progress):
        return ResultSignal.completed(data=b"Y" * 40, mime="video/mp4", ext="mp4")

    async with _running(gw, tmp_path, handler, sym, ws_result_inline_max=8):
        await _wait_for(lambda: len(gw.results) >= 1)

    # forward-compatible: если Symfony начнёт возвращать outputKey — клиент его эхонёт.
    assert gw.results[0]["resultKey"] == "results/2026/07-06/42.mp4"


# --------------------------------------------------------------------------
# Fail-ветка
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_fail_branch_permanent(tmp_path):
    gw = FakeGateway(jobs=[_job(job_id="3-0")])
    sym = FakeSymfony()

    async def handler(job, progress):
        return ResultSignal.failed(error="boom", permanent=True)

    async with _running(gw, tmp_path, handler, sym):
        await _wait_for(lambda: len(gw.fails) >= 1)

    fail = gw.fails[0]
    assert fail == {"type": "fail", "jobId": "3-0", "error": "boom", "permanent": True}
    assert gw.results == []
    assert list(tmp_path.iterdir()) == []  # job_dir удалён → work_dir пуст


@pytest.mark.asyncio
async def test_fail_branch_carries_processing_ms(tmp_path):
    """hardening-06: ResultSignal.failed(processing_ms=...) → fail-фрейм несёт processingMs
    (то же имя ключа, что и на inline/large success-путях)."""
    gw = FakeGateway(jobs=[_job(job_id="13-0")])
    sym = FakeSymfony()

    async def handler(job, progress):
        return ResultSignal.failed(error="disk full", permanent=False, processing_ms=456)

    async with _running(gw, tmp_path, handler, sym):
        await _wait_for(lambda: len(gw.fails) >= 1)

    fail = gw.fails[0]
    assert fail == {
        "type": "fail", "jobId": "13-0", "error": "disk full", "processingMs": 456,
    }


@pytest.mark.asyncio
async def test_handler_exception_becomes_retryable_fail(tmp_path):
    gw = FakeGateway(jobs=[_job(job_id="4-0")])
    sym = FakeSymfony()

    async def handler(job, progress):
        raise RuntimeError("kaboom")

    async with _running(gw, tmp_path, handler, sym):
        await _wait_for(lambda: len(gw.fails) >= 1)

    fail = gw.fails[0]
    assert fail["jobId"] == "4-0"
    assert "permanent" not in fail  # необъявленный сбой → retryable
    assert "kaboom" in fail["error"]


# --------------------------------------------------------------------------
# Progress: ~1/сек только пока задача в работе
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_progress_emitted_only_during_job(tmp_path):
    gw = FakeGateway(jobs=[_job(job_id="5-0")])
    sym = FakeSymfony()

    async def handler(job, progress):
        progress.report(30, "decoding")
        await asyncio.sleep(0.2)  # даём progress-циклу тикнуть несколько раз
        return ResultSignal.completed(data=b"done", ext="txt")

    async with _running(gw, tmp_path, handler, sym):
        await _wait_for(lambda: len(gw.results) >= 1)

    assert gw.progress, "во время задачи должен идти progress"
    p = gw.progress[0]
    assert p["type"] == "progress" and p["jobId"] == "5-0"
    assert p["percent"] == 30 and p["stage"] == "decoding"


@pytest.mark.asyncio
async def test_no_progress_when_idle(tmp_path):
    gw = FakeGateway()  # НИ одной задачи
    sym = FakeSymfony()

    async def noop(job, progress):
        return ResultSignal.completed(data=b"", ext="txt")

    async with _running(gw, tmp_path, noop, sym):
        await _wait_for(lambda: len(gw.pings) >= 1)  # клиент жив (пингует)
        await asyncio.sleep(0.2)
        assert gw.progress == []  # idle → progress не эмитится


# --------------------------------------------------------------------------
# Ping/pong liveness + reconnect тем же workerId + backoff
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_missed_pongs_reconnect_same_worker_id_with_backoff(tmp_path):
    gw = FakeGateway(answer_ping=False)  # gateway молчит на ping → liveness сработает
    sym = FakeSymfony()

    async def noop(job, progress):
        return ResultSignal.completed(data=b"", ext="txt")

    async with _running(gw, tmp_path, noop, sym):
        await _wait_for(lambda: len(gw.readys) >= 2, timeout=5.0)

    # Переподключение под ТЕМ ЖЕ workerId (стабильный consumer, §6.1).
    assert gw.readys[0]["workerId"] == gw.readys[1]["workerId"] == "w-img-1"
    # Backoff между попытками (не reconnect-шторм).
    assert len(gw.conn_times) >= 2
    assert gw.conn_times[1] - gw.conn_times[0] >= 0.01


@pytest.mark.asyncio
async def test_ping_pong_keeps_flowing(tmp_path):
    gw = FakeGateway(answer_ping=True)
    sym = FakeSymfony()

    async def noop(job, progress):
        return ResultSignal.completed(data=b"", ext="txt")

    async with _running(gw, tmp_path, noop, sym):
        await _wait_for(lambda: len(gw.pings) >= 3)  # несколько ping без reconnect

    assert len(gw.readys) == 1  # pong приходят → liveness не срабатывает


# --------------------------------------------------------------------------
# Non-blocking: медленный обработчик не морит ping/progress
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_slow_async_handler_does_not_starve_ping_progress(tmp_path):
    gw = FakeGateway(jobs=[_job(job_id="6-0")])
    sym = FakeSymfony()
    holder = {}

    async def slow(job, progress):
        progress.report(10, "mid")
        before = len(gw.pings)
        await asyncio.sleep(0.3)  # долгая async-задача
        holder["pings_during"] = len(gw.pings) - before
        return ResultSignal.completed(data=b"ok", ext="txt")

    async with _running(gw, tmp_path, slow, sym):
        await _wait_for(lambda: len(gw.results) >= 1)

    assert holder["pings_during"] >= 1, "ping-цикл голодал во время задачи"
    assert gw.progress, "progress-цикл голодал во время задачи"


@pytest.mark.asyncio
async def test_thread_offloaded_blocking_handler_does_not_starve_loop(tmp_path):
    """Продакшн-паттерн: синхронный CPU-bound convert() уводится в asyncio.to_thread —
    event-loop свободен, ping/progress текут (иначе долгая задача тронула бы liveness)."""
    gw = FakeGateway(jobs=[_job(job_id="8-0")])
    sym = FakeSymfony()
    holder = {}

    async def blocking(job, progress):
        progress.report(20, "transcoding")
        before = len(gw.pings)
        await asyncio.to_thread(time.sleep, 0.3)  # блокирующий sync convert() в потоке
        holder["pings_during"] = len(gw.pings) - before
        return ResultSignal.completed(data=b"ok", ext="txt")

    async with _running(gw, tmp_path, blocking, sym):
        await _wait_for(lambda: len(gw.results) >= 1)

    assert holder["pings_during"] >= 1
    assert gw.progress


# --------------------------------------------------------------------------
# Дубликат job-фрейма в одном соединении не крашит и не дублирует обработку
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_duplicate_job_frame_no_crash_single_processing(tmp_path):
    gw = FakeGateway(jobs=[_job(job_id="2-0")], send_twice=True)
    sym = FakeSymfony()
    calls = {"n": 0}

    async def handler(job, progress):
        calls["n"] += 1
        await asyncio.sleep(0.1)  # держим задачу in-flight пока приходит дубликат
        return ResultSignal.completed(data=b"once", ext="txt")

    async with _running(gw, tmp_path, handler, sym):
        await _wait_for(lambda: len(gw.results) >= 1)
        await asyncio.sleep(0.15)  # дать шанс лишнему результату (не должен появиться)

    assert calls["n"] == 1, "дубликат jobId не должен переобрабатываться"
    assert len(gw.results) == 1


# --------------------------------------------------------------------------
# Жёсткий инвариант: нет импортов S3/KeyDB
# --------------------------------------------------------------------------

def test_no_s3_or_keydb_imports():
    """Вход только через GET /jobs/{id}/input; ws_client НЕ импортирует S3/KeyDB."""
    src = Path(ws_client_mod.__file__).read_text(encoding="utf-8")
    import_lines = [
        ln for ln in src.splitlines() if ln.strip().startswith(("import ", "from "))
    ]
    joined = "\n".join(import_lines).lower()
    for forbidden in ("boto3", "botocore", "redis", "keydb", "minio"):
        assert forbidden not in joined, f"forbidden transport import: {forbidden}"


# --------------------------------------------------------------------------
# ResultSignal / ProgressReporter — семантика конструкторов
# --------------------------------------------------------------------------

def test_result_signal_constructors():
    ok = ResultSignal.completed(data=b"x", mime="text/plain", ext="txt", processing_ms=5)
    assert ok.ok and ok.read_bytes() == b"x" and ok.processing_ms == 5
    bad = ResultSignal.failed(error="e", permanent=True)
    assert not bad.ok and bad.error == "e" and bad.permanent is True and bad.processing_ms is None
    bad_timed = ResultSignal.failed(error="e", permanent=False, processing_ms=42)
    assert bad_timed.processing_ms == 42
    with pytest.raises(ValueError):
        ResultSignal.completed()  # ни path, ни data


def test_progress_reporter_clamps():
    r = ProgressReporter()
    assert r.snapshot == (0, None)
    r.report(150, "s")
    assert r.snapshot == (100, "s")
    r.report(-5)
    assert r.snapshot == (0, "s")  # stage сохраняется, percent зажат


# --------------------------------------------------------------------------
# Пре-connect валидация: мисконфиг → отказ старта (без reconnect-шторма)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_invalid_worker_type_refuses_to_start(tmp_path):
    gw = FakeGateway()
    sym = FakeSymfony()

    async def noop(job, progress):
        return ResultSignal.completed(data=b"", ext="txt")

    async with _serve(gw) as port:
        cfg = _cfg(port, tmp_path, worker_type="bogus")
        client = WsClient(cfg, noop, http_client=sym.client())
        await asyncio.wait_for(client.run(), timeout=1.0)  # возвращается сразу, без цикла

    assert gw.readys == []  # ни одной попытки подключения


@pytest.mark.asyncio
async def test_empty_worker_id_refuses_to_start(tmp_path):
    gw = FakeGateway()
    sym = FakeSymfony()

    async def noop(job, progress):
        return ResultSignal.completed(data=b"", ext="txt")

    async with _serve(gw) as port:
        cfg = _cfg(port, tmp_path, worker_id="")
        client = WsClient(cfg, noop, http_client=sym.client())
        await asyncio.wait_for(client.run(), timeout=1.0)

    assert gw.readys == []


@pytest.mark.asyncio
async def test_handshake_reject_backoff_grows_ready_stays_false(tmp_path):
    """Сервер upgrade-принимает и тут же закрывает (handshake-reject). Клиент не должен
    сбрасывать backoff (нет входящих фреймов → _ready_ok=False) → интервалы РАСТУТ."""
    gw = FakeGateway(close_after_ready=True)
    sym = FakeSymfony()

    async def noop(job, progress):
        return ResultSignal.completed(data=b"", ext="txt")

    async with _serve(gw) as port:
        cfg = _cfg(port, tmp_path)
        client = WsClient(cfg, noop, http_client=sym.client())
        task = asyncio.create_task(client.run())
        try:
            await _wait_for(lambda: len(gw.conn_times) >= 4, timeout=5.0)
        finally:
            client.stop()
            task.cancel()
            with suppress(asyncio.CancelledError):
                await task

    assert client._ready_ok is False  # ни один handshake не подтверждён
    gaps = [gw.conn_times[i + 1] - gw.conn_times[i] for i in range(len(gw.conn_times) - 1)]
    # Backoff растёт: последний интервал заметно больше первого (не reconnect-шторм).
    assert gaps[-1] > gaps[0]


# --------------------------------------------------------------------------
# Setup-сбой (mkdir) не оставляет джобу в inflight, шлёт retryable fail
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_mkdir_failure_sends_retryable_fail(tmp_path):
    blocker = tmp_path / "notadir"
    blocker.write_text("x")  # файл на месте work_dir → mkdir упадёт
    gw = FakeGateway(jobs=[_job(job_id="12-0")])
    sym = FakeSymfony()
    calls = {"n": 0}

    async def handler(job, progress):
        calls["n"] += 1
        return ResultSignal.completed(data=b"x", ext="txt")

    async with _running(gw, tmp_path, handler, sym, work_dir=blocker):
        await _wait_for(lambda: len(gw.fails) >= 1)
        await asyncio.sleep(0.1)  # соединение живо, клиент не крашнулся

    fail = gw.fails[0]
    assert fail["jobId"] == "12-0"
    assert "permanent" not in fail          # setup-сбой → retryable
    assert calls["n"] == 0                   # до handle_job не дошли
    assert gw.pings, "клиент жив после setup-сбоя (пинги идут)"


# --------------------------------------------------------------------------
# stop() сворачивает ЖИВОЕ idle-соединение быстро (не ждёт обрыва TCP)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_stop_tears_down_idle_connection_promptly(tmp_path):
    gw = FakeGateway()  # idle, отвечает pong — ни reader, ни pinger сами не завершатся
    sym = FakeSymfony()

    async def noop(job, progress):
        return ResultSignal.completed(data=b"", ext="txt")

    async with _serve(gw) as port:
        cfg = _cfg(port, tmp_path)
        client = WsClient(cfg, noop, http_client=sym.client())
        task = asyncio.create_task(client.run())
        await _wait_for(lambda: len(gw.readys) >= 1)
        client.stop()
        await asyncio.wait_for(task, timeout=1.0)  # без stop-waiter'а висело бы до таймаута


# --------------------------------------------------------------------------
# Пропавший/нечитаемый выход → PERMANENT fail (не гоняем retry→DLQ впустую)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_missing_output_path_permanent_fail(tmp_path):
    gw = FakeGateway(jobs=[_job(job_id="13-0")])
    sym = FakeSymfony()

    async def handler(job, progress):
        return ResultSignal.completed(path=str(tmp_path / "nope.txt"), ext="txt")

    async with _running(gw, tmp_path, handler, sym):
        await _wait_for(lambda: len(gw.fails) >= 1)

    fail = gw.fails[0]
    assert fail["jobId"] == "13-0"
    assert fail["permanent"] is True
    assert "unreadable" in fail["error"]


# --------------------------------------------------------------------------
# Сбой скачивания входа (GET /jobs/{id}/input → 404) → retryable fail-фрейм
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_input_download_404_sends_retryable_fail(tmp_path):
    """GET /jobs/{id}/input → 404 → fail-фрейм (retryable), handler не вызван, tmp подчищен."""
    gw = FakeGateway(jobs=[_job(job_id="14-0")])

    class _NotFoundSymfony(FakeSymfony):
        def _handle(self, request: httpx.Request) -> httpx.Response:
            self.requests.append(request)
            return httpx.Response(404, json={"error": "not found"})

    sym = _NotFoundSymfony()
    calls = {"n": 0}

    async def handler(job, progress):
        calls["n"] += 1
        return ResultSignal.completed(data=b"x", ext="txt")

    async with _running(gw, tmp_path, handler, sym):
        await _wait_for(lambda: len(gw.fails) >= 1)

    fail = gw.fails[0]
    assert fail["jobId"] == "14-0"
    assert "permanent" not in fail  # download-сбой → retryable
    assert calls["n"] == 0          # handle_job не вызван
    # job_dir удалён вместе с частичным входом → work_dir пуст
    assert list(tmp_path.iterdir()) == []


# --------------------------------------------------------------------------
# ready-ack: воркер адоптирует inlineMax от gateway (s1-08)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_worker_adopts_gateway_inline_max_over_env(tmp_path):
    """Gateway присылает inlineMax=8; 50-байтный результат идёт large-путём (не inline),
    несмотря на env-дефолт 1000. Доказывает адопцию авторитетного порога из ready-ack."""
    out_file = tmp_path / "out.bin"
    out_file.write_bytes(b"x" * 50)

    gw = FakeGateway(jobs=[_job()], send_ack=True, ack_inline_max=8)
    sym = FakeSymfony()

    async def handler(job, progress):
        return ResultSignal.completed(path=str(out_file))

    async with _running(gw, tmp_path, handler, sym, ws_result_inline_max=1000):
        await _wait_for(lambda: bool(gw.results or gw.fails), timeout=5.0)

    # 50 байт > ack inlineMax(8), хотя env=1000 → large-путь (POST)
    assert len(gw.results) == 1
    assert "resultKey" in gw.results[0]
    assert len(sym.posts()) == 1


@pytest.mark.asyncio
async def test_gateway_inline_max_larger_than_env_allows_inline(tmp_path):
    """Gateway присылает inlineMax=1000 > env=8; 50-байтный результат идёт inline."""
    out_file = tmp_path / "out.bin"
    out_file.write_bytes(b"x" * 50)

    gw = FakeGateway(jobs=[_job()], send_ack=True, ack_inline_max=1000)
    sym = FakeSymfony()

    async def handler(job, progress):
        return ResultSignal.completed(path=str(out_file))

    async with _running(gw, tmp_path, handler, sym, ws_result_inline_max=8):
        await _wait_for(lambda: bool(gw.results or gw.fails), timeout=5.0)

    # 50 байт ≤ ack inlineMax(1000), хотя env=8 → inline
    assert len(gw.results) == 1
    assert "inline" in gw.results[0]
    assert len(sym.posts()) == 0


@pytest.mark.asyncio
async def test_worker_uses_env_default_when_no_ack(tmp_path):
    """Без ready-ack воркер использует env WS_RESULT_INLINE_MAX как порог."""
    out_file = tmp_path / "out.bin"
    out_file.write_bytes(b"x" * 50)

    gw = FakeGateway(jobs=[_job()], send_ack=False)  # нет ready-ack
    sym = FakeSymfony()

    async def handler(job, progress):
        return ResultSignal.completed(path=str(out_file))

    async with _running(gw, tmp_path, handler, sym, ws_result_inline_max=8):
        await _wait_for(lambda: bool(gw.results or gw.fails), timeout=5.0)

    # 50 байт > env=8, ready-ack не пришёл → large-путь (fallback к env)
    assert len(gw.results) == 1
    assert "resultKey" in gw.results[0]
    assert len(sym.posts()) == 1


# --------------------------------------------------------------------------
# #2: Обязательные поля фрейма — валидация ДО скачивания
# --------------------------------------------------------------------------

@pytest.mark.asyncio
@pytest.mark.parametrize("missing_field", ["conversionId", "sourceFormat", "targetFormat"])
async def test_malformed_missing_mandatory_field_permanent_fail(tmp_path, missing_field):
    """Отсутствие обязательного поля → permanent fail без скачивания входа."""
    frame = _job(job_id="mf-0")
    del frame[missing_field]
    gw = FakeGateway(jobs=[frame])
    sym = FakeSymfony()
    calls = {"n": 0}

    async def handler(job, progress):
        calls["n"] += 1
        return ResultSignal.completed(data=b"x", ext="txt")

    async with _running(gw, tmp_path, handler, sym):
        await _wait_for(lambda: len(gw.fails) >= 1)

    fail = gw.fails[0]
    assert fail["permanent"] is True, f"должен быть permanent для missing {missing_field}"
    assert missing_field in fail["error"], f"ошибка должна упоминать {missing_field}"
    assert sym.gets() == [], f"скачивания не должно быть при missing {missing_field}"
    assert calls["n"] == 0, "handle_job не должен быть вызван"


# --------------------------------------------------------------------------
# #3: Per-job temp-subdir — изоляция и очистка
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_job_dir_cleaned_on_success(tmp_path):
    """После успеха job_dir (WORK_DIR/<jobId>/) полностью удаляется."""
    gw = FakeGateway(jobs=[_job(job_id="jd-ok")])
    sym = FakeSymfony()
    seen: dict = {}

    async def handler(job, progress):
        job_dir = Path(job["_jobDir"])
        seen["dir"] = job_dir
        (job_dir / "out.txt").write_bytes(b"result")  # файл внутри job_dir
        return ResultSignal.completed(data=b"ok", ext="txt")

    async with _running(gw, tmp_path, handler, sym):
        await _wait_for(lambda: len(gw.results) >= 1)

    assert "dir" in seen
    assert not seen["dir"].exists(), "job_dir должен быть удалён после успеха"
    assert list(tmp_path.iterdir()) == [], "work_dir пуст"


@pytest.mark.asyncio
async def test_job_dir_cleaned_on_failure_with_partial_output(tmp_path):
    """Частичный выход в job_dir после сбоя convert() → job_dir полностью удаляется."""
    gw = FakeGateway(jobs=[_job(job_id="jd-fail")])
    sym = FakeSymfony()
    seen: dict = {}

    async def handler(job, progress):
        job_dir = Path(job["_jobDir"])
        seen["dir"] = job_dir
        (job_dir / "partial.bin").write_bytes(b"PARTIAL DATA")  # частичный выход
        return ResultSignal.failed(error="conversion failed", permanent=False)

    async with _running(gw, tmp_path, handler, sym):
        await _wait_for(lambda: len(gw.fails) >= 1)

    assert gw.fails[0]["jobId"] == "jd-fail"
    assert "dir" in seen
    assert not seen["dir"].exists(), "job_dir удаляется после фейла (вкл. частичный выход)"
    assert list(tmp_path.iterdir()) == [], "work_dir пуст"


@pytest.mark.asyncio
async def test_job_id_path_traversal_neutralized(tmp_path):
    """jobId с path traversal (../...) санитизируется → job_dir остаётся внутри work_dir."""
    frame = _job(job_id="../../evil")
    gw = FakeGateway(jobs=[frame])
    sym = FakeSymfony()
    seen: dict = {}

    async def handler(job, progress):
        seen["dir"] = Path(job["_jobDir"])
        return ResultSignal.completed(data=b"ok", ext="txt")

    async with _running(gw, tmp_path, handler, sym):
        await _wait_for(lambda: bool(gw.results or gw.fails), timeout=3.0)

    assert gw.results, "задача завершилась успехом (traversal не блокирует обработку)"
    assert "dir" in seen
    # job_dir ВНУТРИ work_dir (не поднялся выше)
    assert tmp_path.resolve() in seen["dir"].parents
    # work_dir пуст после завершения
    assert list(tmp_path.iterdir()) == []


# --------------------------------------------------------------------------
# #4: API_BASE_URL path-doubling диагностика
# --------------------------------------------------------------------------

def test_api_base_url_with_path_component_warns(tmp_path, caplog):
    """API_BASE_URL с path-компонентом → warning о дублировании пути при validate()."""
    import logging
    with caplog.at_level(logging.WARNING, logger="workers.common.ws_client"):
        cfg = _cfg(9999, tmp_path, api_base_url="http://api.example.com/v1")
        cfg.validate()
    assert any(
        "/v1" in r.message or "path" in r.message.lower()
        for r in caplog.records
    ), "должен быть warning о path-компоненте в API_BASE_URL"


def test_api_base_url_no_path_no_warning(tmp_path, caplog):
    """API_BASE_URL без пути → без warning при validate()."""
    import logging
    with caplog.at_level(logging.WARNING, logger="workers.common.ws_client"):
        cfg = _cfg(9999, tmp_path, api_base_url="http://api.example.com")
        cfg.validate()
    path_warns = [
        r for r in caplog.records
        if "API_BASE_URL" in r.message
        and ("path" in r.message.lower() or "doubl" in r.message.lower())
    ]
    assert not path_warns


# --------------------------------------------------------------------------
# Worker register (best-effort HTTP POST, non-fatal)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_register_called_on_connect(tmp_path):
    """При старте воркер шлёт POST /register с корректным телом контракта."""
    gw = FakeGateway()
    sym = FakeSymfony()

    async def noop(job, progress):
        return ResultSignal.completed(data=b"", ext="txt")

    caps = {
        "routing_keys": ["image"],
        "matrix": {"png": {"jpg", "webp"}},
    }
    async with _running(gw, tmp_path, noop, sym, capabilities=caps):
        await _wait_for(lambda: any("/register" in r.url.path for r in sym.requests))

    reg_reqs = [r for r in sym.requests if "/register" in r.url.path]
    assert len(reg_reqs) == 1
    req = reg_reqs[0]
    assert req.method == "POST"
    assert req.headers.get("authorization") == f"Bearer {TOKEN}"
    body = json.loads(req.content)
    assert body["workerType"] == "image"
    assert body["isAi"] is False
    assert body["routingKeys"] == ["image"]
    assert body["streams"] == ["image"]
    assert body["matrix"] == {"png": sorted(["jpg", "webp"])}
    assert body["version"] == "0.1.7"
    assert body["image"] is None


@pytest.mark.asyncio
async def test_register_failure_does_not_stop_worker(tmp_path):
    """Сбой register (ConnectError) — воркер продолжает работу и обрабатывает задачи."""
    gw = FakeGateway(jobs=[_job(job_id="reg-fail-1")])

    class FailOnRegisterSymfony(FakeSymfony):
        def _handle(self, request: httpx.Request) -> httpx.Response:
            self.requests.append(request)
            if "/register" in request.url.path:
                raise httpx.ConnectError("refused")
            return super()._handle(request)

    sym = FailOnRegisterSymfony()
    caps = {"routing_keys": ["image"], "matrix": {}}

    async def noop(job, progress):
        return ResultSignal.completed(data=b"ok", ext="txt")

    async with _running(gw, tmp_path, noop, sym, capabilities=caps):
        await _wait_for(lambda: len(gw.results) >= 1)

    assert gw.results[0]["jobId"] == "reg-fail-1"


@pytest.mark.asyncio
async def test_no_register_when_no_capabilities(tmp_path):
    """Без capabilities воркер НЕ делает POST /register."""
    gw = FakeGateway()
    sym = FakeSymfony()

    async def noop(job, progress):
        return ResultSignal.completed(data=b"", ext="txt")

    async with _running(gw, tmp_path, noop, sym):  # capabilities=None (default)
        await _wait_for(lambda: len(gw.readys) >= 1)
        await asyncio.sleep(0.05)

    reg_reqs = [r for r in sym.requests if "/register" in r.url.path]
    assert reg_reqs == [], "register не должен вызываться без capabilities"
