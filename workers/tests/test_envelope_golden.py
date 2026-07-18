"""Golden-страж Python-стороны чистого single-JSON контракта (§4/§9 spec).

Читает ТОТ ЖЕ замороженный fixture, что и PHP-golden
(`app-symfony/tests/Fixtures/messenger_envelope.golden.json`), и проверяет, что
`envelope.parse_message` разбирает его одной `json.loads` в ожидаемый dict
задачи (§3) — как для str-, так и для bytes-ключей/значений. Любой дрейф
контракта (возврат обёртки `{body,headers}`, PHP-сериализация) → громкий отказ
в одном месте на обеих сторонах.
"""

import json
from pathlib import Path

import pytest

from workers.common.envelope import parse_message

_FIXTURE = (
    Path(__file__).resolve().parents[2]
    / "app-symfony"
    / "tests"
    / "Fixtures"
    / "messenger_envelope.golden.json"
)

_EXPECTED_JOB = {
    "conversionId": 123,
    "inputBucket": "convertor-inputs",
    "inputKey": "inputs/2026/06/19/ab12cd34.pdf",
    "originalFilename": "invoice.pdf",
    "sourceFormat": "pdf",
    "targetFormat": "docx",
    "category": "document",
    "isAi": False,
    "options": [],
    "attempt": "0",
}


def _fixture_bytes() -> bytes:
    return _FIXTURE.read_bytes()


def test_fixture_exists():
    assert _FIXTURE.is_file(), f"shared golden fixture missing: {_FIXTURE}"


def test_parse_message_str_key_str_value():
    raw = _fixture_bytes().decode("utf-8")
    job = parse_message({"message": raw})
    assert job == _EXPECTED_JOB


def test_parse_message_bytes_key_bytes_value():
    raw = _fixture_bytes()
    job = parse_message({b"message": raw})
    assert job == _EXPECTED_JOB


def test_parse_message_single_decode_no_envelope_wrap():
    # Декодированное — САМА задача, а не {body,headers}: одна декодировка.
    job = parse_message({"message": _fixture_bytes()})
    assert "body" not in job
    assert "headers" not in job
    assert job["conversionId"] == 123


def test_fixture_matches_expected_job():
    # Fixture (сырые байты, с экранированными слэшами) декодится в ожидаемый job.
    assert json.loads(_fixture_bytes()) == _EXPECTED_JOB


def test_parse_message_missing_field_raises():
    with pytest.raises(KeyError):
        parse_message({"data": "{}"})


def test_parse_message_malformed_raises():
    with pytest.raises(json.JSONDecodeError):
        parse_message({"message": "{not json"})
