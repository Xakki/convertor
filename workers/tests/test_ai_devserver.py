"""Tests for the AI-worker dev-server (FastAPI + WS), all in-process.

No real models, no network, no ffmpeg/av: every heavy provider (StreamingWhisper,
convert(), the pull-API client) is mocked. Covers the API contract in
.claude/skills/devserver-api-contract:
- routes_methods : GET /api/methods, POST /api/run (happy + text/binary branch +
                   security/format allowlist + convert error), GET /api/result/{id}
- routes_stream  : WS /ws/stream handshake → partial → final, Origin anti-CSWSH
- routes_settings: GET/PUT, overlay persist across reload, PULL_ENABLED hot toggle,
                   type/enum validation
- routes_stats   : seeded Stats snapshot (running) + disabled path
- pull_runner    : _poll_cycle updates Stats (success + failure) with API mocked

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
from workers.ai.worker import JobOutcome, _poll_cycle


# ---------------------------------------------------------------------------
# Fixtures / helpers
# ---------------------------------------------------------------------------


def _cfg(tmp_path: Path):
    return replace(load_config(), work_dir=tmp_path)


@pytest.fixture
def dev_app(tmp_path, monkeypatch):
    """A fresh dev-server app whose effective config points at tmp_path.

    WORK_DIR + DEVSERVER_CONFIG_PATH flow through load_config()/overlay_path()
    when the lifespan builds app.state on `with TestClient(app) as c:` enter, so
    cfg.work_dir == tmp_path and the settings overlay lives under tmp_path.
    """
    monkeypatch.setenv("WORK_DIR", str(tmp_path))
    monkeypatch.setenv("DEVSERVER_CONFIG_PATH", str(tmp_path / "overlay.json"))
    monkeypatch.delenv("DEVSERVER_TOKEN", raising=False)
    monkeypatch.delenv("PULL_ENABLED", raising=False)
    return create_app()


def _fake_convert(work_dir: Path, *, mime: str, ext: str, payload):
    """Build an async convert() stub that writes a real file under work_dir.

    The route asserts containment (`_within(cfg.work_dir, out_path)`) and stats
    the file, so the output MUST live under work_dir (== tmp_path here).
    """

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
        "segments": [{"start": 0.0, "end": 1.2, "text": "hello world"}],
        "language": "en",
    }

    def __init__(self, *args, **kwargs):
        pass

    def process_file(self, path):
        return dict(self.CANNED)

    def process_chunk(self, data):
        return {"partial": "hello"}


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

    # Every method carries a human description the UI surfaces under the selector.
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
    assert body["text"] == "transcript"          # text/* inlined
    assert body["bytes"] == len("transcript")
    assert body["downloadUrl"] == f"/api/result/{body['resultId']}"
    assert isinstance(body["elapsedMs"], int) and body["elapsedMs"] >= 0


def test_run_text_input_branch(dev_app, tmp_path):
    """Text-input methods: a typed `text` field substitutes for a file upload."""
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
    """Neither file nor text → 422 before any convert/path build."""
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
    assert body["text"] == '{"x":1}'             # application/json inlined


def test_run_binary_result_text_null_and_downloadable(dev_app, tmp_path):
    """Audio result: text=null, but downloadUrl streams the file back."""
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
    """srt is application/x-subrip — NOT text/* or json → text=null, download-only."""
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
    """`MP3` / `.MP3` must normalize to a valid advertised format (not rejected)."""
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
    """Non-advertised / traversal / invalid tokens → 422 before any path build."""
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
    convert_spy.assert_not_called()             # rejected before convert/path build


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


def test_ws_partial_then_final(dev_app):
    with patch(
        "workers.ai.providers.streaming_stt.StreamingWhisper", _FakeStreamingWhisper
    ):
        with TestClient(dev_app) as client:
            with client.websocket_connect(
                "/ws/stream", headers={"origin": "http://localhost:8877"}
            ) as ws:
                ws.send_json({"type": "start", "format": "webm/opus", "sampleRate": 16000})
                ws.send_bytes(b"webm-frame-bytes")   # last_emit=0 → emits a partial now
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
    """No Origin (CLI/non-browser) is accepted; empty buffer → final with empty text."""
    with patch(
        "workers.ai.providers.streaming_stt.StreamingWhisper", _FakeStreamingWhisper
    ):
        with TestClient(dev_app) as client:
            with client.websocket_connect("/ws/stream") as ws:
                ws.send_json({"type": "start", "format": "webm/opus"})
                ws.send_json({"type": "stop"})       # no audio buffered
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
    assert ei.value.code == 1008                     # policy violation, closed pre-accept


def test_allowed_origins_helper(monkeypatch):
    """Authoritative anti-CSWSH assertion (independent of TestClient WS quirks)."""
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
    # Secrets + infra keys never exposed.
    for hidden in ("WORKER_API_TOKEN", "LLM_MODEL_PATH", "WORK_DIR", "API_BASE_URL", "WORKER_TYPE"):
        assert hidden not in by_key

    assert by_key["PULL_ENABLED"]["apply"] == "hot"
    assert by_key["PULL_ENABLED"]["type"] == "bool"
    assert by_key["PULL_ENABLED"]["group"] == "pull"
    wm = by_key["WHISPER_MODEL"]
    assert wm["apply"] == "restart"
    assert wm["type"] == "enum"
    assert wm["options"] == ["tiny", "base", "small", "medium", "large"]

    # Every setting carries a human label + help for the UI (no key-duplication).
    for s in body["settings"]:
        assert s["label"].strip()
        assert s["help"].strip()


def test_settings_put_persists_and_applies(dev_app, tmp_path):
    with TestClient(dev_app) as client:
        resp = client.put("/api/settings", json={"LLM_MAX_TOKENS": 2048, "WHISPER_MODEL": "small"})
        assert resp.status_code == 200
        body = resp.json()
        assert body["ok"] is True
        assert "LLM_MAX_TOKENS" in body["applied"]          # hot key
        assert "WHISPER_MODEL" in body["pendingRestart"]    # restart key
        by_key = {s["key"]: s for s in body["settings"]}
        assert by_key["LLM_MAX_TOKENS"]["value"] == 2048
        assert by_key["WHISPER_MODEL"]["value"] == "small"

    # Overlay JSON written to the monkeypatched path.
    overlay = read_overlay()
    assert overlay["LLM_MAX_TOKENS"] == 2048
    assert overlay["WHISPER_MODEL"] == "small"


def test_settings_persist_across_reload(dev_app, tmp_path):
    """A saved setting survives a fresh effective-config derivation from the overlay."""
    with TestClient(dev_app) as client:
        assert client.put("/api/settings", json={"LLM_TEMPERATURE": 0.25}).status_code == 200

    # Fresh derivation reads the same overlay file (DEVSERVER_CONFIG_PATH unchanged).
    cfg = effective_config()
    assert cfg.llm_temperature == 0.25


def test_settings_put_toggles_pull_runner(dev_app):
    """PULL_ENABLED is a hot key: turning it on/off starts/stops the PullRunner."""
    with TestClient(dev_app) as client:
        runner = MagicMock()
        runner.start = AsyncMock()
        runner.stop = AsyncMock()
        runner.update_cfg = MagicMock()
        client.app.state.runner = runner       # inject AFTER lifespan built state

        assert client.put("/api/settings", json={"PULL_ENABLED": True}).status_code == 200
        runner.start.assert_awaited_once()
        runner.stop.assert_not_awaited()

        assert client.put("/api/settings", json={"PULL_ENABLED": False}).status_code == 200
        runner.stop.assert_awaited_once()
        runner.update_cfg.assert_called()


def test_settings_put_bad_enum_422(dev_app):
    with TestClient(dev_app) as client:
        resp = client.put("/api/settings", json={"WHISPER_MODEL": "ginormous"})
    assert resp.status_code == 422
    body = resp.json()
    assert body["ok"] is False
    assert body["key"] == "WHISPER_MODEL"


def test_settings_put_bad_int_422(dev_app):
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
# routes_stats — GET /api/stats
# ---------------------------------------------------------------------------


def test_stats_running_snapshot(dev_app):
    stats = Stats()
    stats.on_runner_start()
    meta1 = {"conversionId": "c_1", "sourceFormat": "mp3", "targetFormat": "txt"}
    stats.job_started(meta1)
    stats.job_finished(meta1, ok=True, error=None, elapsed_ms=900)
    meta2 = {"conversionId": "c_2", "sourceFormat": "mp3", "targetFormat": "srt"}
    stats.job_started(meta2)                    # in-flight → currentJob present

    with TestClient(dev_app) as client:
        client.app.state.stats = stats
        body = client.get("/api/stats").json()

    assert body["pullEnabled"] is True
    assert body["state"] == "running"
    assert body["processed"] == 1
    assert body["success"] == 1
    assert body["failed"] == 0
    assert body["latencyMs"]["last"] == 900
    assert body["currentJob"]["conversionId"] == "c_2"
    assert body["currentJob"]["targetFormat"] == "srt"


def test_stats_disabled_snapshot(dev_app):
    with TestClient(dev_app) as client:
        client.app.state.stats = Stats()        # never started → stopped
        body = client.get("/api/stats").json()
    assert body == {"pullEnabled": False, "state": "stopped"}


# ---------------------------------------------------------------------------
# pull_runner / worker refactor — _poll_cycle updates Stats
# ---------------------------------------------------------------------------

_JOB_META = {
    "jobId": "j-1",
    "conversionId": "c_1",
    "sourceFormat": "mp3",
    "targetFormat": "txt",
}


async def test_poll_cycle_updates_stats_on_success(tmp_path):
    stats = Stats()
    stats.on_runner_start()
    fake_api = MagicMock()
    fake_api.claim = AsyncMock(return_value=dict(_JOB_META))

    with patch("workers.ai.worker.PullApiClient", return_value=fake_api), \
         patch("workers.ai.worker._process_job", AsyncMock(return_value=JobOutcome(ok=True))):
        handled = await _poll_cycle(AsyncMock(), _cfg(tmp_path), "consumer-x", stats)

    assert handled is True
    assert stats.processed == 1
    assert stats.success == 1
    assert stats.failed == 0
    assert stats.current_job is None            # cleared in job_finished
    assert stats.last_latency_ms is not None


async def test_poll_cycle_updates_stats_on_failure(tmp_path):
    stats = Stats()
    stats.on_runner_start()
    fake_api = MagicMock()
    fake_api.claim = AsyncMock(return_value=dict(_JOB_META))
    outcome = JobOutcome(ok=False, error="whisper boom")

    with patch("workers.ai.worker.PullApiClient", return_value=fake_api), \
         patch("workers.ai.worker._process_job", AsyncMock(return_value=outcome)):
        handled = await _poll_cycle(AsyncMock(), _cfg(tmp_path), "consumer-x", stats)

    assert handled is True
    assert stats.processed == 1
    assert stats.success == 0
    assert stats.failed == 1
    assert stats.last_errors[0]["error"] == "whisper boom"
    assert stats.last_errors[0]["conversionId"] == "c_1"


async def test_poll_cycle_empty_queue_no_stats_change(tmp_path):
    """No job claimed → handled False, counters untouched."""
    stats = Stats()
    stats.on_runner_start()
    fake_api = MagicMock()
    fake_api.claim = AsyncMock(return_value=None)

    with patch("workers.ai.worker.PullApiClient", return_value=fake_api):
        handled = await _poll_cycle(AsyncMock(), _cfg(tmp_path), "consumer-x", stats)

    assert handled is False
    assert stats.processed == 0
