"""Expiry-sweep принятых, но никем не взятых задач (CNV-71-03, эпик CNV-71).

**Проблема.** `ConversionManager::createConversion()` (PHP) ставит задачу в
`conv.<type>`, если строка типа воркера когда-либо существовала в
`worker_capabilities` — даже offline. Если ни один воркер этого типа так и не
подключился, запись НИКОГДА не была отдана `XREADGROUP`: она не входит ни в
чей PEL, поэтому idle-reclaim (`workers/gateway/reclaim.py`, `XAUTOCLAIM`)
СТРУКТУРНО её не видит — `XAUTOCLAIM` работает только по уже-доставленным
записям. Без этого модуля такая запись висела бы в стриме вечно.

**Правило детекции.** Для каждого `conv.<type>` backlog "никогда не
доставлено" — это ХВОСТ стрима СТРОГО ПОСЛЕ `last-delivered-id` группы
(`XINFO GROUPS`, `KeyDbGateway.get_last_delivered_id`). Всё, что <= этого id,
уже было отдано (хотя бы раз) — территория reclaim/PEL, этот sweep её не
трогает НИКОГДА (иначе задача, которую воркер сейчас честно обрабатывает,
получила бы дублирующий expire). Backlog читаем `XRANGE (last_delivered_id +
COUNT <expiry_sweep_batch>` — ограниченным куском, не всем хвостом разом
(см. `Config.expiry_sweep_batch`), в порядке возрастания id (oldest-first) —
как только встречаем запись моложе таймаута, дальнейшие в этом тике заведомо
ещё моложе, обработку стрима останавливаем.

**Возраст** — из миллисекундного префикса самого id записи (`<ms>-<seq>`,
формат Redis stream id), НЕ из полезной нагрузки задачи: payload может вообще
не нести временных меток, а id — гарантированно монотонный факт самого
KeyDB.

**Race-safety (важно).** Между тем, как sweep прочитал backlog, и тем, как он
решил экспайрить конкретную запись, воркер мог наконец подключиться и её
забрать (`XREADGROUP` продвинет `last-delivered-id` ПОСЛЕ нашего id). Поэтому
ПЕРЕД действием на записи мы перечитываем `last-delivered-id` ЗАНОВО и
пропускаем запись, если она теперь <= него (значит, ушла в обычную
доставку) — это не устраняет окно гонки полностью (см. следующий абзац), но
минимизирует его: `XDEL` идёт ТОЛЬКО ПОСЛЕ успешного (2xx) ответа PHP
`/expire`, так что даже запись, которую воркер успел забрать в узком окне
между re-check и `XDEL`, останется в стриме — просто её заберёт воркер
нормальным путём, а PHP-идемпотентность (`ConversionResultPersister`,
terminal-status guard) сделает исход детерминированным вне зависимости от
того, кто финализирует первым.

**Отказы.**
- PHP недоступен / не-2xx (кроме 404, см. ниже) → запись НЕ удаляется,
  ошибка логируется — следующий тик подберёт её снова (`RelayClient.post_expire`
  возвращает `(False, status)`).
- PHP отвечает 404 ("Conversion not found" — строка удалена раньше, чем
  задачу вообще кто-то забрал) → отмечать нечего, `RelayClient.post_expire`
  трактует это как terminal (`(True, 404)`, WARNING внутри relay.py, тот же
  terminal-4xx-whitelist паттерн, что `post_dlq_fail`, но у`/expire` в
  whitelist'е ТОЛЬКО 404 — не 400) — сюда приходит уже `ok=True`, запись
  удаляется обычной веткой ниже, никакого бесконечного ретрая.
- Payload записи не парсится (`workers.common.envelope.parse_message`) или
  несёт `conversionId <= 0` → PHP вообще не вызываем (звонить не с чем), но
  и молча `XDEL` больше НЕ делаем (иначе `Conversion`-строка навсегда
  застревает в `Pending` — ни терминального статуса, ни возврата средств,
  находка ревью CNV-71-03). Вместо этого — ТОТ ЖЕ DLQ-механизм, что и
  poison-записи job-стрима (`KeyDbGateway.add_to_dlq`, используемый также
  `ws_server._to_dlq_and_release`): `XADD conv.dead` с payload'ом задачи в
  `reason` (conversionId неизвестен → `0`, тот же "closest safe equivalent",
  что fallback `_to_dlq_and_release` при сбое `get_job_meta`) — запись
  остаётся greppable через `XRANGE conv.dead`/Graylog ДАЖЕ после того, как
  DLQ-consumer её тоже поглотит своим independent poison-guard'ом
  (`conversionId<=0` → `_decode_dlq_or_ack` делает `XACK` БЕЗ `XDEL`, сырые
  данные остаются в стриме). Логируем на уровне ERROR. `XDEL` backlog-записи
  из `conv.<type>` идёт СРАЗУ ПОСЛЕ `add_to_dlq` — иначе отравленная запись
  заклинивает sweep этого стрима навсегда (та же причина, что и раньше).

Запускается как отдельная asyncio-задача из `__main__.py`, на СВОЁМ
интервале (`Config.expiry_sweep_interval_s`, дефолт 5 мин) — НАМЕРЕННО не тот
же тик, что `reclaim.run_reclaim_loop`: таймаут протухания — десятки минут
(`WORKER_CLAIM_TIMEOUT_MINUTES`, дефолт 60), частый тик не нужен и не должен
примешивать "никогда не доставлено" к семантике idle-reclaim (`reclaim.py`
своим докстрингом явно ограничивает себя idle-timeout уже доставленных
записей).
"""

