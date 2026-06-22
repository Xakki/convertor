"""Tests for AI worker HTTP pull-client.

Covers:
- CAPABILITIES key (routing-drift-test compatibility)
- Format derivation: flag-agnostic _derive_mode(), including error on underivable pair
- ROADMAP.md format matrix validation (STT rows 172-173)
- Poll-client flow: claim → download → convert → result (happy path)
- Poll-client flow: input download fails → /fail called
- Poll-client flow: convert() raises → /fail called
- convert() routing: mp3→txt goes to STT path; txt→mp3 goes to TTS path
- Provider selection + fallback (mocked SDKs/engines)
- Real TTS e2e with espeak-ng (integration marker, skipped if binary absent)
"""

from __future__ import annotations

import asyncio
import shutil
from contextlib import asynccontextmanager
from pathlib import Path
from unittest.mock import AsyncMock, patch

import httpx
import pytest

from workers.ai.worker import (
    CAPABILITIES,
    _STT_INPUTS,
    _STT_OUTPUTS,
    _TTS_INPUTS,
    _TTS_OUTPUTS,
    _derive_mode,
    _process_job,
    _speech_to_text,
    _text_to_speech,
    convert,
)


# ---------------------------------------------------------------------------
# CAPABILITIES — routing-drift-test hook
# ---------------------------------------------------------------------------


def test_capabilities_routing_key():
    assert CAPABILITIES["routing_keys"] == ["ai"]


# ---------------------------------------------------------------------------
# _derive_mode — flag-agnostic format derivation
# ---------------------------------------------------------------------------


class TestDeriveMode:
    @pytest.mark.parametrize("src,tgt", [
        ("mp3", "txt"), ("mp3", "srt"), ("mp3", "vtt"),
        ("wav", "txt"), ("ogg", "srt"), ("m4a", "vtt"),
        ("opus", "txt"), ("flac", "srt"),
    ])
    def test_stt_pairs(self, src, tgt):
        assert _derive_mode(src, tgt) == "stt"

    @pytest.mark.parametrize("src,tgt", [
        ("txt", "mp3"), ("txt", "wav"), ("txt", "ogg"),
        ("md", "mp3"), ("md", "wav"), ("md", "ogg"),
    ])
    def test_tts_pairs(self, src, tgt):
        assert _derive_mode(src, tgt) == "tts"

    @pytest.mark.parametrize("src,tgt", [
        ("mp3", "mp4"),    # audio → video: ffmpeg, not ai
        ("xyz", "txt"),    # unknown input
        ("txt", "pdf"),    # text → pdf: libreoffice, not ai
        ("pdf", "txt"),    # ocr: image worker, not ai
        ("mp3", "mp3"),    # same format
        ("wav", "ogg"),    # audio → audio: ffmpeg, not ai
    ])
    def test_invalid_pairs_raise(self, src, tgt):
        with pytest.raises(ValueError, match="cannot derive"):
            _derive_mode(src, tgt)

    def test_roadmap_stt_inputs_covered(self):
        """ROADMAP.md ~line 172: mp3, wav, ogg, m4a, opus must be in _STT_INPUTS."""
        for fmt in ("mp3", "wav", "ogg", "m4a", "opus"):
            assert fmt in _STT_INPUTS, f"ROADMAP STT input {fmt!r} not in _STT_INPUTS"

    def test_roadmap_stt_outputs_covered(self):
        """ROADMAP.md ~line 172: txt, srt, vtt must be in _STT_OUTPUTS."""
        for fmt in ("txt", "srt", "vtt"):
            assert fmt in _STT_OUTPUTS, f"ROADMAP STT output {fmt!r} not in _STT_OUTPUTS"

    def test_roadmap_tts_inputs_covered(self):
        """ROADMAP.md ~line 173: txt, md must be in _TTS_INPUTS."""
        for fmt in ("txt", "md"):
            assert fmt in _TTS_INPUTS, f"ROADMAP TTS input {fmt!r} not in _TTS_INPUTS"

    def test_roadmap_tts_outputs_covered(self):
        """ROADMAP.md ~line 173: mp3, wav, ogg must be in _TTS_OUTPUTS."""
        for fmt in ("mp3", "wav", "ogg"):
            assert fmt in _TTS_OUTPUTS, f"ROADMAP TTS output {fmt!r} not in _TTS_OUTPUTS"


