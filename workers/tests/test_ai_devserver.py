"""Tests for the AI-worker dev-server (FastAPI + WS), all in-process.

No real models, no network, no ffmpeg/av: every heavy provider (StreamingWhisper,
convert()) is mocked. Covers the API contract in
.claude/skills/devserver-api-contract:
- routes_methods : GET /api/methods, POST /api/run (happy + text/binary branch +
                   security/format allowlist + convert error), GET /api/result/{id}
- routes_stream  : WS /ws/stream handshake → partial → final, Origin anti-CSWSH
- routes_settings: GET/PUT, overlay persist across reload, type/enum validation
- routes_stats   : WS-stats snapshot (connected + inflight) + disconnected path

Run inside the worker-ffmpeg image: `make -C workers test-python-ai`
(host lacks fastapi/python-multipart).
"""

from __future__ import annotations

from dataclasses import replace
from pathlib import Path
from unittest.mock import AsyncMock, MagicMock, patch

import pytest
from fastapi.testclient import TestClient

from workers.ai.config import load_config
from workers.ai.devserver.app import create_app
from workers.ai.devserver.settings import (
    SETTINGS_BY_KEY,
    effective_config,
    read_overlay,
)
from workers.ai.devserver.stats import Stats


# ---------------------------------------------------------------------------
# Fixtures / helpers
# ---------------------------------------------------------------------------


def _cfg(tmp_path: Path):
    return replace(load_config(), work_dir=tmp_path)


@pytest.fixture
def dev_app(tmp_path, monkeypatch):
    """A fresh dev-server app whose effective config points at tmp_path.

    WORK_DIR + DEVSERVER_CONFIG_PATH flow through load_config()/overlay_path()
    when the lifespan builds app.state on `with TestClient(app) as c:` enter.
    """
    monkeypatch.setenv("WORK_DIR", str(tmp_path))
    monkeypatch.setenv("DEVSERVER_CONFIG_PATH", str(tmp_path / "overlay.json"))
    monkeypatch.delenv("DEVSERVER_TOKEN", raising=False)
    return create_app()


def _fake_convert(work_dir: Path, *, mime: str, ext: str, payload):
    async def fake(job, cfg):
        out = Path(work_dir) / f"out-{job['conversionId']}.{ext}"
        if isinstance(payload, bytes):
            out.write_bytes(payload)
        else:
            out.write_text(payload, encoding="utf-8")
        return str(out), mime, ext

    return fake


class _FakeStreamingWhisper:
    """Drop-in for providers.streaming_stt.StreamingWhisper — no faster_whisper."""

    CANNED = {
        "final": "hello world",
        "partial": "hello world",
        "segments": [{"start": 0.0, "end": 1.2, "text": "hello world"}],
        "language": "en",
    }

    def __init__(self, *args, **kwargs):
        pass

    def process_file(self, path):
        return dict(self.CANNED)

    def process_chunk(self, data):
        return {"partial": "hello"}

    def transcribe_pcm(self, pcm: bytes, sample_rate: int = 16000) -> dict:
        return dict(self.CANNED)


# ---------------------------------------------------------------------------
# routes_methods — GET /api/methods
# ---------------------------------------------------------------------------


def test_methods_shape(dev_app):
    with TestClient(dev_app) as client:
        body = client.get("/api/methods").json()

    methods = {m["mode"]: m for m in body["methods"]}
    assert set(methods) == {"stt", "stt_stream", "tts", "embedding", "llm"}

    stt = methods["stt"]
    assert stt["label"] == "Speech → Text"
    for fmt in ("mp3", "wav", "ogg", "m4a", "opus", "flac"):
        assert fmt in stt["sources"]
    assert stt["targets"] == ["txt", "srt", "vtt"]

    assert methods["stt_stream"]["targets"] == ["json"]
    assert methods["tts"]["sources"] == ["txt", "md"]
    assert methods["tts"]["targets"] == ["mp3", "wav", "ogg"]
    assert methods["embedding"]["targets"] == ["json"]
    assert methods["llm"]["sources"] == ["txt", "md"]
    assert methods["llm"]["targets"] == ["txt", "md"]

    for mode in ("stt", "stt_stream", "tts", "embedding", "llm"):
        assert methods[mode]["description"].strip()


