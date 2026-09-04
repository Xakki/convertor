"""WS-сервер WS-Gateway + кредитный dispatch (s1-03, §4 spec).

Протокол — pull со стороны воркера на кредитах: воркер аутентифицируется в
WS-handshake (граница a, §7), шлёт `ready{workerId, workerType, slots, ...}`,
gateway на каждый свободный кредит читает одну запись `conv.<workerType>` и
проталкивает её фреймом `job`. Запись остаётся **pending** (не acked) — освобождение
кредита, `result`/`fail`, `XACK`, `ping`/`progress` — это s1-04/05/07.

Инварианты этого шага:
- одно соединение = один `workerType` = один stream `conv.<type>` (§6.2);
- имя KeyDB-consumer'а = handshake `workerId` ДОСЛОВНО, без PID (§6.1);
- при (пере)подключении сперва возобновить собственный PEL воркера (read_pending,
  id `0`, §6.6 путь «a»), затем читать новые (`>`);
- на каждый свободный кредит `reclaim_stale()` (неблок.) ИДЁТ ПЕРЕД `read_new()`
  (блок.) — иначе блокирующее чтение голодало бы stale-reclaim (s1-02 nit #1);
- `version` (ready) только принимается и логируется — не потребляется (S1).
- `cpu`/`mem`/`load` (ready + ping) регистрируются в `LivenessAggregator`
  (registry-06, `workers/gateway/liveness.py`) по `(workerType, instanceId)` и
  батчем пушатся в PHP отдельной периодической задачей — см. `_handle_ping` /
  `handle()` (record_connect/record_ping/record_disconnect). `instanceId`
  (ready-фрейм, аддитивное поле) отсутствует у старых воркеров — их
  соединения просто не трекаются для liveness, диспетч задач не зависит от
  этого поля никак.
"""

from __future__ import annotations

import asyncio
import base64
import binascii
import hmac
import json
import logging
import math
import re
import time
from collections import deque
from contextlib import suppress
from dataclasses import dataclass

from redis.exceptions import RedisError
from websockets.asyncio.server import ServerConnection, serve
from websockets.exceptions import ConnectionClosed

from workers.gateway.config import Config
from workers.gateway.keydb import MAX_RETRIES, WORKER_TYPES, KeyDbGateway, stream_for
from workers.gateway.liveness import LivenessAggregator
from workers.gateway.relay import RelayClient

logger = logging.getLogger(__name__)

# WS close code 1008 = policy violation (§6.4/§7): auth-fail / искажённый handshake.
CLOSE_POLICY_VIOLATION = 1008
HOST_TELEMETRY_TYPE = "telemetry"
_HOST_NAME_RE = re.compile(r"^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$")
_HOST_TELEMETRY_MAX_BODY = 65536
_HOST_TELEMETRY_MAX_AGE = 1200
_HOST_TELEMETRY_INTERVAL = 600
_HOST_TELEMETRY_MAX_CONNECTIONS = 64
_HOST_TELEMETRY_MAX_CONNECTIONS_PER_INTERVAL = 256
_HOST_TELEMETRY_MAX_HOSTS = 1024


class TelemetryIngressLimiter:
    """Bound shared-token telemetry connections and asserted-host frames."""

    def __init__(self, clock=time.time) -> None:
        self.clock = clock
        self.active_connections = 0
        self.connection_times: deque[float] = deque()
        self.host_times: dict[str, float] = {}

    def allow_connection(self, now: float | None = None) -> bool:
        now = float(self.clock() if now is None else now)
        cutoff = now - _HOST_TELEMETRY_INTERVAL
        while self.connection_times and self.connection_times[0] <= cutoff:
            self.connection_times.popleft()
        if self.active_connections >= _HOST_TELEMETRY_MAX_CONNECTIONS:
            return False
        if len(self.connection_times) >= _HOST_TELEMETRY_MAX_CONNECTIONS_PER_INTERVAL:
            return False
        self.active_connections += 1
        self.connection_times.append(now)
        return True

    def release_connection(self) -> None:
        self.active_connections = max(0, self.active_connections - 1)

    def allow_frame(self, host: str, now: float | None = None) -> bool:
        now = float(self.clock() if now is None else now)
        previous = self.host_times.get(host)
        if previous is None and len(self.host_times) >= _HOST_TELEMETRY_MAX_HOSTS:
            return False
        if previous is not None and now - previous < _HOST_TELEMETRY_INTERVAL:
            return False
        self.host_times[host] = now
        return True


def _validate_host_telemetry(snapshot: object) -> dict | None:
    """Validate transport limits; `host` remains an asserted value."""
    if not isinstance(snapshot, dict) or len(json.dumps(snapshot, separators=(",", ":"))) > _HOST_TELEMETRY_MAX_BODY:
        return None
    host = snapshot.get("host")
    observed = snapshot.get("observedAt")
    if not isinstance(host, str) or _HOST_NAME_RE.fullmatch(host) is None:
        return None
    if snapshot.get("contractVersion") != 1:
        return None
    if not isinstance(observed, (int, float)) or isinstance(observed, bool) or not math.isfinite(observed):
        return None
    fresh_until = snapshot.get("freshUntil")
    if not isinstance(fresh_until, (int, float)) or isinstance(fresh_until, bool) or not math.isfinite(fresh_until) or fresh_until < observed:
        return None
    now = time.time()
    if observed < now - _HOST_TELEMETRY_MAX_AGE or observed > now or fresh_until < now:
        return None
    if snapshot.get("source") != "host-collector" or snapshot.get("scope") != "host":
        return None
    workers = snapshot.get("workers")
    if not isinstance(workers, dict) or len(workers) > 32:
        return None
    return snapshot


