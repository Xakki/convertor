"""Flag-agnostic conversion core.

The conversion MODE is derived from the (sourceFormat, targetFormat) pair ONLY.
The worker never reads `taskType`/`subType`/`ocr` or any other flag — behaviour is
a pure function of the format pair:

    audio → {txt, srt, vtt}   = batch STT (faster-whisper)
    audio → json              = streaming STT (faster-whisper, segment JSON)
    {txt, md} → {mp3,wav,ogg} = TTS (espeak-ng / pyttsx3)
    {txt, md} → json          = embedding (sentence-transformers)
    {txt, md} → {txt, md}     = LLM text→text (local Ollama / llama.cpp)

Any pair outside these → ValueError (the registry must not route it here).
"""

from __future__ import annotations

import asyncio
import json
import uuid
from enum import Enum
from pathlib import Path
from typing import Any

from workers.ai.config import Config
from workers.ai.utils import OUTPUT_MIME

# ---------------------------------------------------------------------------
# Format sets — the ONLY input to mode derivation
# ---------------------------------------------------------------------------

STT_INPUTS: set[str] = {"mp3", "wav", "ogg", "m4a", "opus", "flac"}
STT_OUTPUTS: set[str] = {"txt", "srt", "vtt"}
TTS_INPUTS: set[str] = {"txt", "md"}
TTS_OUTPUTS: set[str] = {"mp3", "wav", "ogg"}
# text family — both sides of the LLM text→text path: txt↔txt, txt↔md, md↔md.
LLM_INPUTS: set[str] = {"txt", "md"}
LLM_OUTPUTS: set[str] = {"txt", "md"}


class Mode(str, Enum):
    STT = "stt"
    STT_STREAM = "stt_stream"
    TTS = "tts"
    EMBEDDING = "embedding"
    LLM = "llm"


def derive_mode(src_fmt: str, tgt_fmt: str) -> Mode:
    """Derive the conversion Mode from a format pair only. Raises ValueError if underivable."""
    if src_fmt in STT_INPUTS and tgt_fmt in STT_OUTPUTS:
        return Mode.STT
    if src_fmt in STT_INPUTS and tgt_fmt == "json":
        return Mode.STT_STREAM
    if src_fmt in TTS_INPUTS and tgt_fmt in TTS_OUTPUTS:
        return Mode.TTS
    if src_fmt in TTS_INPUTS and tgt_fmt == "json":
        return Mode.EMBEDDING
    if src_fmt in LLM_INPUTS and tgt_fmt in LLM_OUTPUTS:
        return Mode.LLM
    raise ValueError(
        f"cannot derive conversion mode for {src_fmt!r} → {tgt_fmt!r}: "
        f"not STT ({sorted(STT_INPUTS)} → {sorted(STT_OUTPUTS)}|json), "
        f"nor TTS ({sorted(TTS_INPUTS)} → {sorted(TTS_OUTPUTS)}), "
        f"nor embedding ({sorted(TTS_INPUTS)} → json), "
        f"nor LLM ({sorted(LLM_INPUTS)} → {sorted(LLM_OUTPUTS)})"
    )


def _mime_for(tgt_fmt: str) -> str:
    return OUTPUT_MIME.get(tgt_fmt, "application/octet-stream")


async def convert(job: dict[str, Any], cfg: Config) -> tuple[str, str, str]:
    """Run one conversion derived purely from the format pair.

    Returns (output_path, mime, target_ext). Raises FileNotFoundError if the input
    is missing, ValueError on an underivable pair or empty TTS input.
    """
    src = Path(job["_localInput"])
    src_fmt = str(job["sourceFormat"]).lower().lstrip(".")
    tgt_fmt = str(job["targetFormat"]).lower().lstrip(".")
    conv_id = job["conversionId"]

    if not src.exists():
        raise FileNotFoundError(src)

    mode = derive_mode(src_fmt, tgt_fmt)

    cfg.work_dir.mkdir(parents=True, exist_ok=True)
    out_path = cfg.work_dir / f"out-{conv_id}-{uuid.uuid4().hex}.{tgt_fmt}"

    if mode is Mode.STT:
        from workers.ai.providers.stt import SpeechToTextProvider

        provider = SpeechToTextProvider(
            model_name=cfg.whisper_model,
            device=cfg.whisper_device,
            compute_type=cfg.whisper_compute_type,
        )
        text = await provider.transcribe(src, tgt_fmt)
        out_path.write_text(text, encoding="utf-8")

    elif mode is Mode.STT_STREAM:
        from workers.ai.providers.streaming_stt import StreamingWhisper

        def _run() -> dict:
            model = StreamingWhisper(
                model_name=cfg.whisper_model,
                device=cfg.whisper_device,
                compute_type=cfg.whisper_compute_type,
                window_sec=cfg.stream_window_sec,
                overlap_sec=cfg.stream_overlap_sec,
            )
            return model.process_file(src)

        result = await asyncio.to_thread(_run)
        out_path.write_text(
            json.dumps(result, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )

    elif mode is Mode.TTS:
        from workers.ai.providers.tts import TextToSpeechProvider

        text = src.read_text(encoding="utf-8")
        if not text.strip():
            raise ValueError("TTS source text is empty")
        provider = TextToSpeechProvider(engine=cfg.tts_engine)
        await provider.synthesize(text, tgt_fmt, out_path)

    elif mode is Mode.EMBEDDING:
        from workers.ai.providers.embedding import generate_embedding

        model_name = job.get("model") or cfg.embedding_model
        await asyncio.to_thread(
            generate_embedding,
            src,
            out_path,
            model_name,
            cfg.embedding_device,
        )

    elif mode is Mode.LLM:
        from workers.ai.providers.llm import make_llm_provider

        text = src.read_text(encoding="utf-8")
        if not text.strip():
            raise ValueError("LLM source text is empty")
        provider = make_llm_provider(cfg)
        result = await provider.generate(text)
        if not result.strip():
            raise ValueError("LLM produced empty output")
        out_path.write_text(result, encoding="utf-8")

    if not out_path.exists():
        raise RuntimeError("conversion produced no output")

    return str(out_path), _mime_for(tgt_fmt), tgt_fmt
