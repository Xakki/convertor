"""Audio stream tab — WS /ws/stream, dev-only live STT.

Wire format (FINALIZED by backend-dev, see card decision A):
  Browser captures mic with MediaRecorder → emits `audio/webm;codecs=opus` blobs.
  The client sends each blob as a binary frame; the SERVER ACCUMULATES all frames
  (the first carries the container header, so the running buffer is always a valid
  decodable file). On a cadence, and again on stop, the server writes the buffer to
  a temp file and runs faster-whisper, which decodes webm/opus via PyAV+ffmpeg.

  `StreamingWhisper.process_chunk(bytes)` only returns {"partial"} (no segments /
  language), so we use `process_file()` over the accumulated buffer to populate the
  contract's partial/final shape (text + segments + language). Partials are
  cumulative re-transcriptions — fine for a dev tester.

  The format is negotiated in the start handshake. `pcm_s16le` is also supported:
  raw 16-bit mono PCM frames are wrapped in a WAV container server-side (handshake
  `sampleRate`, default 16000) before transcription — no extra deps.

This route NEVER touches the backend pull-API.
"""

from __future__ import annotations

import asyncio
import io
import json
import logging
import os
import tempfile
import time
import wave
from pathlib import Path
from typing import Any

from fastapi import APIRouter, WebSocket
from starlette.websockets import WebSocketState

from workers.ai.worker import _safe_err

logger = logging.getLogger(__name__)
router = APIRouter()

_EXT_BY_FORMAT = {
    "webm/opus": ".webm",
    "webm": ".webm",
    "ogg/opus": ".ogg",
    "ogg": ".ogg",
    "wav": ".wav",
    "mp3": ".mp3",
    "m4a": ".m4a",
}


def _to_input_bytes(data: bytes, fmt: str, sample_rate: int) -> tuple[bytes, str]:
    """Map the accumulated buffer to a decodable file payload + extension."""
    if fmt.startswith("pcm"):
        buf = io.BytesIO()
        with wave.open(buf, "wb") as w:
            w.setnchannels(1)
            w.setsampwidth(2)  # s16le
            w.setframerate(sample_rate)
            w.writeframes(data)
        return buf.getvalue(), ".wav"
    return data, _EXT_BY_FORMAT.get(fmt, ".webm")


def _transcribe(model: Any, data: bytes, fmt: str, sample_rate: int) -> dict:
    payload, ext = _to_input_bytes(data, fmt, sample_rate)
    with tempfile.NamedTemporaryFile(suffix=ext, delete=False) as tmp:
        tmp.write(payload)
        path = Path(tmp.name)
    try:
        return model.process_file(path)
    finally:
        path.unlink(missing_ok=True)


@router.websocket("/ws/stream")
async def stream(ws: WebSocket) -> None:
    token = os.getenv("DEVSERVER_TOKEN")
    if token and ws.query_params.get("token") != token:
        await ws.close(code=1008)  # policy violation
        return

    await ws.accept()
    cfg = ws.app.state.cfg

    # Handshake (first message): {type:"start", format, sampleRate, lang}
    try:
        first = await ws.receive_json()
    except Exception:
        await ws.send_json({"type": "error", "message": "expected JSON start handshake"})
        await ws.close()
        return

    fmt = str(first.get("format") or "webm/opus").lower()
    try:
        sample_rate = int(first.get("sampleRate") or 16000)
    except (TypeError, ValueError):
        sample_rate = 16000

    # Build the model in a worker thread (heavy import + load).
    try:
        from workers.ai.providers.streaming_stt import StreamingWhisper

        model = await asyncio.to_thread(
            StreamingWhisper,
            cfg.whisper_model,
            cfg.whisper_device,
            cfg.whisper_compute_type,
            cfg.stream_window_sec,
            cfg.stream_overlap_sec,
        )
    except Exception as exc:  # noqa: BLE001 — model/load errors are reported, not raised
        logger.warning("stream model load failed: %s", _safe_err(exc))
        await ws.send_json({"type": "error", "message": f"model load failed: {_safe_err(exc)}"})
        await ws.close()
        return

    buffer = bytearray()
    window = max(2, int(cfg.stream_window_sec or 20))
    last_emit = 0.0

    async def emit(is_final: bool) -> None:
        if not buffer:
            if is_final:
                await ws.send_json({"type": "final", "text": "", "segments": [], "language": None})
            return
        try:
            result = await asyncio.to_thread(_transcribe, model, bytes(buffer), fmt, sample_rate)
        except Exception as exc:  # noqa: BLE001 — transcription failure → error frame, keep socket
            await ws.send_json({"type": "error", "message": _safe_err(exc)})
            return
        await ws.send_json({
            "type": "final" if is_final else "partial",
            "text": result.get("final", ""),
            "segments": result.get("segments", []),
            "language": result.get("language"),
        })

    try:
        while True:
            msg = await ws.receive()
            if msg.get("type") == "websocket.disconnect":
                break

            data = msg.get("bytes")
            if data is not None:
                buffer += data
                now = time.monotonic()
                if (now - last_emit) >= window:
                    last_emit = now
                    await emit(is_final=False)
                continue

            text = msg.get("text")
            if text is not None:
                try:
                    payload = json.loads(text)
                except (json.JSONDecodeError, TypeError):
                    continue
                if payload.get("type") == "stop":
                    await emit(is_final=True)
                    break
    except Exception as exc:  # noqa: BLE001 — never let a stream bug crash the server
        logger.warning("ws stream error: %s", _safe_err(exc))
        try:
            await ws.send_json({"type": "error", "message": _safe_err(exc)})
        except Exception:
            pass
    finally:
        if ws.client_state != WebSocketState.DISCONNECTED:
            await ws.close()
