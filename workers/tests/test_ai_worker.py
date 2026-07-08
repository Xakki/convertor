"""Tests for the AI worker (local-only, flag-agnostic, WS transport).

Covers:
- CAPABILITIES routing key (routing-drift contract: single 'ai' stream)
- Mode derivation: flag-agnostic derive_mode(), incl. error on underivable pair
- ROADMAP.md format matrix validation (STT/TTS rows)
- convert() routing: format pair → mode (STT / TTS / embedding / LLM), no flags read
- LLM text→text: routing + mocked Ollama backend + backend factory selection
- handle_job seam: direct unit tests (happy/fail/permanent/progress)
- WS transport: FakeGateway end-to-end (connect → ready{workerType:"ai"} → job →
  convert mocked → result delivered)
- Real TTS e2e with espeak-ng (integration marker, skipped if binary absent)
"""

from __future__ import annotations

import asyncio
import importlib.util
import json
import os
import shutil
import signal as _signal
from contextlib import suppress
from dataclasses import replace
from pathlib import Path
from unittest.mock import patch

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
from workers.ai.worker import CAPABILITIES, build_handle_job
from workers.common.ws_client import ProgressReporter, WsClient, WsClientConfig


def _cfg(tmp_path: Path):
    """A load_config() snapshot with work_dir pointed at the test tmp_path."""
    return replace(load_config(), work_dir=tmp_path)


def _ws_cfg(port: int, tmp_path: Path, token: str = "tok") -> WsClientConfig:
    return WsClientConfig(
        worker_id="test-ai-worker",
        worker_type="ai",
        gateway_ws_url=f"ws://127.0.0.1:{port}",
        api_base_url="http://127.0.0.1:9999",  # overridden by FakeSymfony transport
        worker_api_token=token,
        version="0.1",
        work_dir=tmp_path,
        ws_ping_interval_s=999.0,    # keep pings silent in tests
        ws_reconnect_backoff_base_s=0.05,
        ws_reconnect_backoff_max_s=0.1,
    )


async def _wait_for(pred, timeout: float = 3.0, interval: float = 0.02) -> None:
    loop = asyncio.get_running_loop()
    deadline = loop.time() + timeout
    while loop.time() < deadline:
        if pred():
            return
        await asyncio.sleep(interval)
    raise TimeoutError(f"condition not met within {timeout}s")


class FakeGateway:
    """Minimal WS gateway: accepts connection, records frames, can push jobs."""

    def __init__(self, input_bytes: bytes = b"fake-audio") -> None:
        self.received: list[dict] = []
        self.input_bytes = input_bytes
        self._ws = None

    async def handler(self, ws) -> None:
        self._ws = ws
        try:
            async for raw in ws:
                try:
                    frame = json.loads(raw)
                except Exception:
                    continue
                self.received.append(frame)
        except Exception:
            pass

    def frames_of_type(self, ftype: str) -> list[dict]:
        return [f for f in self.received if f.get("type") == ftype]

    async def send(self, frame: dict) -> None:
        if self._ws is not None:
            await self._ws.send(json.dumps(frame))

    def make_http_transport(self) -> httpx.MockTransport:
        """Симулирует /jobs/{id}/input и /jobs/{id}/result на стороне Symfony."""
        gw = self

        def handler(request: httpx.Request) -> httpx.Response:
            path = request.url.path
            if "/input" in path:
                return httpx.Response(200, content=gw.input_bytes)
            if "/result" in path:
                return httpx.Response(200, json={"ok": True})
            return httpx.Response(404)

        return httpx.MockTransport(handler)


# ---------------------------------------------------------------------------
# CAPABILITIES — routing-drift contract
# ---------------------------------------------------------------------------


def test_capabilities_routing_key():
    assert CAPABILITIES["routing_keys"] == ["ai"]


