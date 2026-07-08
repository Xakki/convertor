"""End-to-end тест WS-transport архитектуры (conv.data → csv→json).

Сквозной поток:
  SEED:  INSERT user+file_storage+conversion → convertor-test DB
         Загрузить data.csv → S3 convertor-inputs (ключ с e2e/ префиксом)
         XADD job → conv.data stream (KeyDB DB 3, Messenger envelope)
  RUN:   ws-gateway XREADGROUPs → set_status_processing → dispatch WS
         worker-data: GET /api/v1/worker/jobs/{jobId}/input (Symfony → S3 стрим)
         worker-data: конвертирует csv→json, отправляет inline результат по WS
         gateway: POST /api/v1/internal/worker/result → Symfony: S3 put + DB persist
         gateway: XACK → DEL conv:status:{convId}
  CHECK: Фаза 1 — conv:status key появился (state=processing)
         Фаза 2 — conv:status key исчез (gateway сделал XACK+clear_status)
         DB — conversions.status == 'completed'
         S3 — скачать result key, проверить корректный JSON

Требует поднятого стека (`make up`) и живого S3 (S3_SECRET задан).
Изоляция: KeyDB DB 3 (тест) vs DB 2 (dev), MariaDB 'convertor-test'.
"""

import json
import os
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path

import pytest

from workers.common import s3 as s3_mod

pytestmark = pytest.mark.e2e

FIXTURES = Path(__file__).parent / "example_files"

REDIS_HOST     = os.getenv("REDIS_HOST", "keydb")
REDIS_PORT     = int(os.getenv("REDIS_PORT", "6379"))
REDIS_DB       = int(os.getenv("REDIS_DB", "2"))
REDIS_PASSWORD = os.getenv("REDIS_PASSWORD") or None

S3_BUCKET_PREFIX = os.getenv("S3_BUCKET_PREFIX", "convertor")
S3_PREFIX        = os.getenv("S3_PREFIX", "")
INPUTS_BUCKET    = f"{S3_BUCKET_PREFIX}-inputs"
RESULTS_BUCKET   = f"{S3_BUCKET_PREFIX}-results"

DB_HOST = os.getenv("DB_HOST", "mariadb")
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_NAME = os.getenv("DB_NAME", "convertor-test")
DB_USER = os.getenv("DB_USER", "convertor-test")
DB_PASS = os.getenv("DB_PASS", "123456")

_no_s3 = not (os.getenv("S3_ENDPOINT") and os.getenv("S3_SECRET"))
requires_s3 = pytest.mark.skipif(_no_s3, reason="S3 endpoint/secret not configured")


def _redis():
    import redis
    return redis.Redis(
        host=REDIS_HOST, port=REDIS_PORT, db=REDIS_DB,
        password=REDIS_PASSWORD, decode_responses=True,
    )


def _db():
    import pymysql
    return pymysql.connect(
        host=DB_HOST, port=DB_PORT, db=DB_NAME,
        user=DB_USER, password=DB_PASS,
        autocommit=True,
        connect_timeout=5,
    )


def _s3():
    return s3_mod._make_client()


def _seed_db(input_key: str, fixture_size: int) -> tuple[int, int]:
    """INSERT user + file_storage + conversion row. Returns (conv_id, file_id)."""
    conn = _db()
    try:
        cur = conn.cursor()
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        quota_reset = now[:10] + " 23:59:59"

        # Test user (INSERT IGNORE — идемпотентно если уже есть)
        cur.execute(
            """INSERT IGNORE INTO users
               (telegram_id, plan, daily_conversions, daily_ai_conversions,
                quota_reset_at, created_at, is_active)
               VALUES (99999999, 'free', 0, 0, %s, %s, 1)""",
            (quota_reset, now),
        )
        cur.execute("SELECT id FROM users WHERE telegram_id = 99999999")
        row = cur.fetchone()
        assert row is not None, "Test user not found after INSERT IGNORE"
        user_id = row[0]

        # Входной файл
        cur.execute(
            """INSERT INTO file_storage
               (original_name, storage_path, mime_type, size_bytes, created_at)
               VALUES ('data.csv', %s, 'text/csv', %s, %s)""",
            (input_key, fixture_size, now),
        )
        file_id = cur.lastrowid

        # Conversion
        cur.execute(
            """INSERT INTO conversions
               (user_id, input_file_id, from_format, to_format, category,
                status, is_ai, is_ocr, created_at, updated_at)
               VALUES (%s, %s, 'csv', 'json', 'data', 'pending', 0, 0, %s, %s)""",
            (user_id, file_id, now, now),
        )
        conv_id = cur.lastrowid
    finally:
        conn.close()
    return conv_id, file_id


def _cleanup_db(conv_id: int, file_id: int) -> None:
    try:
        conn = _db()
        cur = conn.cursor()
        # Найти output_file_id до удаления conversion
        cur.execute("SELECT output_file_id FROM conversions WHERE id = %s", (conv_id,))
        row = cur.fetchone()
        output_file_id = row[0] if row else None
        cur.execute("DELETE FROM conversions WHERE id = %s", (conv_id,))
        cur.execute("DELETE FROM file_storage WHERE id = %s", (file_id,))
        if output_file_id:
            cur.execute("DELETE FROM file_storage WHERE id = %s", (output_file_id,))
        conn.close()
    except Exception:  # noqa: BLE001
        pass


