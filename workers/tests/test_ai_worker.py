"""Tests for the AI worker (local-only, flag-agnostic).

Covers:
- CAPABILITIES routing key (routing-drift contract: single 'ai' stream)
- Mode derivation: flag-agnostic derive_mode(), incl. error on underivable pair
- ROADMAP.md format matrix validation (STT/TTS rows)
- convert() routing: format pair → mode (STT / TTS / embedding / LLM), no flags read
- LLM text→text: routing + mocked Ollama backend + backend factory selection
- Poll-client flow: download → convert → result (happy path) + failure paths
- PULL_ENABLED gate: false → worker stays idle and does not claim
- Real TTS e2e with espeak-ng (integration marker, skipped if binary absent)

External-provider (OpenAI/Gemini/Claude) and fallback tests were DELETED in
ai-worker-refactor-core — the worker is local-inference only.
"""

from __future__ import annotations

import asyncio
import importlib.util
import os
import shutil
from contextlib import asynccontextmanager
from dataclasses import replace
from pathlib import Path
from unittest.mock import AsyncMock, patch

import httpx
import pytest

from workers.ai.config import load_config
from workers.ai.convert import (
    STT_INPUTS,
    STT_OUTPUTS,
    TTS_INPUTS,
    TTS_OUTPUTS,
    Mode,
    convert,
    derive_mode,
)
from workers.ai.worker import CAPABILITIES, _process_job, run


def _cfg(tmp_path: Path):
    """A load_config() snapshot with work_dir pointed at the test tmp_path."""
    return replace(load_config(), work_dir=tmp_path)


# ---------------------------------------------------------------------------
# CAPABILITIES — routing-drift contract
# ---------------------------------------------------------------------------


def test_capabilities_routing_key():
    assert CAPABILITIES["routing_keys"] == ["ai"]


def test_capabilities_matrix_empty():
    # AI (from→to) pairs live in the PHP registry only as virtual *_stt/*_tts keys
    # (skipped by the drift subset assertion); advertising concrete pairs would fail it.
    assert CAPABILITIES["matrix"] == {}


# ---------------------------------------------------------------------------
# derive_mode — flag-agnostic format derivation
# ---------------------------------------------------------------------------


class TestDeriveMode:
    @pytest.mark.parametrize("src,tgt", [
        ("mp3", "txt"), ("mp3", "srt"), ("mp3", "vtt"),
        ("wav", "txt"), ("ogg", "srt"), ("m4a", "vtt"),
        ("opus", "txt"), ("flac", "srt"),
    ])
    def test_stt_pairs(self, src, tgt):
        assert derive_mode(src, tgt) is Mode.STT

    @pytest.mark.parametrize("src,tgt", [
        ("mp3", "json"), ("wav", "json"), ("flac", "json"),
    ])
    def test_stt_stream_pairs(self, src, tgt):
        assert derive_mode(src, tgt) is Mode.STT_STREAM

    @pytest.mark.parametrize("src,tgt", [
        ("txt", "mp3"), ("txt", "wav"), ("txt", "ogg"),
        ("md", "mp3"), ("md", "wav"), ("md", "ogg"),
    ])
    def test_tts_pairs(self, src, tgt):
        assert derive_mode(src, tgt) is Mode.TTS

    @pytest.mark.parametrize("src,tgt", [
        ("txt", "json"), ("md", "json"),
    ])
    def test_embedding_pairs(self, src, tgt):
        assert derive_mode(src, tgt) is Mode.EMBEDDING

    @pytest.mark.parametrize("src,tgt", [
        ("txt", "txt"), ("txt", "md"), ("md", "txt"), ("md", "md"),
    ])
    def test_llm_pairs(self, src, tgt):
        assert derive_mode(src, tgt) is Mode.LLM

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
            derive_mode(src, tgt)

    def test_roadmap_stt_inputs_covered(self):
        for fmt in ("mp3", "wav", "ogg", "m4a", "opus"):
            assert fmt in STT_INPUTS, f"ROADMAP STT input {fmt!r} not in STT_INPUTS"

    def test_roadmap_stt_outputs_covered(self):
        for fmt in ("txt", "srt", "vtt"):
            assert fmt in STT_OUTPUTS, f"ROADMAP STT output {fmt!r} not in STT_OUTPUTS"

    def test_roadmap_tts_inputs_covered(self):
        for fmt in ("txt", "md"):
            assert fmt in TTS_INPUTS, f"ROADMAP TTS input {fmt!r} not in TTS_INPUTS"

    def test_roadmap_tts_outputs_covered(self):
        for fmt in ("mp3", "wav", "ogg"):
            assert fmt in TTS_OUTPUTS, f"ROADMAP TTS output {fmt!r} not in TTS_OUTPUTS"