# ---------------------------------------------------------------------------
# routes_methods — POST /api/run
# ---------------------------------------------------------------------------


def test_run_happy_path_text(dev_app, tmp_path):
    fake = _fake_convert(tmp_path, mime="text/plain", ext="txt", payload="transcript")
    with patch("workers.ai.devserver.routes_methods.convert", side_effect=fake):
        with TestClient(dev_app) as client:
            resp = client.post(
                "/api/run",
                data={"sourceFormat": "mp3", "targetFormat": "txt"},
                files={"file": ("a.mp3", b"audio-bytes", "audio/mpeg")},
            )
    assert resp.status_code == 200
    body = resp.json()
    assert body["ok"] is True
    assert body["mime"] == "text/plain"
    assert body["ext"] == "txt"
    assert body["text"] == "transcript"
    assert body["bytes"] == len("transcript")
    assert body["downloadUrl"] == f"/api/result/{body['resultId']}"
    assert isinstance(body["elapsedMs"], int) and body["elapsedMs"] >= 0


def test_run_text_input_branch(dev_app, tmp_path):
    fake = _fake_convert(tmp_path, mime="audio/mpeg", ext="mp3", payload=b"\x00audio")
    with patch("workers.ai.devserver.routes_methods.convert", side_effect=fake):
        with TestClient(dev_app) as client:
            resp = client.post(
                "/api/run",
                data={"sourceFormat": "txt", "targetFormat": "mp3", "text": "speak this"},
            )
    assert resp.status_code == 200
    assert resp.json()["ok"] is True


def test_run_no_file_no_text_422(dev_app):
    convert_spy = MagicMock()
    with patch("workers.ai.devserver.routes_methods.convert", convert_spy):
        with TestClient(dev_app) as client:
            resp = client.post("/api/run", data={"sourceFormat": "txt", "targetFormat": "mp3"})
    assert resp.status_code == 422
    assert resp.json()["ok"] is False
    convert_spy.assert_not_called()


def test_run_json_result_inlines_text(dev_app, tmp_path):
    fake = _fake_convert(tmp_path, mime="application/json", ext="json", payload='{"x":1}')
    with patch("workers.ai.devserver.routes_methods.convert", side_effect=fake):
        with TestClient(dev_app) as client:
            resp = client.post(
                "/api/run",
                data={"sourceFormat": "txt", "targetFormat": "json"},
                files={"file": ("a.txt", b"hello", "text/plain")},
            )
    body = resp.json()
    assert body["ok"] is True
    assert body["text"] == '{"x":1}'


def test_run_binary_result_text_null_and_downloadable(dev_app, tmp_path):
    fake = _fake_convert(tmp_path, mime="audio/mpeg", ext="mp3", payload=b"\x00\x01ID3audio")
    with patch("workers.ai.devserver.routes_methods.convert", side_effect=fake):
        with TestClient(dev_app) as client:
            resp = client.post(
                "/api/run",
                data={"sourceFormat": "txt", "targetFormat": "mp3"},
                files={"file": ("a.txt", b"speak this", "text/plain")},
            )
            body = resp.json()
            assert body["ok"] is True
            assert body["text"] is None
            assert body["mime"] == "audio/mpeg"

            dl = client.get(body["downloadUrl"])
            assert dl.status_code == 200
            assert dl.content == b"\x00\x01ID3audio"
            assert dl.headers["content-type"].startswith("audio/mpeg")


