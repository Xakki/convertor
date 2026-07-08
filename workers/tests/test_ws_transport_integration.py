"""Сквозной интеграционный smoke WS-транспорта по всем 6 типам воркеров (s1-13, §9).

Гоняет РЕАЛЬНЫЙ стек транспорта в одном процессе:
  - РЕАЛЬНЫЙ KeyDB (как test_gateway_reclaim_dlq.py: XREADGROUP/XAUTOCLAIM/XACK/PEL);
  - РЕАЛЬНЫЙ `WsGateway` + `KeyDbGateway` — единственный читатель `conv.<type>`;
  - РЕАЛЬНЫЙ idle-reclaim (`reclaim._sweep_all_types`, детерминированно — один прогон);
  - фейковые воркеры = «сырые» websockets-клиенты (ни один не открывает KeyDB/S3 —
    воркеры общаются ТОЛЬКО по WS, вход/результат шли бы через Symfony API);
  - relay в Symfony замокан (`RelayRecorder`) — считаем ровно persist-вызовы по jobId.

Инвариант архитектуры: воркеры — чистые WS-клиенты. `FakeWorker` не импортирует
redis/boto3 и физически не может прочитать stream/S3; единственный stream-reader —
gateway'ский `KeyDbGateway`. Это структурно гарантирует критерий «ни один воркер
не коннектится к KeyDB/S3».

Покрытие (критерии приёмки s1-13):
  [1] Маршрутизация: по одному воркеру на каждый из 6 типов; каждый читается из
      своего `conv.<type>` и получает ТОЛЬКО свою задачу (image ← только conv.image).
  [2] Мульти-тип (ffmpeg audio+video): ДВА `workerId`-соединения с НЕПЕРЕСЕКАЮЩИМИСЯ
      PEL (conv.audio ← ffmpeg-audio, conv.video ← ffmpeg-video).
  [3] Backstop-reclaim (§6.6 путь «b»): мёртвый воркер посреди задачи → per-type
      idle-timeout → reclaim → переотправка второму воркеру; PEL в итоге пуст.
  [4] Идемпотентность доставки: форсируем дублирующую доставку (тот же jobId →
      двум соединениям). Транспорт по дизайну НЕ дедуплицирует межсоединенческую
      доставку — он гарантирует лишь детерминированный якорь (тот же jobId). Ровно
      один сохранённый результат под детерминированным ключом и отсутствие двойного
      списания/возврата квоты обеспечивает PHP `ConversionResultPersister`
      (status-guard, тест app-symfony/.../ConversionResultPersisterTest.php).
"""

from __future__ import annotations

import asyncio
import base64
import json
from contextlib import asynccontextmanager, suppress

import httpx
import pytest
from websockets.asyncio.client import connect
from websockets.asyncio.server import serve

from workers.gateway import reclaim
from workers.gateway.config import Config
from workers.gateway.keydb import (
    DLQ_STREAM,
    GROUP,
    WORKER_TYPES,
    KeyDbGateway,
    build_client,
    stream_for,
)
from workers.gateway.relay import RelayClient
from workers.gateway.ws_server import WsGateway
from workers.tests.ws_helpers import wait_for

pytestmark = pytest.mark.asyncio

TOKEN = "test-token"
INTERNAL_TOKEN = "internal-tok"
BASE_URL = "http://symfony-test"

CONV_STREAMS = tuple(stream_for(t) for t in WORKER_TYPES) + (DLQ_STREAM,)

# Отдельный conversionId на каждый тип — доказывает, что задача попала в «свой»
# stream (image-воркер обязан увидеть ИМЕННО 130, никогда чужой).
CONV_ID = {
    "ai": 110, "document": 120, "image": 130,
    "audio": 140, "video": 150, "data": 160,
}


# ---------------------------------------------------------------------------
# Real-KeyDB helpers
# ---------------------------------------------------------------------------

# Выделенный KeyDB db-индекс для ЭТОГО файла — обязателен для изоляции: тест
# сеет в РЕАЛЬНЫЕ conv.<type> стримы, и если рядом поднят dev/e2e-стек (его
# ws-gateway + on-server воркеры audio/video/data читают conv.<type>), он бы
# «воровал» наши записи. db 4 свободен: keydb.conf `databases 5` (0..4), где
# 0=cache, 1=sessions, 2=queues(dev), 3=e2e. Аналог e2e-изоляции на db 3.
ITEST_DB = 4