# ---------------------------------------------------------------------------
# convert() routing — derives mode from format pair, no flags read
# ---------------------------------------------------------------------------


async def test_convert_mp3_to_txt_calls_stt(tmp_path, example_files):
    """convert() derives STT from mp3→txt and uses SpeechToTextProvider.transcribe."""
    story = example_files / "story.mp3"

    async def fake_transcribe(self, src, fmt):
        return "transcript"

    with patch("workers.ai.providers.stt.SpeechToTextProvider.transcribe", fake_transcribe):
        out_path, mime, ext = await convert({
            "_localInput": str(story),
            "conversionId": 99,
            "sourceFormat": "mp3",
            "targetFormat": "txt",
        }, _cfg(tmp_path))

    assert Path(out_path).exists()
    assert ext == "txt"
    assert mime == "text/plain"
    assert Path(out_path).name.startswith("out-99-")
    assert Path(out_path).read_text() == "transcript"


async def test_convert_txt_to_mp3_calls_tts(tmp_path):
    """convert() derives TTS from txt→mp3 and uses TextToSpeechProvider.synthesize."""
    src = tmp_path / "text.txt"
    src.write_text("hello world", encoding="utf-8")

    async def fake_synth(self, text, fmt, out):
        out.write_bytes(b"audio")

    with patch("workers.ai.providers.tts.TextToSpeechProvider.synthesize", fake_synth):
        out_path, mime, ext = await convert({
            "_localInput": str(src),
            "conversionId": 7,
            "sourceFormat": "txt",
            "targetFormat": "mp3",
        }, _cfg(tmp_path))

    assert Path(out_path).exists()
    assert ext == "mp3"
    assert mime == "audio/mpeg"


async def test_convert_txt_to_json_calls_embedding(tmp_path):
    """convert() derives embedding from txt→json and calls generate_embedding."""
    src = tmp_path / "text.txt"
    src.write_text("hello world", encoding="utf-8")

    def fake_gen(src, out_path, model_name, device):
        out_path.write_text('{"embedding": [0.1]}', encoding="utf-8")

    with patch("workers.ai.providers.embedding.generate_embedding", fake_gen):
        out_path, mime, ext = await convert({
            "_localInput": str(src),
            "conversionId": 5,
            "sourceFormat": "txt",
            "targetFormat": "json",
        }, _cfg(tmp_path))

    assert Path(out_path).exists()
    assert ext == "json"
    assert mime == "application/json"


async def test_convert_txt_to_txt_calls_llm(tmp_path):
    """convert() derives LLM from txt→txt and dispatches to the llm provider."""
    src = tmp_path / "text.txt"
    src.write_text("summarize this", encoding="utf-8")

    class FakeProvider:
        async def generate(self, prompt):
            return f"RESULT:{prompt}"

    with patch("workers.ai.providers.llm.make_llm_provider", return_value=FakeProvider()):
        out_path, mime, ext = await convert({
            "_localInput": str(src),
            "conversionId": 21,
            "sourceFormat": "txt",
            "targetFormat": "txt",
        }, _cfg(tmp_path))

    assert Path(out_path).exists()
    assert ext == "txt"
    assert mime == "text/plain"
    assert Path(out_path).read_text() == "RESULT:summarize this"


async def test_convert_md_to_md_calls_llm(tmp_path):
    """md→md is part of the text family → LLM, mime is markdown."""
    src = tmp_path / "doc.md"
    src.write_text("# heading", encoding="utf-8")

    class FakeProvider:
        async def generate(self, prompt):
            return "# rewritten"

    with patch("workers.ai.providers.llm.make_llm_provider", return_value=FakeProvider()):
        out_path, mime, ext = await convert({
            "_localInput": str(src),
            "conversionId": 22,
            "sourceFormat": "md",
            "targetFormat": "md",
        }, _cfg(tmp_path))

    assert ext == "md"
    assert mime == "text/markdown"
    assert Path(out_path).read_text() == "# rewritten"


