"""Provider interfaces for the AI worker (local inference only).

Thin Protocols documenting the contract each local provider satisfies. They are
runtime-checkable but kept dependency-free so importing this module never pulls a
heavy ML library.
"""

from __future__ import annotations

from pathlib import Path
from typing import Protocol, runtime_checkable


@runtime_checkable
class SttProvider(Protocol):
    async def transcribe(self, src: Path, output_format: str) -> str:
        """Transcribe audio at `src` into text in `output_format` (txt|srt|vtt)."""
        ...


@runtime_checkable
class TtsProvider(Protocol):
    async def synthesize(self, text: str, output_format: str, out_path: Path) -> None:
        """Synthesize `text` to audio written at `out_path` in `output_format`."""
        ...
