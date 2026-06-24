from __future__ import annotations

import asyncio
import os
import subprocess
import tempfile
from pathlib import Path

import httpx


OPENAI_API_KEY = os.getenv(
    "OPENAI_API_KEY",
    "",
)

TTS_ENGINE = os.getenv(
    "TTS_ENGINE",
    "espeak",
)

AI_TTS_PROVIDER = os.getenv(
    "AI_TTS_PROVIDER",
    "local",
)


class TextToSpeechProvider:

    async def synthesize(
        self,
        text: str,
        output_format: str,
        out_path: Path,
    ) -> None:

        if AI_TTS_PROVIDER == "openai":
            await self._openai(
                text,
                output_format,
                out_path,
            )
            return

        if TTS_ENGINE == "pyttsx3":

            await asyncio.to_thread(
                self._pyttsx3,
                text,
                output_format,
                out_path,
            )

            return

        await self._espeak(
            text,
            output_format,
            out_path,
        )

    async def _espeak(
        self,
        text: str,
        output_format: str,
        out_path: Path,
    ) -> None:

        with tempfile.NamedTemporaryFile(
            suffix=".wav",
            delete=False,
        ) as tmp:

            wav_path = Path(
                tmp.name
            )

        try:

            proc = await asyncio.create_subprocess_exec(
                "espeak-ng",
                "--stdin",
                "--stdout",
                stdin=asyncio.subprocess.PIPE,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
            )

            audio, err = await proc.communicate(
                text.encode(
                    "utf-8"
                )
            )

            if proc.returncode:
                raise RuntimeError(
                    err.decode()
                )

            wav_path.write_bytes(
                audio
            )

            if output_format == "wav":

                out_path.write_bytes(
                    wav_path.read_bytes()
                )

            else:

                ffmpeg = await asyncio.create_subprocess_exec(
                    "ffmpeg",
                    "-i",
                    str(wav_path),
                    "-y",
                    str(out_path),
                )

                await ffmpeg.wait()

        finally:
            wav_path.unlink(
                missing_ok=True
            )

    def _pyttsx3(
        self,
        text: str,
        output_format: str,
        out_path: Path,
    ):

        import pyttsx3

        with tempfile.NamedTemporaryFile(
            suffix=".wav",
            delete=False,
        ) as tmp:

            wav_path = Path(
                tmp.name
            )

        try:

            engine = pyttsx3.init()

            engine.save_to_file(
                text,
                str(wav_path),
            )

            engine.runAndWait()

            if output_format == "wav":

                out_path.write_bytes(
                    wav_path.read_bytes()
                )

            else:

                subprocess.run(
                    [
                        "ffmpeg",
                        "-i",
                        str(wav_path),
                        "-y",
                        str(out_path),
                    ],
                    check=True,
                )

        finally:
            wav_path.unlink(
                missing_ok=True
            )

    async def _openai(
        self,
        text: str,
        output_format: str,
        out_path: Path,
    ) -> None:

        async with httpx.AsyncClient(
            timeout=300
        ) as client:

            response = await client.post(
                "https://api.openai.com/v1/audio/speech",
                headers={
                    "Authorization":
                    f"Bearer {OPENAI_API_KEY}"
                },
                json={
                    "model": "tts-1",
                    "voice": "alloy",
                    "input": text,
                    "response_format":
                    output_format,
                },
            )

        response.raise_for_status()

        out_path.write_bytes(
            response.content
        )
