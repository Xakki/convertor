"""AI worker: Speech-to-Text and Text-to-Speech via local or cloud providers.

Providers (set via AI_STT_PROVIDER / AI_TTS_PROVIDER env):
  local   — faster-whisper (STT) + espeak-ng/pyttsx3 (TTS), no API cost
  openai  — OpenAI Whisper API (STT) + OpenAI TTS API (TTS)
  gemini  — Google Gemini 2.0 Flash (STT via audio understanding)
  claude  — Anthropic Claude (STT via vision/audio, TTS not supported → fallback local)
"""

import asyncio
import base64
import logging
import os
import subprocess
import tempfile
from pathlib import Path
from typing import Any

from workers.common.base_worker import SHARE_DIR, BaseWorker
from workers.common.safe_path import safe_share_path

logger = logging.getLogger(__name__)

WHISPER_MODEL = os.getenv("WHISPER_MODEL", "base")
TTS_ENGINE = os.getenv("TTS_ENGINE", "espeak")

AI_STT_PROVIDER = os.getenv("AI_STT_PROVIDER", "local")
AI_TTS_PROVIDER = os.getenv("AI_TTS_PROVIDER", "local")

OPENAI_API_KEY = os.getenv("OPENAI_API_KEY", "")
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY", "")
CLAUDE_API_KEY = os.getenv("CLAUDE_API_KEY", "")

_STT_INPUTS: set[str] = {"mp3", "wav", "ogg", "m4a", "opus", "flac"}
_STT_OUTPUTS: set[str] = {"txt", "srt", "vtt"}
_TTS_INPUTS: set[str] = {"txt", "md"}
_TTS_OUTPUTS: set[str] = {"mp3", "wav", "ogg"}


# ---------------------------------------------------------------------------
# Time formatters
# ---------------------------------------------------------------------------

def _fmt_srt_time(seconds: float) -> str:
    h, rem = divmod(int(seconds), 3600)
    m, s = divmod(rem, 60)
    ms = int((seconds - int(seconds)) * 1000)
    return f"{h:02d}:{m:02d}:{s:02d},{ms:03d}"


def _fmt_vtt_time(seconds: float) -> str:
    h, rem = divmod(int(seconds), 3600)
    m, s = divmod(rem, 60)
    ms = int((seconds - int(seconds)) * 1000)
    return f"{h:02d}:{m:02d}:{s:02d}.{ms:03d}"


def _segments_to_text(segments: list, output_format: str) -> str:
    if output_format == "txt":
        return "\n".join(seg.text.strip() for seg in segments)
    if output_format == "srt":
        lines: list[str] = []
        for i, seg in enumerate(segments, 1):
            lines.append(f"{i}\n{_fmt_srt_time(seg.start)} --> {_fmt_srt_time(seg.end)}\n{seg.text.strip()}\n")
        return "\n".join(lines)
    if output_format == "vtt":
        lines = ["WEBVTT", ""]
        for seg in segments:
            lines.append(f"{_fmt_vtt_time(seg.start)} --> {_fmt_vtt_time(seg.end)}\n{seg.text.strip()}\n")
        return "\n".join(lines)
    raise ValueError(f"unsupported STT output format: {output_format}")


# ---------------------------------------------------------------------------
# STT — Local (faster-whisper)
# ---------------------------------------------------------------------------

async def _stt_local(src: Path, output_format: str) -> str:
    from faster_whisper import WhisperModel  # type: ignore[import]

    def _run() -> str:
        model = WhisperModel(WHISPER_MODEL, device="cpu", compute_type="int8")
        segments, _ = model.transcribe(str(src), beam_size=5)
        return _segments_to_text(list(segments), output_format)

    return await asyncio.to_thread(_run)


# ---------------------------------------------------------------------------
# STT — OpenAI Whisper API
# ---------------------------------------------------------------------------

async def _stt_openai(src: Path, output_format: str) -> str:
    import httpx  # type: ignore[import]

    # OpenAI transcriptions endpoint only returns txt/srt/vtt natively
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
    # wrap in VTT header if needed
    if output_format == "vtt" and not text.startswith("WEBVTT"):
        text = "WEBVTT\n\n" + text
    return text


# ---------------------------------------------------------------------------
# STT — Google Gemini (audio understanding)
# ---------------------------------------------------------------------------

