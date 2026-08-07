"""DLQ-consumer WS-Gateway (`conv.dead`, hardening: conv-dead-no-consumer).

До этого модуля `conv.dead` был write-only: `KeyDbGateway.add_to_dlq` писал
туда провалившиеся задачи (`ws_server._to_dlq_and_release`), но никто их не
читал — строка `Conversion` в БД оставалась `pending` навсегда (kanban-карточка
`.claude/kanban/progress/conv-dead-no-consumer.md`).

Этот consumer — ЕДИНСТВЕННЫЙ читатель `conv.dead` (тот же принцип "единственный
читатель KeyDB Streams = gateway", что и для job-стримов `conv.<type>`).
Собственная consumer-группа (та же `GROUP="convertor"`, отдельное consumer-имя
`DLQ_CONSUMER_NAME`, чтобы PEL DLQ не смешивался с job-стримами).

Контракт internal-эндпоинта (согласован в kanban-карточке, реализован в
`InternalWorkerController::dlqFail`):
`POST /api/v1/internal/worker/dlq-fail`, bearer `GATEWAY_INTERNAL_TOKEN`,
body `{"conversionId": int, "reason": str, "processingMs": int|null, "attempt": int|null}` →
`ConversionResultPersister::persist(state=failed)` (идемпотентно, по
guard'у "уже terminal → skip"); PHP отдаёт 400 (conversionId<=0) / 404
(Conversion не найдена) — обе трактуются `RelayClient.post_dlq_fail` как
terminal (ack, не ретраить), см. её docstring.

`attempt` (requeue-generation-маркер, кросс-зонный follow-up карточки
`requeue-attempt-generation-marker`) — PHP сравнивает его с текущим
`Conversion.attempt` перед финализацией (stale-guard: устаревший дубль
`dlq-fail` от прошлой попытки после operator-requeue — no-op). `None` (а не
0!) означает «guard пропустить, финализировать как обычно» — так шлются
DLQ-записи, написанные ДО этого изменения (`attempt` в payload отсутствует
вовсе, см. `_coerce_attempt`), чтобы их дренаж при первом деплое не падал и
не блокировался несуществующим маркером.

At-least-once + idempotent-consumer + retry (важно понимать ВМЕСТЕ, иначе
получается ложное чувство надёжности):
- `XACK` — ТОЛЬКО после подтверждённого (2xx ИЛИ terminal-4xx) relay-ответа.
- Провал relay (5xx/сеть) → запись остаётся unacked в PEL `DLQ_CONSUMER_NAME`.
  `XREADGROUP ... >` её больше НЕ вернёт (уже была доставлена этому consumer'у) —
  БЕЗ явного retry-механизма запись осела бы в PEL НАВСЕГДА и "retry на
  следующем sweep'е" было бы ложью. Поэтому каждая итерация цикла ПЕРЕД
  блокирующим `read_dlq` делает `reclaim_dlq_idle` (XAUTOCLAIM, порог
  `cfg.dlq_consumer_retry_idle_ms`) — переклеймивает свои же простаивающие
  unacked-записи и повторно их обрабатывает. Тот же паттерн, что в
  `ws_server._dispatch` (`reclaim_stale()` ПЕРЕД `read_new()`).
- Operator-requeue (перезапуск проваленной конверсии) работает от строки БД
  (`Conversion.status=failed`), НЕ от сырого `conv.dead` — вне объёма этого
  модуля, см. kanban-карточку.

XDEL после XACK (монотонный gauge, CNV-71 / `ConvertorDeadLetterGrowing`):
- `add_to_dlq` пишет `conv.dead` БЕЗ `MAXLEN` (намеренно — любой cap > 0 не даёт
  `XLEN` вернуться к 0, алерт `convertor_dead_letter_messages > 0` (см.
  `workers/metrics_exporter/exporter.py`) горел бы вечно после первой же
  DLQ-записи). Раньше `XACK` был терминальной операцией над записью — стрим
  рос монотонно, `XLEN` никогда не уменьшался. Теперь `_process_entry` ПОСЛЕ
  успешного `XACK` также делает `XDEL` (тот же порядок "XDEL только после
  подтверждённого relay-2xx", что в `expiry.py`) — записи, которые consumer
  успешно зафиналил в PHP, больше не занимают место в `conv.dead`, и gauge
  честно падает до 0.
- Перед `XDEL` вся декодированная запись логируется на уровне WARNING
  (маркер `DLQ_AUDIT_LOG_MARKER`) — стрим был единственной сырой копией,
  этот лог становится аудит-следом.
- Edge case: если consumer упадёт МЕЖДУ `XACK` и `XDEL`, запись останется в
  `conv.dead` навсегда осиротевшей (PHP уже зафиналил Conversion, но XLEN не
  уменьшится на эту запись) — редкий случай, отдельного механизма для него
  нет (принято сознательно, не разработка "cleanup-sweep").
"""

