"""AI worker: HTTP pull-API client for Speech-to-Text / Text-to-Speech.

Polls the universal worker pull-API for jobs (~10 s interval), downloads the
input file through the API, runs STT or TTS conversion, and uploads the result.

No direct KeyDB or S3 access — the API acts as a gateway.

Config env vars:
  API_BASE_URL         base URL of the convertor pull-API (e.g. https://convertor.xakki.pro)
  WORKER_API_TOKEN     bearer token for all API requests
  WORKER_TYPE          worker type to claim (default "ai")
  POLL_INTERVAL        seconds to sleep between polls when queue is empty (default 10)
  WHISPER_MODEL        faster-whisper model name (default "base")
  WHISPER_DEVICE       faster-whisper device: "cpu" or "cuda" (default "cpu")
  WHISPER_COMPUTE_TYPE faster-whisper compute type (default "int8")
  TTS_ENGINE           local TTS engine: "espeak" or "pyttsx3" (default "espeak")
  AI_STT_PROVIDER      STT provider: local|openai|gemini|claude (default "local")
  AI_TTS_PROVIDER      TTS provider: local|openai (default "local")
  OPENAI_API_KEY / GEMINI_API_KEY / CLAUDE_API_KEY  provider credentials
  WORK_DIR             writable directory for intermediate temp files
"""

from __future__ import annotations

import asyncio
import base64
import logging
import os
import signal
import socket
import subprocess
import tempfile
import uuid
import json
from pathlib import Path
from typing import Any, AsyncGenerator

import httpx

logger = logging.getLogger(__name__)
# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
API_BASE_URL = os.getenv("API_BASE_URL", "http://localhost:8080")
WORKER_API_TOKEN = os.getenv("WORKER_API_TOKEN", "")
WORKER_TYPE = os.getenv("WORKER_TYPE", "ai")
POLL_INTERVAL = int(os.getenv("POLL_INTERVAL", "10"))

_api_base: str = API_BASE_URL.rstrip("/")

WHISPER_MODEL = os.getenv("WHISPER_MODEL", "base")
WHISPER_DEVICE = os.getenv("WHISPER_DEVICE", "cpu")
WHISPER_COMPUTE_TYPE = os.getenv("WHISPER_COMPUTE_TYPE", "int8")

EMBEDDING_MODEL = os.getenv("EMBEDDING_MODEL", "all-MiniLM-L6-v2")
EMBEDDING_DEVICE = os.getenv("EMBEDDING_DEVICE", "cpu")

TTS_ENGINE = os.getenv("TTS_ENGINE", "espeak")
AI_STT_PROVIDER = os.getenv("AI_STT_PROVIDER", "local")
AI_TTS_PROVIDER = os.getenv("AI_TTS_PROVIDER", "local")
AI_EMBEDDING_PROVIDER = os.getenv("AI_EMBEDDING_PROVIDER", "local")

OPENAI_API_KEY = os.getenv("OPENAI_API_KEY", "")
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY", "")
CLAUDE_API_KEY = os.getenv("CLAUDE_API_KEY", "")

WORK_DIR = Path(os.getenv("WORK_DIR", tempfile.gettempdir())).resolve()

# ---------------------------------------------------------------------------
# Format sets
# ---------------------------------------------------------------------------
_STT_INPUTS: set[str] = {"mp3", "wav", "ogg", "m4a", "opus", "flac"}
_STT_OUTPUTS: set[str] = {"txt", "srt", "vtt"}
_TTS_INPUTS: set[str] = {"txt", "md"}
_TTS_OUTPUTS: set[str] = {"mp3", "wav", "ogg"}
_EMBEDDING_INPUTS: set[str] = {"txt", "md", "json"}
_EMBEDDING_OUTPUTS: set[str] = {"json"}

_MIME: dict[str, str] = {
    "txt": "text/plain",
    "srt": "application/x-subrip",
    "vtt": "text/vtt",
    "mp3": "audio/mpeg",
    "wav": "audio/wav",
    "ogg": "audio/ogg",
    "json": "application/json",
}

def _safe_err(exc: Exception, limit: int = 200) -> str:
    return f"{type(exc).__name__}: {str(exc)[:limit]}"