@dataclass(frozen=True)
class WorkerSession:
    """Per-connection состояние после успешного handshake."""

    worker_id: str        # = имя KeyDB-consumer'а (дословно, без PID)
    worker_type: str      # ∈ WORKER_TYPES
    slots: int            # число кредитов (S1 = 1)
    stream: str           # conv.<worker_type>
    # instanceId (registry-06): та же строка, что воркер шлёт в register()
    # (registry-02, ws_client.py::_instance_id()) — теперь ЕЩЁ и в ready-фрейме
    # (ws_client.py::_send_ready), чтобы gateway мог ключевать liveness-агрегацию
    # по (workerType, instanceId). None — старый воркер без этого поля в ready
    # ИЛИ невалидное значение; такое соединение просто не трекается для liveness
    # (см. LivenessAggregator.record_connect), job-диспетч не зависит от этого поля.
    instance_id: str | None = None


class Credits:
    """Per-connection кредитный учёт: множество in-flight jobId + слоты (§4/§5).

    Кредит удерживается, пока запись pending (диспетчеризована, но не acked).
    Диспетчер добавляет jobId при выдаче `job`; reader снимает при ack. Новый
    `XREADGROUP` разрешён, только когда `len(inflight) < slots`. Возобновлённые
    (PEL) записи занимают кредиты — при over-subscription (`resumed > slots`)
    новых не читаем, пока releases не опустят inflight ниже slots.
    """

    def __init__(self, slots: int) -> None:
        self.slots = slots
        self.inflight: set[str] = set()
        # jobId → conversionId для in-flight записей: gateway держит его в памяти,
        # чтобы на `progress`/терминале писать/чистить conv:status:{convId} без
        # лишнего round-trip'а в KeyDB (s1-07). Живёт синхронно с inflight.
        self.conv_by_job: dict[str, int] = {}
        self._cond = asyncio.Condition()

    async def acquire_slot(self) -> None:
        """Ждать, пока появится свободный кредит (`len(inflight) < slots`)."""
        async with self._cond:
            await self._cond.wait_for(lambda: len(self.inflight) < self.slots)

    async def add(self, job_id: str, conv_id: int = 0) -> None:
        """Пометить jobId in-flight (занять кредит) + запомнить его conversionId.
        Идемпотентно (set/dict)."""
        async with self._cond:
            self.inflight.add(job_id)
            if conv_id > 0:
                self.conv_by_job[job_id] = conv_id

    async def release(self, job_id: str) -> bool:
        """Освободить кредит jobId и разбудить диспетчер. False — если не in-flight
        (дубликат/уже освобождён): освобождать нечего, кредит НЕ возвращаем."""
        async with self._cond:
            if job_id not in self.inflight:
                return False
            self.inflight.discard(job_id)
            self.conv_by_job.pop(job_id, None)
            self._cond.notify()
            return True

    def conv_id_for(self, job_id: str) -> int:
        """conversionId in-flight jobId (0 — если не известен). Для conv:status."""
        return self.conv_by_job.get(job_id, 0)


def _extract_bearer(headers) -> str | None:
    """Достать bearer-токен из заголовка `Authorization: Bearer <token>`."""
    raw = headers.get("Authorization")
    if not raw:
        return None
    parts = raw.split(" ", 1)
    if len(parts) != 2 or parts[0].lower() != "bearer":
        return None
    token = parts[1].strip()
    return token or None


def _as_float(value) -> float | None:
    """Coerce a ping-frame telemetry value to float, or None (missing/garbage).
    Defensive against a malformed/adversarial worker frame — mirrors the
    already-existing `_clamp_percent` coercion style used for progress frames."""
    if value is None:
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _clamp_percent(value) -> int:
    """percent из progress-фрейма → int в 0..100; невалидный/None → 0."""
    try:
        n = int(round(float(value)))
    except (TypeError, ValueError):
        return 0
    return max(0, min(100, n))


def _try_handoff(queue: asyncio.Queue | None) -> tuple[str, dict] | None:
    """Неблокирующий pop из handoff-очереди или None (пустая / нет очереди).

    Вызывается в _dispatch перед reclaim_stale/read_new — handoff имеет приоритет:
    запись уже переклеймлена idle-reclaim, мета и conv:status обновлены.
    """
    if queue is None:
        return None
    try:
        return queue.get_nowait()
    except asyncio.QueueEmpty:
        return None