async def test_convert_llm_ignores_flags(tmp_path):
    """A taskType/subType flag must NOT change the txt→txt LLM derivation."""
    src = tmp_path / "text.txt"
    src.write_text("hello", encoding="utf-8")

    class FakeProvider:
        async def generate(self, prompt):
            return "out"

    with patch("workers.ai.providers.llm.make_llm_provider", return_value=FakeProvider()):
        _, _, ext = await convert({
            "_localInput": str(src),
            "conversionId": 23,
            "sourceFormat": "txt",
            "targetFormat": "txt",
            "taskType": "embedding",   # bogus flag — must be ignored
            "subType": "ocr",
            "ocr": True,
        }, _cfg(tmp_path))

    assert ext == "txt"  # LLM derived from txt→txt, flags ignored


async def test_convert_empty_llm_output_raises(tmp_path):
    """A whitespace-only backend reply must not be written silently."""
    src = tmp_path / "text.txt"
    src.write_text("summarize this", encoding="utf-8")

    class FakeProvider:
        async def generate(self, prompt):
            return "   \n  "

    with patch("workers.ai.providers.llm.make_llm_provider", return_value=FakeProvider()):
        with pytest.raises(ValueError, match="empty output"):
            await convert({
                "_localInput": str(src),
                "conversionId": 25,
                "sourceFormat": "txt",
                "targetFormat": "txt",
            }, _cfg(tmp_path))


async def test_convert_empty_llm_input_raises(tmp_path):
    src = tmp_path / "empty.txt"
    src.write_bytes(b"   \n  ")
    with pytest.raises(ValueError, match="empty"):
        await convert({
            "_localInput": str(src),
            "conversionId": 24,
            "sourceFormat": "txt",
            "targetFormat": "txt",
        }, _cfg(tmp_path))


# ---------------------------------------------------------------------------
# LLM providers — mocked backends (no real Ollama server, no llama_cpp import)
# ---------------------------------------------------------------------------


async def test_ollama_provider_generate(monkeypatch):
    """OllamaProvider POSTs the expected payload and returns the stripped response."""
    from workers.ai.providers.llm import OllamaProvider

    captured: dict = {}

    class FakeResp:
        def raise_for_status(self):
            pass

        def json(self):
            return {"response": "  summarized text  "}

    class FakeClient:
        def __init__(self, *a, **kw):
            captured["client_kwargs"] = kw

        async def __aenter__(self):
            return self

        async def __aexit__(self, *a):
            return False

        async def post(self, url, json):
            captured["url"] = url
            captured["payload"] = json
            return FakeResp()

    monkeypatch.setattr("workers.ai.providers.llm.httpx.AsyncClient", FakeClient)

    provider = OllamaProvider(
        url="http://ollama:11434/",
        model="qwen2.5",
        max_tokens=128,
        temperature=0.3,
        system_prompt="be brief",
    )
    out = await provider.generate("translate this")

    assert out == "summarized text"  # stripped
    assert captured["url"] == "http://ollama:11434/api/generate"
    payload = captured["payload"]
    assert payload["model"] == "qwen2.5"
    assert payload["prompt"] == "translate this"
    assert payload["stream"] is False
    assert payload["system"] == "be brief"
    assert payload["options"]["num_predict"] == 128
    assert payload["options"]["temperature"] == 0.3
    assert captured["client_kwargs"].get("timeout") is not None


async def test_ollama_provider_omits_empty_system_prompt(monkeypatch):
    from workers.ai.providers.llm import OllamaProvider

    captured: dict = {}

    class FakeResp:
        def raise_for_status(self):
            pass

        def json(self):
            return {"response": "ok"}

    class FakeClient:
        def __init__(self, *a, **kw):
            pass

        async def __aenter__(self):
            return self

        async def __aexit__(self, *a):
            return False

        async def post(self, url, json):
            captured["payload"] = json
            return FakeResp()

    monkeypatch.setattr("workers.ai.providers.llm.httpx.AsyncClient", FakeClient)

    provider = OllamaProvider(url="http://x", model="m", max_tokens=10, temperature=0.0)
    await provider.generate("hi")
    assert "system" not in captured["payload"]


def test_make_llm_provider_selects_backend(tmp_path):
    from workers.ai.providers.llm import (
        LlamaCppProvider,
        OllamaProvider,
        make_llm_provider,
    )

    ollama_cfg = replace(load_config(), llm_backend="ollama")
    assert isinstance(make_llm_provider(ollama_cfg), OllamaProvider)

    llamacpp_cfg = replace(
        load_config(), llm_backend="llamacpp", llm_model_path="/models/m.gguf"
    )
    assert isinstance(make_llm_provider(llamacpp_cfg), LlamaCppProvider)


