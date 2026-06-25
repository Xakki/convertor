"""Speech-to-Text — LOCAL faster-whisper only.

No external-API providers (OpenAI/Gemini/Claude removed in ai-worker-refactor-core).
`faster_whisper` is imported lazily inside the worker thread so importing this module
stays cheap and dependency-free.
"""

from __future__ import annotations

import asyncio
from pathlib import Path

from workers.ai.utils import segments_to_text


class SpeechToTextProvider:
    def __init__(
        self,
        model_name: str,
        device: str,
        compute_type: str,
    ) -> None:
        self.model_name = model_name
        self.device = device
        self.compute_type = compute_type

    async def transcribe(self, src: Path, output_format: str) -> str:
        def run() -> str:
            from faster_whisper import WhisperModel

            model = WhisperModel(
                self.model_name,
                device=self.device,
                compute_type=self.compute_type,
            )
            segments, _ = model.transcribe(str(src), beam_size=5)
            return segments_to_text(list(segments), output_format)

        return await asyncio.to_thread(run)
