from __future__ import annotations
from pathlib import Path

OUTPUT_MIME = {
    "txt": "text/plain",
    "srt": "application/x-subrip",
    "vtt": "text/vtt",
    "json": "application/json",
    "mp3": "audio/mpeg",
    "wav": "audio/wav",
    "ogg": "audio/ogg",
}


def audio_mime(
    src: Path,
) -> str:

    return {
        ".mp3": "audio/mpeg",
        ".wav": "audio/wav",
        ".ogg": "audio/ogg",
        ".m4a": "audio/mp4",
        ".opus": "audio/opus",
        ".flac": "audio/flac",
    }.get(
        src.suffix.lower(),
        "audio/mpeg",
    )

def fmt_srt_time(seconds: float) -> str:
    h, rem = divmod(int(seconds), 3600)
    m, s = divmod(rem, 60)
    ms = int((seconds - int(seconds)) * 1000)

    return f"{h:02d}:{m:02d}:{s:02d},{ms:03d}"


def fmt_vtt_time(seconds: float) -> str:
    h, rem = divmod(int(seconds), 3600)
    m, s = divmod(rem, 60)
    ms = int((seconds - int(seconds)) * 1000)

    return f"{h:02d}:{m:02d}:{s:02d}.{ms:03d}"


def segments_to_text(
    segments: list,
    output_format: str,
) -> str:

    if output_format == "txt":
        return "\n".join(
            seg.text.strip()
            for seg in segments
        )

    if output_format == "srt":

        lines: list[str] = []

        for i, seg in enumerate(
            segments,
            1,
        ):
            lines.append(
                f"{i}\n"
                f"{fmt_srt_time(seg.start)} --> {fmt_srt_time(seg.end)}\n"
                f"{seg.text.strip()}\n"
            )

        return "\n".join(lines)

    if output_format == "vtt":

        lines = [
            "WEBVTT",
            "",
        ]

        for seg in segments:

            lines.append(
                f"{fmt_vtt_time(seg.start)} --> {fmt_vtt_time(seg.end)}\n"
                f"{seg.text.strip()}\n"
            )

        return "\n".join(lines)

    raise ValueError(
        f"unsupported format: {output_format}"
    )