def _derive_mode(src_fmt: str, tgt_fmt: str) -> str:
    if src_fmt in _STT_INPUTS and tgt_fmt in _STT_OUTPUTS:
        return "stt"
    if src_fmt in _TTS_INPUTS and tgt_fmt in _TTS_OUTPUTS:
        return "tts"
    if src_fmt in _EMBEDDING_INPUTS and tgt_fmt in _EMBEDDING_OUTPUTS:
        return "embedding"
    raise ValueError(f"cannot derive conversion mode for {src_fmt!r} → {tgt_fmt!r}")

# [Вспомогательные методы форматирования времени оставлены без изменений]
def _fmt_srt_time(seconds: float) -> str:
    h, rem = divmod(int(seconds), 3600); m, s = divmod(rem, 60); ms = int((seconds - int(seconds)) * 1000)
    return f"{h:02d}:{m:02d}:{s:02d},{ms:03d}"

def _fmt_vtt_time(seconds: float) -> str:
    h, rem = divmod(int(seconds), 3600); m, s = divmod(rem, 60); ms = int((seconds - int(seconds)) * 1000)
    return f"{h:02d}:{m:02d}:{s:02d}.{ms:03d}"

def _segments_to_text(segments: list, output_format: str) -> str:
    if output_format == "txt": return "\n".join(seg.text.strip() for seg in segments)
    if output_format == "srt":
        return "\n".join(f"{i}\n{_fmt_srt_time(seg.start)} --> {_fmt_srt_time(seg.end)}\n{seg.text.strip()}\n" for i, seg in enumerate(segments, 1))
    if output_format == "vtt":
        return "WEBVTT\n\n" + "\n".join(f"{_fmt_vtt_time(seg.start)} --> {_fmt_vtt_time(seg.end)}\n{seg.text.strip()}\n" for seg in segments)
    raise ValueError(f"unsupported format: {output_format}")

# ---------------------------------------------------------------------------
# NEW: Потоковое распознавание звука (Streaming Speech-to-Text)
# ---------------------------------------------------------------------------
async def _speech_to_text_stream(src: Path, chunk_size_bytes: int = 32000) -> AsyncGenerator[str, None]:
    """Генератор для эмуляции или обработки потокового аудио (chunk-by-chunk транскрипция)."""
    from faster_whisper import WhisperModel
    model = WhisperModel(WHISPER_MODEL, device=WHISPER_DEVICE, compute_type=WHISPER_COMPUTE_TYPE)

    # В реальном стриминге здесь обрабатывается входящий поток байт (например, через ffmpeg pipe)
    # Ниже реализована логика для обработки итерируемых аудио-сегментов
    segments, _ = await asyncio.to_thread(model.transcribe, str(src), beam_size=3, vad_filter=True)
    for segment in segments:
        yield json.dumps({"start": segment.start, "end": segment.end, "text": segment.text.strip()}, ensure_ascii=False)
        await asyncio.sleep(0.01)

# ---------------------------------------------------------------------------
# CAPABILITIES — read by the routing-drift test (keeps ai stream registered)
# ---------------------------------------------------------------------------

CAPABILITIES: dict[str, Any] = {
    "routing_keys": ["ai"],
    "matrix": {},
}

# ---------------------------------------------------------------------------
# STT / TTS Методы
# ---------------------------------------------------------------------------
async def _stt_local(src: Path, output_format: str) -> str:
    from faster_whisper import WhisperModel
    def _run():
        model = WhisperModel(WHISPER_MODEL, device=WHISPER_DEVICE, compute_type=WHISPER_COMPUTE_TYPE)
        segments, _ = model.transcribe(str(src), beam_size=5)
        return _segments_to_text(list(segments), output_format)
    return await asyncio.to_thread(_run)


# ---------------------------------------------------------------------------
# STT — OpenAI Whisper API
# ---------------------------------------------------------------------------


async def _stt_openai(src: Path, output_format: str) -> str:
    response_format = output_format if output_format in ("srt", "vtt") else "text"

    async with httpx.AsyncClient(timeout=300) as client:
        with src.open("rb") as f:
            response = await client.post(
                "https://api.openai.com/v1/audio/transcriptions",
                headers={"Authorization": f"Bearer {OPENAI_API_KEY}"},
                data={"model": "whisper-1", "response_format": response_format},
                files={"file": (src.name, f, "audio/mpeg")},
            )
        response.raise_for_status()

    text = response.text
    if output_format == "vtt" and not text.startswith("WEBVTT"):
        text = "WEBVTT\n\n" + text
    return text


