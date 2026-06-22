"""Tests for AiWorker.convert() — Phase 1 stream-based worker.

The STT/TTS provider coroutines are mocked (no whisper/espeak in the unit-test
env): the mock writes a dummy output file. Tests exercise subType routing
(explicit + auto-detect), format validation, output placement, and MIME.
"""

from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

# worker-ai heavy deps (faster_whisper/espeak/provider SDKs) are lazy-imported
# inside the conversion functions, so importing the module here is safe and the
# provider coroutines are mocked in every test — no Stage-2 engines are touched.
from workers.ai.worker import AiWorker


def _make_job(conv_id, input_path, src_fmt, tgt_fmt, sub_type=None) -> dict:
    return {
        "conversionId": conv_id,
        "inputBucket": "convertor-inputs",
        "inputKey": f"inputs/{Path(input_path).name}",
        "_localInput": str(input_path),
        "originalFilename": Path(input_path).name,
        "sourceFormat": src_fmt,
        "targetFormat": tgt_fmt,
        "category": "audio",
        "isAi": True,
        "subType": sub_type,
        "options": [],
    }


def _worker(tmp_path: Path) -> AiWorker:
    import workers.common.stream_consumer as sc_mod
    import workers.ai.worker as aw_mod

    mock_redis = MagicMock()
    with patch.object(sc_mod, "REDIS_HOST", "localhost"), \
         patch("workers.common.stream_consumer.redis.Redis", return_value=mock_redis):
        worker = AiWorker()

    patch.object(aw_mod, "WORK_DIR", tmp_path).start()
    patch.object(sc_mod, "WORK_DIR", tmp_path).start()
    return worker


def _src(tmp_path: Path, name: str, content: bytes = b"data") -> Path:
    p = tmp_path / name
    p.write_bytes(content)
    return p


def _stt_mock():
    async def fake(src, output_format, out_path):
        Path(out_path).write_text("transcript", encoding="utf-8")
    return patch("workers.ai.worker._speech_to_text", side_effect=fake)


def _tts_mock():
    async def fake(src, output_format, out_path):
        Path(out_path).write_bytes(b"audio")
    return patch("workers.ai.worker._text_to_speech", side_effect=fake)


# ---------------------------------------------------------------------------
# Subscription wiring
# ---------------------------------------------------------------------------

def test_subscribes_to_ai_stream():
    assert AiWorker.CAPABILITIES["routing_keys"] == ["ai"]


# ---------------------------------------------------------------------------
# convert() — STT / TTS routing
# ---------------------------------------------------------------------------

class TestAiConvert:
    def test_stt_explicit_subtype(self, tmp_path):
        src = _src(tmp_path, "audio.mp3")
        worker = _worker(tmp_path)
        with patch("workers.ai.worker.WORK_DIR", tmp_path), _stt_mock():
            out_path, mime, ext = worker.convert(_make_job(1, src, "mp3", "txt", "stt"))
        assert Path(out_path).exists()
        assert ext == "txt"
        assert mime == "text/plain"

    def test_stt_auto_detect(self, tmp_path):
        src = _src(tmp_path, "audio.wav")
        worker = _worker(tmp_path)
        with patch("workers.ai.worker.WORK_DIR", tmp_path), _stt_mock():
            out_path, mime, ext = worker.convert(_make_job(2, src, "wav", "srt"))
        assert ext == "srt"
        assert mime == "application/x-subrip"

    def test_tts_explicit_subtype(self, tmp_path):
        src = _src(tmp_path, "text.txt", b"hello world")
        worker = _worker(tmp_path)
        with patch("workers.ai.worker.WORK_DIR", tmp_path), _tts_mock():
            out_path, mime, ext = worker.convert(_make_job(3, src, "txt", "mp3", "tts"))
        assert ext == "mp3"
        assert mime == "audio/mpeg"

    def test_tts_auto_detect(self, tmp_path):
        src = _src(tmp_path, "text.md", b"hi")
        worker = _worker(tmp_path)
        with patch("workers.ai.worker.WORK_DIR", tmp_path), _tts_mock():
            out_path, mime, ext = worker.convert(_make_job(4, src, "md", "wav"))
        assert ext == "wav"
        assert mime == "audio/wav"

    def test_output_in_work_dir_with_conv_id(self, tmp_path):
        src = _src(tmp_path, "audio.mp3")
        worker = _worker(tmp_path)
        with patch("workers.ai.worker.WORK_DIR", tmp_path), _stt_mock():
            out_path, _, ext = worker.convert(_make_job(77, src, "mp3", "txt", "stt"))
        name = Path(out_path).name
        assert Path(out_path).parent == tmp_path
        assert name.startswith("out-77-")
        assert name.endswith(f".{ext}")


