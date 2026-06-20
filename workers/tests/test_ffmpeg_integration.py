"""Integration test for FfmpegWorker — real ffmpeg, marker `integration`.

Unlike test_ffmpeg_worker.py (run_ffmpeg mocked), this drives the worker's
actual conversion code path on a real fixture (workers/tests/example_files/
video.3gp, h263+aac, 3s) and asserts a valid mp4 is produced. Skipped when
ffmpeg is not on PATH so a plain `pytest workers/tests` run stays green.
"""

import shutil
import subprocess
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

from workers.ffmpeg.worker import FfmpegWorker

pytestmark = pytest.mark.integration

FIXTURE = Path(__file__).parent / "example_files" / "video.3gp"

requires_ffmpeg = pytest.mark.skipif(
    shutil.which("ffmpeg") is None, reason="ffmpeg not installed / not on PATH"
)


def _build_worker() -> FfmpegWorker:
    import workers.common.stream_consumer as sc_mod

    with patch.object(sc_mod, "REDIS_HOST", "localhost"), \
         patch("workers.common.stream_consumer.redis.Redis", return_value=MagicMock()):
        return FfmpegWorker()


def _job(conv_id: int, src: Path, src_fmt: str, tgt_fmt: str) -> dict:
    return {
        "conversionId": conv_id,
        "inputBucket": "convertor-inputs",
        "inputKey": f"inputs/{src.name}",
        "_localInput": str(src),
        "originalFilename": src.name,
        "sourceFormat": src_fmt,
        "targetFormat": tgt_fmt,
        "category": "video",
        "isAi": False,
        "subType": None,
        "options": [],
    }


@requires_ffmpeg
def test_3gp_to_mp4_real_ffmpeg(tmp_path):
    assert FIXTURE.is_file(), f"fixture missing: {FIXTURE}"
    src = tmp_path / "video.3gp"
    src.write_bytes(FIXTURE.read_bytes())

    worker = _build_worker()
    with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = worker.convert(_job(1, src, "3gp", "mp4"))

    out = Path(out_path)
    assert out.exists() and out.stat().st_size > 0
    assert ext == "mp4"
    assert mime == "video/mp4"

    # ffmpeg must fully decode the output without error (container is valid).
    probe = subprocess.run(
        ["ffmpeg", "-v", "error", "-i", str(out), "-f", "null", "-"],
        capture_output=True,
    )
    assert probe.returncode == 0, probe.stderr.decode("utf-8", "replace")
    assert out.read_bytes()[4:8] == b"ftyp", "output is not an ISO/MP4 container"
