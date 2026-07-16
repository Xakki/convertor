"""KeyDB-слой WS-Gateway — асинхронный (redis.asyncio).

Реплицирует семантику PHP `App\\Service\\Worker\\WorkerStreamGateway` (источник
истины) на стороне gateway: чтение job-стримов `conv.<type>` (`XREADGROUP` для
новых записей + `XAUTOCLAIM` для зависших), мета задачи `worker:job:{jobId}`
(TTL 24 h) и `XACK` + `DEL`. Разбор записи — одна `json.loads` через единый
декодер `workers.common.envelope.parse_message`.

Каждая операция чтения/ack принимает ЯВНЫЙ per-worker `consumer`/`workerId`
(consumer per-worker, не глобальный) — так `XAUTOCLAIM`/`XACK` идут под именем
того воркера, которому диспетчеризована задача.

Poison-safe: искажённая запись или `conversionId <= 0` → `XACK` (дроп) + лог,
возврат `None` — чтобы отравленная запись не крутилась в reclaim вечно.
"""

from __future__ import annotations

import json
import logging
from typing import Any

import redis.asyncio as redis
from redis.exceptions import ResponseError

from workers.common.envelope import parse_message
from workers.gateway.config import Config

logger = logging.getLogger(__name__)

# --- Контракт (байт-в-байт с WorkerStreamGateway.php) ---------------------
GROUP = "convertor"
JOB_META_TTL = 86400            # 24 h
# TTL живого статуса `conv:status:{conversionId}` (D5/§4). Совпадает с JOB_META_TTL,
# но семантически отдельный knob: истёкший хеш → читатель падает на строку БД.
CONV_STATUS_TTL_S = 86400       # 24 h
STALE_IDLE_MS = 300_000         # 5 min — порог XAUTOCLAIM reclaim
WORKER_TYPES = ("ai", "document", "image", "audio", "video", "data")
# Верхняя граница записей PEL, возобновляемых за один read_pending при reconnect.
PENDING_BATCH = 100

# --- DLQ (s1-06, §6.4) ---
# Canonical DLQ-стрим; зеркалит stream_consumer._send_to_dlq.
DLQ_STREAM = "conv.dead"
# Максимум попыток доставки до DLQ (= Python _MAX_RETRIES в stream_consumer).
MAX_RETRIES = 3


def stream_for(worker_type: str) -> str:
    """Имя job-стрима для типа воркера: `conv.<type>`."""
    return "conv." + worker_type


def _to_str(value: Any) -> str:
    """redis.asyncio с decode_responses=True отдаёт str; подстраховка на bytes."""
    if isinstance(value, bytes):
        return value.decode("utf-8")
    return str(value)


def build_client(cfg: Config) -> redis.Redis:
    """Собрать async KeyDB-клиент из Config (см. config.py).

    decode_responses=True — как в синхронных воркерах: id/поля/значения приходят
    как str, `parse_message` их принимает.

    socket_timeout ЗАДАЁМ ЯВНО, производным от `ws_block_ms` (+ запас 5 c), а НЕ
    полагаемся на дефолт redis-py. В контейнере redis-py 8.0.0 дефолтит
    `socket_timeout=5s`, что почти равно серверному `XREADGROUP ... BLOCK
    <ws_block_ms>` (тоже 5000 мс по умолчанию) — клиентский таймаут гонится с
    серверным BLOCK почти на каждом пустом poll'е, клиент кидает
    `redis.exceptions.TimeoutError` раньше/одновременно с ответом сервера →
    сокет рвётся (disconnect_on_error=True) → reconnect-churn (WARNING "stream
    read failed, retrying" в ws_server.py). Держа `socket_timeout` заметно выше
    `ws_block_ms`, обычный пустой BLOCK успевает вернуться штатно, а
    молча-мёртвый TCP-коннект всё ещё детектится и переоткрывается — просто не
    гонится с сервером. `socket_timeout=None` НЕ используем: это вернёт другой
    баг — мёртвое соединение будет вешать диспетч стрима навсегда.
    """
    return redis.Redis(
        host=cfg.redis_host,
        port=cfg.redis_port,
        db=cfg.redis_db,
        password=cfg.redis_password,
        decode_responses=True,
        socket_timeout=cfg.ws_block_ms / 1000 + 5,
    )


