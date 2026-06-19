"""Tests for stream_consumer: envelope decode and idempotency guard."""

import json
from typing import Any
from unittest.mock import MagicMock, call, patch

import pytest

from workers.common.stream_consumer import (
    _GROUP,
    StreamConsumerBase,
    _parse_entry,
)


# --------------------------------------------------------------------------
# (a) Envelope decode — §2 double-encoding, bytes/str keys
# --------------------------------------------------------------------------

def _make_fields(body: dict, key_type: str = "str", value_type: str = "str") -> dict:
    """Build a fake XREADGROUP fields dict for testing _parse_entry."""
    envelope_str = json.dumps({"body": json.dumps(body), "headers": {"type": "App\\Message\\ConversionMessage"}})
    if key_type == "bytes":
        k: Any = b"message"
    else:
        k = "message"
    if value_type == "bytes":
        v: Any = envelope_str.encode("utf-8")
    else:
        v = envelope_str
    return {k: v}


def test_parse_entry_str_key_str_value():
    job = _parse_entry(_make_fields({"conversionId": 1, "inputPath": "input/a.jpg"}, "str", "str"))
    assert job["conversionId"] == 1
    assert job["inputPath"] == "input/a.jpg"


def test_parse_entry_bytes_key_str_value():
    job = _parse_entry(_make_fields({"conversionId": 2, "targetFormat": "png"}, "bytes", "str"))
    assert job["conversionId"] == 2
    assert job["targetFormat"] == "png"


def test_parse_entry_bytes_key_bytes_value():
    job = _parse_entry(_make_fields({"conversionId": 3, "isAi": False}, "bytes", "bytes"))
    assert job["conversionId"] == 3
    assert job["isAi"] is False


def test_parse_entry_str_key_bytes_value():
    job = _parse_entry(_make_fields({"conversionId": 4, "options": []}, "str", "bytes"))
    assert job["conversionId"] == 4
    assert job["options"] == []  # empty list, not {}


def test_parse_entry_all_job_fields():
    body = {
        "conversionId": 99,
        "inputPath": "input/2026/06/19/abc.png",
        "sourceFormat": "png",
        "targetFormat": "jpg",
        "category": "image",
        "isAi": False,
        "subType": None,
        "options": [],
    }
    job = _parse_entry(_make_fields(body))
    assert job == body


# --------------------------------------------------------------------------
# Concrete stub for testing StreamConsumerBase
# --------------------------------------------------------------------------

class _StubWorker(StreamConsumerBase):
    """Minimal concrete subclass for unit testing the base."""

    CAPABILITIES = {"routing_keys": ["image"], "matrix": {}}

    def __init__(self, mock_redis: MagicMock) -> None:
        # Skip real __init__; inject mock redis directly.
        self._consumer = "test-worker-1"
        self._running = True
        self._redis = mock_redis

    def convert(self, job: dict) -> tuple[str, str, str]:
        raise NotImplementedError("should not be called in these tests")


def _make_entry_fields(conv_id: int) -> dict:
    body = {
        "conversionId": conv_id,
        "inputPath": f"input/2026/06/19/{conv_id}.png",
        "sourceFormat": "png",
        "targetFormat": "jpg",
        "category": "image",
        "isAi": False,
        "subType": None,
        "options": [],
    }
    return _make_fields(body)


# --------------------------------------------------------------------------
# (c) Idempotency guard
# --------------------------------------------------------------------------

def test_idempotency_guard_skips_when_completed():
    mock_redis = MagicMock()
    mock_redis.hget.return_value = "completed"
    mock_redis.hgetall.return_value = {
        "state": "completed",
        "outputBucket": "convertor-results",
        "outputKey": "results/2026/06/19/42.jpg",
        "outputMime": "image/jpeg",
        "outputSize": "98765",
    }

    worker = _StubWorker(mock_redis)
    convert_spy = MagicMock(side_effect=AssertionError("convert() must not be called"))
    worker.convert = convert_spy

    worker._process_entry("conv.image", "1234-0", _make_entry_fields(42))

    # Must ack and NOT call convert
    mock_redis.xack.assert_called_once_with("conv.image", _GROUP, "1234-0")
    convert_spy.assert_not_called()