# ---------------------------------------------------------------------------
# convert() routing — derives mode from format pair, not subType
# ---------------------------------------------------------------------------


async def test_convert_mp3_to_txt_calls_stt(tmp_path, example_files):
    """convert() derives STT from mp3→txt and calls _speech_to_text."""
    story = example_files / "story.mp3"

    async def fake_stt(src, fmt, out):
        out.write_text("transcript", encoding="utf-8")

    with patch("workers.ai.worker._speech_to_text", side_effect=fake_stt), \
         patch("workers.ai.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = await convert({
            "_localInput": str(story),
            "conversionId": 99,
            "sourceFormat": "mp3",
            "targetFormat": "txt",
        })

    assert Path(out_path).exists()
    assert ext == "txt"
    assert mime == "text/plain"
    assert Path(out_path).name.startswith("out-99-")


async def test_convert_txt_to_mp3_calls_tts(tmp_path):
    """convert() derives TTS from txt→mp3 and calls _text_to_speech."""
    src = tmp_path / "text.txt"
    src.write_text("hello world", encoding="utf-8")

    async def fake_tts(src, fmt, out):
        out.write_bytes(b"audio")

    with patch("workers.ai.worker._text_to_speech", side_effect=fake_tts), \
         patch("workers.ai.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = await convert({
            "_localInput": str(src),
            "conversionId": 7,
            "sourceFormat": "txt",
            "targetFormat": "mp3",
        })

    assert Path(out_path).exists()
    assert ext == "mp3"
    assert mime == "audio/mpeg"


async def test_convert_invalid_pair_raises(tmp_path):
    """convert() raises ValueError on an underivable format pair."""
    src = tmp_path / "file.xyz"
    src.write_bytes(b"data")

    with patch("workers.ai.worker.WORK_DIR", tmp_path):
        with pytest.raises(ValueError, match="cannot derive"):
            await convert({
                "_localInput": str(src),
                "conversionId": 1,
                "sourceFormat": "xyz",
                "targetFormat": "txt",
            })


async def test_convert_missing_input_raises(tmp_path):
    """convert() raises FileNotFoundError when input file is absent."""
    with patch("workers.ai.worker.WORK_DIR", tmp_path):
        with pytest.raises(FileNotFoundError):
            await convert({
                "_localInput": str(tmp_path / "nope.mp3"),
                "conversionId": 2,
                "sourceFormat": "mp3",
                "targetFormat": "txt",
            })


async def test_convert_empty_tts_input_raises(tmp_path):
    """convert() raises ValueError when the TTS source text is empty."""
    src = tmp_path / "empty.txt"
    src.write_bytes(b"   \n  ")

    with patch("workers.ai.worker.WORK_DIR", tmp_path):
        with pytest.raises(ValueError, match="empty"):
            await convert({
                "_localInput": str(src),
                "conversionId": 3,
                "sourceFormat": "txt",
                "targetFormat": "mp3",
            })


# ---------------------------------------------------------------------------
# Helpers for poll-client tests
# ---------------------------------------------------------------------------

JOB_META = {
    "jobId": "1234-5678",
    "conversionId": 42,
    "sourceFormat": "mp3",
    "targetFormat": "txt",
}

_DUMMY_REQUEST = httpx.Request("GET", "http://test-server/")


def _ok_resp(content: bytes = b"") -> httpx.Response:
    """200 OK response with request set (required for raise_for_status)."""
    return httpx.Response(200, content=content, request=_DUMMY_REQUEST)


# ---------------------------------------------------------------------------
# _process_job — happy path: claim → download → convert → result uploaded
# ---------------------------------------------------------------------------


