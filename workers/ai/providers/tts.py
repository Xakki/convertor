"""Text-to-Speech — LOCAL espeak-ng / pyttsx3 only.

No external-API providers (OpenAI removed in ai-worker-refactor-core). `pyttsx3` is
imported lazily so importing this module stays dependency-free; espeak-ng/ffmpeg are
external binaries invoked via subprocess.
"""

from __future__ import annotations

import asyncio
import subprocess
import tempfile
from pathlib import Path


async def espeak(text: str, output_format: str, out_path: Path) -> None:
    """Synthesize `text` with espeak-ng; transcode to `output_format` via ffmpeg if not wav."""
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
        wav_path = Path(tmp.name)
    try:
        proc = await asyncio.create_subprocess_exec(
            "espeak-ng",
            "--stdin",
            "--stdout",
            stdin=asyncio.subprocess.PIPE,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        audio, err = await proc.communicate(text.encode("utf-8"))
        if proc.returncode:
            raise RuntimeError(err.decode())

        wav_path.write_bytes(audio)

        if output_format == "wav":
            out_path.write_bytes(wav_path.read_bytes())
        else:
            ffmpeg = await asyncio.create_subprocess_exec(
                "ffmpeg", "-i", str(wav_path), "-y", str(out_path)
            )
            await ffmpeg.wait()
    finally:
        wav_path.unlink(missing_ok=True)


def _pyttsx3_sync(text: str, output_format: str, out_path: Path) -> None:
    import pyttsx3

    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
        wav_path = Path(tmp.name)
    try:
        engine = pyttsx3.init()
        engine.save_to_file(text, str(wav_path))
        engine.runAndWait()

        if output_format == "wav":
            out_path.write_bytes(wav_path.read_bytes())
        else:
            subprocess.run(
                ["ffmpeg", "-i", str(wav_path), "-y", str(out_path)],
                check=True,
            )
    finally:
        wav_path.unlink(missing_ok=True)


class TextToSpeechProvider:
    def __init__(self, engine: str = "espeak") -> None:
        self.engine = engine

    async def synthesize(self, text: str, output_format: str, out_path: Path) -> None:
        if self.engine == "pyttsx3":
            await asyncio.to_thread(_pyttsx3_sync, text, output_format, out_path)
            return
        await espeak(text, output_format, out_path)
