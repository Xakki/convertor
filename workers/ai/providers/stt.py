from __future__ import annotations

import asyncio
import base64
import os
from pathlib import Path

import httpx

from workers.ai.utils.mime import audio_mime
from workers.ai.utils.subtitles import segments_to_text

WHISPER_MODEL = os.getenv(
    "WHISPER_MODEL",
    "base",
)

WHISPER_DEVICE = os.getenv(
    "WHISPER_DEVICE",
    "cpu",
)

WHISPER_COMPUTE_TYPE = os.getenv(
    "WHISPER_COMPUTE_TYPE",
    "int8",
)

AI_STT_PROVIDER = os.getenv(
    "AI_STT_PROVIDER",
    "local",
)

OPENAI_API_KEY = os.getenv(
    "OPENAI_API_KEY",
    "",
)

GEMINI_API_KEY = os.getenv(
    "GEMINI_API_KEY",
    "",
)

CLAUDE_API_KEY = os.getenv(
    "CLAUDE_API_KEY",
    "",
)


class SpeechToTextProvider:

    async def transcribe(
        self,
        src: Path,
        output_format: str,
    ) -> str:

        provider = AI_STT_PROVIDER

        if provider == "openai":
            return await self._openai(
                src,
                output_format,
            )

        if provider == "gemini":
            return await self._gemini(
                src,
                output_format,
            )

        if provider == "claude":
            return await self._claude(
                src,
                output_format,
            )

        return await self._local(
            src,
            output_format,
        )

    async def _local(
        self,
        src: Path,
        output_format: str,
    ) -> str:

        from faster_whisper import WhisperModel

        def run():

            model = WhisperModel(
                WHISPER_MODEL,
                device=WHISPER_DEVICE,
                compute_type=WHISPER_COMPUTE_TYPE,
            )

            segments, _ = model.transcribe(
                str(src),
                beam_size=5,
            )

            return segments_to_text(
                list(segments),
                output_format,
            )

        return await asyncio.to_thread(
            run
        )

    async def _openai(
        self,
        src: Path,
        output_format: str,
    ) -> str:

        response_format = (
            output_format
            if output_format in ("srt", "vtt")
            else "text"
        )

        async with httpx.AsyncClient(
            timeout=300
        ) as client:

            with src.open("rb") as fp:

                response = await client.post(
                    "https://api.openai.com/v1/audio/transcriptions",
                    headers={
                        "Authorization":
                        f"Bearer {OPENAI_API_KEY}"
                    },
                    data={
                        "model": "whisper-1",
                        "response_format": response_format,
                    },
                    files={
                        "file": (
                            src.name,
                            fp,
                            "audio/mpeg",
                        )
                    },
                )

        response.raise_for_status()

        return response.text

    async def _gemini(
        self,
        src: Path,
        output_format: str,
    ) -> str:

        audio_data = base64.b64encode(
            src.read_bytes()
        ).decode()

        payload = {
            "contents": [{
                "parts": [
                    {
                        "text":
                        "Transcribe audio"
                    },
                    {
                        "inline_data": {
                            "mime_type":
                            audio_mime(src),
                            "data":
                            audio_data,
                        }
                    },
                ]
            }]
        }

        async with httpx.AsyncClient(
            timeout=300
        ) as client:

            response = await client.post(
                f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={GEMINI_API_KEY}",
                json=payload,
            )

        response.raise_for_status()

        data = response.json()

        return data["candidates"][0]["content"]["parts"][0]["text"]

    async def _claude(
        self,
        src: Path,
        output_format: str,
    ) -> str:

        audio_data = base64.b64encode(
            src.read_bytes()
        ).decode()

        payload = {
            "model": "claude-sonnet-4-6",
            "max_tokens": 8192,
            "messages": [{
                "role": "user",
                "content": [
                    {
                        "type": "document",
                        "source": {
                            "type": "base64",
                            "media_type":
                            audio_mime(src),
                            "data":
                            audio_data,
                        },
                    },
                    {
                        "type": "text",
                        "text":
                        "Transcribe audio",
                    },
                ],
            }],
        }

        async with httpx.AsyncClient(
            timeout=300
        ) as client:

            response = await client.post(
                "https://api.anthropic.com/v1/messages",
                headers={
                    "x-api-key":
                    CLAUDE_API_KEY,
                    "anthropic-version":
                    "2023-06-01",
                },
                json=payload,
            )

        response.raise_for_status()

        data = response.json()

        return data["content"][0]["text"]