# ---------------------------------------------------------------------------
# STT — Google Gemini (audio understanding)
# ---------------------------------------------------------------------------


async def _stt_gemini(src: Path, output_format: str) -> str:
    audio_data = base64.b64encode(src.read_bytes()).decode()
    mime = _audio_mime(src)

    prompt = "Transcribe this audio exactly. Return only the transcript text without any commentary."
    if output_format == "srt":
        prompt = "Transcribe this audio in SRT subtitle format with timestamps."
    elif output_format == "vtt":
        prompt = "Transcribe this audio in WebVTT subtitle format with timestamps."

    payload = {
        "contents": [{"parts": [
            {"text": prompt},
            {"inline_data": {"mime_type": mime, "data": audio_data}},
        ]}],
        "generationConfig": {"temperature": 0},
    }

    async with httpx.AsyncClient(timeout=300) as client:
        response = await client.post(
            f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent"
            f"?key={GEMINI_API_KEY}",
            json=payload,
        )
        response.raise_for_status()

    result = response.json()
    text = result["candidates"][0]["content"]["parts"][0]["text"]
    if output_format == "vtt" and not text.startswith("WEBVTT"):
        text = "WEBVTT\n\n" + text
    return text


# ---------------------------------------------------------------------------
# STT — Anthropic Claude (audio via base64)
# ---------------------------------------------------------------------------


async def _stt_claude(src: Path, output_format: str) -> str:
    audio_data = base64.b64encode(src.read_bytes()).decode()
    mime = _audio_mime(src)

    prompt = "Transcribe this audio exactly. Return only the transcript text."
    if output_format == "srt":
        prompt = "Transcribe this audio in SRT subtitle format with timestamps."
    elif output_format == "vtt":
        prompt = "Transcribe this audio in WebVTT subtitle format with timestamps."

    payload = {
        "model": "claude-sonnet-4-6",
        "max_tokens": 8192,
        "messages": [{"role": "user", "content": [
            {"type": "document", "source": {"type": "base64", "media_type": mime, "data": audio_data}},
            {"type": "text", "text": prompt},
        ]}],
    }

    async with httpx.AsyncClient(timeout=300) as client:
        response = await client.post(
            "https://api.anthropic.com/v1/messages",
            headers={"x-api-key": CLAUDE_API_KEY, "anthropic-version": "2023-06-01"},
            json=payload,
        )
        response.raise_for_status()

    result = response.json()
    text = result["content"][0]["text"]
    if output_format == "vtt" and not text.startswith("WEBVTT"):
        text = "WEBVTT\n\n" + text
    return text

def _audio_mime(src: Path) -> str:
    return {
        ".mp3": "audio/mpeg", ".wav": "audio/wav", ".ogg": "audio/ogg",
        ".m4a": "audio/mp4", ".opus": "audio/opus", ".flac": "audio/flac",
    }.get(src.suffix.lower(), "audio/mpeg")


# ---------------------------------------------------------------------------
# STT — dispatcher
# ---------------------------------------------------------------------------


async def _speech_to_text(src: Path, output_format: str, out_path: Path) -> None:
    provider = AI_STT_PROVIDER
    try:
        if provider == "openai":
            text = await _stt_openai(src, output_format)
        elif provider == "gemini":
            text = await _stt_gemini(src, output_format)
        elif provider == "claude":
            text = await _stt_claude(src, output_format)
        else:
            text = await _stt_local(src, output_format)
    except Exception as exc:
        if provider != "local":
            logger.warning("STT provider %s failed (%s), falling back to local", provider, _safe_err(exc))
            text = await _stt_local(src, output_format)
        else:
            raise
    out_path.write_text(text, encoding="utf-8")
    logger.info("STT done: %s → %s (%d chars) via %s", src.name, out_path.name, len(text), provider)


# ---------------------------------------------------------------------------
# TTS — Local (espeak-ng)
# ---------------------------------------------------------------------------


