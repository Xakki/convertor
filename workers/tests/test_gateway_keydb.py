"""Тесты KeyDB-слоя WS-Gateway на РЕАЛЬНОМ KeyDB (не мок).

Запускаются через `make test-gateway` (compose поднимает реальный `keydb`,
тест бежит внутри worker-образа на сети `backend`). Каждый тест использует
уникальный тип воркера → уникальный стрим `conv.testgw_<rnd>`, чтобы не задеть
реальные `conv.<type>` очереди; за собой чистит стрим.

Покрытие (критерии приёмки s1-02):
- happy path: XADD чистый single-JSON `message` (форма golden-фикстуры) →
  read_new (одна json.loads) → write_job_meta (поля + TTL 24 h) → ack →
  PEL пуст (XPENDING count 0) + мета-ключ удалён;
- poison: искажённый `message` → XACK + дроп (не возвращается), PEL пуст;
- conversionId<=0 → XACK + дроп, PEL пуст;
- reclaim_stale на свежем стриме (idle < 5 мин) → None.
"""

from __future__ import annotations

import json
import uuid
from pathlib import Path

import pytest

from workers.gateway.config import load_config
from workers.gateway.keydb import (
    GROUP,
    JOB_META_TTL,
    KeyDbGateway,
    build_client,
    stream_for,
)

GOLDEN = (
    Path(__file__).resolve().parents[2]
    / "app-symfony/tests/Fixtures/messenger_envelope.golden.json"
)


def _golden_job() -> dict:
    """Чистый single-JSON payload задачи (форма golden-фикстуры s1-01)."""
    return json.loads(GOLDEN.read_text())


def _unique_type() -> str:
    return "testgw_" + uuid.uuid4().hex[:12]


async def _new_gateway():
    client = build_client(load_config())
    return client, KeyDbGateway(client)


async def _cleanup(client, stream: str) -> None:
    # Удаление стрима сносит и группу — за собой не оставляем ничего в db2.
    await client.delete(stream)


async def _pending_count(client, stream: str) -> int:
    res = await client.xpending(stream, GROUP)
    return int(res["pending"])


@pytest.mark.asyncio
async def test_claim_write_meta_ack_roundtrip():
    client, gw = await _new_gateway()
    stream = stream_for(_unique_type())
    consumer = "worker-test-1"
    try:
        job = _golden_job()
        await client.xadd(stream, {"message": json.dumps(job)})

        result = await gw.read_new(stream, consumer, block_ms=2000)
        assert result is not None, "новая запись должна вернуться"
        job_id, decoded = result
        # Одна json.loads — decoded равен исходному payload'у.
        assert decoded["conversionId"] == job["conversionId"]
        assert decoded["inputKey"] == job["inputKey"]

        # PEL-запись принадлежит ИМЕННО переданному per-worker consumer, не
        # глобальному/захардкоженному имени (критерий #3).
        pel = await client.xpending_range(stream, GROUP, min="-", max="+", count=10)
        assert len(pel) == 1
        assert pel[0]["message_id"] == job_id
        assert pel[0]["consumer"] == consumer

        await gw.write_job_meta(job_id, decoded, stream)

        meta = await gw.get_job_meta(job_id)
        assert meta is not None
        assert meta["conversionId"] == job["conversionId"]
        assert meta["inputBucket"] == job["inputBucket"]
        assert meta["inputKey"] == job["inputKey"]
        assert meta["stream"] == stream
        assert meta["targetFormat"] == job["targetFormat"]
        # requeue-attempt-generation-marker: golden-фикстура (legacy, до этого
        # изменения) не несёт `attempt` → дефолт 0, как у остальных числовых полей.
        assert meta["attempt"] == 0

        ttl = await client.ttl(f"worker:job:{job_id}")
        assert 0 < ttl <= JOB_META_TTL, f"TTL меты вне (0, 24h]: {ttl}"

        await gw.ack(stream, job_id)

        assert await _pending_count(client, stream) == 0, "PEL должен быть пуст"
        assert await client.exists(f"worker:job:{job_id}") == 0, "мета удалена при ack"
    finally:
        await _cleanup(client, stream)
        await client.aclose()


@pytest.mark.asyncio
async def test_write_job_meta_attempt_roundtrips_as_int():
    """requeue-attempt-generation-marker: job несёт `attempt` как строку-int
    (PHP-контракт, напр. `"2"`) → write_job_meta/get_job_meta round-trip даёт int."""
    client, gw = await _new_gateway()
    stream = stream_for(_unique_type())
    try:
        job = _golden_job()
        job["attempt"] = "2"
        await client.xadd(stream, {"message": json.dumps(job)})

        result = await gw.read_new(stream, "worker-test-1", block_ms=2000)
        assert result is not None
        job_id, decoded = result

        await gw.write_job_meta(job_id, decoded, stream)
        meta = await gw.get_job_meta(job_id)
        assert meta is not None
        assert meta["attempt"] == 2
    finally:
        await _cleanup(client, stream)
        await client.aclose()


