"""End-to-end smoke for WS-transport across all worker categories.

Сквозной поток (на категорию):
  SEED:  INSERT user+file_storage+conversion → convertor-test DB
         Upload fixture → S3 convertor-inputs (ключ с e2e/ префиксом)
         XADD job → conv.<type> stream (KeyDB DB 3, Messenger envelope)
  RUN:   ws-gateway → WS worker → Symfony relay → S3 result
  CHECK: processing → terminal (DEL conv:status) → DB completed → S3 body

Категории: document, image, audio, video, data, ai.
Markup свёрнут в document (docx→pdf). AI — hard-require (txt→wav TTS / espeak).

Требует поднятого тест-стенда (`make TEST=1 smoke` / `make smoke`) и живого S3.
Изоляция: KeyDB DB 3, MariaDB convertor-test, S3_PREFIX=test_.
"""

from __future__ import annotations

import json
import os
import time
import uuid
from collections.abc import Callable
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

import pytest

from workers.common import s3 as s3_mod

pytestmark = pytest.mark.e2e

EXAMPLE_FILES = Path(__file__).parent / "example_files"
SYMFONY_FIXTURES = Path(__file__).resolve().parents[2] / "app-symfony" / "tests" / "Fixtures"

REDIS_HOST = os.getenv("REDIS_HOST", "keydb")
REDIS_PORT = int(os.getenv("REDIS_PORT", "6379"))
REDIS_DB = int(os.getenv("REDIS_DB", "2"))
REDIS_PASSWORD = os.getenv("REDIS_PASSWORD") or None

S3_BUCKET_PREFIX = os.getenv("S3_BUCKET_PREFIX", "convertor")
S3_PREFIX = os.getenv("S3_PREFIX", "")
INPUTS_BUCKET = f"{S3_BUCKET_PREFIX}-inputs"
RESULTS_BUCKET = f"{S3_BUCKET_PREFIX}-results"

DB_HOST = os.getenv("DB_HOST", "mariadb")
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_NAME = os.getenv("DB_NAME", "convertor-test")
DB_USER = os.getenv("DB_USER", "convertor-test")
DB_PASS = os.getenv("DB_PASS", "123456")

_no_s3 = not (os.getenv("S3_ENDPOINT") and os.getenv("S3_SECRET"))
requires_s3 = pytest.mark.skipif(_no_s3, reason="S3 endpoint/secret not configured")


@dataclass(frozen=True)
class SmokeCase:
    """One conversion leg for smoke / e2e."""

    id: str
    fixture: Path
    source_format: str
    target_format: str
    category: str
    stream: str
    mime: str
    is_ai: bool
    timeout_s: float
    validate: Callable[[bytes], None]


def _valid_json_csv(data: bytes) -> None:
    parsed = json.loads(data.decode("utf-8"))
    assert isinstance(parsed, list) and parsed, "вывод не является непустым JSON-массивом"
    assert parsed[0]["name"] == "alice", f"неожиданная первая запись: {parsed[0]!r}"


def _valid_png(data: bytes) -> None:
    assert data[:8] == b"\x89PNG\r\n\x1a\n", "result lacks PNG signature"


def _valid_wav(data: bytes) -> None:
    assert data[:4] == b"RIFF", "result lacks RIFF header"
    assert data[8:12] == b"WAVE", "result lacks WAVE tag"
    assert len(data) > 44, "WAV body too small"


def _valid_pdf(data: bytes) -> None:
    assert data[:4] == b"%PDF", "result lacks %PDF header"
    assert len(data) > 64, "PDF body too small"


def _valid_mp4(data: bytes) -> None:
    # ISO BMFF: size(4) + 'ftyp' at offset 4
    assert len(data) > 16, "MP4 body too small"
    assert data[4:8] == b"ftyp", f"result lacks ftyp box (got {data[4:8]!r})"