def test_run_srt_result_is_download_only(dev_app, tmp_path):
    fake = _fake_convert(
        tmp_path, mime="application/x-subrip", ext="srt", payload=b"1\n00:00 --> 00:01\nhi\n"
    )
    with patch("workers.ai.devserver.routes_methods.convert", side_effect=fake):
        with TestClient(dev_app) as client:
            resp = client.post(
                "/api/run",
                data={"sourceFormat": "mp3", "targetFormat": "srt"},
                files={"file": ("a.mp3", b"audio", "audio/mpeg")},
            )
            body = resp.json()
            assert body["ok"] is True
            assert body["text"] is None
            assert client.get(body["downloadUrl"]).status_code == 200


def test_run_mp3_uppercase_normalizes(dev_app, tmp_path):
    fake = _fake_convert(tmp_path, mime="text/plain", ext="txt", payload="ok")
    with patch("workers.ai.devserver.routes_methods.convert", side_effect=fake):
        with TestClient(dev_app) as client:
            resp = client.post(
                "/api/run",
                data={"sourceFormat": "MP3", "targetFormat": "TXT"},
                files={"file": ("a.mp3", b"audio", "audio/mpeg")},
            )
    assert resp.status_code == 200
    assert resp.json()["ok"] is True


@pytest.mark.parametrize("bad", ["../../x", "exe", "php", "..", "mp3;rm", "json5"])
def test_run_invalid_source_format_rejected(dev_app, tmp_path, bad):
    convert_spy = MagicMock()
    with patch("workers.ai.devserver.routes_methods.convert", convert_spy):
        with TestClient(dev_app) as client:
            resp = client.post(
                "/api/run",
                data={"sourceFormat": bad, "targetFormat": "txt"},
                files={"file": ("a.bin", b"x", "application/octet-stream")},
            )
    assert resp.status_code == 422
    body = resp.json()
    assert body["ok"] is False and "error" in body
    convert_spy.assert_not_called()


def test_run_invalid_target_format_rejected(dev_app, tmp_path):
    convert_spy = MagicMock()
    with patch("workers.ai.devserver.routes_methods.convert", convert_spy):
        with TestClient(dev_app) as client:
            resp = client.post(
                "/api/run",
                data={"sourceFormat": "mp3", "targetFormat": "../../etc/passwd"},
                files={"file": ("a.mp3", b"x", "audio/mpeg")},
            )
    assert resp.status_code == 422
    convert_spy.assert_not_called()


def test_run_convert_error_returns_422(dev_app, tmp_path):
    async def boom(job, cfg):
        raise ValueError("cannot derive conversion mode")

    with patch("workers.ai.devserver.routes_methods.convert", side_effect=boom):
        with TestClient(dev_app) as client:
            resp = client.post(
                "/api/run",
                data={"sourceFormat": "mp3", "targetFormat": "txt"},
                files={"file": ("a.mp3", b"audio", "audio/mpeg")},
            )
    assert resp.status_code == 422
    body = resp.json()
    assert body["ok"] is False
    assert "cannot derive" in body["error"]


# ---------------------------------------------------------------------------
# routes_methods — GET /api/result/{id}
# ---------------------------------------------------------------------------


def test_result_unknown_id_404(dev_app):
    with TestClient(dev_app) as client:
        resp = client.get("/api/result/does-not-exist")
    assert resp.status_code == 404
    assert resp.json()["ok"] is False


# ---------------------------------------------------------------------------
# routes_stream — WS /ws/stream
# ---------------------------------------------------------------------------


def test_ws_partial_then_final(dev_app, monkeypatch):
    class _ImmediateChunker:
        def __init__(self, cfg): pass
        def push(self, pcm: bytes): return [pcm] if pcm else []
        def flush(self): return None
        @property
        def resident_bytes(self): return 0

    monkeypatch.setattr(
        "workers.ai.devserver.routes_stream._new_vad_chunker",
        lambda cfg: _ImmediateChunker(cfg),
    )

    with patch(
        "workers.ai.providers.streaming_stt.StreamingWhisper", _FakeStreamingWhisper
    ):
        with TestClient(dev_app) as client:
            with client.websocket_connect(
                "/ws/stream", headers={"origin": "http://localhost:8877"}
            ) as ws:
                ws.send_json({"type": "start", "format": "pcm_s16le", "sampleRate": 16000})
                ws.send_bytes(b"\x00" * 960)
                partial = ws.receive_json()
                assert partial["type"] == "partial"
                assert partial["text"] == "hello world"
                assert partial["language"] == "en"
                assert partial["segments"][0]["text"] == "hello world"

                ws.send_json({"type": "stop"})
                final = ws.receive_json()
                assert final["type"] == "final"
                assert final["text"] == "hello world"
                assert final["segments"]