from __future__ import annotations

import asyncio
import logging

from workers.gateway.config import Config
from workers.gateway.keydb import DLQ_STREAM, KeyDbGateway
from workers.gateway.relay import RelayClient

# Greppable маркер аудит-строки — единственная сохранившаяся копия сырой
# DLQ-записи после XDEL (см. `_process_entry` docstring).
DLQ_AUDIT_LOG_MARKER = "dlq entry audit (pre-delete)"

logger = logging.getLogger(__name__)

# Consumer-имя в PEL для DLQ-consumer'а. Не совпадает ни с одним workerId
# реального воркера и не совпадает с RECLAIM_CONSUMER ("gw-reclaim", reclaim.py) —
# отдельная роль, отдельный PEL.
DLQ_CONSUMER_NAME = "dlq-consumer"

# Пауза перед повтором после транзиентной ошибки чтения (не роняем цикл целиком).
_READ_ERROR_BACKOFF_S = 1.0


async def run_dlq_consumer_loop(
    keydb: KeyDbGateway,
    relay: RelayClient,
    cfg: Config,
    *,
    stop_event: asyncio.Event | None = None,
) -> None:
    """Async-задача: непрерывно читает `conv.dead` и финализирует Conversion как failed.

    Каждая итерация: (1) `reclaim_dlq_idle` — забрать и повторно обработать свои
    же unacked-записи, простаивающие дольше `dlq_consumer_retry_idle_ms` (retry
    после транзиентного relay-фейла, см. module docstring); (2) блокирующее
    чтение новых записей (`read_dlq`, `BLOCK dlq_consumer_block_ms`).

    Запускается через `asyncio.create_task` из `__main__.py` рядом с WS-сервером
    и reclaim-циклом (структура — по образцу `reclaim.run_reclaim_loop`).
    Завершается по `CancelledError` (graceful shutdown) либо когда `stop_event`
    установлен (используется тестами для детерминированного прогона —
    проверяется в начале каждой итерации).
    """
    await keydb.ensure_group(DLQ_STREAM)
    logger.info(
        "dlq consumer loop started",
        extra={
            "stream": DLQ_STREAM,
            "consumer": DLQ_CONSUMER_NAME,
            "retryIdleMs": cfg.dlq_consumer_retry_idle_ms,
        },
    )

    while True:
        if stop_event is not None and stop_event.is_set():
            logger.info("dlq consumer loop stopped (stop_event)")
            return

        try:
            reclaimed = await keydb.reclaim_dlq_idle(
                DLQ_CONSUMER_NAME,
                cfg.dlq_consumer_retry_idle_ms,
                count=cfg.dlq_consumer_reclaim_batch,
            )
        except asyncio.CancelledError:
            logger.info("dlq consumer loop cancelled")
            return
        except Exception as exc:
            # Транзиентная ошибка reclaim не роняет цикл — следующая итерация исправит.
            logger.warning("dlq reclaim failed", extra={"error": str(exc)})
            reclaimed = []

        for entry_id, payload in reclaimed:
            await _process_entry(keydb, relay, entry_id, payload)

        if stop_event is not None and stop_event.is_set():
            logger.info("dlq consumer loop stopped (stop_event)")
            return

        try:
            entry = await keydb.read_dlq(DLQ_CONSUMER_NAME, cfg.dlq_consumer_block_ms)
        except asyncio.CancelledError:
            logger.info("dlq consumer loop cancelled")
            return
        except Exception as exc:
            # Транзиентная ошибка не роняет цикл — следующая итерация исправит.
            logger.warning("dlq read failed — retrying", extra={"error": str(exc)})
            try:
                await asyncio.sleep(_READ_ERROR_BACKOFF_S)
            except asyncio.CancelledError:
                logger.info("dlq consumer loop cancelled")
                return
            continue

        if entry is None:
            continue

        entry_id, payload = entry
        await _process_entry(keydb, relay, entry_id, payload)