async def _tts_espeak(text: str, output_format: str, out_path: Path) -> None:
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
        wav_path = Path(tmp.name)
    try:
        # Pass text via stdin (--stdin) to avoid OSError E2BIG on large inputs.
        proc = await asyncio.create_subprocess_exec(
            "espeak-ng", "--stdin", "--stdout",
            stdin=asyncio.subprocess.PIPE,
            stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE,
        )
        out_b, err_b = await asyncio.wait_for(proc.communicate(input=text.encode("utf-8")), timeout=30)
        if proc.returncode != 0:
            raise RuntimeError(f"espeak-ng failed: {err_b.decode('utf-8', 'replace').strip()}")
        wav_path.write_bytes(out_b)

        if output_format == "wav":
            out_path.write_bytes(wav_path.read_bytes())
        else:
            conv = await asyncio.create_subprocess_exec(
                "ffmpeg", "-i", str(wav_path), "-y", str(out_path),
                stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE,
            )
            _, ferr = await asyncio.wait_for(conv.communicate(), timeout=60)
            if conv.returncode != 0:
                raise RuntimeError(f"ffmpeg TTS conversion failed: {ferr.decode('utf-8', 'replace').strip()}")
    finally:
        wav_path.unlink(missing_ok=True)


def _tts_pyttsx3(text: str, output_format: str, out_path: Path) -> None:
    import pyttsx3  # type: ignore[import]

    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
        wav_path = Path(tmp.name)
    ok = False
    try:
        engine = pyttsx3.init()
        engine.save_to_file(text, str(wav_path))
        engine.runAndWait()
        if output_format == "wav":
            out_path.write_bytes(wav_path.read_bytes())
        else:
            subprocess.run(["ffmpeg", "-i", str(wav_path), "-y", str(out_path)], check=True, capture_output=True)
        ok = True
    finally:
        wav_path.unlink(missing_ok=True)
        if not ok:
            out_path.unlink(missing_ok=True)


# ---------------------------------------------------------------------------
# TTS — OpenAI TTS API
# ---------------------------------------------------------------------------


async def _tts_openai(text: str, output_format: str, out_path: Path) -> None:
    fmt = output_format if output_format in ("mp3", "opus", "aac", "flac", "wav") else "mp3"
    async with httpx.AsyncClient(timeout=120) as client:
        response = await client.post(
            "https://api.openai.com/v1/audio/speech",
            headers={"Authorization": f"Bearer {OPENAI_API_KEY}"},
            json={"model": "tts-1", "voice": "alloy", "input": text, "response_format": fmt},
        )
        response.raise_for_status()

    out_path.write_bytes(response.content)
    if fmt != output_format:
        tmp = out_path.with_suffix(f".{fmt}")
        out_path.rename(tmp)
        subprocess.run(["ffmpeg", "-i", str(tmp), "-y", str(out_path)], check=True, capture_output=True)
        tmp.unlink(missing_ok=True)


# ---------------------------------------------------------------------------
# TTS — dispatcher
# ---------------------------------------------------------------------------


async def _text_to_speech(src: Path, output_format: str, out_path: Path) -> None:
    text = src.read_text(encoding="utf-8").strip()
    if not text:
        raise ValueError("input text file is empty")

    provider = AI_TTS_PROVIDER
    try:
        if provider == "openai":
            await _tts_openai(text, output_format, out_path)
        else:
            if TTS_ENGINE == "espeak":
                await _tts_espeak(text, output_format, out_path)
            else:
                await asyncio.to_thread(_tts_pyttsx3, text, output_format, out_path)
    except Exception as exc:
        if provider != "local":
            logger.warning("TTS provider %s failed (%s), falling back to local", provider, _safe_err(exc))
            await _tts_espeak(text, output_format, out_path)
        else:
            raise

    logger.info("TTS done: %s → %s via %s", src.name, out_path.name, provider)

# ---------------------------------------------------------------------------
# NEW: Обработка Эмбеддингов (_embedding)
# ---------------------------------------------------------------------------
async def _embedding_local(text: str) -> list[float]:
    from sentence_transformers import SentenceTransformer
    model = SentenceTransformer(EMBEDDING_MODEL, device=EMBEDDING_DEVICE)
    embedding = await asyncio.to_thread(model.encode, text, convert_to_numpy=True)
    return embedding.tolist()