def test_ws_absent_origin_accepted(dev_app):
    with patch(
        "workers.ai.providers.streaming_stt.StreamingWhisper", _FakeStreamingWhisper
    ):
        with TestClient(dev_app) as client:
            with client.websocket_connect("/ws/stream") as ws:
                ws.send_json({"type": "start", "format": "webm/opus"})
                ws.send_json({"type": "stop"})
                final = ws.receive_json()
                assert final["type"] == "final"
                assert final["text"] == ""


def test_ws_foreign_origin_rejected(dev_app):
    from starlette.websockets import WebSocketDisconnect

    with TestClient(dev_app) as client:
        with pytest.raises(WebSocketDisconnect) as ei:
            with client.websocket_connect(
                "/ws/stream", headers={"origin": "http://evil.example"}
            ):
                pass
    assert ei.value.code == 1008


def test_ws_per_tick_work_bounded(dev_app, monkeypatch):
    SEG_BYTES = 9600
    N_SEGS = 10
    call_lengths: list[int] = []

    class _RecordingWhisper:
        def __init__(self, *a, **kw): pass

        def transcribe_pcm(self, pcm: bytes, sample_rate: int = 16000) -> dict:
            call_lengths.append(len(pcm))
            return {
                "partial": "x", "final": "x",
                "segments": [{"start": 0.0, "end": 0.3, "text": "x"}],
                "language": "en",
            }

    class _FixedSizeChunker:
        def __init__(self, cfg): self._buf: bytearray = bytearray()

        def push(self, pcm: bytes) -> list[bytes]:
            self._buf.extend(pcm)
            segs: list[bytes] = []
            while len(self._buf) >= SEG_BYTES:
                segs.append(bytes(self._buf[:SEG_BYTES]))
                del self._buf[:SEG_BYTES]
            return segs

        def flush(self) -> bytes | None:
            return bytes(self._buf) if self._buf else None

        @property
        def resident_bytes(self) -> int:
            return len(self._buf)

    monkeypatch.setattr(
        "workers.ai.devserver.routes_stream._new_vad_chunker",
        lambda cfg: _FixedSizeChunker(cfg),
    )
    with patch("workers.ai.providers.streaming_stt.StreamingWhisper", _RecordingWhisper):
        with TestClient(dev_app) as client:
            with client.websocket_connect(
                "/ws/stream", headers={"origin": "http://localhost:8877"}
            ) as ws:
                ws.send_json({"type": "start", "format": "pcm_s16le", "sampleRate": 16000})
                for _ in range(N_SEGS):
                    ws.send_bytes(b"\x00" * SEG_BYTES)
                    ws.receive_json()
                ws.send_json({"type": "stop"})
                ws.receive_json()

    assert call_lengths, "transcribe_pcm ни разу не вызван"
    assert all(length == SEG_BYTES for length in call_lengths)
    assert max(call_lengths) < SEG_BYTES * N_SEGS