class WsGateway:
    """WS-сервер + кредитный dispatch поверх KeyDB-слоя (s1-02)."""

    def __init__(
        self,
        cfg: Config,
        keydb: KeyDbGateway,
        relay: RelayClient | None = None,
        liveness: LivenessAggregator | None = None,
        telemetry_limiter: TelemetryIngressLimiter | None = None,
    ) -> None:
        self._cfg = cfg
        self._keydb = keydb
        self._relay = relay  # None → lazy-init собственного (см. _get_relay)
        # None → собственный агрегатор (registry-06); инжектируется в тестах,
        # чтобы assert'ить на нём напрямую без гонки с реальным push-циклом.
        self._liveness = liveness if liveness is not None else LivenessAggregator()
        self._telemetry_limiter = telemetry_limiter or TelemetryIngressLimiter()
        # Per-type handoff-очереди для idle-reclaim (s1-06): reclaim-цикл кладёт
        # переклеймленные записи, _dispatch забирает их до reclaim_stale/read_new.
        # Без maxsize — очередь органически ограничена: каждый цикл XAUTOCLAIM
        # увеличивает times_delivered, поэтому «застрявшая» запись уходит в DLQ
        # не позднее чем через ≤4 sweep'а; утечка очереди невозможна.
        self._handoff: dict[str, asyncio.Queue] = {
            t: asyncio.Queue() for t in WORKER_TYPES
        }
        # registry-09: (workerType, instanceId) → live WS connection, so the
        # liveness push loop can send a `re-register` control frame to a worker
        # PHP reported as having no capability row. Populated ONLY for
        # connections that presented a valid instanceId (same key space as
        # `LivenessAggregator._alive`) and removed on teardown with an IDENTITY
        # check — a reconnect that lands before the old handler's finally runs
        # must not have its fresh entry deleted by the dying one.
        self._conns: dict[tuple[str, str], ServerConnection] = {}

    def get_handoff_queues(self) -> dict[str, asyncio.Queue]:
        """Per-type handoff-очереди для idle-reclaim (s1-06)."""
        return self._handoff

    def get_liveness_aggregator(self) -> LivenessAggregator:
        """Агрегатор liveness (registry-06) — читается `run_liveness_push_loop`,
        запущенным рядом отдельной asyncio-задачей из `__main__.py`."""
        return self._liveness

    @staticmethod
    def _conn_key(session: WorkerSession) -> tuple[str, str] | None:
        """Ключ реестра соединений (registry-09) — тот же `(workerType,
        instanceId)`, которым ключуется `LivenessAggregator`. None — старый
        воркер без instanceId в ready: его нельзя ни отследить в liveness, ни
        адресно попросить перерегистрироваться (диспетч задач не затронут)."""
        if not session.instance_id:
            return None
        return (session.worker_type, session.instance_id)

    async def request_reregister(self, worker_type: str, instance_id: str) -> bool:
        """Попросить конкретный живой инстанс заново выполнить `register()`
        (registry-09 self-healing). Зовётся из liveness-push-цикла, когда PHP
        вернул этот инстанс в `unknown` (строки capability нет — воркер проиграл
        гонку register при деплое, либо строку уже собрал GC).

        Контроль-фрейм `re-register` — АДДИТИВНЫЙ: воркер старого билда попадёт
        в `else`-ветку своего `_reader_loop` и просто залогирует его в debug —
        не крашится и не рвёт соединение (см. `workers/common/ws_client.py`).

        True — фрейм отправлен; False — соединения уже нет (гонка со снапшотом)
        либо оно закрылось прямо в момент отправки. Не бросает: это телеметрия,
        а не путь доставки задачи. Отправка из ЧУЖОЙ задачи безопасна — по этому
        же сокету уже конкурентно шлют `pong` (reader) и `job` (dispatcher).
        """
        ws = self._conns.get((worker_type, instance_id))
        if ws is None:
            return False
        try:
            await ws.send(json.dumps({"type": "re-register", "reason": "no capability row"}))
        except ConnectionClosed:
            return False
        logger.info(
            "re-register frame sent",
            extra={"workerType": worker_type, "instanceId": instance_id},
        )
        return True

    def _get_relay(self) -> RelayClient:
        """Ленивая инициализация relay-клиента (создаём в async-контексте, не в
        __init__). В тестах relay инжектится через конструктор."""
        if self._relay is None:
            self._relay = RelayClient(
                self._cfg.symfony_internal_url, self._cfg.gateway_internal_token
            )
        return self._relay

    async def serve_forever(self) -> None:
        """Поднять WS-сервер и работать до отмены."""
        try:
            async with serve(self.handle, self._cfg.ws_host, self._cfg.ws_port):
                logger.info(
                    "ws-gateway listening",
                    extra={"wsHost": self._cfg.ws_host, "wsPort": self._cfg.ws_port},
                )
                await asyncio.Future()  # работать вечно (до отмены)
        finally:
            if self._relay is not None:
                await self._relay.aclose()

    # ------------------------------------------------------------------
    # Соединение
    # ------------------------------------------------------------------

    async def handle(self, ws: ServerConnection) -> None:
        """Хендлер одного WS-соединения: auth → handshake → dispatch."""
        # --- Граница a (§7): bearer в WS-upgrade; невалидный → close 1008 ДО ready.
        # constant-time сравнение (hmac.compare_digest) — без timing side-channel по
        # токену; пустой сконфигуренный/присланный токен → fail-closed (reject).
        token = _extract_bearer(ws.request.headers)
        expected = self._cfg.worker_api_token
        if not expected or not token or not hmac.compare_digest(token, expected):
            logger.warning("ws auth rejected", extra={"remote": str(ws.remote_address)})
            await ws.close(CLOSE_POLICY_VIOLATION, "unauthorized")
            return

        session = await self._handshake(ws)
        if session is None:
            return
        if session.worker_type == HOST_TELEMETRY_TYPE:
            if not self._telemetry_limiter.allow_connection():
                await ws.close(CLOSE_POLICY_VIOLATION, "telemetry rate limit")
                return
            try:
                await self._read_host_telemetry(ws)
            finally:
                self._telemetry_limiter.release_connection()
            return

        logger.info(
            "worker ready",
            extra={
                "workerId": session.worker_id,
                "workerType": session.worker_type,
                "slots": session.slots,
                "stream": session.stream,
            },
        )
        # registry-06: агрегатор сам логирует WARNING, если instanceId
        # отсутствует/невалиден — здесь просто передаём, что есть.
        self._liveness.record_connect(session.worker_type, session.instance_id)
        conn_key = self._conn_key(session)
        if conn_key is not None:
            self._conns[conn_key] = ws

        # Диспетч и чтение входящих фреймов — параллельно; закрытие соединения
        # (клиентом) завершает reader → отменяем dispatcher. Кредитный учёт общий:
        # dispatcher занимает кредит при выдаче job, reader освобождает при ack.
        credits = Credits(session.slots)
        reader = asyncio.create_task(self._read_frames(ws, session, credits))
        dispatcher = asyncio.create_task(self._dispatch(ws, session, credits))
        try:
            await asyncio.wait(
                {reader, dispatcher}, return_when=asyncio.FIRST_COMPLETED
            )
        finally:
            for task in (reader, dispatcher):
                task.cancel()
            for task in (reader, dispatcher):
                with suppress(asyncio.CancelledError, ConnectionClosed):
                    await task
            # registry-09: снять СВОЮ запись из реестра соединений — но только
            # если там всё ещё лежит ИМЕННО этот ws. Реконнект того же инстанса
            # может успеть записать новое соединение до того, как отработает
            # этот finally; безусловный pop() убил бы живую запись.
            if conn_key is not None and self._conns.get(conn_key) is ws:
                del self._conns[conn_key]
            # registry-06: обрыв WS → следующий liveness-батч репортит инстанс
            # disconnected (сигнал ТОЛЬКО для админ-вью — НЕ трогает
            # routing-матрицу, gateway её вообще не читает/не пишет).
            self._liveness.record_disconnect(session.worker_type, session.instance_id)

    async def _read_host_telemetry(self, ws: ServerConnection) -> None:
        """Accept one bounded snapshot and relay it with the gateway credential."""
        try:
            raw = await ws.recv()
            frame = json.loads(raw)
            snapshot = frame.get("snapshot") if isinstance(frame, dict) and frame.get("type") == "host-telemetry" else None
            snapshot = _validate_host_telemetry(snapshot)
            if snapshot is None:
                await ws.close(CLOSE_POLICY_VIOLATION, "invalid host telemetry")
                return
            if not self._telemetry_limiter.allow_frame(snapshot["host"]):
                await ws.close(CLOSE_POLICY_VIOLATION, "telemetry rate limit")
                return
            if await self._get_relay().post_host_telemetry(snapshot):
                await ws.send(json.dumps({"type": "host-telemetry-ack"}))
            else:
                await ws.close(1011, "telemetry relay failed")
        except (ConnectionClosed, json.JSONDecodeError, TypeError, ValueError):
            return

    async def _handshake(self, ws: ServerConnection) -> WorkerSession | None:
        """Прочитать и провалидировать фрейм `ready`. Ошибка → close 1008 + None."""
        try:
            raw = await ws.recv()
        except ConnectionClosed:
            return None

        try:
            frame = json.loads(raw)
        except (json.JSONDecodeError, TypeError, ValueError):
            await ws.close(CLOSE_POLICY_VIOLATION, "malformed ready")
            return None

        if not isinstance(frame, dict) or frame.get("type") != "ready":
            await ws.close(CLOSE_POLICY_VIOLATION, "expected ready frame")
            return None

        worker_id = frame.get("workerId")
        worker_type = frame.get("workerType")
        if not isinstance(worker_id, str) or not worker_id:
            await ws.close(CLOSE_POLICY_VIOLATION, "missing workerId")
            return None
        if not isinstance(worker_type, str) or (worker_type not in WORKER_TYPES and worker_type != HOST_TELEMETRY_TYPE):
            await ws.close(CLOSE_POLICY_VIOLATION, "invalid workerType")
            return None

        try:
            slots = int(frame.get("slots", 1) or 1)
        except (TypeError, ValueError):
            slots = 1
        if slots < 1:
            slots = 1

        # cpu/mem/load/version — только принимаем и логируем (S1, не потребляем).
        logger.debug(
            "worker telemetry (accepted, not consumed)",
            extra={
                "workerId": worker_id,
                "version": frame.get("version"),
                "cpu": frame.get("cpu"),
                "mem": frame.get("mem"),
                "load": frame.get("load"),
            },
        )
        # instanceId (registry-06) — аддитивное поле ready-фрейма (ws_client.py
        # ::_send_ready). Старый воркер его не шлёт → None, а не отказ handshake:
        # instanceId используется ТОЛЬКО для liveness-агрегации, не для маршрутизации.
        instance_id_raw = frame.get("instanceId")
        instance_id = instance_id_raw if isinstance(instance_id_raw, str) and instance_id_raw else None
        # Сообщить воркеру авторитетный порог inline — воркер адоптирует его в _deliver.
        await ws.send(json.dumps({
            "type": "ready-ack",
            "inlineMax": self._cfg.ws_result_inline_max,
        }))
        stream = "" if worker_type == HOST_TELEMETRY_TYPE else stream_for(worker_type)
        return WorkerSession(worker_id, worker_type, slots, stream, instance_id)

    # ------------------------------------------------------------------
    # Dispatch
    # ------------------------------------------------------------------

    async def _dispatch(
        self, ws: ServerConnection, session: WorkerSession, credits: Credits
    ) -> None:
        """Возобновление PEL (§6.6 a) + кредитный цикл (§4). Освобождение кредита —
        в reader (`_read_frames`) при ack; здесь только выдача под свободный кредит."""
        consumer = session.worker_id
        stream = session.stream

        # (§6.6 путь «a») Сначала переотправить собственные in-flight записи воркера.
        # Они уже обрабатываются воркером → ЗАНИМАЮТ кредиты (add), поэтому при
        # over-subscription (resumed > slots) новых не читаем, пока не пойдут releases.
        resumed = await self._keydb.read_pending(stream, consumer)
        for job_id, job in resumed:
            conv_id = int(job.get("conversionId", 0) or 0)
            await credits.add(job_id, conv_id)
            await self._keydb.write_job_meta(job_id, job, stream)
            await self._keydb.set_status_processing(job_id, job, consumer)
            await self._push_job(ws, job_id, job)

        # Кредитный цикл: ждём свободный кредит, читаем одну запись, выдаём job.
        while True:
            await credits.acquire_slot()

            # nit #3: транзиентный RedisError на чтении не должен ронять соединение
            # без адресного лога — логируем и продолжаем (воркер/reclaim переживут).
            try:
                # Handoff от idle-reclaim (s1-06) — высший приоритет (уже переклеймлено).
                # nit #1: reclaim_stale (неблок.) ПЕРЕД read_new (блок.).
                entry = _try_handoff(self._handoff.get(session.worker_type))
                if entry is None:
                    entry = await self._keydb.reclaim_stale(stream, consumer)
                if entry is None:
                    entry = await self._keydb.read_new(
                        stream, consumer, self._cfg.ws_block_ms
                    )
            except RedisError as exc:
                logger.warning(
                    "stream read failed, retrying",
                    extra={"stream": stream, "consumer": consumer, "error": str(exc)},
                )
                continue
            if entry is None:
                continue  # block-таймаут / нечего забрать → следующий кредитный тик

            job_id, job = entry
            conv_id = int(job.get("conversionId", 0) or 0)
            await credits.add(job_id, conv_id)  # занять кредит (запись pending до ack)
            await self._keydb.write_job_meta(job_id, job, stream)
            # conv:status={processing} на диспетче — gateway единственный писатель (s1-07).
            await self._keydb.set_status_processing(job_id, job, consumer)
            await self._push_job(ws, job_id, job)

    async def _push_job(self, ws: ServerConnection, job_id: str, job: dict) -> None:
        """Протолкнуть воркеру фрейм `job` (§4). Воркер flag-agnostic — берёт форматы."""
        frame = {
            "type": "job",
            "jobId": job_id,
            "conversionId": int(job.get("conversionId", 0) or 0),
            "sourceFormat": str(job.get("sourceFormat", "") or ""),
            "targetFormat": str(job.get("targetFormat", "") or ""),
            "inputKey": str(job.get("inputKey", "") or ""),
            "inputBucket": str(job.get("inputBucket", "") or ""),
        }
        # model МОЖЕТ ехать вместе для format-логики воркера (§4).
        if job.get("model") is not None:
            frame["model"] = job["model"]

        await ws.send(json.dumps(frame))
        logger.info(
            "job dispatched",
            extra={"jobId": job_id, "conversionId": frame["conversionId"]},
        )

    # ------------------------------------------------------------------
    # Чтение фреймов воркера: ping (§4/§6.6) / result / fail (§5)
    # ------------------------------------------------------------------

    async def _read_frames(
        self, ws: ServerConnection, session: WorkerSession, credits: Credits
    ) -> None:
        """Читать входящие фреймы воркера до закрытия и маршрутизировать.

        `ping` → `pong` (liveness; кредит НЕ трогает, stream НЕ читает — s1-05);
        `result{inline}` → relay result → ack; `result{resultKey}` → ack на доверии
        (payload воркер уже POST'нул сам); `fail` → relay fail → ack;
        `progress` → HSET conv:status (best-effort, кредит/stream/ack НЕ трогает, s1-07)."""
        with suppress(ConnectionClosed):
            async for raw in ws:
                try:
                    frame = json.loads(raw)
                except (json.JSONDecodeError, TypeError, ValueError):
                    logger.warning("malformed worker frame ignored")
                    continue
                if not isinstance(frame, dict):
                    logger.warning("non-object worker frame ignored")
                    continue

                ftype = frame.get("type")
                if ftype == "ping":
                    await self._handle_ping(ws, session, credits, frame)
                elif ftype == "result":
                    await self._handle_result(session, credits, frame)
                elif ftype == "fail":
                    await self._handle_fail(session, credits, frame)
                elif ftype == "progress":
                    await self._handle_progress(credits, frame)
                else:
                    logger.debug("worker frame ignored", extra={"type": ftype})

    async def _handle_progress(self, credits: Credits, frame: dict) -> None:
        """`progress{jobId, percent:0..100, stage?}` → HSET conv:status (§4, s1-07).

        Best-effort индикатор для UI: эмитится ТОЛЬКО между `job` и терминалом.
        Кредит/stream/ack не трогает. Неизвестный/не-in-flight jobId — игнор (лог).
        percent зажимается в 0..100; невалидный → 0. Ошибка записи — глотается
        (потерянный progress на надёжность не влияет)."""
        job_id = frame.get("jobId")
        if not isinstance(job_id, str) or not job_id:
            logger.debug("progress frame without jobId ignored")
            return
        # Валидируем, что jobId — известная in-flight задача (иначе игнор).
        if job_id not in credits.inflight:
            logger.debug("progress for unknown/not-in-flight job — ignored",
                         extra={"jobId": job_id})
            return
        conv_id = credits.conv_id_for(job_id)
        if conv_id <= 0:
            logger.debug("progress with no conversionId — ignored",
                         extra={"jobId": job_id})
            return

        percent = _clamp_percent(frame.get("percent"))
        stage = frame.get("stage")
        stage = stage if isinstance(stage, str) else None
        try:
            await self._keydb.update_status_progress(conv_id, percent, stage)
        except RedisError as exc:
            logger.warning(
                "progress conv:status update failed (best-effort, ignored)",
                extra={"jobId": job_id, "conversionId": conv_id, "error": str(exc)},
            )

    async def _handle_ping(
        self, ws: ServerConnection, session: WorkerSession, credits: Credits, frame: dict
    ) -> None:
        """`ping{cpu,mem,load}` → `pong` по тому же WS (liveness, §4/§6.6).

        Критерий reconnect («N пропущенных ping'ов», backoff) — на СТОРОНЕ ВОРКЕРА
        (s1-08); сервер лишь отвечает `pong`. Кредит НЕ занимается, stream НЕ
        читается, XACK нет. registry-06: cpu/mem/load теперь ЕЩЁ и агрегируются
        по `(workerType, instanceId)` для периодического push в PHP — см.
        `workers/gateway/liveness.py`; сам ping/pong-путь этим не меняется.
        CNV-61: `credits.inflight` (та же связка, что держит кредитный учёт
        §4/§5) отдаём агрегатору как `len(inflight)` — сколько job'ов инстанс
        держит прямо сейчас; может быть > slots на resumed PEL (см. Credits) —
        репортим как есть, не клэмпим."""
        logger.debug(
            "ping telemetry (accepted, not consumed)",
            extra={"cpu": frame.get("cpu"), "mem": frame.get("mem"),
                   "load": frame.get("load")},
        )
        self._liveness.record_ping(
            session.worker_type, session.instance_id,
            _as_float(frame.get("cpu")), _as_float(frame.get("mem")), _as_float(frame.get("load")),
            inflight=len(credits.inflight),
        )
        await ws.send(json.dumps({"type": "pong"}))

    async def _handle_result(
        self, session: WorkerSession, credits: Credits, frame: dict
    ) -> None:
        """result{inline|resultKey}. Маршрут по наличию поля; ack по §5."""
        job_id = frame.get("jobId")
        if not isinstance(job_id, str) or not job_id:
            logger.warning("result frame without jobId ignored")
            return

        # Идемпотентность (carry-forward s1-03): дубликат result для уже acked/
        # неизвестного jobId — no-op ДО relay (не тратим persist-запрос повторно).
        if job_id not in credits.inflight:
            logger.info("duplicate/unknown result — ignored (idempotent)",
                        extra={"jobId": job_id})
            return

        result_key = frame.get("resultKey")
        inline = frame.get("inline")
        processing_ms = frame.get("processingMs")

        # Dual-payload (CNV-53): ровно одно из inline|resultKey. Оба сразу —
        # malformed → permanent DLQ ДО large-path trust-ack (иначе обход
        # pre-relay cap/decode из CNV-37 через прикреплённый resultKey).
        if result_key and inline is not None:
            logger.warning(
                "result frame has both inline and resultKey → DLQ",
                extra={"jobId": job_id},
            )
            await self._to_dlq_and_release(
                session, credits, job_id,
                "result frame has both inline and resultKey",
                processing_ms,
            )
            return

        if result_key:
            # Большой путь: файл воркер POST'нул сам, payload через gateway не шёл →
            # ack на доверии к WS-сообщению (§5).
            await self._ack_and_release(session, credits, job_id, "result")
            return

        # Permanent pre-relay (CNV-37): malformed / oversize / decode → DLQ сразу
        # через общий `_to_dlq_and_release` (симметрия с post-relay 4xx). Иначе
        # `_release_no_ack` оставлял запись в PEL и idle-reclaim крутил её вечно.
        if inline is None:
            logger.warning(
                "result frame has neither inline nor resultKey → DLQ",
                extra={"jobId": job_id},
            )
            await self._to_dlq_and_release(
                session, credits, job_id,
                "result frame has neither inline nor resultKey",
                processing_ms,
            )
            return

        if not isinstance(inline, str):
            logger.warning(
                "result inline is not a string → DLQ",
                extra={"jobId": job_id},
            )
            await self._to_dlq_and_release(
                session, credits, job_id,
                "result inline is not a string",
                processing_ms,
            )
            return

        # Порог: меряем ДЕКОДИРОВАННЫЕ байты. Свыше порога / не-base64 → DLQ
        # (воркер обязан был идти большим путём; повтор не поможет).
        decoded_len = self._inline_decoded_len(inline)
        if decoded_len is None:
            logger.warning(
                "result inline is not valid base64 → DLQ",
                extra={"jobId": job_id},
            )
            await self._to_dlq_and_release(
                session, credits, job_id,
                "result inline is not valid base64 — rejected",
                processing_ms,
            )
            return
        if decoded_len > self._cfg.ws_result_inline_max:
            logger.warning(
                "inline result exceeds WS_RESULT_INLINE_MAX → DLQ",
                extra={"jobId": job_id, "bytes": decoded_len,
                       "max": self._cfg.ws_result_inline_max},
            )
            await self._to_dlq_and_release(
                session, credits, job_id,
                "inline result exceeds WS_RESULT_INLINE_MAX — rejected",
                processing_ms,
            )
            return

        # relay inline → Symfony; ack ТОЛЬКО при 2xx (persist подтверждён).
        ok, status = await self._get_relay().post_result(
            job_id, inline, frame.get("mime"), processing_ms
        )
        if ok:
            await self._ack_and_release(session, credits, job_id, "result")
            return

        # HTTP 4xx — permanent client error (пустой data, битый jobId и т.п.) → DLQ
        # сразу, без бесконечного idle-reclaim (symmetric с permanent fail-веткой).
        if status is not None and 400 <= status < 500:
            reason = f"inline relay rejected HTTP {status}"
            logger.warning(
                "inline relay 4xx → DLQ",
                extra={"jobId": job_id, "status": status},
            )
            await self._to_dlq_and_release(
                session, credits, job_id, reason, processing_ms
            )
            return

        # HTTP 5xx / сеть — capped retry (times_delivered / MAX_RETRIES), как fail-ветка.
        times_delivered = await self._keydb.get_times_delivered(session.stream, job_id)
        if times_delivered > MAX_RETRIES:
            reason = f"inline relay failed (times_delivered={times_delivered})"
            logger.warning(
                "inline relay max retries exceeded → DLQ",
                extra={"jobId": job_id, "timesDelivered": times_delivered,
                       "maxRetries": MAX_RETRIES, "status": status},
            )
            await self._to_dlq_and_release(
                session, credits, job_id, reason, processing_ms
            )
            return

        # Retryable: оставить unacked (idle-reclaim переклеймит), кредит освободить.
        await self._release_no_ack(
            credits, job_id,
            "inline relay failed — pending, credit released",
            timesDelivered=times_delivered, status=status,
        )

    async def _handle_fail(
        self, session: WorkerSession, credits: Credits, frame: dict
    ) -> None:
        """fail → DLQ (permanent / times_delivered>MAX_RETRIES) или release_no_ack.

        permanent:true → немедленный DLQ (детерминированная неретраябельная ошибка).
        times_delivered > MAX_RETRIES → DLQ (poison-job по счётчику PEL).
        Иначе: кредит освобождается, запись остаётся unacked — idle-reclaim подберёт
        и передиспетчеризует (retry). Relay (post_fail/post_result) НЕ вызывается ни
        в одной ветке: DLQ-consumer (`dlq_consumer.py`) читает `conv.dead` отдельно
        и сам зовёт relay (`post_dlq_fail`); retryable-fail → pending (результат
        через retry).

        ТРЕБОВАНИЕ к consumer'у `conv.dead` (`dlq_consumer.py`): он ОБЯЗАН быть
        идемпотентным по `conversionId` — записи DLQ пишутся at-least-once (см.
        docstring `KeyDB.add_to_dlq`), возможны дубли на один conversionId.
        Выполняется на стороне Symfony через status-guard
        `ConversionResultPersister` (skip, если статус уже Completed/Failed).
        """
        job_id = frame.get("jobId")
        if not isinstance(job_id, str) or not job_id:
            logger.warning("fail frame without jobId ignored")
            return
        if job_id not in credits.inflight:
            logger.info("duplicate/unknown fail — ignored (idempotent)",
                        extra={"jobId": job_id})
            return

        error = frame.get("error")
        error = str(error) if error is not None else ""
        permanent = bool(frame.get("permanent"))
        processing_ms = frame.get("processingMs")

        if permanent:
            await self._to_dlq_and_release(
                session, credits, job_id, error, processing_ms
            )
            return

        times_delivered = await self._keydb.get_times_delivered(session.stream, job_id)
        if times_delivered > MAX_RETRIES:
            reason = f"{error} (times_delivered={times_delivered})"
            logger.warning(
                "max retries exceeded → DLQ",
                extra={"jobId": job_id, "timesDelivered": times_delivered,
                       "maxRetries": MAX_RETRIES},
            )
            await self._to_dlq_and_release(
                session, credits, job_id, reason, processing_ms
            )
            return

        # Retryable: оставить unacked (idle-reclaim переклеймит), кредит освободить.
        await self._release_no_ack(
            credits, job_id,
            "fail retryable — leaving pending for idle-reclaim retry",
            timesDelivered=times_delivered,
        )

    async def _to_dlq_and_release(
        self,
        session: WorkerSession,
        credits: Credits,
        job_id: str,
        reason: str,
        processing_ms: int | None = None,
    ) -> None:
        """DLQ: XADD conv.dead + XACK + DEL меты + кредит освобождён. Идемпотентно.

        `processing_ms` — из fail-фрейма воркера (может отсутствовать/быть None);
        прокидывается в DLQ-payload дословно (см. `KeyDbGateway.add_to_dlq`), чтобы
        DLQ-consumer мог передать его в финализацию `Conversion.failed`.

        `attempt` (requeue-generation-маркер, `requeue-attempt-generation-marker`)
        читается из `job_id`-меты ТЕМ ЖЕ путём, что и `conv_id` — самого job-dict
        здесь уже нет (fail-фрейм несёт только jobId), только то, что `_dispatch`
        сохранил в `write_job_meta`. Сбой `get_job_meta` или отсутствие ключа
        (legacy-мета до раскатки этого изменения) → дефолт 0, как у `conv_id`.
        """
        if job_id not in credits.inflight:
            logger.info("duplicate DLQ call — ignored", extra={"jobId": job_id})
            return
        conv_id = 0
        attempt = 0
        try:
            meta = await self._keydb.get_job_meta(job_id)
            if meta:
                conv_id = meta.get("conversionId", 0) or 0
                attempt = meta.get("attempt", 0) or 0
        except Exception as exc:
            logger.warning(
                "get_job_meta failed in DLQ path — conv_id=0",
                extra={"jobId": job_id, "error": str(exc)},
            )
        try:
            await self._keydb.add_to_dlq(
                session.stream, job_id, conv_id, reason, processing_ms, attempt
            )
        except Exception as exc:
            # DLQ-запись НЕ прошла → это НЕ терминал: запись остаётся полностью
            # pending (без XACK), idle-reclaim переклеймит и повторит. conv:status
            # НЕ чистим — задача ещё в работе (at-least-once контракт add_to_dlq).
            # Освобождаем только кредит, чтобы соединение не клинило на acquire_slot.
            logger.warning(
                "add_to_dlq failed — leaving pending, conv:status kept for reclaim",
                extra={"jobId": job_id, "error": str(exc)},
            )
            await credits.release(job_id)
            return
        # Терминал (DLQ прошёл): чистим живой conv:status (D5). conv_id из меты может
        # быть 0 при сбое get_job_meta — тогда берём из in-memory map кредитов.
        clear_id = conv_id if conv_id > 0 else credits.conv_id_for(job_id)
        if clear_id > 0:
            with suppress(RedisError):
                await self._keydb.clear_status(clear_id)
        await credits.release(job_id)
        logger.info(
            "job sent to DLQ",
            extra={"jobId": job_id, "conversionId": conv_id, "reason": reason},
        )

    async def _ack_and_release(
        self, session: WorkerSession, credits: Credits, job_id: str, kind: str
    ) -> None:
        """XACK (+DEL меты) затем освободить кредит. Идемпотентно: дубликат для уже
        acked/неизвестного jobId — no-op. Транзиентный RedisError на XACK НЕ роняет
        соединение — persist уже сделан (at-least-once), кредит всё равно освобождаем."""
        if job_id not in credits.inflight:
            logger.info(f"duplicate/unknown {kind} ack — ignored (idempotent)",
                        extra={"jobId": job_id})
            return
        conv_id = credits.conv_id_for(job_id)  # до release (release чистит map)
        try:
            await self._keydb.ack(session.stream, job_id)  # XACK + DEL (идемпотентны)
        except RedisError as exc:
            logger.warning(
                "XACK failed after persist — leaving pending, releasing credit",
                extra={"jobId": job_id, "stream": session.stream, "error": str(exc)},
            )
        # Терминал: живой conv:status больше не нужен — истина в строке БД (D5).
        # Best-effort: ошибка DEL не критична (TTL 24 h всё равно вычистит).
        if conv_id > 0:
            with suppress(RedisError):
                await self._keydb.clear_status(conv_id)
        await credits.release(job_id)

    async def _release_no_ack(
        self, credits: Credits, job_id: str, reason: str, **extra
    ) -> None:
        """Освободить кредит БЕЗ XACK (nit#1): запись остаётся pending — reclaim
        подберёт её позже — но соединение не клинит на acquire_slot."""
        await credits.release(job_id)
        logger.warning(reason, extra={"jobId": job_id, **extra})

    def _inline_decoded_len(self, inline_b64: str) -> int | None:
        """Длина декодированного base64 в байтах, либо None (не base64).

        Сначала дешёвая верхняя граница на длину строки — чтобы не декодировать
        гигантский блоб в память; затем строгий декод для точного размера."""
        max_b64 = (self._cfg.ws_result_inline_max // 3 + 1) * 4 + 4
        if len(inline_b64) > max_b64:
            return self._cfg.ws_result_inline_max + 1  # заведомо свыше порога
        try:
            return len(base64.b64decode(inline_b64, validate=True))
        except (binascii.Error, ValueError):
            return None
