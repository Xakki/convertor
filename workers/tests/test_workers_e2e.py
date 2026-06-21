"""End-to-end S3 in/out tests — marker `e2e`.

Unlike the `integration` tests (which drive a worker's `convert()` in-process),
these exercise the FULL runtime path of an already-running worker container:

    upload fixture → S3 inputs bucket
      → XADD job to conv.<stream>  (Symfony Messenger envelope shape)
      → running worker XREADGROUPs it, downloads from S3, converts,
        uploads result to S3 results bucket, HSETs conv:status:<id>
      → assert status == completed + result object is valid → cleanup

Requires the stack up (`make up`) AND reachable S3 (S3_SECRET set). Meant to be
run from inside a worker container that is on BOTH the `default` (S3 egress) and
`backend` (keydb) networks — see the `make test-e2e` target. Skipped cleanly
when S3 creds / redis are unavailable so a plain `pytest workers/tests` stays
green.
"""

import json
import os
import time
import uuid
from pathlib import Path

import pytest

from workers.common import s3

pytestmark = pytest.mark.e2e

FIXTURES = Path(__file__).parent / "example_files"

REDIS_HOST = os.getenv("REDIS_HOST", "keydb")
REDIS_PORT = int(os.getenv("REDIS_PORT", "6379"))
REDIS_DB = int(os.getenv("REDIS_DB", "2"))
REDIS_PASSWORD = os.getenv("REDIS_PASSWORD", "") or None
S3_BUCKET_PREFIX = os.getenv("S3_BUCKET_PREFIX", "convertor")
# Same key prefix the workers apply to generated output (set via .env.test in the
# `make test-e2e` path). The test owns its input objects, so it prefixes those itself.
S3_PREFIX = os.getenv("S3_PREFIX", "")
INPUTS_BUCKET = f"{S3_BUCKET_PREFIX}-inputs"
RESULTS_BUCKET = f"{S3_BUCKET_PREFIX}-results"

_no_s3 = not (os.getenv("S3_ENDPOINT") and os.getenv("S3_SECRET"))
requires_s3 = pytest.mark.skipif(_no_s3, reason="S3 endpoint/secret not configured")

# (fixture, sourceFormat, targetFormat, category, stream, validator)
CASES = [
    ("video.3gp", "3gp", "mp4", "video", "video", "_valid_mp4"),
    ("data.csv", "csv", "json", "data", "data", "_valid_json"),
]


def _redis():
    import redis

    return redis.Redis(
        host=REDIS_HOST, port=REDIS_PORT, db=REDIS_DB,
        password=REDIS_PASSWORD, decode_responses=True,
    )


def _client():
    return s3._make_client()


def _valid_mp4(data: bytes) -> None:
    assert len(data) > 0
    assert data[4:8] == b"ftyp", "output is not an ISO/MP4 container"


def _valid_json(data: bytes) -> None:
    parsed = json.loads(data.decode("utf-8"))
    assert isinstance(parsed, list) and parsed, "json output is not a non-empty list"
    assert parsed[0]["name"] == "alice"


def _enqueue(r, stream: str, job: dict) -> None:
    # Symfony Messenger Redis-Streams entry: field `message` = {body, headers},
    # body itself a JSON string (double-encoded). See stream_consumer._parse_entry.
    envelope = {"body": json.dumps(job), "headers": {}}
    r.xadd(f"conv.{stream}", {"message": json.dumps(envelope)})


def _wait_completed(r, conv_id: int, timeout_s: float) -> dict:
    status_key = f"conv:status:{conv_id}"
    deadline = time.time() + timeout_s
    last: dict = {}
    while time.time() < deadline:
        last = r.hgetall(status_key)
        state = last.get("state")
        if state == "completed":
            return last
        if last.get("error"):
            pytest.fail(f"worker reported error: {last.get('error')}")
        time.sleep(1.0)
    pytest.fail(f"timeout after {timeout_s}s; last status={last!r}")


@requires_s3
@pytest.mark.parametrize("fixture,src_fmt,tgt_fmt,category,stream,validator", CASES)
def test_worker_s3_roundtrip(fixture, src_fmt, tgt_fmt, category, stream, validator):
    fx = FIXTURES / fixture
    assert fx.is_file(), f"fixture missing: {fx}"

    # Unique synthetic id well above real conversion ids; random low bits keep
    # concurrent/parametrized runs from colliding on the conv:status:<id> key.
    conv_id = 9_000_000_000 + int.from_bytes(os.urandom(4), "big")
    input_key = f"{S3_PREFIX}e2e/{conv_id}-{uuid.uuid4().hex}.{src_fmt}"
    r = _redis()
    cli = _client()

    s3.put_file(str(fx), INPUTS_BUCKET, input_key, "application/octet-stream")

    job = {
        "conversionId": conv_id,
        "inputBucket": INPUTS_BUCKET,
        "inputKey": input_key,
        "originalFilename": fixture,
        "sourceFormat": src_fmt,
        "targetFormat": tgt_fmt,
        "category": category,
        "isAi": False,
        "subType": None,
        "options": [],
    }

    output_key = None
    try:
        _enqueue(r, stream, job)
        status = _wait_completed(r, conv_id, timeout_s=120.0)

        assert status["outputBucket"] == RESULTS_BUCKET
        output_key = status["outputKey"]
        # Worker must honor S3_PREFIX on its generated output key (not a silent no-op).
        assert output_key.startswith(S3_PREFIX), f"output key not prefixed: {output_key}"
        obj = cli.get_object(Bucket=RESULTS_BUCKET, Key=output_key)
        data = obj["Body"].read()
        globals()[validator](data)
    finally:
        cli.delete_object(Bucket=INPUTS_BUCKET, Key=input_key)
        if output_key:
            cli.delete_object(Bucket=RESULTS_BUCKET, Key=output_key)
        r.delete(f"conv:status:{conv_id}")