def test_ws_resident_buffer_bounded(dev_app, monkeypatch):
    SEG_BYTES = 9600
    N_SEGS = 20
    push_sizes: list[int] = []

    class _RecordingChunker:
        def __init__(self, cfg): pass

        def push(self, pcm: bytes) -> list[bytes]:
            push_sizes.append(len(pcm))
            return []

        def flush(self) -> bytes | None:
            return None

        @property
        def resident_bytes(self) -> int:
            return 0

    monkeypatch.setattr(
        "workers.ai.devserver.routes_stream._new_vad_chunker",
        lambda cfg: _RecordingChunker(cfg),
    )
    with patch("workers.ai.providers.streaming_stt.StreamingWhisper", _FakeStreamingWhisper):
        with TestClient(dev_app) as client:
            with client.websocket_connect(
                "/ws/stream", headers={"origin": "http://localhost:8877"}
            ) as ws:
                ws.send_json({"type": "start", "format": "pcm_s16le", "sampleRate": 16000})
                for _ in range(N_SEGS):
                    ws.send_bytes(b"\x00" * SEG_BYTES)
                ws.send_json({"type": "stop"})
                ws.receive_json()

    assert push_sizes, "push() ни разу не вызван"
    assert max(push_sizes) <= SEG_BYTES


def test_allowed_origins_helper(monkeypatch):
    monkeypatch.delenv("DEVSERVER_HOST", raising=False)
    monkeypatch.setenv("DEVSERVER_PORT", "8877")
    from workers.ai.devserver.routes_stream import _allowed_origins

    origins = _allowed_origins()
    assert "http://localhost:8877" in origins
    assert "http://127.0.0.1:8877" in origins
    assert "http://evil.example" not in origins


# ---------------------------------------------------------------------------
# routes_settings — GET / PUT
# ---------------------------------------------------------------------------


def test_settings_get_shape_and_secrets_absent(dev_app):
    with TestClient(dev_app) as client:
        body = client.get("/api/settings").json()

    by_key = {s["key"]: s for s in body["settings"]}
    # Секреты + инфра-ключи и удалённые pull-поля никогда не выставляются.
    for hidden in (
        "WORKER_API_TOKEN", "LLM_MODEL_PATH", "WORK_DIR",
        "API_BASE_URL", "WORKER_TYPE", "PULL_ENABLED", "POLL_INTERVAL",
    ):
        assert hidden not in by_key

    wm = by_key["WHISPER_MODEL"]
    assert wm["apply"] == "restart"
    assert wm["type"] == "enum"
    assert wm["options"] == ["tiny", "base", "small", "medium", "large"]

    for s in body["settings"]:
        assert s["label"].strip()
        assert s["help"].strip()


def test_settings_put_persists_and_applies(dev_app, tmp_path):
    with TestClient(dev_app) as client:
        resp = client.put("/api/settings", json={"LLM_MAX_TOKENS": 2048, "WHISPER_MODEL": "small"})
        assert resp.status_code == 200
        body = resp.json()
        assert body["ok"] is True
        assert "LLM_MAX_TOKENS" in body["applied"]
        assert "WHISPER_MODEL" in body["pendingRestart"]
        by_key = {s["key"]: s for s in body["settings"]}
        assert by_key["LLM_MAX_TOKENS"]["value"] == 2048
        assert by_key["WHISPER_MODEL"]["value"] == "small"

    overlay = read_overlay()
    assert overlay["LLM_MAX_TOKENS"] == 2048
    assert overlay["WHISPER_MODEL"] == "small"


def test_settings_persist_across_reload(dev_app, tmp_path):
    with TestClient(dev_app) as client:
        assert client.put("/api/settings", json={"LLM_TEMPERATURE": 0.25}).status_code == 200

    cfg = effective_config()
    assert cfg.llm_temperature == 0.25


def test_settings_put_calls_runner_update_cfg(dev_app):
    """PUT /api/settings вызывает runner.update_cfg с новым конфигом."""
    with TestClient(dev_app) as client:
        runner = MagicMock()
        runner.start = AsyncMock()
        runner.stop = AsyncMock()
        runner.update_cfg = MagicMock()
        client.app.state.runner = runner

        assert client.put("/api/settings", json={"LLM_MAX_TOKENS": 512}).status_code == 200
        runner.update_cfg.assert_called_once()


def test_settings_put_bad_enum_422(dev_app):
    with TestClient(dev_app) as client:
        resp = client.put("/api/settings", json={"WHISPER_MODEL": "ginormous"})
    assert resp.status_code == 422
    body = resp.json()
    assert body["ok"] is False
    assert body["key"] == "WHISPER_MODEL"