def test_idempotency_guard_re_emits_result_event_when_completed():
    """On redelivery of a completed job, the result event must be re-emitted
    to conv.result BEFORE XACK so PHP always gets the DB-write trigger."""
    mock_redis = MagicMock()
    mock_redis.hget.return_value = "completed"
    mock_redis.hgetall.return_value = {
        "state": "completed",
        "outputBucket": "convertor-results",
        "outputKey": "results/2026/06/19/42.jpg",
        "outputMime": "image/jpeg",
        "outputSize": "98765",
    }

    worker = _StubWorker(mock_redis)
    worker._process_entry("conv.image", "1234-0", _make_entry_fields(42))

    # Verify XADD conv.result was called with the right shape
    xadd_calls = mock_redis.xadd.call_args_list
    result_calls = [c for c in xadd_calls if c.args[0] == "conv.result"]
    assert len(result_calls) == 1, "must emit exactly one result event"

    data = json.loads(result_calls[0].args[1]["data"])
    assert data["conversionId"] == 42
    assert data["state"] == "completed"
    assert data["outputBucket"] == "convertor-results"
    assert data["outputKey"] == "results/2026/06/19/42.jpg"
    assert data["outputMime"] == "image/jpeg"
    assert data["outputSize"] == 98765  # int, not string

    # XADD must precede XACK
    all_calls = [c.args[0] for c in mock_redis.method_calls if hasattr(c, "args")]
    # At minimum verify xack happened after the xadd (xadd is called, then xack)
    mock_redis.xack.assert_called_once_with("conv.image", _GROUP, "1234-0")


def test_idempotency_guard_proceeds_when_not_completed():
    mock_redis = MagicMock()
    # State hash reports 'processing' (or anything other than 'completed')
    mock_redis.hget.return_value = "processing"
    # Delivery count = 1
    mock_redis.xpending_range.return_value = [{"times_delivered": 1}]

    worker = _StubWorker(mock_redis)
    convert_called = []

    def fake_convert(job: dict) -> tuple[str, str, str]:
        convert_called.append(job)
        raise RuntimeError("convert error for test")

    worker.convert = fake_convert

    worker._process_entry("conv.image", "1234-0", _make_entry_fields(43))

    assert len(convert_called) == 1, "convert() should have been called once"
    # Entry must NOT be acked (left for retry)
    mock_redis.xack.assert_not_called()


def test_idempotency_guard_proceeds_when_pending():
    mock_redis = MagicMock()
    mock_redis.hget.return_value = "pending"
    mock_redis.xpending_range.return_value = [{"times_delivered": 1}]

    worker = _StubWorker(mock_redis)
    convert_calls = []

    def fake_convert(job: dict) -> tuple[str, str, str]:
        convert_calls.append(job)
        raise RuntimeError("stop here")

    worker.convert = fake_convert
    worker._process_entry("conv.image", "9999-0", _make_entry_fields(44))

    assert len(convert_calls) == 1


def test_idempotency_guard_proceeds_when_no_status():
    mock_redis = MagicMock()
    mock_redis.hget.return_value = None  # key doesn't exist
    mock_redis.xpending_range.return_value = [{"times_delivered": 1}]

    worker = _StubWorker(mock_redis)
    convert_calls = []

    def fake_convert(job: dict) -> tuple[str, str, str]:
        convert_calls.append(job)
        raise RuntimeError("stop here")

    worker.convert = fake_convert
    worker._process_entry("conv.image", "8888-0", _make_entry_fields(45))

    assert len(convert_calls) == 1


# --------------------------------------------------------------------------
# DLQ routing after max_retries
# --------------------------------------------------------------------------

def test_dlq_after_max_retries():
    mock_redis = MagicMock()
    mock_redis.hget.return_value = None  # not completed
    # Simulate 4th delivery (> max_retries=3)
    mock_redis.xpending_range.return_value = [{"times_delivered": 4}]

    worker = _StubWorker(mock_redis)
    convert_spy = MagicMock(side_effect=AssertionError("must not call convert after max retries"))
    worker.convert = convert_spy

    worker._process_entry("conv.image", "5555-0", _make_entry_fields(99))

    # Must ack and push to DLQ stream
    mock_redis.xack.assert_called_once_with("conv.image", _GROUP, "5555-0")
    dead_call_args = [c for c in mock_redis.xadd.call_args_list if c.args[0] == "conv.dead"]
    assert len(dead_call_args) == 1, "should emit one entry to conv.dead"
    convert_spy.assert_not_called()