class KeyDbGateway:
    """Асинхронный слой доступа к KeyDB Streams для WS-Gateway."""

    def __init__(self, client: redis.Redis) -> None:
        self._redis = client
        # One-time guard: создаём группу один раз на стрим за процесс
        # (зеркалит `groupEnsured` из CleanRedisTransport, s1-01).
        self._groups_ensured: set[str] = set()

    # ------------------------------------------------------------------
    # Группа
    # ------------------------------------------------------------------

    async def ensure_group(self, stream: str) -> None:
        """`XGROUP CREATE <stream> convertor 0 MKSTREAM`; глотаем только BUSYGROUP.

        Любая другая ошибка фатальна (как в PHP `ensureGroup`). Идемпотентно —
        реально ходит в KeyDB один раз на стрим за процесс.
        """
        if stream in self._groups_ensured:
            return
        try:
            await self._redis.xgroup_create(stream, GROUP, id="0", mkstream=True)
        except ResponseError as exc:
            if "BUSYGROUP" not in str(exc):
                raise
        self._groups_ensured.add(stream)

    # ------------------------------------------------------------------
    # Чтение
    # ------------------------------------------------------------------

    async def read_new(
        self, stream: str, consumer: str, block_ms: int
    ) -> tuple[str, dict] | None:
        """`XREADGROUP GROUP convertor <consumer> COUNT 1 BLOCK <ms> STREAMS <stream> >`.

        Возвращает `(jobId, job)` для новой валидной записи, либо `None`
        (нет записи / poison-дроп / conversionId<=0).
        """
        await self.ensure_group(stream)

        messages = await self._redis.xreadgroup(
            GROUP, consumer, {stream: ">"}, count=1, block=block_ms
        )
        entry = _first_entry(messages)
        if entry is None:
            return None

        job_id, fields = entry
        return await self._decode_or_ack(stream, job_id, fields)

    async def read_pending(
        self, stream: str, consumer: str, count: int = PENDING_BATCH
    ) -> list[tuple[str, dict]]:
        """`XREADGROUP GROUP convertor <consumer> COUNT <n> STREAMS <stream> 0`.

        id `0` (НЕ блокирует) — уже-pending записи ЭТОГО consumer'а из его PEL.
        Используется для возобновления при (пере)подключении воркера (§6.6, путь
        «a»): вернуть его in-flight задачи, чтобы gateway переотправил их.
        Poison / `conversionId<=0` дропаются (XACK) теми же guard'ами, что в
        read_new. Возвращает список `(jobId, job)` (пустой — если PEL пуст).
        """
        await self.ensure_group(stream)

        messages = await self._redis.xreadgroup(
            GROUP, consumer, {stream: "0"}, count=count
        )
        out: list[tuple[str, dict]] = []
        for job_id, fields in _all_entries(messages):
            decoded = await self._decode_or_ack(stream, job_id, fields)
            if decoded is not None:
                out.append(decoded)
        return out

    async def reclaim_stale(
        self, stream: str, consumer: str
    ) -> tuple[str, dict] | None:
        """`XAUTOCLAIM <stream> convertor <consumer> 300000 0-0 COUNT 1`.

        Переназначает одну запись, простаивающую дольше 5 мин (упавший consumer).
        Возвращает `(jobId, job)` или `None` (нечего забрать / ошибка → лог+глотаем,
        как PHP `reclaimStale`; poison-дроп / conversionId<=0).
        """
        await self.ensure_group(stream)

        try:
            result = await self._redis.xautoclaim(
                stream, GROUP, consumer, min_idle_time=STALE_IDLE_MS,
                start_id="0-0", count=1,
            )
        except Exception as exc:
            logger.warning(
                "XAUTOCLAIM failed, skipping stale reclaim",
                extra={"stream": stream, "error": str(exc)},
            )
            return None

        # redis 6.2: [next_id, entries]; redis 7.0: [next_id, entries, deleted]
        entries = result[1] if result and len(result) > 1 else []
        if not entries:
            return None

        job_id, fields = entries[0]
        return await self._decode_or_ack(stream, _to_str(job_id), fields)

    # ------------------------------------------------------------------
    # Мета задачи
    # ------------------------------------------------------------------

    async def write_job_meta(self, job_id: str, job: dict, stream: str) -> None:
        """`SETEX worker:job:{jobId} 86400 <json>` — мета для эндпоинтов input/result.

        Набор/порядок полей идентичен `WorkerStreamGateway::claim()` (строки 70-78);
        `stream` = `conv.<type>` из контекста чтения (в payload задачи его нет),
        отсутствующие поля дефолтятся в 0/"". Зеркалит перенос владения из §5 спеки.
        """
        meta = json.dumps({
            "conversionId": int(job.get("conversionId", 0) or 0),
            "inputBucket": str(job.get("inputBucket", "") or ""),
            "inputKey": str(job.get("inputKey", "") or ""),
            "stream": stream,
            "targetFormat": str(job.get("targetFormat", "") or ""),
        })
        # SET ... EX <ttl> == SETEX (одна атомарная запись value+TTL).
        await self._redis.set(_job_key(job_id), meta, ex=JOB_META_TTL)

    async def get_job_meta(self, job_id: str) -> dict | None:
        """`GET worker:job:{jobId}` → разобранный dict или `None` (зеркалит getJobMeta)."""
        raw = await self._redis.get(_job_key(job_id))
        if not raw:
            return None

        data = json.loads(_to_str(raw))
        if not isinstance(data, dict):
            return None

        return {
            "conversionId": int(data.get("conversionId", 0) or 0),
            "inputBucket": str(data.get("inputBucket", "") or ""),
            "inputKey": str(data.get("inputKey", "") or ""),
            "stream": str(data.get("stream", "") or ""),
            "targetFormat": str(data.get("targetFormat", "") or ""),
        }

    # ------------------------------------------------------------------
    # Ack
    # ------------------------------------------------------------------

    async def ack(self, stream: str, job_id: str) -> None:
        """`XACK <stream> convertor <jobId>` + `DEL worker:job:{jobId}` (зеркалит ack)."""
        await self._redis.xack(stream, GROUP, job_id)
        await self._redis.delete(_job_key(job_id))

    # ------------------------------------------------------------------
    # DLQ + idle-reclaim (s1-06)
    # ------------------------------------------------------------------

    async def get_times_delivered(self, stream: str, job_id: str) -> int:
        """`XPENDING_RANGE` для одной записи → `times_delivered` (доставок из PEL).

        Зеркалит `stream_consumer._get_delivery_count`. Дефолт 1 при любой ошибке:
        транзиентная проблема не должна блокировать DLQ-проверку.
        Следствие дефолта: запись, которую нужно было DLQ (4-я доставка), на время
        ошибки XPENDING трактуется как первая → остаётся retryable, DLQ откладывается
        до следующего fail-события. DLQ не пропускается, только задерживается.
        """
        try:
            pending = await self._redis.xpending_range(
                stream, GROUP, min=job_id, max=job_id, count=1
            )
            if pending:
                return int(pending[0].get("times_delivered", 1))
        except Exception as exc:
            logger.warning(
                "XPENDING_RANGE failed — defaulting times_delivered=1",
                extra={"stream": stream, "jobId": job_id, "error": str(exc)},
            )
        return 1

    async def add_to_dlq(
        self, stream: str, job_id: str, conv_id: int, reason: str
    ) -> None:
        """`XADD conv.dead` + `XACK` оригинала + `DEL` меты (DLQ, s1-06 §6.4).

        Форма поля `data` зеркалит `stream_consumer._send_to_dlq` (единственный
        исторический producer `conv.dead`): JSON с ключами conversionId / state /
        reason / originalStream / originalEntryId. Реальная причина (reason) — не
        хардкод; для permanent-фейла это строка worker'а, для max-retries —
        строка worker'а с суффиксом `(times_delivered=N)`.

        Семантика записи в `conv.dead` — at-least-once: `XADD conv.dead` и `XACK`
        оригинала — две отдельные операции. Если `XACK` упадёт после успешного
        `XADD`, запись останется и в `conv.dead`, и в PEL `gw-reclaim`, поэтому
        idle-reclaim передиспетчеризует её и падающий worker может произвести
        ДУБЛЬ записи `conv.dead` с тем же conversionId. Дедупликация (по
        conversionId) — ответственность consumer'а DLQ.
        """
        dlq_payload = json.dumps({
            "conversionId": conv_id,
            "state": "failed",
            "reason": reason,
            "originalStream": stream,
            "originalEntryId": job_id,
        })
        await self._redis.xadd(DLQ_STREAM, {"data": dlq_payload})
        await self._redis.xack(stream, GROUP, job_id)
        await self._redis.delete(_job_key(job_id))

    async def reclaim_idle(
        self,
        stream: str,
        consumer: str,
        min_idle_ms: int,
        count: int = 10,
    ) -> list[tuple[str, dict]]:
        """`XAUTOCLAIM` с произвольным per-type idle-порогом (для глобального reclaim-цикла).

        Зеркалит `reclaim_stale`, но принимает произвольные `min_idle_ms` и `count`
        вместо глобального `STALE_IDLE_MS`. Poison-guard идентичен: искажённые /
        conversionId<=0 → XACK + дроп. Транзиентные ошибки → лог + [].
        """
        await self.ensure_group(stream)

        try:
            result = await self._redis.xautoclaim(
                stream, GROUP, consumer, min_idle_time=min_idle_ms,
                start_id="0-0", count=count,
            )
        except Exception as exc:
            logger.warning(
                "reclaim_idle XAUTOCLAIM failed",
                extra={"stream": stream, "consumer": consumer, "error": str(exc)},
            )
            return []

        entries = result[1] if result and len(result) > 1 else []
        out: list[tuple[str, dict]] = []
        for entry in entries:
            job_id, fields = entry
            decoded = await self._decode_or_ack(stream, _to_str(job_id), fields)
            if decoded is not None:
                out.append(decoded)
        return out

    # ------------------------------------------------------------------
    # Живой статус conv:status:{convId} — ЕДИНСТВЕННЫЙ писатель = gateway (s1-07)
    # ------------------------------------------------------------------

    async def set_status_processing(
        self, job_id: str, job: dict, worker: str = "gw-reclaim"
    ) -> None:
        """`HSET conv:status:{convId} state processing worker <id>` + TTL (dispatch/reclaim).

        Единый write-путь живого статуса на диспетче (D5): вызывается и обычным
        dispatch'ем (`_dispatch`, worker=workerId, кому выдан job), и reclaim-циклом
        перед handoff (worker=gw-reclaim по умолчанию). TTL = CONV_STATUS_TTL_S (24 h).
        Владельцем conv:status теперь всегда gateway — воркеры KeyDB не трогают.
        """
        conv_id = int(job.get("conversionId", 0) or 0)
        if conv_id <= 0:
            return
        status_key = _status_key(conv_id)
        await self._redis.hset(
            status_key,
            mapping={"state": "processing", "worker": worker},
        )
        await self._redis.expire(status_key, CONV_STATUS_TTL_S)

    async def update_status_progress(
        self, conv_id: int, percent: int, stage: str | None = None
    ) -> None:
        """`HSET conv:status:{convId} state processing percent <n> [stage <s>]` + refresh TTL.

        На каждый `progress`-фрейм воркера (§4). `percent`/`stage` — best-effort
        индикатор для будущего UI (getStatus их в S1 не сюрфейсит); потерянный
        progress на надёжность не влияет. Пишет только gateway.
        """
        if conv_id <= 0:
            return
        status_key = _status_key(conv_id)
        mapping: dict[str, str] = {"state": "processing", "percent": str(percent)}
        if stage is not None:
            mapping["stage"] = stage
        await self._redis.hset(status_key, mapping=mapping)
        await self._redis.expire(status_key, CONV_STATUS_TTL_S)

    async def clear_status(self, conv_id: int) -> None:
        """`DEL conv:status:{convId}` на терминале/ack — дальше истина в строке БД."""
        if conv_id <= 0:
            return
        await self._redis.delete(_status_key(conv_id))

    # ------------------------------------------------------------------
    # Внутреннее
    # ------------------------------------------------------------------

    async def _decode_or_ack(
        self, stream: str, job_id: str, fields: dict
    ) -> tuple[str, dict] | None:
        """Разобрать поля записи в job; при провале — `XACK` (дроп) + `None`.

        Совмещает `parseOrAck` (poison-safe) и дроп `conversionId <= 0` из
        `claim()`: обе ветки делают `XACK`, чтобы отравленная запись не крутилась
        в reclaim бесконечно.
        """
        try:
            job = parse_message(fields)
        except Exception as exc:
            await self._redis.xack(stream, GROUP, job_id)
            logger.error(
                "Poisoned stream entry — dropped",
                extra={
                    "stream": stream,
                    "jobId": job_id,
                    "error": str(exc),
                    "hint": f"XRANGE {stream} {job_id} {job_id} recovers raw entry",
                },
            )
            return None

        if int(job.get("conversionId", 0) or 0) <= 0:
            await self._redis.xack(stream, GROUP, job_id)
            logger.error(
                "Stream entry has no positive conversionId — dropped",
                extra={
                    "stream": stream,
                    "jobId": job_id,
                    "conversionId": job.get("conversionId"),
                    "hint": f"XRANGE {stream} {job_id} {job_id} recovers raw entry",
                },
            )
            return None

        return (job_id, job)


def _job_key(job_id: str) -> str:
    return f"worker:job:{job_id}"


def _status_key(conv_id: int) -> str:
    return f"conv:status:{conv_id}"


def _first_entry(messages: Any) -> tuple[str, dict] | None:
    """Достать первую (id, fields) из результата XREADGROUP или None.

    Формат redis-py: `[(stream, [(id, {field: value}), ...]), ...]` либо `[]`/None.
    """
    if not messages:
        return None
    _stream, entries = messages[0]
    if not entries:
        return None
    entry_id, fields = entries[0]
    return (_to_str(entry_id), fields)


def _all_entries(messages: Any) -> list[tuple[str, dict]]:
    """Все (id, fields) из результата XREADGROUP (для read_pending) или []."""
    if not messages:
        return []
    _stream, entries = messages[0]
    return [(_to_str(entry_id), fields) for entry_id, fields in (entries or [])]