# ---------------------------------------------------------------------------
# Error / unsupported-format cases
# ---------------------------------------------------------------------------

class TestAiConvertErrors:
    def test_cannot_autodetect_raises(self, tmp_path):
        src = _src(tmp_path, "f.xyz")
        worker = _worker(tmp_path)
        with patch("workers.ai.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="auto-detect"):
                worker.convert(_make_job(5, src, "xyz", "txt"))

    def test_unsupported_stt_output_raises(self, tmp_path):
        src = _src(tmp_path, "audio.mp3")
        worker = _worker(tmp_path)
        with patch("workers.ai.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported STT output"):
                worker.convert(_make_job(6, src, "mp3", "mp4", "stt"))

    def test_unsupported_tts_input_raises(self, tmp_path):
        src = _src(tmp_path, "audio.mp3")
        worker = _worker(tmp_path)
        with patch("workers.ai.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported TTS input"):
                worker.convert(_make_job(7, src, "mp3", "wav", "tts"))

    def test_unknown_subtype_raises(self, tmp_path):
        src = _src(tmp_path, "audio.mp3")
        worker = _worker(tmp_path)
        with patch("workers.ai.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unknown subType"):
                worker.convert(_make_job(8, src, "mp3", "txt", "bogus"))

    def test_missing_input_raises(self, tmp_path):
        worker = _worker(tmp_path)
        with patch("workers.ai.worker.WORK_DIR", tmp_path):
            with pytest.raises(FileNotFoundError):
                worker.convert(_make_job(9, tmp_path / "nope.mp3", "mp3", "txt", "stt"))

    def test_empty_tts_input_raises(self, tmp_path):
        # _text_to_speech validates non-empty text BEFORE dispatching to any
        # engine, so this exercises the real code path with no espeak/ffmpeg.
        src = _src(tmp_path, "empty.txt", b"   \n  ")
        worker = _worker(tmp_path)
        with patch("workers.ai.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="empty"):
                worker.convert(_make_job(10, src, "txt", "mp3", "tts"))


# ---------------------------------------------------------------------------
# Fixture corpus: story.mp3 must stay tiny (committed) — STT engine is mocked,
# the worker only needs the file to exist.
# ---------------------------------------------------------------------------

class TestStoryMp3Fixture:
    def test_story_mp3_exists_and_small(self, example_files):
        story = example_files / "story.mp3"
        assert story.is_file(), "story.mp3 fixture missing"
        size = story.stat().st_size
        assert size <= 50 * 1024, f"story.mp3 must be ≤50KB, got {size} bytes"

    def test_stt_from_story_fixture(self, tmp_path, example_files):
        # Wire the real committed fixture through convert() with STT mocked.
        story = example_files / "story.mp3"
        worker = _worker(tmp_path)
        with patch("workers.ai.worker.WORK_DIR", tmp_path), _stt_mock():
            out_path, mime, ext = worker.convert(_make_job(11, story, "mp3", "txt", "stt"))
        assert Path(out_path).exists()
        assert ext == "txt"
        assert mime == "text/plain"