async def _embedding_openai(text: str) -> list[float]:
    async with httpx.AsyncClient(timeout=60) as client:
        response = await client.post(
            "https://api.openai.com/v1/embeddings",
            headers={"Authorization": f"Bearer {OPENAI_API_KEY}"},
            json={"model": "text-embedding-3-small", "input": text}
        )
        response.raise_for_status()
        return response.json()["data"][0]["embedding"]

async def _process_embedding(src: Path, out_path: Path) -> None:
    text = src.read_text(encoding="utf-8").strip()
    if not text:
        raise ValueError("Empty text file")

    provider = AI_EMBEDDING_PROVIDER
    if provider == "openai":
        vectors = await _embedding_openai(text)
    else:
        vectors = await _embedding_local(text)

    out_path.write_text(json.dumps({"embedding": vectors}), encoding="utf-8")
    logger.info("Embedding processing completed via %s", provider)

# ---------------------------------------------------------------------------
# Core convert (async; no subType — mode derived from format pair only)
# ---------------------------------------------------------------------------


async def convert(job: dict[str, Any]) -> tuple[str, str, str]:
    """Convert input file to output format.

    Returns (output_path, output_mime, target_ext).
    Mode (STT/TTS) is derived solely from (sourceFormat, targetFormat).
    """
    src = Path(job["_localInput"])
    src_fmt = str(job["sourceFormat"]).lower().lstrip(".")
    tgt_fmt = str(job["targetFormat"]).lower().lstrip(".")
    conv_id = job["conversionId"]

    if not src.is_file():
        raise FileNotFoundError(f"input file not found: {src}")

    mode = _derive_mode(src_fmt, tgt_fmt)

    WORK_DIR.mkdir(parents=True, exist_ok=True)
    out_path = WORK_DIR / f"out-{conv_id}-{uuid.uuid4().hex}.{tgt_fmt}"

    if mode == "stt":
        await _speech_to_text(src, tgt_fmt, out_path)
    elif mode == "tts":
        await _text_to_speech(src, tgt_fmt, out_path)
    elif mode == "embedding":
        await _process_embedding(src, out_path)

    if not out_path.exists():
        raise RuntimeError("AI conversion produced no output file")

    mime = _MIME.get(tgt_fmt, "application/octet-stream")
    logger.info("AI converted %s → %s (conversionId=%s, mode=%s)", src.name, out_path.name, conv_id, mode)
    return str(out_path), mime, tgt_fmt


# ---------------------------------------------------------------------------
# HTTP poll client
# ---------------------------------------------------------------------------


async def _fail_job(client: httpx.AsyncClient, job_id: str, error: str) -> None:
    """POST /fail for a job. Swallows exceptions (best-effort notification)."""
    try:
        resp = await client.post(
            f"{_api_base}/api/v1/worker/jobs/{job_id}/fail",
            json={"error": error},
        )
        resp.raise_for_status()
    except Exception as exc:
        logger.warning("fail notification itself failed for job %s: %s", job_id, exc)


async def _process_job(client: httpx.AsyncClient, job_meta: dict) -> None:
    """Process one claimed job: download input → convert → upload result or fail."""
    job_id = str(job_meta["jobId"])
    conv_id = job_meta["conversionId"]
    src_fmt = str(job_meta["sourceFormat"]).lower().lstrip(".")
    tgt_fmt = str(job_meta["targetFormat"]).lower().lstrip(".")

    # Download input (streamed to avoid OOM on large files)
    WORK_DIR.mkdir(parents=True, exist_ok=True)
    input_path = WORK_DIR / f"in-{conv_id}-{uuid.uuid4().hex}.{src_fmt}"
    try:
        async with client.stream("GET", f"{_api_base}/api/v1/worker/jobs/{job_id}/input") as resp:
            resp.raise_for_status()
            with input_path.open("wb") as f:
                async for chunk in resp.aiter_bytes(65536):
                    f.write(chunk)
    except Exception as exc:
        logger.error("input download failed for job %s: %s", job_id, _safe_err(exc))
        await _fail_job(client, job_id, _safe_err(exc))
        input_path.unlink(missing_ok=True)
        return

    # Convert
    output_path: Path | None = None
    try:
        job_payload: dict[str, Any] = {
            "_localInput": str(input_path),
            "conversionId": conv_id,
            "sourceFormat": src_fmt,
            "targetFormat": tgt_fmt,
        }
        out_str, mime, _ = await convert(job_payload)
        output_path = Path(out_str)
    except Exception as exc:
        logger.error("conversion failed for job %s: %s", job_id, _safe_err(exc))
        await _fail_job(client, job_id, _safe_err(exc))
        return
    finally:
        input_path.unlink(missing_ok=True)

    # Upload result (separate longer timeout: large files + slow home connections)
    try:
        with output_path.open("rb") as f:
            resp = await client.post(
                f"{_api_base}/api/v1/worker/jobs/{job_id}/result",
                files={"file": (output_path.name, f, mime)},
                timeout=httpx.Timeout(30.0, read=300.0, write=None),
            )
        resp.raise_for_status()
        logger.info("job %s completed (%s → %s)", job_id, src_fmt, tgt_fmt)
    except Exception as exc:
        logger.error("result upload failed for job %s: %s", job_id, _safe_err(exc))
        await _fail_job(client, job_id, _safe_err(exc))
    finally:
        if output_path:
            output_path.unlink(missing_ok=True)