def test_settings_put_bad_int_422(dev_app):
    """Удалённый ключ POLL_INTERVAL — неизвестен → 422 (unknown key, не type mismatch)."""
    with TestClient(dev_app) as client:
        resp = client.put("/api/settings", json={"POLL_INTERVAL": "not-a-number"})
    assert resp.status_code == 422
    assert resp.json()["key"] == "POLL_INTERVAL"


def test_settings_put_unknown_key_422(dev_app):
    with TestClient(dev_app) as client:
        resp = client.put("/api/settings", json={"WORKER_API_TOKEN": "leak"})
    assert resp.status_code == 422
    assert resp.json()["key"] == "WORKER_API_TOKEN"
    assert "WORKER_API_TOKEN" not in SETTINGS_BY_KEY


# ---------------------------------------------------------------------------
# routes_stats — GET /api/stats (WS-stats)
# ---------------------------------------------------------------------------


def test_stats_connected_snapshot(dev_app):
    """Stats после подключения: connected=True, inflight отражает активные задачи."""
    stats = Stats()
    stats.on_connected()
    stats.on_job_start()  # одна задача в работе
    # не вызываем on_job_done → inflight=1

    with TestClient(dev_app) as client:
        client.app.state.stats = stats
        body = client.get("/api/stats").json()

    assert body["connected"] is True
    assert body["inflight"] == 1
    assert "lastPong" in body


def test_stats_disconnected_snapshot(dev_app):
    """Stats пустой (не подключались): connected=False, inflight=0."""
    with TestClient(dev_app) as client:
        client.app.state.stats = Stats()
        body = client.get("/api/stats").json()

    assert body["connected"] is False
    assert body["inflight"] == 0
    assert body["lastPong"] is None


def test_stats_inflight_counter(dev_app):
    """on_job_start/on_job_done корректно инкрементируют/декрементируют inflight."""
    stats = Stats()
    stats.on_connected()
    stats.on_job_start()
    stats.on_job_start()
    stats.on_job_done()

    with TestClient(dev_app) as client:
        client.app.state.stats = stats
        body = client.get("/api/stats").json()

    assert body["inflight"] == 1


def test_stats_on_disconnected_clears_connected(dev_app):
    """on_disconnected сбрасывает connected → False (не инфлайт)."""
    stats = Stats()
    stats.on_connected()
    stats.on_disconnected()

    with TestClient(dev_app) as client:
        client.app.state.stats = stats
        body = client.get("/api/stats").json()

    assert body["connected"] is False


def test_stats_on_pong_updates_last_pong(dev_app):
    """on_pong() устанавливает lastPong в ненулевую ISO-строку."""
    stats = Stats()
    stats.on_connected()
    stats.on_pong()

    with TestClient(dev_app) as client:
        client.app.state.stats = stats
        body = client.get("/api/stats").json()

    assert body["lastPong"] is not None
    assert "T" in body["lastPong"]  # ISO-8601 shape


# ---------------------------------------------------------------------------
# #1 — семантика connected: привязка к pong, сброс при reconnect
# ---------------------------------------------------------------------------


def test_stats_not_connected_before_any_pong():
    """До первого pong connected=False — нет ложной видимости подключения."""
    stats = Stats()
    assert stats.snapshot()["connected"] is False
    assert stats.snapshot()["lastPong"] is None


def test_stats_pong_wiring_sets_connected_and_last_pong():
    """Колбэк, собранный как в WsRunner: on_pong → on_connected() + on_pong().
    Проверяем, что именно сочетание колбэков даёт нужный эффект."""
    stats = Stats()

    def pong_cb():
        stats.on_connected()
        stats.on_pong()

    assert stats.snapshot()["connected"] is False
    pong_cb()
    snap = stats.snapshot()
    assert snap["connected"] is True
    assert snap["lastPong"] is not None