def test_capabilities_matrix_flat_pairs():
    """Matrix теперь содержит плоские пары (без виртуальных _stt/_tts ключей)."""
    matrix = CAPABILITIES["matrix"]
    # STT: все аудио-форматы → текст
    for src in ("mp3", "wav", "ogg", "m4a", "opus", "flac"):
        assert src in matrix, f"{src} must be an STT source"
        assert "txt" in matrix[src]
        assert "srt" in matrix[src]
        assert "vtt" in matrix[src]
    # TTS: txt → аудио + embedding json
    assert set(matrix["txt"]) >= {"mp3", "wav", "ogg", "json"}
    # TTS: md → аудио (без embedding)
    assert set(matrix["md"]) >= {"mp3", "wav", "ogg"}
    # matrix_categories присутствует и корректен
    cats = CAPABILITIES["matrix_categories"]
    for src in ("mp3", "wav", "ogg", "m4a", "opus", "flac"):
        assert cats[src] == "audio"
    assert cats["txt"] == "document"
    assert cats["md"] == "document"


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
        ("mp3", "mp4"),
        ("xyz", "txt"),
        ("txt", "pdf"),
        ("pdf", "txt"),
        ("mp3", "mp3"),
        ("wav", "ogg"),
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
            "taskType": "embedding",
            "ocr": True,
        }, _cfg(tmp_path))

    assert ext == "txt"


async def test_convert_empty_llm_output_raises(tmp_path):
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

    assert out == "summarized text"
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
            "taskType": "embedding",
        }, _cfg(tmp_path))

    assert ext == "mp3"


# ---------------------------------------------------------------------------
# handle_job seam — прямые unit-тесты (без WS)
# ---------------------------------------------------------------------------


async def test_handle_job_happy_path(tmp_path):
    """handle_job: корректный job → ResultSignal.completed с path/mime/ext."""
    cfg = _cfg(tmp_path)
    handle_job = build_handle_job(cfg)

    input_file = tmp_path / "in.mp3"
    input_file.write_bytes(b"fake-audio")

    job = {
        "jobId": "j-1",
        "conversionId": 42,
        "sourceFormat": "mp3",
        "targetFormat": "txt",
        "_localInput": str(input_file),
    }

    async def fake_convert(job_payload, cfg):
        out = tmp_path / f"out-{job_payload['conversionId']}.txt"
        out.write_text("transcript", encoding="utf-8")
        return str(out), "text/plain", "txt"

    reporter = ProgressReporter()
    with patch("workers.ai.worker.convert", side_effect=fake_convert):
        result = await handle_job(job, reporter)

    assert result.ok is True
    assert result.path == str(tmp_path / "out-42.txt")
    assert result.mime == "text/plain"
    assert result.ext == "txt"


async def test_handle_job_invalid_pair_permanent_fail(tmp_path):
    """Неверная пара форматов (ValueError из derive_mode) → permanent=True."""
    cfg = _cfg(tmp_path)
    handle_job = build_handle_job(cfg)

    input_file = tmp_path / "in.xyz"
    input_file.write_bytes(b"data")

    job = {
        "jobId": "j-2",
        "conversionId": 99,
        "sourceFormat": "xyz",
        "targetFormat": "txt",
        "_localInput": str(input_file),
    }

    reporter = ProgressReporter()
    result = await handle_job(job, reporter)

    assert result.ok is False
    assert result.permanent is True
    assert "cannot derive" in result.error.lower() or "ValueError" in result.error


async def test_handle_job_missing_input_retryable(tmp_path):
    """FileNotFoundError (пропавший бинарник/модель) → permanent=False (ресурсная проблема воркера)."""
    cfg = _cfg(tmp_path)
    handle_job = build_handle_job(cfg)

    job = {
        "jobId": "j-3",
        "conversionId": 99,
        "sourceFormat": "mp3",
        "targetFormat": "txt",
        "_localInput": str(tmp_path / "ghost.mp3"),
    }

    reporter = ProgressReporter()
    result = await handle_job(job, reporter)

    assert result.ok is False
    assert result.permanent is False  # не дефект задачи, а ресурсная проблема