async def _new_real_kv() -> tuple[object, KeyDbGateway]:
    from dataclasses import replace

    from workers.gateway.config import load_config
    cfg = replace(load_config(), redis_db=ITEST_DB)
    client = build_client(cfg)
    return client, KeyDbGateway(client)


async def _wipe(client) -> None:
    """Чистый лист: удалить все conv.<type> + conv.dead + job-мету + conv:status."""
    for s in CONV_STREAMS:
        await client.delete(s)
    async for k in client.scan_iter(match="worker:job:*"):
        await client.delete(k)
    async for k in client.scan_iter(match="conv:status:*"):
        await client.delete(k)


def _job(conv_id: int, src: str, tgt: str, category: str) -> dict:
    return {
        "conversionId": conv_id,
        "inputBucket": "convertor-inputs",
        "inputKey": f"inputs/{conv_id}.{src}",
        "originalFilename": f"file.{src}",
        "sourceFormat": src,
        "targetFormat": tgt,
        "category": category,
        "isAi": category == "ai",
        "options": [],
    }


async def _seed(client, stream: str, job: dict) -> str:
    """XADD чистого single-JSON (поле `message`) — форма CleanRedisTransport (§2)."""
    return await client.xadd(stream, {"message": json.dumps(job)})


async def _pending(client, stream: str) -> int:
    res = await client.xpending(stream, GROUP)
    return int(res["pending"])


async def _pending_consumers(client, stream: str) -> dict[str, str]:
    """{jobId → consumer} по PEL стрима (для проверки disjoint-владения)."""
    rows = await client.xpending_range(stream, GROUP, min="-", max="+", count=100)
    return {r["message_id"]: r["consumer"] for r in rows}


async def _until(pred, timeout: float = 6.0, interval: float = 0.02) -> None:
    """Ждать истины предиката. `pred` — sync (bool) или coroutine-возвращающий callable."""
    loop = asyncio.get_running_loop()
    deadline = loop.time() + timeout
    while loop.time() < deadline:
        res = pred()
        if asyncio.iscoroutine(res):
            res = await res
        if res:
            return
        await asyncio.sleep(interval)
    raise TimeoutError(f"condition not met within {timeout}s")


async def _pending_zero(client, stream: str) -> bool:
    return await _pending(client, stream) == 0


async def _wait_jobs(workers, timeout: float = 6.0) -> None:
    ws = list(workers) if isinstance(workers, (list, tuple)) else [workers]
    try:
        await wait_for(lambda: all(w.jobs for w in ws), timeout=timeout)
    except TimeoutError:
        got = {w.worker_id: len(w.jobs) for w in ws}
        errs = {w.worker_id: repr(w.error) for w in ws if w.error}
        raise AssertionError(f"jobs not received got={got} errors={errs}")


# ---------------------------------------------------------------------------
# Relay-рекордер (mock Symfony persist) + gateway server
# ---------------------------------------------------------------------------

class RelayRecorder:
    """MockTransport-обработчик: пишет каждый persist-запрос; отдаёт настраиваемый статус."""

    def __init__(self, status: int = 200):
        self.status = status
        self.requests: list[dict] = []

    def handler(self, request: httpx.Request) -> httpx.Response:
        self.requests.append({
            "path": request.url.path,
            "body": json.loads(request.content.decode("utf-8")),
        })
        return httpx.Response(self.status)

    def result_job_ids(self) -> list[str]:
        return [r["body"]["jobId"] for r in self.requests
                if r["path"].endswith("/result")]


def _cfg(**over) -> Config:
    base = dict(
        redis_host="unused", redis_port=6379, redis_db=0, redis_password=None,
        ws_block_ms=50, ws_host="localhost", ws_port=0, worker_api_token=TOKEN,
        ws_result_inline_max=262144, gateway_internal_token=INTERNAL_TOKEN,
        symfony_internal_url=BASE_URL, reclaim_batch=10,
    )
    base.update(over)
    return Config(**base)