def test_make_llm_provider_unknown_backend_raises():
    from workers.ai.providers.llm import make_llm_provider

    cfg = replace(load_config(), llm_backend="bogus")
    with pytest.raises(ValueError, match="unknown LLM_BACKEND"):
        make_llm_provider(cfg)


async def test_convert_invalid_pair_raises(tmp_path):
    src = tmp_path / "file.xyz"
    src.write_bytes(b"data")
    with pytest.raises(ValueError, match="cannot derive"):
        await convert({
            "_localInput": str(src),
            "conversionId": 1,
            "sourceFormat": "xyz",
            "targetFormat": "txt",
        }, _cfg(tmp_path))


async def test_convert_missing_input_raises(tmp_path):
    with pytest.raises(FileNotFoundError):
        await convert({
            "_localInput": str(tmp_path / "nope.mp3"),
            "conversionId": 2,
            "sourceFormat": "mp3",
            "targetFormat": "txt",
        }, _cfg(tmp_path))


async def test_convert_empty_tts_input_raises(tmp_path):
    src = tmp_path / "empty.txt"
    src.write_bytes(b"   \n  ")
    with pytest.raises(ValueError, match="empty"):
        await convert({
            "_localInput": str(src),
            "conversionId": 3,
            "sourceFormat": "txt",
            "targetFormat": "mp3",
        }, _cfg(tmp_path))


async def test_convert_ignores_flags(tmp_path):
    """A taskType/subType flag in the job must NOT change the derived mode."""
    src = tmp_path / "text.txt"
    src.write_text("hello", encoding="utf-8")

    async def fake_synth(self, text, fmt, out):
        out.write_bytes(b"audio")

    with patch("workers.ai.providers.tts.TextToSpeechProvider.synthesize", fake_synth):
        _, _, ext = await convert({
            "_localInput": str(src),
            "conversionId": 8,
            "sourceFormat": "txt",
            "targetFormat": "mp3",
            "taskType": "embedding",   # bogus flag — must be ignored
            "subType": "ocr",
        }, _cfg(tmp_path))

    assert ext == "mp3"  # TTS derived from txt→mp3, flag ignored


# ---------------------------------------------------------------------------
# _process_job — poll-client flow
# ---------------------------------------------------------------------------

JOB_META = {
    "jobId": "1234-5678",
    "conversionId": 42,
    "sourceFormat": "mp3",
    "targetFormat": "txt",
}

_DUMMY_REQUEST = httpx.Request("GET", "http://test-server/")


def _ok_resp(content: bytes = b"") -> httpx.Response:
    return httpx.Response(200, content=content, request=_DUMMY_REQUEST)


async def test_process_job_happy_path(tmp_path):
    """input streamed → convert mocked → result POSTed."""
    input_content = b"fake-audio-bytes"
    stream_calls: list[str] = []

    @asynccontextmanager
    async def mock_stream(method, url, **kw):
        stream_calls.append(url)
        yield httpx.Response(200, content=input_content, request=_DUMMY_REQUEST)

    mock_client = AsyncMock()
    mock_client.stream = mock_stream
    mock_client.post = AsyncMock(return_value=_ok_resp())

    async def fake_convert(job, cfg):
        out = tmp_path / "out-42-abc.txt"
        out.write_text("transcript", encoding="utf-8")
        return str(out), "text/plain", "txt"

    with patch("workers.ai.worker.convert", side_effect=fake_convert):
        await _process_job(mock_client, _cfg(tmp_path), JOB_META)

    assert len(stream_calls) == 1
    assert "jobs/1234-5678/input" in stream_calls[0]

    assert mock_client.post.call_count == 1
    call_args = mock_client.post.call_args
    assert "/api/v1/worker/jobs/1234-5678/result" in call_args.args[0]
    assert "file" in call_args.kwargs["files"]


async def test_process_job_convert_fails_calls_fail_endpoint(tmp_path):
    @asynccontextmanager
    async def mock_stream(method, url, **kw):
        yield httpx.Response(200, content=b"fake-audio", request=_DUMMY_REQUEST)

    mock_client = AsyncMock()
    mock_client.stream = mock_stream
    mock_client.post = AsyncMock(return_value=_ok_resp())

    async def fail_convert(job, cfg):
        raise RuntimeError("whisper exploded")

    with patch("workers.ai.worker.convert", side_effect=fail_convert):
        await _process_job(mock_client, _cfg(tmp_path), JOB_META)

    mock_client.post.assert_awaited_once()
    args = mock_client.post.call_args
    assert "/fail" in args.args[0]
    assert "whisper exploded" in args.kwargs["json"]["error"]