def _enqueue(r, conv_id: int, input_key: str) -> None:
    """XADD Symfony Messenger envelope в conv.data (KeyDB DB 3)."""
    payload = {
        "conversionId":    conv_id,
        "inputBucket":     INPUTS_BUCKET,
        "inputKey":        input_key,
        "originalFilename": "data.csv",
        "sourceFormat":    "csv",
        "targetFormat":    "json",
        "category":        "data",
        "isAi":            False,
        "options":         [],
    }
    r.xadd("conv.data", {"message": json.dumps(payload)})


def _wait_processing(r, conv_id: int, timeout_s: float) -> None:
    """Фаза 1: ждать, пока gateway dispatch'ит задачу (conv:status key появится)."""
    key = f"conv:status:{conv_id}"
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        if r.hget(key, "state") == "processing":
            return
        time.sleep(0.5)
    pytest.fail(f"conv:status:{conv_id} не достиг state=processing за {timeout_s}s")


def _wait_terminal(r, conv_id: int, timeout_s: float) -> None:
    """Фаза 2: ждать, пока gateway DEL'ит conv:status key (XACK, терминальное состояние).

    Gateway вызывает clear_status(conv_id) → DEL conv:status:{conv_id} при успешном
    XACK (не HSET state=completed). Отсутствие ключа = терминал.
    """
    key = f"conv:status:{conv_id}"
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        if not r.exists(key):
            return
        time.sleep(0.5)
    last = r.hgetall(key)
    pytest.fail(f"conv:status:{conv_id} не удалён за {timeout_s}s; last={last!r}")


def _check_db_completed(conv_id: int) -> None:
    """Финальный DB-оракул: conversions.status == 'completed'."""
    conn = _db()
    try:
        cur = conn.cursor()
        cur.execute("SELECT status FROM conversions WHERE id = %s", (conv_id,))
        row = cur.fetchone()
        assert row is not None, f"Conversion {conv_id} не найдена в DB после терминала"
        assert row[0] == "completed", f"Ожидался status=completed, получен {row[0]!r}"
    finally:
        conn.close()


def _result_key(conv_id: int) -> str:
    """Предсказать S3 result key (зеркало ResultKeyBuilder::build из PHP).

    Формат: {S3_PREFIX}results/{Y}/{m-d}/{conv_id}.json
    """
    date_str = datetime.now(timezone.utc).strftime("%Y/%m-%d")
    return f"{S3_PREFIX}results/{date_str}/{conv_id}.json"


def _valid_json(data: bytes) -> None:
    parsed = json.loads(data.decode("utf-8"))
    assert isinstance(parsed, list) and parsed, "вывод не является непустым JSON-массивом"
    assert parsed[0]["name"] == "alice", f"неожиданная первая запись: {parsed[0]!r}"


@requires_s3
def test_worker_data_csv_to_json():
    """csv→json через data stream: полный WS-transport seed→gateway→relay поток."""
    fx = FIXTURES / "data.csv"
    assert fx.is_file(), f"фикстура не найдена: {fx}"
    fixture_bytes = fx.read_bytes()

    input_key = f"{S3_PREFIX}e2e/{uuid.uuid4().hex}.csv"

    r   = _redis()
    cli = _s3()

    # SEED: S3 + DB
    s3_mod.put_file(str(fx), INPUTS_BUCKET, input_key, "text/csv")
    conv_id, file_id = _seed_db(input_key, len(fixture_bytes))

    output_key = None
    try:
        # Запустить поток: XADD → gateway → worker → relay
        _enqueue(r, conv_id, input_key)

        # Фаза 1: gateway получил задачу и dispatch'ит её воркеру
        _wait_processing(r, conv_id, timeout_s=30.0)

        # Фаза 2: relay завершён, gateway XACK'нул, conv:status удалён
        _wait_terminal(r, conv_id, timeout_s=90.0)

        # Вычисляем result key ПОСЛЕ терминала: PHP persists при relay, дата = время
        # persist'а (30–90s после seed), а не seed'а — иначе midnight crossing ломает ключ.
        # output_key ставим до DB-проверки, чтобы finally убирал объект даже при assert fail.
        output_key = _result_key(conv_id)

        # DB-оракул: статус должен быть completed
        _check_db_completed(conv_id)

        # S3: скачать результат и валидировать
        obj  = cli.get_object(Bucket=RESULTS_BUCKET, Key=output_key)
        data = obj["Body"].read()
        _valid_json(data)

    finally:
        try:
            cli.delete_object(Bucket=INPUTS_BUCKET, Key=input_key)
        except Exception:  # noqa: BLE001
            pass
        if output_key:
            try:
                cli.delete_object(Bucket=RESULTS_BUCKET, Key=output_key)
            except Exception:  # noqa: BLE001
                pass
        _cleanup_db(conv_id, file_id)
        r.delete(f"conv:status:{conv_id}")