def _relay(rec: RelayRecorder) -> RelayClient:
    client = httpx.AsyncClient(transport=httpx.MockTransport(rec.handler))
    return RelayClient(BASE_URL, INTERNAL_TOKEN, client=client)


@asynccontextmanager
async def _gateway(kv: KeyDbGateway, rec: RelayRecorder, cfg: Config | None = None):
    gw = WsGateway(cfg or _cfg(), kv, relay=_relay(rec))
    async with serve(gw.handle, "localhost", 0) as server:
        yield gw, server.sockets[0].getsockname()[1]


# ---------------------------------------------------------------------------
# FakeWorker — «сырой» WS-клиент (НИКАКОГО KeyDB/S3)
# ---------------------------------------------------------------------------

def _b64(nbytes: int = 8) -> str:
    return base64.b64encode(b"x" * nbytes).decode("ascii")


class FakeWorker:
    """WS-воркер поверх `websockets`: ready → приём job → (опц.) inline-result.

    Умышленно НЕ импортирует redis/boto3 — общается ТОЛЬКО по WS, чем структурно
    подтверждает инвариант «воркер не трогает KeyDB/S3».

    auto_result=False → воркер УДЕРЖИВАЕТ задачу (не ack'ает) — для reclaim-сценариев.
    die_after_job=True → воркер РВЁТ соединение сразу после приёма job (запись
    остаётся pending в его PEL) — имитация «мёртвого посреди задачи».
    """

    def __init__(self, port, worker_id, worker_type, *,
                 auto_result=True, die_after_job=False):
        self._uri = f"ws://localhost:{port}"
        self.worker_id = worker_id
        self.worker_type = worker_type
        self._auto = auto_result
        self._die = die_after_job
        self.jobs: list[dict] = []
        self.error: BaseException | None = None
        self._ws = None
        self._ready = asyncio.Event()
        self._task: asyncio.Task | None = None

    async def start(self) -> None:
        self._task = asyncio.create_task(self._run())
        await asyncio.wait_for(self._ready.wait(), timeout=4.0)

    async def _run(self) -> None:
        headers = {"Authorization": f"Bearer {TOKEN}"}
        try:
            async with connect(self._uri, additional_headers=headers) as ws:
                self._ws = ws
                await ws.send(json.dumps({
                    "type": "ready", "workerId": self.worker_id,
                    "workerType": self.worker_type, "slots": 1, "version": "0.1",
                }))
                async for raw in ws:
                    frame = json.loads(raw)
                    ftype = frame.get("type")
                    if ftype == "ready-ack":
                        self._ready.set()
                        continue
                    if ftype != "job":
                        continue
                    self.jobs.append(frame)
                    if self._die:
                        return  # рвём соединение — запись остаётся pending
                    if self._auto:
                        await self._send_result(frame["jobId"])
        except asyncio.CancelledError:
            raise
        except BaseException as exc:  # noqa: BLE001 — диагностика в тестах
            self.error = exc

    async def _send_result(self, job_id: str) -> None:
        await self._ws.send(json.dumps({
            "type": "result", "jobId": job_id,
            "inline": _b64(), "mime": "application/octet-stream",
        }))

    async def send_result(self, job_id: str) -> None:
        """Ручная отправка результата (для удерживаемой auto_result=False задачи)."""
        await self._send_result(job_id)

    async def stop(self) -> None:
        if self._ws is not None:
            with suppress(Exception):
                await self._ws.close()
        if self._task is not None:
            with suppress(Exception):
                await asyncio.wait_for(self._task, timeout=3.0)


# ---------------------------------------------------------------------------
# [1] Маршрутизация: каждый из 6 типов получает ТОЛЬКО свой conv.<type>
# ---------------------------------------------------------------------------