async def test_handle_job_runtime_error_retryable(tmp_path):
    """Неожиданная ошибка в convert() → permanent=False (повторяемая)."""
    cfg = _cfg(tmp_path)
    handle_job = build_handle_job(cfg)

    input_file = tmp_path / "in.mp3"
    input_file.write_bytes(b"data")

    async def fail_convert(job, cfg):
        raise RuntimeError("gpu out of memory")

    job = {
        "jobId": "j-4",
        "conversionId": 10,
        "sourceFormat": "mp3",
        "targetFormat": "txt",
        "_localInput": str(input_file),
    }

    reporter = ProgressReporter()
    with patch("workers.ai.worker.convert", side_effect=fail_convert):
        result = await handle_job(job, reporter)

    assert result.ok is False
    assert result.permanent is False
    assert "gpu out of memory" in result.error


async def test_handle_job_reports_progress(tmp_path):
    """handle_job вызывает progress.report() как минимум дважды (start + done)."""
    cfg = _cfg(tmp_path)
    handle_job = build_handle_job(cfg)

    input_file = tmp_path / "in.mp3"
    input_file.write_bytes(b"data")

    reports: list[tuple] = []

    class RecordingReporter(ProgressReporter):
        def report(self, percent, stage=None):
            super().report(percent, stage)
            reports.append((percent, stage))

    async def fake_convert(job, cfg):
        out = tmp_path / "out.txt"
        out.write_text("ok", encoding="utf-8")
        return str(out), "text/plain", "txt"

    job = {
        "jobId": "j-5",
        "conversionId": 55,
        "sourceFormat": "mp3",
        "targetFormat": "txt",
        "_localInput": str(input_file),
    }

    with patch("workers.ai.worker.convert", side_effect=fake_convert):
        await handle_job(job, RecordingReporter())

    assert len(reports) >= 2
    assert reports[0][0] == 5   # starting
    assert reports[-1][0] == 95  # done


async def test_handle_job_does_not_delete_input_or_output(tmp_path):
    """handle_job НЕ удаляет input или output — это делает ws_client."""
    cfg = _cfg(tmp_path)
    handle_job = build_handle_job(cfg)

    input_file = tmp_path / "in.mp3"
    input_file.write_bytes(b"data")
    out_file = tmp_path / "out-66.txt"

    async def fake_convert(job, cfg):
        out_file.write_text("result", encoding="utf-8")
        return str(out_file), "text/plain", "txt"

    job = {
        "jobId": "j-6",
        "conversionId": 66,
        "sourceFormat": "mp3",
        "targetFormat": "txt",
        "_localInput": str(input_file),
    }

    with patch("workers.ai.worker.convert", side_effect=fake_convert):
        await handle_job(job, ProgressReporter())

    # Оба файла должны существовать — удалит ws_client
    assert input_file.exists(), "handle_job не должен удалять input"
    assert out_file.exists(), "handle_job не должен удалять output"


# ---------------------------------------------------------------------------
# WS transport — FakeGateway end-to-end
# ---------------------------------------------------------------------------