async def test_process_job_happy_path(tmp_path):
    """claim returns job → input streamed → convert mocked → result POSTed."""
    input_content = b"fake-audio-bytes"
    stream_calls: list[str] = []

    @asynccontextmanager
    async def mock_stream(method, url, **kw):
        stream_calls.append(url)
        yield httpx.Response(200, content=input_content, request=_DUMMY_REQUEST)

    mock_client = AsyncMock()
    mock_client.stream = mock_stream
    mock_client.post = AsyncMock(return_value=_ok_resp())

    async def fake_convert(job):
        out = tmp_path / "out-42-abc.txt"
        out.write_text("transcript", encoding="utf-8")
        return str(out), "text/plain", "txt"

    with patch("workers.ai.worker.convert", side_effect=fake_convert), \
         patch("workers.ai.worker.WORK_DIR", tmp_path):
        await _process_job(mock_client, JOB_META)

    # Input was fetched via streaming GET
    assert len(stream_calls) == 1
    assert "jobs/1234-5678/input" in stream_calls[0]

    # Result was uploaded via POST with a file field
    assert mock_client.post.call_count == 1
    call_args = mock_client.post.call_args
    assert "/api/v1/worker/jobs/1234-5678/result" in call_args.args[0]
    assert "file" in call_args.kwargs["files"]


# ---------------------------------------------------------------------------
# _process_job — convert() raises → POST /fail
# ---------------------------------------------------------------------------


async def test_process_job_convert_fails_calls_fail_endpoint(tmp_path):
    """When convert() raises, /fail is called with the error string."""
    @asynccontextmanager
    async def mock_stream(method, url, **kw):
        yield httpx.Response(200, content=b"fake-audio", request=_DUMMY_REQUEST)

    mock_client = AsyncMock()
    mock_client.stream = mock_stream
    mock_client.post = AsyncMock(return_value=_ok_resp())

    async def fail_convert(job):
        raise RuntimeError("whisper exploded")

    with patch("workers.ai.worker.convert", side_effect=fail_convert), \
         patch("workers.ai.worker.WORK_DIR", tmp_path):
        await _process_job(mock_client, JOB_META)

    # Only one POST call — to /fail
    mock_client.post.assert_awaited_once()
    args = mock_client.post.call_args
    assert "/fail" in args.args[0]
    assert "whisper exploded" in args.kwargs["json"]["error"]


# ---------------------------------------------------------------------------
# _process_job — input download fails → POST /fail
# ---------------------------------------------------------------------------


async def test_process_job_input_download_fails_calls_fail_endpoint(tmp_path):
    """When input stream raises (network error), /fail is called."""
    @asynccontextmanager
    async def fail_stream(method, url, **kw):
        raise httpx.RequestError("connection refused", request=httpx.Request("GET", url))
        yield  # unreachable — required to make this an async generator

    mock_client = AsyncMock()
    mock_client.stream = fail_stream
    mock_client.post = AsyncMock(return_value=_ok_resp())

    with patch("workers.ai.worker.WORK_DIR", tmp_path):
        await _process_job(mock_client, JOB_META)

    mock_client.post.assert_awaited_once()
    assert "/fail" in mock_client.post.call_args.args[0]


# ---------------------------------------------------------------------------
# _process_job — result upload fails → POST /fail + output file cleaned up
# ---------------------------------------------------------------------------


async def test_process_job_result_upload_fails_calls_fail_and_cleans_output(tmp_path):
    """When POST /result raises, /fail is called and the output temp file is removed."""
    input_content = b"fake-audio-bytes"

    @asynccontextmanager
    async def mock_stream(method, url, **kw):
        yield httpx.Response(200, content=input_content, request=_DUMMY_REQUEST)

    mock_client = AsyncMock()
    mock_client.stream = mock_stream
    # First call (POST /result) raises; second call (POST /fail) succeeds.
    mock_client.post = AsyncMock(side_effect=[
        RuntimeError("upload connection reset"),
        _ok_resp(),
    ])

    output_file = tmp_path / "out-42-xyz.txt"

    async def fake_convert(job):
        output_file.write_text("transcript", encoding="utf-8")
        return str(output_file), "text/plain", "txt"

    with patch("workers.ai.worker.convert", side_effect=fake_convert), \
         patch("workers.ai.worker.WORK_DIR", tmp_path):
        await _process_job(mock_client, JOB_META)

    # Both /result and /fail were called
    assert mock_client.post.call_count == 2
    assert "/result" in mock_client.post.call_args_list[0].args[0]
    assert "/fail" in mock_client.post.call_args_list[1].args[0]

    # Output temp file was cleaned up
    assert not output_file.exists(), "output temp file should be deleted after upload failure"