def _cases() -> list[SmokeCase]:
    return [
        SmokeCase(
            id="data_csv_json",
            fixture=EXAMPLE_FILES / "data.csv",
            source_format="csv",
            target_format="json",
            category="data",
            stream="conv.data",
            mime="text/csv",
            is_ai=False,
            timeout_s=90.0,
            validate=_valid_json_csv,
        ),
        SmokeCase(
            id="image_jpg_png",
            fixture=SYMFONY_FIXTURES / "image.jpg",
            source_format="jpg",
            target_format="png",
            category="image",
            stream="conv.image",
            mime="image/jpeg",
            is_ai=False,
            timeout_s=90.0,
            validate=_valid_png,
        ),
        SmokeCase(
            id="audio_mp3_wav",
            fixture=EXAMPLE_FILES / "story.mp3",
            source_format="mp3",
            target_format="wav",
            category="audio",
            stream="conv.audio",
            mime="audio/mpeg",
            is_ai=False,
            timeout_s=120.0,
            validate=_valid_wav,
        ),
        SmokeCase(
            id="video_3gp_mp4",
            fixture=EXAMPLE_FILES / "video.3gp",
            source_format="3gp",
            target_format="mp4",
            category="video",
            stream="conv.video",
            mime="video/3gpp",
            is_ai=False,
            timeout_s=180.0,
            validate=_valid_mp4,
        ),
        SmokeCase(
            id="document_docx_pdf",
            fixture=SYMFONY_FIXTURES / "document.docx",
            source_format="docx",
            target_format="pdf",
            category="document",
            stream="conv.document",
            mime="application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            is_ai=False,
            timeout_s=180.0,
            validate=_valid_pdf,
        ),
        SmokeCase(
            id="ai_txt_wav_tts",
            fixture=EXAMPLE_FILES / "smoke.txt",
            source_format="txt",
            target_format="wav",
            category="document",
            stream="conv.ai",
            mime="text/plain",
            is_ai=True,
            timeout_s=120.0,
            validate=_valid_wav,
        ),
    ]


def _redis():
    import redis

    return redis.Redis(
        host=REDIS_HOST,
        port=REDIS_PORT,
        db=REDIS_DB,
        password=REDIS_PASSWORD,
        decode_responses=True,
    )


def _db():
    import pymysql

    return pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        database=DB_NAME,
        user=DB_USER,
        password=DB_PASS,
        autocommit=True,
        connect_timeout=5,
    )


def _s3():
    return s3_mod._make_client()


def _seed_db(case: SmokeCase, input_key: str, fixture_size: int) -> tuple[int, int]:
    """INSERT user + file_storage + conversion. Returns (conv_id, file_id)."""
    conn = _db()
    try:
        cur = conn.cursor()
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        quota_reset = now[:10] + " 23:59:59"

        cur.execute(
            """INSERT IGNORE INTO users
               (telegram_id, plan,
                light_daily_conversions, light_monthly_conversions,
                medium_daily_conversions, medium_monthly_conversions,
                heavy_daily_conversions, heavy_monthly_conversions,
                ai_daily_conversions, ai_monthly_conversions,
                quota_reset_at, monthly_reset_at, created_at,
                is_active, is_guest, is_admin)
               VALUES (99999999, 'free',
                       0, 0, 0, 0, 0, 0, 0, 0,
                       %s, %s, %s, 1, 0, 0)""",
            (quota_reset, quota_reset, now),
        )
        cur.execute("SELECT id FROM users WHERE telegram_id = 99999999")
        row = cur.fetchone()
        assert row is not None, "Test user not found after INSERT IGNORE"
        user_id = row[0]

        original = case.fixture.name
        cur.execute(
            """INSERT INTO file_storage
               (original_name, storage_path, mime_type, size_bytes, created_at)
               VALUES (%s, %s, %s, %s, %s)""",
            (original, input_key, case.mime, fixture_size, now),
        )
        file_id = cur.lastrowid

        cur.execute(
            """INSERT INTO conversions
               (user_id, input_file_id, from_format, to_format, category,
                status, is_ai, is_ocr, created_at, updated_at)
               VALUES (%s, %s, %s, %s, %s, 'pending', %s, 0, %s, %s)""",
            (
                user_id,
                file_id,
                case.source_format,
                case.target_format,
                case.category,
                1 if case.is_ai else 0,
                now,
                now,
            ),
        )
        conv_id = cur.lastrowid
    finally:
        conn.close()
    return conv_id, file_id