async def test_ws_ready_frame_has_worker_type_ai(tmp_path):
    """AI-воркер при подключении шлёт ready{workerType:"ai"}."""
    from websockets.asyncio.server import serve

    gw = FakeGateway()

    async def fake_convert(job, cfg):
        out = tmp_path / "out.txt"
        out.write_text("x")
        return str(out), "text/plain", "txt"

    cfg = _cfg(tmp_path)
    handle_job = build_handle_job(cfg)

    async with serve(gw.handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        ws_cfg = _ws_cfg(port, tmp_path)
        http = httpx.AsyncClient(transport=gw.make_http_transport())
        client = WsClient(ws_cfg, handle_job, http_client=http)
        runner = asyncio.create_task(client.run())
        try:
            await _wait_for(lambda: gw.frames_of_type("ready"), 3.0)
            ready_frames = gw.frames_of_type("ready")
            assert len(ready_frames) >= 1
            assert ready_frames[0]["workerType"] == "ai"
            assert ready_frames[0]["workerId"] == "test-ai-worker"
        finally:
            client.stop()
            await asyncio.wait_for(runner, timeout=3.0)


async def test_ws_job_inline_result(tmp_path):
    """Gateway отправляет job → воркер конвертирует → шлёт inline result (текст ≤256 KB)."""
    from websockets.asyncio.server import serve

    gw = FakeGateway(input_bytes=b"fake-audio")

    async def fake_convert(job_payload, cfg):
        out = tmp_path / f"out-{job_payload['conversionId']}.txt"
        out.write_text("transcript", encoding="utf-8")
        return str(out), "text/plain", "txt"

    cfg = _cfg(tmp_path)
    handle_job = build_handle_job(cfg)

    async with serve(gw.handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        ws_cfg = _ws_cfg(port, tmp_path)
        http = httpx.AsyncClient(transport=gw.make_http_transport())
        client = WsClient(ws_cfg, handle_job, http_client=http)
        runner = asyncio.create_task(client.run())
        try:
            # Ждём ready
            await _wait_for(lambda: gw.frames_of_type("ready"), 3.0)

            # Отправляем job и ждём result
            with patch("workers.ai.worker.convert", side_effect=fake_convert):
                await gw.send({
                    "type": "job",
                    "jobId": "j-ws-1",
                    "conversionId": 77,
                    "sourceFormat": "mp3",
                    "targetFormat": "txt",
                    "inputKey": "inputs/test.mp3",
                })
                await _wait_for(lambda: gw.frames_of_type("result"), 5.0)

            result_frames = gw.frames_of_type("result")
            assert len(result_frames) == 1
            rf = result_frames[0]
            assert rf["jobId"] == "j-ws-1"
            assert "inline" in rf           # текст ≤256 KB идёт inline

        finally:
            client.stop()
            await asyncio.wait_for(runner, timeout=3.0)


async def test_ws_on_pong_callback_fires(tmp_path):
    """Gateway шлёт pong → on_pong callback вызван."""
    from websockets.asyncio.server import serve

    gw = FakeGateway()
    pong_calls: list[int] = []

    def on_pong() -> None:
        pong_calls.append(1)

    cfg = _cfg(tmp_path)
    handle_job = build_handle_job(cfg)

    async with serve(gw.handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        ws_cfg = _ws_cfg(port, tmp_path)
        http = httpx.AsyncClient(transport=gw.make_http_transport())
        client = WsClient(ws_cfg, handle_job, http_client=http, on_pong=on_pong)
        runner = asyncio.create_task(client.run())
        try:
            await _wait_for(lambda: gw.frames_of_type("ready"), 3.0)
            await gw.send({"type": "pong"})
            await _wait_for(lambda: len(pong_calls) > 0, 3.0)
            assert len(pong_calls) >= 1
        finally:
            client.stop()
            await asyncio.wait_for(runner, timeout=3.0)


async def test_ws_on_reconnect_start_fires_on_disconnect(tmp_path):
    """Gateway закрывает соединение → on_reconnect_start вызывается перед backoff-сном.

    Smoke-тест полного пути: реальный WS-сервер, реальный WsClient, реальный
    on_reconnect_start колбэк — гарантирует, что подключение Stats.on_disconnected
    через этот колбэк не сломается при будущем рефакторинге транспортного слоя."""
    from websockets.asyncio.server import serve

    reconnect_calls: list[int] = []
    close_event = asyncio.Event()
    conn_count = 0

    async def gateway_handler(ws) -> None:
        nonlocal conn_count
        conn_count += 1
        try:
            async for raw in ws:
                try:
                    frame = json.loads(raw)
                except Exception:
                    continue
                if frame.get("type") == "ready" and conn_count == 1:
                    # Первое соединение: подтвердить хэндшейк и сразу закрыть.
                    await ws.send(json.dumps({"type": "pong"}))
                    await ws.close()
                    close_event.set()
                    return
        except Exception:
            pass

    def on_reconnect_start() -> None:
        reconnect_calls.append(1)

    cfg = _cfg(tmp_path)
    handle_job = build_handle_job(cfg)

    async with serve(gateway_handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        ws_cfg = _ws_cfg(port, tmp_path)
        http = httpx.AsyncClient(transport=FakeGateway().make_http_transport())
        client = WsClient(
            ws_cfg, handle_job, http_client=http, on_reconnect_start=on_reconnect_start
        )
        runner = asyncio.create_task(client.run())
        try:
            await asyncio.wait_for(close_event.wait(), timeout=3.0)
            await _wait_for(lambda: len(reconnect_calls) > 0, 3.0)
            assert len(reconnect_calls) >= 1
        finally:
            client.stop()
            with suppress(asyncio.TimeoutError):
                await asyncio.wait_for(runner, timeout=3.0)


async def test_sigterm_stops_client_run(tmp_path):
    """SIGTERM → зарегистрированный handler вызывает client.stop() → run() возвращается."""
    from websockets.asyncio.server import serve

    gw = FakeGateway()
    cfg = _cfg(tmp_path)
    handle_job = build_handle_job(cfg)

    async with serve(gw.handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        ws_cfg = _ws_cfg(port, tmp_path)
        http = httpx.AsyncClient(transport=gw.make_http_transport())
        client = WsClient(ws_cfg, handle_job, http_client=http)

        loop = asyncio.get_running_loop()
        loop.add_signal_handler(_signal.SIGTERM, client.stop)
        runner = asyncio.create_task(client.run())
        try:
            await _wait_for(lambda: gw.frames_of_type("ready"), 3.0)
            os.kill(os.getpid(), _signal.SIGTERM)
            await asyncio.wait_for(runner, timeout=3.0)  # должен выйти, не висеть
        finally:
            loop.remove_signal_handler(_signal.SIGTERM)
            if not runner.done():
                client.stop()
                with suppress(asyncio.CancelledError, Exception):
                    await runner


async def test_ws_job_permanent_fail_on_bad_format(tmp_path):
    """Неверная пара форматов → воркер шлёт fail{permanent:true}."""
    from websockets.asyncio.server import serve

    gw = FakeGateway()

    cfg = _cfg(tmp_path)
    handle_job = build_handle_job(cfg)

    async with serve(gw.handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        ws_cfg = _ws_cfg(port, tmp_path)
        http = httpx.AsyncClient(transport=gw.make_http_transport())
        client = WsClient(ws_cfg, handle_job, http_client=http)
        runner = asyncio.create_task(client.run())
        try:
            await _wait_for(lambda: gw.frames_of_type("ready"), 3.0)

            await gw.send({
                "type": "job",
                "jobId": "j-ws-bad",
                "conversionId": 0,
                "sourceFormat": "xyz",
                "targetFormat": "txt",
                "inputKey": "inputs/x.xyz",
            })
            await _wait_for(lambda: gw.frames_of_type("fail"), 5.0)

            fail_frames = gw.frames_of_type("fail")
            assert len(fail_frames) == 1
            assert fail_frames[0]["jobId"] == "j-ws-bad"
            assert fail_frames[0].get("permanent") is True

        finally:
            client.stop()
            await asyncio.wait_for(runner, timeout=3.0)


# ---------------------------------------------------------------------------
# Story fixture corpus
# ---------------------------------------------------------------------------


def test_story_mp3_fixture_exists_and_small(example_files):
    story = example_files / "story.mp3"
    assert story.is_file(), "story.mp3 fixture missing"
    size = story.stat().st_size
    assert size <= 50 * 1024, f"story.mp3 must be ≤50 KB, got {size}"


async def test_stt_from_story_fixture(tmp_path, example_files):
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