def test_stats_reconnect_start_clears_connected():
    """on_reconnect_start (= on_disconnected) сбрасывает connected во время backoff."""
    stats = Stats()
    stats.on_connected()
    assert stats.snapshot()["connected"] is True
    stats.on_disconnected()  # WsClient вызывает это через on_reconnect_start
    assert stats.snapshot()["connected"] is False


# ---------------------------------------------------------------------------
# #3 — единственный источник WORK_DIR
# ---------------------------------------------------------------------------


def test_ws_client_from_env_uses_explicit_work_dir(monkeypatch, tmp_path):
    """from_env(work_dir=...) использует переданный путь, игнорирует WORK_DIR env."""
    from workers.common.ws_client import WsClientConfig

    monkeypatch.setenv("WORK_DIR", "/should/not/be/used")
    monkeypatch.setenv("WORKER_ID", "test-id")
    monkeypatch.setenv("WORKER_TYPE", "ai")
    monkeypatch.setenv("GATEWAY_WS_URL", "ws://localhost:9999")
    monkeypatch.setenv("WORKER_API_TOKEN", "tok")

    cfg = WsClientConfig.from_env(work_dir=tmp_path)
    assert cfg.work_dir == tmp_path


# ---------------------------------------------------------------------------
# Integration: real PyAV decoder + real webrtcvad (av/webrtcvad pip-installed)
# ---------------------------------------------------------------------------