async def test_process_job_input_download_fails_calls_fail_endpoint(tmp_path):
    @asynccontextmanager
    async def fail_stream(method, url, **kw):
        raise httpx.RequestError("connection refused", request=httpx.Request("GET", url))
        yield  # unreachable — required to make this an async generator

    mock_client = AsyncMock()
    mock_client.stream = fail_stream
    mock_client.post = AsyncMock(return_value=_ok_resp())

    await _process_job(mock_client, _cfg(tmp_path), JOB_META)

    mock_client.post.assert_awaited_once()
    assert "/fail" in mock_client.post.call_args.args[0]


async def test_process_job_result_upload_fails_calls_fail_and_cleans_output(tmp_path):
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

    async def fake_convert(job, cfg):
        output_file.write_text("transcript", encoding="utf-8")
        return str(output_file), "text/plain", "txt"

    with patch("workers.ai.worker.convert", side_effect=fake_convert):
        await _process_job(mock_client, _cfg(tmp_path), JOB_META)

    assert mock_client.post.call_count == 2
    assert "/result" in mock_client.post.call_args_list[0].args[0]
    assert "/fail" in mock_client.post.call_args_list[1].args[0]
    assert not output_file.exists(), "output temp file should be deleted after upload failure"


# ---------------------------------------------------------------------------
# PULL_ENABLED gate
# ---------------------------------------------------------------------------


def test_run_idle_when_pull_disabled(tmp_path):
    """PULL_ENABLED=false → run() returns without starting the poll loop / claiming."""
    cfg = replace(load_config(), pull_enabled=False, work_dir=tmp_path)
    with patch("workers.ai.worker._poll_loop") as poll, \
         patch("workers.ai.worker.asyncio.run") as arun:
        run(cfg)
    poll.assert_not_called()
    arun.assert_not_called()


def test_run_claims_when_pull_enabled(tmp_path):
    """PULL_ENABLED=true → run() enters the poll loop via asyncio.run."""
    cfg = replace(
        load_config(),
        pull_enabled=True,
        worker_api_token="tok",
        work_dir=tmp_path,
    )
    from unittest.mock import MagicMock

    sentinel = object()
    poll = MagicMock(return_value=sentinel)
    with patch("workers.ai.worker._poll_loop", poll), \
         patch("workers.ai.worker.asyncio.run") as arun:
        run(cfg)
    poll.assert_called_once()
    arun.assert_called_once_with(sentinel)


def test_run_pull_enabled_without_token_raises(tmp_path):
    """PULL_ENABLED=true but no token → config validation raises before claiming."""
    cfg = replace(
        load_config(),
        pull_enabled=True,
        worker_api_token="",
        work_dir=tmp_path,
    )
    with patch("workers.ai.worker.asyncio.run") as arun:
        with pytest.raises(ValueError, match="WORKER_API_TOKEN"):
            run(cfg)
    arun.assert_not_called()


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

    async def fake_transcribe(self, src, fmt):
        return "transcript"

    with patch("workers.ai.providers.stt.SpeechToTextProvider.transcribe", fake_transcribe):
        out_path, mime, ext = await convert({
            "_localInput": str(story),
            "conversionId": 11,
            "sourceFormat": "mp3",
            "targetFormat": "txt",
        }, _cfg(tmp_path))

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

    from workers.ai.providers.tts import espeak

    asyncio.run(espeak("Hello world", "wav", out))

    assert out.is_file(), "espeak-ng produced no output file"
    assert out.stat().st_size > 0, "espeak-ng output is empty"
    assert out.read_bytes()[:4] == b"RIFF", "output is not a valid WAV file"


# ---------------------------------------------------------------------------
# Real LLM e2e — llama.cpp (integration, skipped without binary + GGUF model)
# ---------------------------------------------------------------------------


@pytest.mark.integration
@pytest.mark.skipif(
    importlib.util.find_spec("llama_cpp") is None or not os.getenv("LLM_MODEL_PATH"),
    reason="llama_cpp not installed or LLM_MODEL_PATH unset — skipping real LLM e2e",
)
def test_llamacpp_real_e2e():
    from workers.ai.providers.llm import LlamaCppProvider

    provider = LlamaCppProvider(
        model_path=os.environ["LLM_MODEL_PATH"],
        max_tokens=16,
        temperature=0.0,
    )
    out = asyncio.run(provider.generate("Say hello in one word."))
    assert isinstance(out, str) and out.strip()