async def _process_entry(
    keydb: KeyDbGateway, relay: RelayClient, entry_id: str, payload: dict
) -> None:
    """Одна DLQ-запись: relay.post_dlq_fail → XACK при True → XDEL, иначе оставить unacked.

    `post_dlq_fail` возвращает `True` и на 2xx, и на terminal-4xx (её контракт,
    см. docstring) — обе ветки здесь неразличимы намеренно: и то, и то ack-worthy.

    Порядок ПОСЛЕ успеха: `XACK` → аудит-лог (WARNING, вся декодированная
    запись) → `XDEL` (см. module docstring "XDEL после XACK") — никогда
    наоборот, `XDEL` до подтверждённого relay-2xx не делаем.
    """
    conversion_id = int(payload.get("conversionId", 0) or 0)
    reason = str(payload.get("reason", "") or "")
    processing_ms = _coerce_processing_ms(payload.get("processingMs"))
    attempt = _coerce_attempt(payload)

    ok = await relay.post_dlq_fail(conversion_id, reason, processing_ms, attempt)
    if ok:
        await keydb.ack_dlq(entry_id)
        logger.info(
            "dlq entry finalized",
            extra={"entryId": entry_id, "conversionId": conversion_id},
        )
        logger.warning(
            DLQ_AUDIT_LOG_MARKER,
            extra={
                "entryId": entry_id,
                "entryTimestampMs": _entry_ms(entry_id),
                "conversionId": conversion_id,
                "reason": reason,
                "originalStream": payload.get("originalStream"),
                "originalEntryId": payload.get("originalEntryId"),
                "processingMs": processing_ms,
                "attempt": attempt,
            },
        )
        await keydb.delete_entry(DLQ_STREAM, entry_id)
    else:
        # Relay недоступен/5xx — НЕ ack и НЕ XDEL: запись остаётся в PEL
        # DLQ_CONSUMER_NAME и в стриме, следующая итерация подберёт её через
        # reclaim_dlq_idle (см. module docstring).
        logger.warning(
            "dlq relay failed — leaving unacked for retry",
            extra={"entryId": entry_id, "conversionId": conversion_id},
        )


def _entry_ms(entry_id: str) -> int:
    """Миллисекундный префикс Redis stream id (`<ms>-<seq>`) → int (зеркалит `expiry.py`)."""
    return int(entry_id.split("-", 1)[0])


def _coerce_processing_ms(value: object) -> int | None:
    """`processingMs` из DLQ-payload → int|None (payload JSON может дать float/str)."""
    if value is None:
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _coerce_attempt(payload: dict) -> int | None:
    """`attempt` из DLQ-payload → int|None (requeue-attempt-generation-marker).

    Ключ ОТСУТСТВУЕТ (legacy-запись `conv.dead`, написанная ДО этого изменения,
    дренируемая при первом деплое) → `None` — PHP пропускает stale-guard.
    Ключ присутствует (в т.ч. `0` для legacy-задачи без `attempt` на job-стриме,
    см. `KeyDbGateway.write_job_meta`) → best-effort int; невалидное значение
    (не должно случаться — пишет только наш `add_to_dlq`) тоже трактуем как
    `None`, а не как повод уронить финализацию.
    """
    if "attempt" not in payload:
        return None
    try:
        return int(payload.get("attempt"))
    except (TypeError, ValueError):
        return None