async def _stt_gemini(src: Path, output_format: str) -> str:
    import httpx  # type: ignore[import]

    audio_data = base64.b64encode(src.read_bytes()).decode()
    mime = _audio_mime(src)

    prompt = "Transcribe this audio exactly. Return only the transcript text without any commentary."
    if output_format == "srt":
        prompt = "Transcribe this audio in SRT subtitle format with timestamps."
    elif output_format == "vtt":
        prompt = "Transcribe this audio in WebVTT subtitle format with timestamps."

    payload = {
        "contents": [{
            "parts": [
                {"text": prompt},
                {"inline_data": {"mime_type": mime, "data": audio_data}},
            ]
        }],
        "generationConfig": {"temperature": 0},
    }

    async with httpx.AsyncClient(timeout=300) as client:
        response = await client.post(
            f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={GEMINI_API_KEY}",
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
    import httpx  # type: ignore[import]

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
        "messages": [{
            "role": "user",
            "content": [
                {
                    "type": "document",
                    "source": {"type": "base64", "media_type": mime, "data": audio_data},
                },
                {"type": "text", "text": prompt},
            ],
        }],
    }

    async with httpx.AsyncClient(timeout=300) as client:
        response = await client.post(
            "https://api.anthropic.com/v1/messages",
            headers={
                "x-api-key": CLAUDE_API_KEY,
                "anthropic-version": "2023-06-01",
            },
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
            logger.warning("STT provider %s failed (%s), falling back to local", provider, exc)
            text = await _stt_local(src, output_format)
        else:
            raise

    out_path.write_text(text, encoding="utf-8")
    logger.info("STT done: %s -> %s (%d chars) via %s", src.name, out_path.name, len(text), provider)


# ---------------------------------------------------------------------------
# TTS — Local (espeak-ng)
# ---------------------------------------------------------------------------

async def _tts_espeak(text: str, output_format: str, out_path: Path) -> None:
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
        wav_path = Path(tmp.name)
    try:
        proc = await asyncio.create_subprocess_exec(
            "espeak-ng", "--stdout", text,
            stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE,
        )
        out_b, err_b = await asyncio.wait_for(proc.communicate(), timeout=30)
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
    try:
        engine = pyttsx3.init()
        engine.save_to_file(text, str(wav_path))
        engine.runAndWait()
        if output_format == "wav":
            out_path.write_bytes(wav_path.read_bytes())
        else:
            subprocess.run(["ffmpeg", "-i", str(wav_path), "-y", str(out_path)], check=True, capture_output=True)
    finally:
        wav_path.unlink(missing_ok=True)


# ---------------------------------------------------------------------------
# TTS — OpenAI TTS API
# ---------------------------------------------------------------------------

async def _tts_openai(text: str, output_format: str, out_path: Path) -> None:
    import httpx  # type: ignore[import]

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
            logger.warning("TTS provider %s failed (%s), falling back to local", provider, exc)
            await _tts_espeak(text, output_format, out_path)
        else:
            raise

    logger.info("TTS done: %s -> %s via %s", src.name, out_path.name, provider)


# ---------------------------------------------------------------------------
# Worker
# ---------------------------------------------------------------------------

class AiWorker(BaseWorker):
    queue_name = "convertor:ai"

    async def process_task(self, task: dict[str, Any]) -> dict[str, Any]:
        input_path_raw: str = task["input_path"]
        output_format: str = task["output_format"].lower().lstrip(".")
        sub_type: str = task.get("sub_type", "").lower()

        src = safe_share_path(input_path_raw, SHARE_DIR)
        if not src.is_file():
            raise FileNotFoundError(f"input file not found: {src}")

        in_format = src.suffix.lower().lstrip(".")

        if not sub_type:
            if in_format in _STT_INPUTS and output_format in _STT_OUTPUTS:
                sub_type = "stt"
            elif in_format in _TTS_INPUTS and output_format in _TTS_OUTPUTS:
                sub_type = "tts"
            else:
                raise ValueError(f"cannot auto-detect sub_type for {in_format} -> {output_format}")

        out_path = src.with_suffix(f".{output_format}")

        if sub_type == "stt":
            if in_format not in _STT_INPUTS:
                raise ValueError(f"unsupported STT input: {in_format}")
            if output_format not in _STT_OUTPUTS:
                raise ValueError(f"unsupported STT output: {output_format}")
            await _speech_to_text(src, output_format, out_path)

        elif sub_type == "tts":
            if in_format not in _TTS_INPUTS:
                raise ValueError(f"unsupported TTS input: {in_format}")
            if output_format not in _TTS_OUTPUTS:
                raise ValueError(f"unsupported TTS output: {output_format}")
            await _text_to_speech(src, output_format, out_path)

        else:
            raise ValueError(f"unknown sub_type: {sub_type!r}")

        if not out_path.exists():
            raise RuntimeError("AI conversion produced no output file")

        return {"status": "ok", "output_path": str(out_path)}


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")
    AiWorker().run()