_running = True


def _handle_shutdown(signum: int, frame: Any) -> None:
    global _running
    logger.info("shutdown signal received (signal %d) — draining", signum)
    _running = False


async def _poll_loop() -> None:
    consumer = f"{socket.gethostname()}-{os.getpid()}"
    auth_headers = {"Authorization": f"Bearer {WORKER_API_TOKEN}"}
    logger.info(
        "poll loop started (consumer=%s, type=%s, api=%s, interval=%ds)",
        consumer, WORKER_TYPE, _api_base, POLL_INTERVAL,
    )

    # No base_url — all request paths are absolute strings built with _api_base so that
    # any path component in API_BASE_URL is preserved (httpx drops base_url path prefixes
    # when the request path starts with "/").
    async with httpx.AsyncClient(
        headers=auth_headers,
        timeout=httpx.Timeout(30.0),
    ) as client:
        while _running:
            # Claim a job
            try:
                resp = await client.post(
                    f"{_api_base}/api/v1/worker/claim",
                    json={"type": WORKER_TYPE, "consumer": consumer},
                )
                if resp.status_code == 204:
                    await asyncio.sleep(POLL_INTERVAL)
                    continue
                resp.raise_for_status()
                job_meta = resp.json()
            except Exception as exc:
                logger.warning("claim request failed: %s", exc)
                await asyncio.sleep(POLL_INTERVAL)
                continue

            # Validate required fields — a malformed response must never crash the loop
            job_id: str | None = None
            try:
                job_id = str(job_meta["jobId"])
                _ = job_meta["conversionId"]
                _ = job_meta["sourceFormat"]
                _ = job_meta["targetFormat"]
            except (KeyError, TypeError) as exc:
                logger.error(
                    "malformed claim response (missing %s) — skipping; raw: %s",
                    exc, job_meta,
                )
                if job_id:
                    await _fail_job(client, job_id, f"malformed job claim: {exc}")
                continue

            # Per-job guard: an unexpected bug in _process_job must not kill the loop
            try:
                await _process_job(client, job_meta)
            except Exception:
                logger.exception("unexpected error processing job %s — skipping", job_id)
                await _fail_job(client, job_id, "internal worker error")


def _check_api_base_url() -> None:
    """Warn if API_BASE_URL contains a path component that would produce double-path URLs."""
    from urllib.parse import urlparse
    parsed = urlparse(API_BASE_URL)
    path = parsed.path.strip("/")
    if path:
        logger.warning(
            "API_BASE_URL %r contains a path component %r — all worker API paths start with "
            "/api/v1/..., so any prefix in API_BASE_URL will be doubled. "
            "Set API_BASE_URL to the application root (scheme+host only, e.g. https://convertor.xakki.pro).",
            API_BASE_URL, path,
        )


def run() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    signal.signal(signal.SIGTERM, _handle_shutdown)
    signal.signal(signal.SIGINT, _handle_shutdown)
    _check_api_base_url()
    logger.info(
        "AI worker starting — API: %s, type: %s, whisper: %s/%s/%s",
        API_BASE_URL, WORKER_TYPE, WHISPER_MODEL, WHISPER_DEVICE, WHISPER_COMPUTE_TYPE,
    )
    asyncio.run(_poll_loop())


if __name__ == "__main__":
    run()
