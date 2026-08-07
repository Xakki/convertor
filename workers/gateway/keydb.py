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
# Типы воркеров, чьи стримы `conv.<type>` gateway читает через XREADGROUP/XAUTOCLAIM.
# Намеренно НЕЗАВИСИМАЯ статическая копия канонического набора из PHP-enum'а
# `App\Enum\WorkerType` — gateway обязан стартовать и обслуживать стримы даже
# если PHP/БД недоступны. Синхронизацию держит drift-guard
# `workers/tests/test_worker_type_drift.py` (`make test-drift` из корня репо).
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

        `attempt` (requeue-generation-маркер, `requeue-attempt-generation-marker`)
        читается из job-дословно так же, как `conversionId`: строка-int от PHP
        (`"0"`, `"1"`, ...), отсутствует на legacy-задачах (до раскатки этого
        изменения) → дефолт 0. Сохраняем в мете, чтобы на fail-пути (только
        `job_id`, самого job-dict уже нет) достать его тем же путём, что и
        `conversionId` — через `get_job_meta`.
        """
        meta = json.dumps({
            "conversionId": int(job.get("conversionId", 0) or 0),
            "inputBucket": str(job.get("inputBucket", "") or ""),
            "inputKey": str(job.get("inputKey", "") or ""),
            "stream": stream,
            "targetFormat": str(job.get("targetFormat", "") or ""),
            "attempt": int(job.get("attempt", 0) or 0),
        })
        # SET ... EX <ttl> == SETEX (одна атомарная запись value+TTL).
        await self._redis.set(_job_key(job_id), meta, ex=JOB_META_TTL)

    async def get_job_meta(self, job_id: str) -> dict | None:
        """`GET worker:job:{jobId}` → разобранный dict или `None` (зеркалит getJobMeta).

        `attempt` — см. `write_job_meta`; мета, записанная ДО этого изменения
        (legacy), не несёт ключа → дефолт 0 тем же `or 0`, что и `conversionId`.
        """
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
            "attempt": int(data.get("attempt", 0) or 0),
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
        self,
        stream: str,
        job_id: str,
        conv_id: int,
        reason: str,
        processing_ms: int | None = None,
        attempt: int = 0,
    ) -> None:
        """`XADD conv.dead` + `XACK` оригинала + `DEL` меты (DLQ, s1-06 §6.4).

        Форма поля `data` зеркалит `stream_consumer._send_to_dlq` (единственный
        исторический producer `conv.dead`): JSON с ключами conversionId / state /
        reason / originalStream / originalEntryId / processingMs / attempt.
        Реальная причина (reason) — не хардкод; для permanent-фейла это строка
        worker'а, для max-retries — строка worker'а с суффиксом
        `(times_delivered=N)`.
        `processingMs` (int|null) — из WS fail-фрейма воркера (`hardening-06`);
        `None`, если воркер его не прислал — поле в JSON всё равно присутствует
        как `null` (предсказуемый shape для consumer'а `conv.dead`).
        `attempt` (int, requeue-generation-маркер, `requeue-attempt-generation-marker`) —
        В ОТЛИЧИЕ от `processingMs` ВСЕГДА int, никогда `null`: вызывающий код
        (`ws_server._to_dlq_and_release`) уже дефолтит его в 0 для legacy-задач
        до передачи сюда — здесь просто прокидываем дословно.
        """
        dlq_payload = json.dumps({
            "conversionId": conv_id,
            "state": "failed",
            "reason": reason,
            "originalStream": stream,
            "originalEntryId": job_id,
            "processingMs": processing_ms,
            "attempt": int(attempt or 0),
        })
        await self._redis.xadd(DLQ_STREAM, {"data": dlq_payload})
        await self._redis.xack(stream, GROUP, job_id)
        await self._redis.delete(_job_key(job_id))

    # ------------------------------------------------------------------
    # DLQ-consumer (conv.dead читатель, hardening: conv-dead-no-consumer)
    # ------------------------------------------------------------------

    async def read_dlq(
        self, consumer: str, block_ms: int
    ) -> tuple[str, dict] | None:
        """`XREADGROUP GROUP convertor <consumer> COUNT 1 BLOCK <ms> STREAMS conv.dead >`.

        Зеркалит `read_new`, но: (1) фиксированный стрим `conv.dead`, (2) разбирает
        поле `data` (форма `add_to_dlq`), НЕ `message` (`parse_message` сюда не
        подходит — контракт DLQ-записи иной), (3) БЕЗ `DEL worker:job` в `ack_dlq` —
        у DLQ-записей нет job-меты. Возвращает `(entryId, payload)` или `None`
        (нет записи / poison-дроп искажённой/нечитаемой `data`).
        """
        await self.ensure_group(DLQ_STREAM)

        messages = await self._redis.xreadgroup(
            GROUP, consumer, {DLQ_STREAM: ">"}, count=1, block=block_ms
        )
        entry = _first_entry(messages)
        if entry is None:
            return None

        entry_id, fields = entry
        return await self._decode_dlq_or_ack(entry_id, fields)

    async def ack_dlq(self, entry_id: str) -> None:
        """`XACK conv.dead convertor <entryId>` — БЕЗ `DEL` (DLQ-записи без job-меты)."""
        await self._redis.xack(DLQ_STREAM, GROUP, entry_id)

    async def reclaim_dlq_idle(
        self, consumer: str, min_idle_ms: int, count: int = 10
    ) -> list[tuple[str, dict]]:
        """`XAUTOCLAIM conv.dead convertor <consumer> <min_idle_ms> 0-0 COUNT <count>`.

        `read_dlq` читает ТОЛЬКО новые записи (`>`) — раз запись уже доставлена
        consumer'у, `>` её больше НЕ вернёт, даже если она осталась unacked
        (транзиентный фейл relay). Без этого метода "оставить unacked → retry на
        следующем sweep'е" (контракт `dlq_consumer.py`) было бы ложью: запись
        осела бы в PEL `consumer`'а НАВСЕГДА. Этот метод — retry-механизм:
        переклеймивает свои же (или чужие) простаивающие >min_idle_ms записи для
        повторной обработки. Зеркалит `reclaim_idle`, но decode через
        `_decode_dlq_or_ack` (поле `data`, контракт DLQ, не job-стримов).
        """
        await self.ensure_group(DLQ_STREAM)

        try:
            result = await self._redis.xautoclaim(
                DLQ_STREAM, GROUP, consumer, min_idle_time=min_idle_ms,
                start_id="0-0", count=count,
            )
        except Exception as exc:
            logger.warning(
                "reclaim_dlq_idle XAUTOCLAIM failed",
                extra={"consumer": consumer, "error": str(exc)},
            )
            return []

        entries = result[1] if result and len(result) > 1 else []
        out: list[tuple[str, dict]] = []
        for entry in entries:
            entry_id, fields = entry
            decoded = await self._decode_dlq_or_ack(_to_str(entry_id), fields)
            if decoded is not None:
                out.append(decoded)
        return out

    async def _decode_dlq_or_ack(
        self, entry_id: str, fields: dict
    ) -> tuple[str, dict] | None:
        """Разобрать поле `data` DLQ-записи; при провале — `XACK` (дроп) + `None`.

        Poison-safe как `_decode_or_ack` для job-стримов: искажённая/отсутствующая
        `data` ИЛИ `conversionId<=0` не должна крутиться в consumer'е вечно (без
        этого guard'а retry через `reclaim_dlq_idle` превратил бы poison-запись в
        бесконечный цикл: relay/PHP отдаёт 400 на `conversionId<=0`, `post_dlq_fail`
        не смог бы её ни зафиналить, ни отбросить).
        """
        raw = fields.get("data")
        if raw is None:
            await self._redis.xack(DLQ_STREAM, GROUP, entry_id)
            logger.error(
                "Poisoned DLQ entry (missing 'data') — dropped",
                extra={"entryId": entry_id},
            )
            return None

        try:
            payload = json.loads(_to_str(raw))
            if not isinstance(payload, dict):
                raise TypeError("DLQ 'data' is not a JSON object")
        except Exception as exc:
            await self._redis.xack(DLQ_STREAM, GROUP, entry_id)
            logger.error(
                "Poisoned DLQ entry (invalid 'data') — dropped",
                extra={"entryId": entry_id, "error": str(exc)},
            )
            return None

        if int(payload.get("conversionId", 0) or 0) <= 0:
            await self._redis.xack(DLQ_STREAM, GROUP, entry_id)
            logger.error(
                "DLQ entry has no positive conversionId — dropped",
                extra={"entryId": entry_id, "conversionId": payload.get("conversionId")},
            )
            return None

        return (entry_id, payload)

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
    # Accepted-but-never-claimed expiry (CNV-71-03, см. workers/gateway/expiry.py)
    # ------------------------------------------------------------------

    async def get_last_delivered_id(self, stream: str) -> str:
        """`XINFO GROUPS <stream>` → `last-delivered-id` группы `convertor`.

        Граница детекции "никогда не доставлено" (§ карточки CNV-71-03): записи
        с id <= last-delivered-id были отданы `XREADGROUP` хотя бы раз (даже
        если ещё не acked) — их территория reclaim/PEL, expiry-sweep их не
        трогает. Записи с id > last-delivered-id — чистый backlog, которого ни
        один consumer никогда не видел (`XAUTOCLAIM` эту категорию структурно
        не видит — оно работает только по PEL).

        Отсутствие группы (ещё ни разу не `ensure_group`) исправляем сами —
        как и другие XAUTOCLAIM-методы этого класса. Транзиентная ошибка →
        лог + `"0-0"` (весь стрим трактуется как backlog — безопасно: строгий
        undercount протухания, не наоборот).
        """
        await self.ensure_group(stream)
        try:
            groups = await self._redis.xinfo_groups(stream)
        except Exception as exc:
            logger.warning(
                "XINFO GROUPS failed — treating stream as fully undelivered",
                extra={"stream": stream, "error": str(exc)},
            )
            return "0-0"

        for g in groups:
            name = g.get("name") if isinstance(g, dict) else None
            if _to_str(name) == GROUP:
                return _to_str(g.get("last-delivered-id", "0-0"))
        return "0-0"

    async def scan_undelivered_backlog(
        self, stream: str, after_id: str, count: int
    ) -> list[tuple[str, dict]]:
        """`XRANGE <stream> (<after_id> + COUNT <count>` — backlog СТРОГО после `after_id`.

        `(` перед `after_id` — exclusive-нижняя граница XRANGE: сама
        `last-delivered-id` (или уже переклеймленная/подобранная запись)
        НЕ должна попасть в результат — она либо доставлена, либо не
        существует. Возвращает по возрастанию id (oldest-first) — вызывающий
        код (`expiry.py`) обрабатывает записи именно в этом порядке.
        Транзиентная ошибка → лог + `[]` (следующий тик исправит).
        """
        try:
            entries = await self._redis.xrange(
                stream, min=f"({after_id}", max="+", count=count
            )
        except Exception as exc:
            logger.warning(
                "XRANGE backlog scan failed",
                extra={"stream": stream, "afterId": after_id, "error": str(exc)},
            )
            return []
        return [(_to_str(entry_id), fields) for entry_id, fields in entries]

    async def delete_entry(self, stream: str, entry_id: str) -> None:
        """`XDEL <stream> <entryId>` — снести expired/poison backlog-запись.

        БЕЗ `XACK`/`DEL worker:job` — в отличие от `ack()`: backlog-запись
        никогда не была доставлена, у неё нет ни PEL-записи, ни job-меты.
        """
        await self._redis.xdel(stream, entry_id)

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