from __future__ import annotations

import asyncio
import logging
import time

from workers.common.envelope import parse_message
from workers.gateway.config import Config
from workers.gateway.keydb import WORKER_TYPES, KeyDbGateway, stream_for
from workers.gateway.relay import RelayClient

logger = logging.getLogger(__name__)

EXPIRE_REASON = "worker_timeout"


async def run_expiry_loop(
    keydb: KeyDbGateway,
    relay: RelayClient,
    cfg: Config,
    *,
    stop_event: asyncio.Event | None = None,
) -> None:
    """Async-задача: периодический expiry-sweep всех `conv.<type>` стримов.

    Структура зеркалит `reclaim.run_reclaim_loop` (sleep-тик → sweep →
    транзиентная ошибка логируется, НЕ роняет цикл). `stop_event` — как в
    `dlq_consumer.run_dlq_consumer_loop`: детерминированная остановка в
    тестах (проверяется перед каждым sleep/sweep).
    """
    logger.info(
        "expiry sweep loop started",
        extra={
            "intervalS": cfg.expiry_sweep_interval_s,
            "timeoutMinutes": cfg.worker_claim_timeout_minutes,
            "types": list(WORKER_TYPES),
        },
    )
    while True:
        if stop_event is not None and stop_event.is_set():
            logger.info("expiry sweep loop stopped (stop_event)")
            return

        try:
            await asyncio.sleep(cfg.expiry_sweep_interval_s)
        except asyncio.CancelledError:
            logger.info("expiry sweep loop cancelled")
            return

        if stop_event is not None and stop_event.is_set():
            logger.info("expiry sweep loop stopped (stop_event)")
            return

        try:
            await sweep_all_types(keydb, relay, cfg)
        except asyncio.CancelledError:
            logger.info("expiry sweep loop cancelled during sweep")
            return
        except Exception as exc:
            # Транзиентная ошибка не роняет цикл — следующий тик исправит.
            logger.warning("expiry sweep error", extra={"error": str(exc)})


async def sweep_all_types(keydb: KeyDbGateway, relay: RelayClient, cfg: Config) -> None:
    """Один проход по всем `conv.<type>` стримам (production-путь, вызывается
    из `run_expiry_loop`) — тонкая обёртка над `sweep_stream` по `WORKER_TYPES`."""
    timeout_ms = cfg.worker_claim_timeout_minutes * 60_000
    batch = cfg.expiry_sweep_batch
    for wtype in WORKER_TYPES:
        stream = stream_for(wtype)
        await sweep_stream(keydb, relay, stream, timeout_ms, batch)


async def sweep_stream(
    keydb: KeyDbGateway, relay: RelayClient, stream: str, timeout_ms: int, batch: int
) -> None:
    """Один проход expiry-sweep'а по ОДНОМУ стриму. Публична — тесты вызывают её
    напрямую с произвольным (уникальным per-test) именем стрима, не завязываясь
    на фиксированный `WORKER_TYPES` (в отличие от `sweep_all_types`)."""
    last_delivered = await keydb.get_last_delivered_id(stream)
    entries = await keydb.scan_undelivered_backlog(stream, last_delivered, batch)
    if not entries:
        return

    now_ms = int(time.time() * 1000)
    for entry_id, fields in entries:
        age_ms = now_ms - _entry_ms(entry_id)
        if age_ms < timeout_ms:
            # oldest-first: все последующие записи в этом батче ещё моложе —
            # дальше в этом стриме на этом тике смотреть нечего.
            break
        await _expire_entry(keydb, relay, stream, entry_id, fields, age_ms)


