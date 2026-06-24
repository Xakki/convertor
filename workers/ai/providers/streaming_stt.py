from __future__ import annotations

import tempfile
from pathlib import Path

from faster_whisper import WhisperModel


class StreamingWhisper:

    def __init__(
        self,
        model_name: str,
        device: str,
        compute_type: str,
        window_sec: int = 20,
        overlap_sec: int = 2,
    ) -> None:

        self.model = WhisperModel(
            model_name,
            device=device,
            compute_type=compute_type,
        )

        self.window_sec = window_sec
        self.overlap_sec = overlap_sec

        self.partial_text: list[str] = []

    def process_file(
        self,
        audio_path: Path,
    ) -> dict:

        segments, info = self.model.transcribe(
            str(audio_path),
            beam_size=5,
        )

        result = []

        for seg in segments:
            result.append(
                {
                    "start": seg.start,
                    "end": seg.end,
                    "text": seg.text.strip(),
                }
            )

        final_text = " ".join(
            s["text"]
            for s in result
        )

        return {
            "partial": final_text,
            "final": final_text,
            "segments": result,
            "language": info.language,
        }

    def process_chunk(
        self,
        chunk_bytes: bytes,
    ) -> dict:

        with tempfile.NamedTemporaryFile(
            suffix=".wav",
            delete=False,
        ) as tmp:

            tmp.write(chunk_bytes)

            tmp_path = Path(tmp.name)

        try:

            segments, _ = self.model.transcribe(
                str(tmp_path),
                beam_size=5,
            )

            text = " ".join(
                s.text.strip()
                for s in segments
            )

            self.partial_text.append(text)

            return {
                "partial": " ".join(self.partial_text),
            }

        finally:
            tmp_path.unlink(missing_ok=True)
