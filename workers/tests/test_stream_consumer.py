"""Tests for stream_consumer: envelope decode and process_job seam."""

import json
from typing import Any
from unittest.mock import MagicMock

import pytest

from workers.common.stream_consumer import (
    StreamConsumerBase,
    _parse_entry,
)
from workers.common.ws_client import ProgressReporter, ResultSignal


# --------------------------------------------------------------------------
# (a) Envelope decode — §2 double-encoding, bytes/str keys
# --------------------------------------------------------------------------

def _make_fields(body: dict, key_type: str = "str", value_type: str = "str") -> dict:
    """Build a fake XREADGROUP fields dict for testing _parse_entry.

    Clean single-JSON contract (§2/§4): field `message` IS the job payload —
    one encode, no `{body,headers}` envelope wrap.
    """
    message_str = json.dumps(body)
    if key_type == "bytes":
        k: Any = b"message"
    else:
        k = "message"
    if value_type == "bytes":
        v: Any = message_str.encode("utf-8")
    else:
        v = message_str
    return {k: v}


def test_parse_entry_str_key_str_value():
    job = _parse_entry(_make_fields(
        {"conversionId": 1, "inputBucket": "convertor-inputs", "inputKey": "inputs/a.jpg"},
        "str", "str",
    ))
    assert job["conversionId"] == 1
    assert job["inputBucket"] == "convertor-inputs"
    assert job["inputKey"] == "inputs/a.jpg"


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
        "inputBucket": "convertor-inputs",
        "inputKey": "inputs/2026/06/19/abc.png",
        "originalFilename": "abc.png",
        "sourceFormat": "png",
        "targetFormat": "jpg",
        "category": "image",
        "isAi": False,
        "options": [],
    }
    job = _parse_entry(_make_fields(body))
    assert job == body


# --------------------------------------------------------------------------
# (b) process_job seam — transport-agnostic unit tests
#
# No redis mocks, no S3 mocks, no XACK assertions — those are transport details
# handled by WsClient, not by the worker base class.
# --------------------------------------------------------------------------

def _make_progress() -> ProgressReporter:
    return MagicMock(spec=ProgressReporter)


def _job(**kwargs: Any) -> dict:
    defaults = {
        "jobId": "test-job-1",
        "sourceFormat": "csv",
        "targetFormat": "json",
        "_localInput": "/tmp/in.csv",
    }
    defaults.update(kwargs)
    return defaults


async def test_process_job_returns_completed_on_success(tmp_path):
    """Successful convert() → ResultSignal.completed with correct fields."""
    out_file = tmp_path / "out.json"
    out_file.write_text("{}", encoding="utf-8")

    class W(StreamConsumerBase):
        CAPABILITIES = {"routing_keys": ["data"], "matrix": {}}

        def convert(self, job: dict) -> tuple[str, str, str]:
            return str(out_file), "application/json", "json"

    progress = _make_progress()
    result = await W().process_job(_job(), progress)

    assert result.ok is True
    assert result.mime == "application/json"
    assert result.ext == "json"
    assert result.path == str(out_file)
    assert result.permanent is False
    progress.report.assert_any_call(5, "starting")
    progress.report.assert_any_call(95, "done")


async def test_process_job_value_error_is_permanent():
    """ValueError → ResultSignal.failed(permanent=True) — bad format pair."""

    class W(StreamConsumerBase):
        CAPABILITIES = {"routing_keys": ["data"], "matrix": {}}

        def convert(self, job: dict) -> tuple[str, str, str]:
            raise ValueError("unsupported conversion: csv -> exe")

    result = await W().process_job(_job(), _make_progress())

    assert result.ok is False
    assert result.permanent is True
    assert "ValueError" in result.error
    assert "unsupported" in result.error


async def test_process_job_file_not_found_is_transient():
    """FileNotFoundError → ResultSignal.failed(permanent=False) — resource issue."""

    class W(StreamConsumerBase):
        CAPABILITIES = {"routing_keys": ["data"], "matrix": {}}

        def convert(self, job: dict) -> tuple[str, str, str]:
            raise FileNotFoundError("binary not found")

    result = await W().process_job(_job(), _make_progress())

    assert result.ok is False
    assert result.permanent is False
    assert "FileNotFoundError" in result.error


async def test_process_job_generic_exception_is_transient():
    """Any other exception → ResultSignal.failed(permanent=False) — retry candidate."""

    class W(StreamConsumerBase):
        CAPABILITIES = {"routing_keys": ["data"], "matrix": {}}

        def convert(self, job: dict) -> tuple[str, str, str]:
            raise RuntimeError("transient failure")

    result = await W().process_job(_job(), _make_progress())

    assert result.ok is False
    assert result.permanent is False
    assert "RuntimeError" in result.error


async def test_process_job_passes_local_input_to_convert(tmp_path):
    """process_job passes job dict (incl. _localInput) straight to convert()."""
    seen: dict[str, Any] = {}
    out_file = tmp_path / "out.xml"
    out_file.write_text("<root/>", encoding="utf-8")

    class W(StreamConsumerBase):
        CAPABILITIES = {"routing_keys": ["data"], "matrix": {}}

        def convert(self, job: dict) -> tuple[str, str, str]:
            seen["job"] = job
            return str(out_file), "application/xml", "xml"

    job = _job(_localInput="/tmp/special.csv", sourceFormat="csv", targetFormat="xml")
    await W().process_job(job, _make_progress())

    assert seen["job"]["_localInput"] == "/tmp/special.csv"
    assert seen["job"]["sourceFormat"] == "csv"
    assert seen["job"]["targetFormat"] == "xml"
