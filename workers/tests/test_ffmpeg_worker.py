"""Tests for FfmpegWorker.convert() — Phase 1 stream-based worker.

run_ffmpeg is mocked (no ffmpeg binary in the unit-test env): the mock writes a
dummy output file so the worker's post-conversion checks pass. This keeps the
tests fast and environment-independent while still exercising format validation,
output placement, MIME selection, and the streams subscription wiring.
"""

from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

from workers.ffmpeg.worker import (
    FfmpegWorker,
    _AUDIO_TIMEOUT,
    _VIDEO_TIMEOUT,
    SUPPORTED,
)


def _make_job(conv_id: int, input_path: Path, src_fmt: str, tgt_fmt: str) -> dict:
    return {
        "conversionId": conv_id,
        "inputBucket": "convertor-inputs",
        "inputKey": f"inputs/{Path(input_path).name}",
        "_localInput": str(input_path),
        "originalFilename": Path(input_path).name,
        "sourceFormat": src_fmt,
        "targetFormat": tgt_fmt,
        "category": "audio",
        "isAi": False,
        "subType": None,
        "options": [],
    }


def _worker(tmp_path: Path) -> FfmpegWorker:
    import workers.common.stream_consumer as sc_mod
    import workers.ffmpeg.worker as fw_mod

    mock_redis = MagicMock()
    with patch.object(sc_mod, "REDIS_HOST", "localhost"), \
         patch("workers.common.stream_consumer.redis.Redis", return_value=mock_redis):
        worker = FfmpegWorker()

    patch.object(fw_mod, "WORK_DIR", tmp_path).start()
    patch.object(sc_mod, "WORK_DIR", tmp_path).start()
    return worker


def _src(tmp_path: Path, name: str) -> Path:
    p = tmp_path / name
    p.write_bytes(b"\x00\x01\x02fake-media")
    return p


# ---------------------------------------------------------------------------
# Subscription wiring
# ---------------------------------------------------------------------------

def test_subscribes_to_audio_and_video_streams():
    assert FfmpegWorker.CAPABILITIES["routing_keys"] == ["audio", "video"]


# ---------------------------------------------------------------------------
# convert() — happy paths (ffmpeg mocked to write a dummy output)
# ---------------------------------------------------------------------------

class TestFfmpegConvert:
    def _patch_ffmpeg(self):
        async def fake(src, out_path, timeout):
            Path(out_path).write_bytes(b"converted")
        return patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake)

    def test_mp3_to_wav(self, tmp_path):
        src = _src(tmp_path, "in.mp3")
        worker = _worker(tmp_path)
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), self._patch_ffmpeg():
            out_path, mime, ext = worker.convert(_make_job(1, src, "mp3", "wav"))
        assert Path(out_path).exists()
        assert ext == "wav"
        assert mime == "audio/wav"

    def test_mp4_to_mp3_audio_extraction(self, tmp_path):
        src = _src(tmp_path, "in.mp4")
        worker = _worker(tmp_path)
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), self._patch_ffmpeg():
            out_path, mime, ext = worker.convert(_make_job(2, src, "mp4", "mp3"))
        assert ext == "mp3"
        assert mime == "audio/mpeg"

    def test_video_uses_video_timeout(self, tmp_path):
        src = _src(tmp_path, "in.mp4")
        worker = _worker(tmp_path)
        captured = {}

        async def fake(src_, out_path, timeout):
            captured["timeout"] = timeout
            Path(out_path).write_bytes(b"v")

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), \
             patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake):
            worker.convert(_make_job(3, src, "mp4", "webm"))
        assert captured["timeout"] == _VIDEO_TIMEOUT

    def test_audio_uses_audio_timeout(self, tmp_path):
        src = _src(tmp_path, "in.wav")
        worker = _worker(tmp_path)
        captured = {}

        async def fake(src_, out_path, timeout):
            captured["timeout"] = timeout
            Path(out_path).write_bytes(b"a")

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), \
             patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake):
            worker.convert(_make_job(4, src, "wav", "mp3"))
        assert captured["timeout"] == _AUDIO_TIMEOUT

    def test_output_in_work_dir_with_conv_id(self, tmp_path):
        src = _src(tmp_path, "in.mp3")
        worker = _worker(tmp_path)
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), self._patch_ffmpeg():
            out_path, _, ext = worker.convert(_make_job(99, src, "mp3", "ogg"))
        name = Path(out_path).name
        assert Path(out_path).parent == tmp_path
        assert name.startswith("out-99-")
        assert name.endswith(f".{ext}")


# ---------------------------------------------------------------------------
# Error / unsupported-format cases
# ---------------------------------------------------------------------------

class TestFfmpegConvertErrors:
    def test_unsupported_source_raises(self, tmp_path):
        src = _src(tmp_path, "in.xyz")
        worker = _worker(tmp_path)
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported input format"):
                worker.convert(_make_job(5, src, "xyz", "mp3"))

    def test_unsupported_conversion_raises(self, tmp_path):
        src = _src(tmp_path, "in.mp3")
        worker = _worker(tmp_path)
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported conversion"):
                worker.convert(_make_job(6, src, "mp3", "mp4"))

    def test_missing_input_raises(self, tmp_path):
        worker = _worker(tmp_path)
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            with pytest.raises(FileNotFoundError):
                worker.convert(_make_job(7, tmp_path / "nope.mp3", "mp3", "wav"))

    def test_no_output_produced_raises(self, tmp_path):
        src = _src(tmp_path, "in.mp3")
        worker = _worker(tmp_path)

        async def fake_noop(src_, out_path, timeout):
            pass  # produce nothing

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), \
             patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake_noop):
            with pytest.raises(RuntimeError, match="no output"):
                worker.convert(_make_job(8, src, "mp3", "wav"))

    def test_engine_failure_propagates(self, tmp_path):
        # A non-zero ffmpeg exit surfaces as RuntimeError from run_ffmpeg; the
        # worker must let it propagate so the base class retries/DLQs.
        src = _src(tmp_path, "in.mp3")
        worker = _worker(tmp_path)

        async def fake_fail(src_, out_path, timeout):
            raise RuntimeError("ffmpeg failed: Invalid data found when processing input")

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), \
             patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake_fail):
            with pytest.raises(RuntimeError, match="ffmpeg failed"):
                worker.convert(_make_job(9, src, "mp3", "wav"))

    def test_empty_input_passes_validation_and_reaches_engine(self, tmp_path):
        # Content-agnostic: a 0-byte file still passes worker-level format
        # validation and is handed to run_ffmpeg with the (empty) source intact.
        # Rejecting bad content is the engine's job, not the worker's — so here
        # the conversion succeeds and we assert run_ffmpeg saw the empty file.
        src = tmp_path / "empty.mp3"
        src.write_bytes(b"")
        worker = _worker(tmp_path)
        seen = {}

        async def fake(src_, out_path, timeout):
            seen["src"] = Path(src_)
            Path(out_path).write_bytes(b"converted")

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), \
             patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake) as mock_ffmpeg:
            out_path, mime, ext = worker.convert(_make_job(10, src, "mp3", "wav"))

        assert mock_ffmpeg.called, "run_ffmpeg must be reached for an empty input"
        assert seen["src"] == src
        assert ext == "wav" and Path(out_path).exists()


def test_matrix_mime_coverage():
    """Every target format reachable in SUPPORTED must have a MIME entry."""
    from workers.ffmpeg.worker import _MIME
    missing = set()
    for targets in SUPPORTED.values():
        for t in targets:
            if t not in _MIME:
                missing.add(t)
    assert not missing, f"missing MIME for: {missing}"