# ---------------------------------------------------------------------------
# Provider selection + fallback
# ---------------------------------------------------------------------------


async def test_stt_falls_back_to_local_on_provider_error(tmp_path):
    """When non-local STT provider raises, _stt_local is called as fallback."""
    src = tmp_path / "audio.mp3"
    src.write_bytes(b"audio")
    out = tmp_path / "out.txt"

    async def fail_openai(src, fmt):
        raise RuntimeError("openai down")

    async def local_ok(src, fmt):
        return "fallback transcript"

    with patch("workers.ai.worker.AI_STT_PROVIDER", "openai"), \
         patch("workers.ai.worker._stt_openai", side_effect=fail_openai), \
         patch("workers.ai.worker._stt_local", side_effect=local_ok):
        await _speech_to_text(src, "txt", out)

    assert out.read_text() == "fallback transcript"


async def test_stt_local_provider_does_not_fallback(tmp_path):
    """When the local STT provider itself fails, the error is re-raised."""
    src = tmp_path / "audio.mp3"
    src.write_bytes(b"audio")
    out = tmp_path / "out.txt"

    async def fail_local(src, fmt):
        raise RuntimeError("local whisper failed")

    with patch("workers.ai.worker.AI_STT_PROVIDER", "local"), \
         patch("workers.ai.worker._stt_local", side_effect=fail_local):
        with pytest.raises(RuntimeError, match="local whisper failed"):
            await _speech_to_text(src, "txt", out)


async def test_tts_falls_back_to_local_on_provider_error(tmp_path):
    """When non-local TTS provider raises, espeak fallback is used."""
    src = tmp_path / "text.txt"
    src.write_text("hello", encoding="utf-8")
    out = tmp_path / "out.mp3"

    async def fail_openai(text, fmt, out):
        raise RuntimeError("openai tts down")

    async def fake_espeak(text, fmt, out):
        out.write_bytes(b"audio")

    with patch("workers.ai.worker.AI_TTS_PROVIDER", "openai"), \
         patch("workers.ai.worker._tts_openai", side_effect=fail_openai), \
         patch("workers.ai.worker._tts_espeak", side_effect=fake_espeak):
        await _text_to_speech(src, "mp3", out)

    assert out.read_bytes() == b"audio"


# ---------------------------------------------------------------------------
# Story fixture corpus
# ---------------------------------------------------------------------------


def test_story_mp3_fixture_exists_and_small(example_files):
    story = example_files / "story.mp3"
    assert story.is_file(), "story.mp3 fixture missing"
    size = story.stat().st_size
    assert size <= 50 * 1024, f"story.mp3 must be ≤50 KB, got {size}"


async def test_stt_from_story_fixture(tmp_path, example_files):
    """Real committed fixture through convert() with STT mocked."""
    story = example_files / "story.mp3"

    async def fake_stt(src, fmt, out):
        out.write_text("transcript", encoding="utf-8")

    with patch("workers.ai.worker._speech_to_text", side_effect=fake_stt), \
         patch("workers.ai.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = await convert({
            "_localInput": str(story),
            "conversionId": 11,
            "sourceFormat": "mp3",
            "targetFormat": "txt",
        })

    assert Path(out_path).exists()
    assert ext == "txt"
    assert mime == "text/plain"


# ---------------------------------------------------------------------------
# Real TTS e2e — espeak-ng (integration, skipped if binary absent)
# ---------------------------------------------------------------------------


@pytest.mark.integration
@pytest.mark.skipif(
    not shutil.which("espeak-ng"),
    reason="espeak-ng not installed — skipping real TTS e2e",
)
def test_tts_espeak_real_e2e_wav(tmp_path):
    """espeak-ng produces a non-empty WAV file without ffmpeg (wav = direct copy)."""
    out = tmp_path / "speech.wav"

    from workers.ai.worker import _tts_espeak

    asyncio.run(_tts_espeak("Hello world", "wav", out))

    assert out.is_file(), "espeak-ng produced no output file"
    assert out.stat().st_size > 0, "espeak-ng output is empty"
    # WAV files start with RIFF header
    assert out.read_bytes()[:4] == b"RIFF", "output is not a valid WAV file"