def _cleanup_db(conv_id: int, file_id: int) -> None:
    try:
        conn = _db()
        cur = conn.cursor()
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


def _enqueue(r, case: SmokeCase, conv_id: int, input_key: str) -> None:
    """XADD Symfony Messenger envelope в conv.<type>."""
    payload = {
        "conversionId": conv_id,
        "inputBucket": INPUTS_BUCKET,
        "inputKey": input_key,
        "originalFilename": case.fixture.name,
        "sourceFormat": case.source_format,
        "targetFormat": case.target_format,
        "category": case.category,
        "isAi": case.is_ai,
        "options": [],
    }
    r.xadd(case.stream, {"message": json.dumps(payload)})


def _wait_processing(r, conv_id: int, timeout_s: float) -> None:
    key = f"conv:status:{conv_id}"
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        if r.hget(key, "state") == "processing":
            return
        time.sleep(0.5)
    pytest.fail(f"conv:status:{conv_id} не достиг state=processing за {timeout_s}s")


def _wait_terminal(r, conv_id: int, timeout_s: float) -> None:
    """Ждать DEL conv:status (XACK). Ключ исчезает и при success, и при fail."""
    key = f"conv:status:{conv_id}"
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        if not r.exists(key):
            return
        time.sleep(0.5)
    last = r.hgetall(key)
    pytest.fail(f"conv:status:{conv_id} не удалён за {timeout_s}s; last={last!r}")


def _check_db_completed(conv_id: int) -> None:
    conn = _db()
    try:
        cur = conn.cursor()
        cur.execute("SELECT status FROM conversions WHERE id = %s", (conv_id,))
        row = cur.fetchone()
        assert row is not None, f"Conversion {conv_id} не найдена в DB после терминала"
        assert row[0] == "completed", f"Ожидался status=completed, получен {row[0]!r}"
    finally:
        conn.close()


def _result_key(conv_id: int, target_format: str) -> str:
    """Зеркало ResultKeyBuilder::build — {S3_PREFIX}results/{Y}/{m-d}/{id}.{ext}."""
    date_str = datetime.now(timezone.utc).strftime("%Y/%m-%d")
    ext = "".join(c for c in target_format.lower() if c.isalnum()) or "bin"
    return f"{S3_PREFIX}results/{date_str}/{conv_id}.{ext}"


@requires_s3
@pytest.mark.parametrize("case", _cases(), ids=lambda c: c.id)
def test_worker_category_smoke(case: SmokeCase):
    """Один успешный seed→gateway→worker→S3 прогон на категорию (AI hard-fail)."""
    assert case.fixture.is_file(), f"фикстура не найдена: {case.fixture}"
    fixture_bytes = case.fixture.read_bytes()

    input_key = f"{S3_PREFIX}e2e/{uuid.uuid4().hex}.{case.source_format}"

    r = _redis()
    cli = _s3()

    s3_mod.put_file(str(case.fixture), INPUTS_BUCKET, input_key, case.mime)
    conv_id, file_id = _seed_db(case, input_key, len(fixture_bytes))

    output_key = None
    try:
        _enqueue(r, case, conv_id, input_key)
        _wait_processing(r, conv_id, timeout_s=min(30.0, case.timeout_s))
        _wait_terminal(r, conv_id, timeout_s=case.timeout_s)

        output_key = _result_key(conv_id, case.target_format)
        _check_db_completed(conv_id)

        obj = cli.get_object(Bucket=RESULTS_BUCKET, Key=output_key)
        data = obj["Body"].read()
        case.validate(data)

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
