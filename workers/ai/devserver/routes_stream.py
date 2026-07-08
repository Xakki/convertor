"""Audio stream tab — WS /ws/stream, dev-only live STT.

Wire format (FINALIZED by backend-dev, see card decision A):
  Браузер захватывает микрофон через MediaRecorder → шлёт audio/webm;codecs=opus блобы.
  Клиент отправляет каждый блоб бинарным фреймом. Сервер пропускает PCM через VAD
  (webrtcvad), накапливает речевые сегменты и транскрибирует каждый завершённый сегмент
  через StreamingWhisper.transcribe_pcm(). Partial-фреймы несут накопленный текст всех
  сегментов сессии (UI заменяет .partial при каждом сообщении).

  pcm_s16le: сырой s16le 16 кГц моно — декодер не создаётся, байты идут напрямую в VAD.

Этот маршрут НИКОГДА не обращается к backend pull-API.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
from typing import TYPE_CHECKING, Any

from fastapi import APIRouter, WebSocket
from starlette.websockets import WebSocketState

from workers.ai.worker import _safe_err

if TYPE_CHECKING:
    from workers.ai.config import Config
    from workers.ai.devserver.pcm_decoder import PcmStreamDecoder
    from workers.ai.devserver.vad_chunker import VadChunker

logger = logging.getLogger(__name__)
router = APIRouter()


# --- фабрики (заменяются monkeypatch'ем в тестах) ---

def _new_pcm_decoder(sample_rate: int = 16000) -> "PcmStreamDecoder":
    from workers.ai.devserver.pcm_decoder import PcmStreamDecoder
    return PcmStreamDecoder(sample_rate=sample_rate)


def _new_vad_chunker(cfg: "Config") -> "VadChunker":
    from workers.ai.devserver.vad_chunker import VadChunker
    return VadChunker(
        aggressiveness=cfg.vad_aggressiveness,
        silence_frames=cfg.vad_silence_frames,
        max_segment_sec=cfg.stream_segment_max_sec,
        overlap_sec=cfg.stream_overlap_sec,
    )


def _allowed_origins() -> set[str]:
    """Same-origin allowlist для WS-хендшейка (anti-CSWSH).

    Браузер всегда шлёт Origin на WS connect — принимаем только доверенные origins.
    Localhost по умолчанию всегда. При экспозиции за nginx добавляй публичный origin
    через DEVSERVER_ALLOWED_ORIGINS (через запятую, напр. "https://host,http://host:8877").
    """
    port = os.getenv("DEVSERVER_PORT", "8877")
    origins: set[str] = set()
    for h in ("localhost", "127.0.0.1"):
        origins.add(f"http://{h}:{port}")
        origins.add(f"https://{h}:{port}")
    for extra in (os.getenv("DEVSERVER_ALLOWED_ORIGINS") or "").split(","):
        extra = extra.strip().rstrip("/")
        if extra:
            origins.add(extra)
    return origins


@router.websocket("/ws/stream")
async def stream(ws: WebSocket) -> None:
    # Anti-CSWSH: браузер шлёт Origin — отклонять чужие origins
    origin = ws.headers.get("origin")
    if origin is not None and origin not in _allowed_origins():
        await ws.close(code=1008)
        return

    token = os.getenv("DEVSERVER_TOKEN")
    if token and ws.query_params.get("token") != token:
        await ws.close(code=1008)
        return

    await ws.accept()
    cfg = ws.app.state.cfg

    # Хендшейк: {type:"start", format, sampleRate, lang}
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

    # Загрузить модель в worker-потоке (тяжёлый импорт + инициализация)
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
    except Exception as exc:  # noqa: BLE001
        logger.warning("stream model load failed: %s", _safe_err(exc))
        await ws.send_json({"type": "error", "message": f"model load failed: {_safe_err(exc)}"})
        await ws.close()
        return

    # VAD chunker — создаём сразу (fail-fast если webrtcvad не установлен)
    try:
        chunker = _new_vad_chunker(cfg)
    except Exception as exc:  # noqa: BLE001
        logger.warning("VAD chunker init failed: %s", _safe_err(exc))
        await ws.send_json({"type": "error", "message": f"VAD init failed: {_safe_err(exc)}"})
        await ws.close()
        return

    # Декодер — лениво (только при первом бинарном фрейме WebM/Opus)
    decoder: Any = None  # PcmStreamDecoder | None

    # Накопленные результаты всей сессии
    all_texts: list[str] = []
    all_segs: list[dict] = []
    last_language: str | None = None

    async def _transcribe_seg(pcm: bytes) -> None:
        """Транскрибировать один VAD-сегмент → отправить partial с накопленным текстом."""
        nonlocal last_language
        try:
            result = await asyncio.to_thread(model.transcribe_pcm, pcm)
        except Exception as exc:  # noqa: BLE001
            await ws.send_json({"type": "error", "message": _safe_err(exc)})
            return
        last_language = result.get("language")
        seg_text = result.get("final", "")
        all_texts.append(seg_text)
        all_segs.extend(result.get("segments", []))
        await ws.send_json({
            "type": "partial",
            "text": " ".join(all_texts),
            "segments": result.get("segments", []),
            "language": last_language,
        })

    try:
        while True:
            msg = await ws.receive()
            if msg.get("type") == "websocket.disconnect":
                break

            data = msg.get("bytes")
            if data is not None:
                # Декодируем аудио → PCM (или берём сырой PCM напрямую)
                if fmt.startswith("pcm"):
                    pcm = bytes(data)
                else:
                    if decoder is None:
                        decoder = _new_pcm_decoder(sample_rate)
                    decoder.feed(data)
                    pcm = decoder.drain()
                    # Fail-fast: фоновый decode-поток мог упасть уже сейчас —
                    # сообщаем на этом же тике, не копя пустые partial до stop.
                    dec_err = decoder.decode_error()
                    if dec_err is not None:
                        await ws.send_json({"type": "error", "message": _safe_err(dec_err)})
                        break

                # VAD-разбивка; каждый готовый сегмент → transcribe
                for seg in chunker.push(pcm):
                    await _transcribe_seg(seg)
                continue

            text = msg.get("text")
            if text is not None:
                try:
                    payload = json.loads(text)
                except (json.JSONDecodeError, TypeError):
                    continue
                if payload.get("type") == "stop":
                    # Дренировать декодер → хвост PCM в VAD
                    if decoder is not None:
                        tail_pcm = await asyncio.to_thread(decoder.close)
                        dec_err = decoder.decode_error()
                        decoder = None
                        if dec_err is not None:
                            await ws.send_json({"type": "error", "message": _safe_err(dec_err)})
                            break
                        for seg in chunker.push(tail_pcm):
                            await _transcribe_seg(seg)
                    # Дренировать VAD (незавершённый сегмент)
                    last_seg = chunker.flush()
                    if last_seg:
                        await _transcribe_seg(last_seg)
                    # Финальный результат
                    await ws.send_json({
                        "type": "final",
                        "text": " ".join(all_texts),
                        "segments": all_segs,
                        "language": last_language,
                    })
                    break
    except Exception as exc:  # noqa: BLE001 — не ронять сервер из-за ошибки потока
        logger.warning("ws stream error: %s", _safe_err(exc))
        try:
            await ws.send_json({"type": "error", "message": _safe_err(exc)})
        except Exception:
            pass
    finally:
        if decoder is not None:
            try:
                await asyncio.to_thread(decoder.close)
            except Exception:
                pass
        if ws.client_state != WebSocketState.DISCONNECTED:
            await ws.close()