def test_pcm_decoder_decode_webm_multipart():
    av = pytest.importorskip("av")
    np = pytest.importorskip("numpy")
    import io
    from workers.ai.devserver.pcm_decoder import PcmStreamDecoder

    RATE_ENC = 48000
    RATE_DEC = 16000
    n = RATE_ENC * 2
    tone = (np.sin(2 * np.pi * 440 * np.arange(n) / RATE_ENC) * 16384).astype(np.int16)
    buf = io.BytesIO()
    out = av.open(buf, "w", format="webm")
    stream = out.add_stream("libopus", rate=RATE_ENC, layout="mono")
    frame = av.AudioFrame.from_ndarray(tone.reshape(1, -1), format="s16p", layout="mono")
    frame.sample_rate = RATE_ENC
    frame.pts = 0
    for pkt in stream.encode(frame):
        out.mux(pkt)
    for pkt in stream.encode(None):
        out.mux(pkt)
    out.close()
    data = buf.getvalue()

    dec = PcmStreamDecoder(RATE_DEC)
    chunk = max(1, len(data) // 4)
    for i in range(0, len(data), chunk):
        dec.feed(data[i : i + chunk])
    pcm = dec.close()

    assert dec._decode_error is None
    expected = RATE_DEC * 2 * 2
    assert len(pcm) > expected // 2
    assert len(pcm) % 2 == 0


def test_vad_chunker_frame_geometry_real():
    pytest.importorskip("webrtcvad")
    from workers.ai.devserver.vad_chunker import VadChunker

    chunker = VadChunker(aggressiveness=2, silence_frames=5, max_segment_sec=10.0)
    segs = chunker.push(b"\x00" * 960 * 20)
    assert segs == []


def test_vad_chunker_silence_boundary():
    pytest.importorskip("webrtcvad")
    from workers.ai.devserver.vad_chunker import VadChunker

    class _FakeVad:
        def __init__(self, responses):
            self._iter = iter(responses)
        def is_speech(self, frame, rate):
            return next(self._iter, False)

    chunker = VadChunker(aggressiveness=2, silence_frames=3, max_segment_sec=30.0)
    chunker._vad = _FakeVad([True] * 5 + [False] * 3)

    segs = chunker.push(b"\x00" * 960 * 8)
    assert len(segs) == 1
    assert len(segs[0]) == 960 * 8


def test_vad_chunker_continuous_speech_force_flush():
    pytest.importorskip("webrtcvad")
    from workers.ai.devserver.vad_chunker import VadChunker

    class _FakeVad:
        def is_speech(self, frame, rate):
            return True

    FRAME_BYTES = 960
    MAX_SEC = 1.0
    FRAME_MS = 30
    MAX_FRAMES = int(MAX_SEC * 1000 / FRAME_MS)
    TOTAL_FRAMES = MAX_FRAMES * 3 + 5

    chunker = VadChunker(aggressiveness=2, silence_frames=50, max_segment_sec=MAX_SEC)
    chunker._vad = _FakeVad()

    segs = chunker.push(b"\x00" * FRAME_BYTES * TOTAL_FRAMES)

    assert len(segs) >= 3
    max_allowed = MAX_FRAMES * FRAME_BYTES
    for i, seg in enumerate(segs):
        assert len(seg) <= max_allowed
    assert chunker.resident_bytes <= max_allowed + FRAME_BYTES


def test_pcm_decoder_garbage_sets_decode_error():
    pytest.importorskip("av")
    from workers.ai.devserver.pcm_decoder import PcmStreamDecoder

    dec = PcmStreamDecoder(16000)
    dec.feed(b"NOT_WEBM_GARBAGE_BYTES" * 50)
    pcm = dec.close()

    assert dec._decode_error is not None
    assert pcm == b""


def test_ws_decode_error_surfaced(dev_app, monkeypatch):
    class _ErrorDecoder:
        def __init__(self, sample_rate: int = 16000) -> None:
            self._decode_error: Exception | None = None
        def feed(self, data: bytes) -> None:
            pass
        def drain(self) -> bytes:
            return b""
        def decode_error(self) -> Exception | None:
            return self._decode_error
        def close(self) -> bytes:
            self._decode_error = RuntimeError("simulated av decode failure")
            return b""

    monkeypatch.setattr(
        "workers.ai.devserver.routes_stream._new_pcm_decoder",
        lambda sample_rate=16000: _ErrorDecoder(sample_rate),
    )

    with patch("workers.ai.providers.streaming_stt.StreamingWhisper", _FakeStreamingWhisper):
        with TestClient(dev_app) as client:
            with client.websocket_connect(
                "/ws/stream", headers={"origin": "http://localhost:8877"}
            ) as ws:
                ws.send_json({"type": "start", "format": "webm/opus", "sampleRate": 16000})
                ws.send_bytes(b"\x00" * 64)
                ws.send_json({"type": "stop"})
                msg = ws.receive_json()
    assert msg["type"] == "error"
    assert "message" in msg
    assert "simulated av decode failure" in msg["message"]


def test_ws_decode_error_surfaced_on_first_tick(dev_app, monkeypatch):
    """Fail-fast: ошибка decode-потока приходит на первом же тике (без stop).

    Синхронный фейк выставляет ошибку при feed(); per-tick проверка после drain()
    обязана прислать error-фрейм сразу. Без правки в routes_stream ничего не шлётся
    после бинарного фрейма и receive_json() завис бы — тест ловит именно новый путь.
    """
    class _FirstTickErrorDecoder:
        def __init__(self, sample_rate: int = 16000) -> None:
            self._decode_error: Exception | None = None
        def feed(self, data: bytes) -> None:
            self._decode_error = RuntimeError("immediate av decode failure")
        def drain(self) -> bytes:
            return b""
        def decode_error(self) -> Exception | None:
            return self._decode_error
        def close(self) -> bytes:
            return b""

    monkeypatch.setattr(
        "workers.ai.devserver.routes_stream._new_pcm_decoder",
        lambda sample_rate=16000: _FirstTickErrorDecoder(sample_rate),
    )

    with patch("workers.ai.providers.streaming_stt.StreamingWhisper", _FakeStreamingWhisper):
        with TestClient(dev_app) as client:
            with client.websocket_connect(
                "/ws/stream", headers={"origin": "http://localhost:8877"}
            ) as ws:
                ws.send_json({"type": "start", "format": "webm/opus", "sampleRate": 16000})
                ws.send_bytes(b"\x00" * 64)
                # НЕ шлём stop — error-фрейм обязан прийти из per-tick проверки
                msg = ws.receive_json()
    assert msg["type"] == "error"
    assert "message" in msg
    assert "immediate av decode failure" in msg["message"]