@pytest.mark.asyncio
async def test_poison_entry_dropped_and_acked():
    client, gw = await _new_gateway()
    stream = stream_for(_unique_type())
    try:
        await client.xadd(stream, {"message": "{ не валидный json"})

        result = await gw.read_new(stream, "worker-test-1", block_ms=2000)
        assert result is None, "отравленная запись не возвращается"
        assert await _pending_count(client, stream) == 0, "poison XACKed → PEL пуст"
    finally:
        await _cleanup(client, stream)
        await client.aclose()


@pytest.mark.asyncio
async def test_nonpositive_conversion_id_dropped():
    client, gw = await _new_gateway()
    stream = stream_for(_unique_type())
    try:
        job = _golden_job()
        job["conversionId"] = 0
        await client.xadd(stream, {"message": json.dumps(job)})

        result = await gw.read_new(stream, "worker-test-1", block_ms=2000)
        assert result is None, "conversionId<=0 не возвращается"
        assert await _pending_count(client, stream) == 0, "дроп XACKed → PEL пуст"
    finally:
        await _cleanup(client, stream)
        await client.aclose()


@pytest.mark.asyncio
async def test_reclaim_stale_fresh_stream_returns_none():
    client, gw = await _new_gateway()
    stream = stream_for(_unique_type())
    consumer = "worker-test-1"
    try:
        job = _golden_job()
        await client.xadd(stream, {"message": json.dumps(job)})
        # Забираем запись — она попадает в PEL с idle=0 (< 5 мин).
        assert await gw.read_new(stream, consumer, block_ms=2000) is not None
        # XAUTOCLAIM с порогом 5 мин ничего не заберёт у свежей записи.
        assert await gw.reclaim_stale(stream, consumer) is None
    finally:
        await _cleanup(client, stream)
        await client.aclose()


@pytest.mark.asyncio
async def test_reclaim_and_expiry_scan_harmless_on_never_created_stream(caplog):
    """CNV-88 Task B: `WORKER_TYPES`/`ALLOWED_WORKER_TYPES` теперь несут `browser`
    (69bbc7b), а `reclaim._sweep_all_types`/`expiry.sweep_all_types` безусловно
    итерируют ПО ВСЕМ `WORKER_TYPES` при каждом тике — включая типы без единого
    подключённого консьюмера (`conv.browser` сегодня). Доказываем на РЕАЛЬНОМ
    KeyDB для стрима, который НИ РАЗУ не существовал (не только пуст — как
    `conv.browser` до первого деплоя этого изменения):
      - `XGROUP CREATE ... MKSTREAM` (`ensure_group`, вызывается и `reclaim_idle`,
        и `get_last_delivered_id`) не падает и не логирует WARNING/ERROR;
      - `XAUTOCLAIM` (reclaim.py-путь) на только что созданном пустом стриме
        возвращает `[]`, не падает;
      - `XINFO GROUPS` + `XRANGE` (expiry.py-путь) на нём же не падают, backlog
        пуст.
    Единственный побочный эффект — MKSTREAM реально создаёт пустой стрим + группу
    в KeyDB (не no-op), это НЕ гонка/ошибка, но новый persistent-ключ.
    """
    client, gw = await _new_gateway()
    stream = stream_for(_unique_type())
    consumer = "gw-reclaim-test"
    try:
        assert await client.exists(stream) == 0, "стрим гарантированно не существует"

        with caplog.at_level("WARNING", logger="workers.gateway.keydb"):
            # reclaim.py-путь: ensure_group(MKSTREAM) + XAUTOCLAIM на пустом стриме.
            assert await gw.reclaim_idle(stream, consumer, min_idle_ms=0) == []
            # expiry.py-путь: ensure_group + XINFO GROUPS + XRANGE на пустом стриме.
            assert await gw.get_last_delivered_id(stream) == "0-0"
            assert await gw.scan_undelivered_backlog(stream, "0-0", count=10) == []

        assert caplog.records == [], (
            f"ни один sweep-путь не должен логировать WARNING/ERROR на пустом "
            f"стриме без консьюмера, получили: {[r.message for r in caplog.records]}"
        )
        # MKSTREAM реально создал пустой стрим с группой — не поднял исключение.
        assert await client.exists(stream) == 1
        assert await _pending_count(client, stream) == 0
    finally:
        await _cleanup(client, stream)
        await client.aclose()


@pytest.mark.asyncio
async def test_get_job_meta_missing_returns_none():
    client, gw = await _new_gateway()
    try:
        assert await gw.get_job_meta("999999-0") is None
    finally:
        await client.aclose()