async def test_all_six_types_route_to_own_stream():
    client, kv = await _new_real_kv()
    rec = RelayRecorder(status=200)
    try:
        await _wipe(client)
        # Засеять по одной задаче в КАЖДЫЙ conv.<type>.
        for t in WORKER_TYPES:
            await _seed(client, stream_for(t), _job(CONV_ID[t], t[:3], "out", t))

        async with _gateway(kv, rec) as (_gw, port):
            workers = [FakeWorker(port, f"w-{t}", t) for t in WORKER_TYPES]
            for w in workers:
                await w.start()
            try:
                # Каждый воркер получает ровно свою задачу.
                await _wait_jobs(workers)
                # Все 6 persist'нуты и все PEL опустели (gateway XACK'нул).
                await _until(lambda: len(rec.result_job_ids()) == len(WORKER_TYPES))

                async def _all_pels_empty() -> bool:
                    for t in WORKER_TYPES:
                        if await _pending(client, stream_for(t)) != 0:
                            return False
                    return True

                await _until(_all_pels_empty)
            finally:
                for w in workers:
                    await w.stop()

        # Маршрутизация: каждый воркер увидел РОВНО ОДНУ задачу — свою.
        for w in workers:
            assert len(w.jobs) == 1, f"{w.worker_id} got {len(w.jobs)} jobs"
            assert w.jobs[0]["conversionId"] == CONV_ID[w.worker_type]
        # image-воркер увидел именно conv.image (130) и никогда чужой.
        img = next(w for w in workers if w.worker_type == "image")
        assert img.jobs[0]["conversionId"] == CONV_ID["image"]
    finally:
        await _wipe(client)
        await client.aclose()


# ---------------------------------------------------------------------------
# [2] ffmpeg audio+video: два workerId с НЕПЕРЕСЕКАЮЩИМИСЯ PEL
# ---------------------------------------------------------------------------

async def test_ffmpeg_dual_connection_disjoint_pel():
    client, kv = await _new_real_kv()
    rec = RelayRecorder(status=200)
    try:
        await _wipe(client)
        aid = await _seed(client, "conv.audio", _job(CONV_ID["audio"], "mp3", "ogg", "audio"))
        vid = await _seed(client, "conv.video", _job(CONV_ID["video"], "mp4", "webm", "video"))

        async with _gateway(kv, rec) as (_gw, port):
            # auto_result=False → воркеры УДЕРЖИВАЮТ задачи, PEL заполнен и проверяем.
            wa = FakeWorker(port, "ffmpeg-audio", "audio", auto_result=False)
            wv = FakeWorker(port, "ffmpeg-video", "video", auto_result=False)
            await wa.start()
            await wv.start()
            try:
                await _wait_jobs([wa, wv])

                # Disjoint PEL: audio-запись у ffmpeg-audio, video-запись у ffmpeg-video.
                # (id'ы стримов per-stream, не глобальны — disjoint меряем по consumer'у.)
                audio_pel = await _pending_consumers(client, "conv.audio")
                video_pel = await _pending_consumers(client, "conv.video")
                assert audio_pel == {aid: "ffmpeg-audio"}
                assert video_pel == {vid: "ffmpeg-video"}
                # PEL двух соединений принадлежат НЕПЕРЕСЕКАЮЩИМСЯ consumer'ам
                # (свой workerId на каждый тип) — задачи не делятся между соединениями.
                assert set(audio_pel.values()).isdisjoint(video_pel.values())
            finally:
                await wa.stop()
                await wv.stop()

        # Каждое соединение увидело только свой тип.
        assert len(wa.jobs) == 1 and wa.jobs[0]["conversionId"] == CONV_ID["audio"]
        assert len(wv.jobs) == 1 and wv.jobs[0]["conversionId"] == CONV_ID["video"]
    finally:
        await _wipe(client)
        await client.aclose()


# ---------------------------------------------------------------------------
# [3] Backstop-reclaim: мёртвый воркер → idle-reclaim → переотправка второму
# ---------------------------------------------------------------------------