async def _expire_entry(
    keydb: KeyDbGateway,
    relay: RelayClient,
    stream: str,
    entry_id: str,
    fields: dict,
    age_ms: int,
) -> None:
    # Race-safety (см. module docstring): между XRANGE-чтением и действием
    # воркер мог подключиться и забрать запись — last-delivered-id продвинулся
    # мимо неё. Перечитываем ЗАНОВО и пропускаем, если она теперь доставлена.
    current_last = await keydb.get_last_delivered_id(stream)
    if _id_leq(entry_id, current_last):
        logger.info(
            "expiry sweep: entry delivered before action — skipping",
            extra={"stream": stream, "entryId": entry_id},
        )
        return

    conv_id, dlq_reason = _extract_conversion_id(stream, entry_id, fields)
    if conv_id is None:
        # Отравленная/непарсящаяся запись — звонить в PHP нечем (см. module
        # docstring "Отказы"). НЕ дропаем тихо (иначе Conversion-строка
        # застревает в Pending навсегда) — заводим в DLQ ТЕМ ЖЕ механизмом,
        # что poison-записи job-стрима (`KeyDbGateway.add_to_dlq`), затем
        # сносим backlog-запись из живого стрима — иначе она заклинивает
        # sweep этого стрима на каждом следующем тике.
        await keydb.add_to_dlq(stream, entry_id, 0, dlq_reason)
        await keydb.delete_entry(stream, entry_id)
        return

    ok, status = await relay.post_expire(conv_id, EXPIRE_REASON)
    if not ok:
        logger.warning(
            "expiry sweep: relay failed — leaving entry for next sweep",
            extra={
                "stream": stream,
                "entryId": entry_id,
                "conversionId": conv_id,
                "status": status,
            },
        )
        return

    await keydb.delete_entry(stream, entry_id)
    logger.info(
        "expiry sweep: entry expired",
        extra={
            "stream": stream,
            "entryId": entry_id,
            "conversionId": conv_id,
            "ageMs": age_ms,
        },
    )


_DLQ_PREVIEW_LEN = 300  # верхняя граница сырого превью payload'а в reason DLQ-записи


def _extract_conversion_id(
    stream: str, entry_id: str, fields: dict
) -> tuple[int | None, str]:
    """Разобрать `message` записи → `conversionId`.

    Возвращает `(conv_id, dlq_reason)`: при успехе `conv_id > 0` и
    `dlq_reason == ""`; при провале `conv_id is None`, `dlq_reason` —
    человекочитаемая причина (используется и в ERROR-логе, и как `reason`
    DLQ-записи в `_expire_entry`, см. module docstring "Отказы").

    Использует ТОТ ЖЕ декодер, что job-стримовый путь (`parse_message`,
    `workers.common.envelope`) — единый формат payload'а (§3 spec). В отличие
    от `KeyDbGateway._decode_or_ack` здесь НЕТ `XACK` (backlog-запись не в
    PEL ни у кого) — только классификация "звонить или сразу в DLQ".
    """
    try:
        job = parse_message(fields)
    except Exception as exc:
        preview = _raw_preview(fields.get("message"))
        logger.error(
            "expiry sweep: unparseable backlog entry — routing to DLQ",
            extra={
                "stream": stream, "entryId": entry_id, "error": str(exc),
                "rawPreview": preview,
            },
        )
        return None, f"expiry sweep: unparseable payload ({exc}); raw={preview!r}"

    raw_conv_id = job.get("conversionId")
    try:
        conv_id = int(raw_conv_id or 0)
    except (TypeError, ValueError):
        logger.error(
            "expiry sweep: backlog entry has non-numeric conversionId — routing to DLQ",
            extra={"stream": stream, "entryId": entry_id, "conversionId": raw_conv_id},
        )
        return None, f"expiry sweep: non-numeric conversionId={raw_conv_id!r}"

    if conv_id <= 0:
        logger.error(
            "expiry sweep: backlog entry has no positive conversionId — routing to DLQ",
            extra={"stream": stream, "entryId": entry_id, "conversionId": raw_conv_id},
        )
        return None, f"expiry sweep: no positive conversionId (raw={raw_conv_id!r})"

    return conv_id, ""


def _raw_preview(raw: object) -> str:
    """Сырое превью поля `message` (для ERROR-лога/DLQ reason) — усечено,
    декодировано best-effort (bytes/иное) — только диагностика, не парсинг."""
    if raw is None:
        return ""
    text = raw.decode("utf-8", errors="replace") if isinstance(raw, bytes) else str(raw)
    return text[:_DLQ_PREVIEW_LEN]


def _entry_ms(entry_id: str) -> int:
    """Миллисекундный префикс Redis stream id (`<ms>-<seq>`) → int."""
    return int(entry_id.split("-", 1)[0])


def _id_leq(a: str, b: str) -> bool:
    """`a <= b` для двух Redis stream id (`<ms>-<seq>`), числовое сравнение
    компонент (НЕ строковое — ширина ms-префикса не гарантированно постоянна)."""
    a_ms, _, a_seq = a.partition("-")
    b_ms, _, b_seq = b.partition("-")
    return (int(a_ms), int(a_seq or 0)) <= (int(b_ms), int(b_seq or 0))