async def test_backstop_reclaim_redispatches_to_second_worker():
    client, kv = await _new_real_kv()
    rec = RelayRecorder(status=200)
    # Крошечный per-type idle-порог для image → reclaim подберёт «зависшую» запись.
    cfg = _cfg(reclaim_idle_ms_image=1)
    try:
        await _wipe(client)
        job_id = await _seed(client, "conv.image", _job(CONV_ID["image"], "png", "txt", "image"))

        async with _gateway(kv, rec, cfg) as (gw, port):
            # Воркер A получает задачу и УМИРАЕТ (рвёт соединение, не ack'нув).
            dead = FakeWorker(port, "image-dead", "image", die_after_job=True)
            await dead.start()
            await wait_for(lambda: dead.jobs, timeout=6.0)
            await dead.stop()
            assert dead.jobs[0]["jobId"] == job_id
            # Запись всё ещё pending в PEL мёртвого воркера.
            assert await _pending(client, "conv.image") == 1

            # Накопить idle > 1ms, затем один детерминированный прогон reclaim.
            await asyncio.sleep(0.02)
            await reclaim._sweep_all_types(kv, cfg, gw.get_handoff_queues())

            # Воркер B подключается → подхватывает handoff-запись → ack.
            alive = FakeWorker(port, "image-alive", "image")
            await alive.start()
            try:
                await wait_for(lambda: alive.jobs, timeout=6.0)
                await _until(lambda: len(rec.result_job_ids()) == 1)
                # PEL в итоге пуст (§6.6 «b»: reclaim → redispatch → XACK).
                await _until(lambda: _pending_zero(client, "conv.image"))
            finally:
                await alive.stop()

        # Переотправлена ТА ЖЕ запись второму воркеру; ровно один persist.
        assert alive.jobs[0]["jobId"] == job_id
        assert rec.result_job_ids() == [job_id]
    finally:
        await _wipe(client)
        await client.aclose()


# ---------------------------------------------------------------------------
# [4] Дублирующая доставка (cross-connection): тот же jobId → детерминированный якорь
# ---------------------------------------------------------------------------

async def test_duplicate_delivery_same_job_deterministic_anchor():
    """Форсируем ДВОЙНУЮ доставку одной записи двум соединениям (A удерживает, reclaim
    отдаёт B). Транспорт по дизайну НЕ дедуплицирует межсоединенческую доставку —
    он лишь гарантирует, что оба persist'а несут ТОТ ЖЕ jobId (детерминированный
    S3-ключ). Схлопывание в один результат + однократная квота — это PHP
    ConversionResultPersister (status-guard), см. ConversionResultPersisterTest.php.
    """
    client, kv = await _new_real_kv()
    rec = RelayRecorder(status=200)
    cfg = _cfg(reclaim_idle_ms_data=1)
    try:
        await _wipe(client)
        job_id = await _seed(client, "conv.data", _job(CONV_ID["data"], "csv", "json", "data"))

        async with _gateway(kv, rec, cfg) as (gw, port):
            # A получает задачу и УДЕРЖИВАЕТ её (не ack) — считает своей.
            wa = FakeWorker(port, "data-a", "data", auto_result=False)
            await wa.start()
            await _wait_jobs(wa)
            assert wa.jobs[0]["jobId"] == job_id

            # idle-reclaim передаёт ТУ ЖЕ запись второму воркеру, пока A ещё «держит».
            await asyncio.sleep(0.02)
            await reclaim._sweep_all_types(kv, cfg, gw.get_handoff_queues())

            wb = FakeWorker(port, "data-b", "data")  # auto_result → persist #1 + XACK
            await wb.start()
            try:
                await wait_for(lambda: wb.jobs, timeout=6.0)
                await _until(lambda: len(rec.result_job_ids()) >= 1)
                await _until(lambda: _pending_zero(client, "conv.data"))

                # Теперь A (всё ещё «владеющий») тоже шлёт результат → persist #2.
                await wa.send_result(job_id)
                await _until(lambda: len(rec.result_job_ids()) == 2)
            finally:
                await wa.stop()
                await wb.stop()

        # Дублирующая доставка достигла persist-слоя ДВАЖДЫ, но оба — под ТЕМ ЖЕ jobId
        # (детерминированный ключ). PHP-persister схлопнет их в один результат.
        job_ids = rec.result_job_ids()
        assert len(job_ids) == 2
        assert set(job_ids) == {job_id}
        assert wb.jobs[0]["jobId"] == job_id
        # PEL пуст — XACK идемпотентен (второй ack — no-op на уровне KeyDB).
        assert await _pending(client, "conv.data") == 0
    finally:
        await _wipe(client)
        await client.aclose()
