"""FFmpeg audio/video conversion worker — Phase 1, XREADGROUP-based.

Consumes streams conv.audio + conv.video (consumer group convertor). Reads the
local input the base class downloaded from S3 (job['_localInput']), runs ffmpeg
into a tmp output under WORK_DIR, and returns (out_path, mime, target_ext) for
the base class to upload to the results bucket.
"""

from __future__ import annotations

import asyncio
import logging
import uuid
from pathlib import Path
from typing import Any

from workers.common.stream_consumer import WORK_DIR, StreamConsumerBase

logger = logging.getLogger(__name__)

# Codec map for output format → ffmpeg codec name
CODEC_MAP: dict[str, str] = {
    "mp3":  "libmp3lame",
    "ogg":  "libvorbis",
    "opus": "libopus",
    "aac":  "aac",
    "flac": "flac",
    "wav":  "pcm_s16le",
    "m4a":  "aac",
    "wma":  "wmav2",
    # video codecs
    "mp4":  "libx264",
    "avi":  "mpeg4",
    "mkv":  "libx264",
    "mov":  "libx264",
    "webm": "libvpx-vp9",
}

# MIME types for output formats
_MIME: dict[str, str] = {
    "mp3":  "audio/mpeg",
    "wav":  "audio/wav",
    "ogg":  "audio/ogg",
    "flac": "audio/flac",
    "aac":  "audio/aac",
    "m4a":  "audio/mp4",
    "opus": "audio/opus",
    "wma":  "audio/x-ms-wma",
    "mp4":  "video/mp4",
    "avi":  "video/x-msvideo",
    "mkv":  "video/x-matroska",
    "mov":  "video/quicktime",
    "webm": "video/webm",
}

# Timeout by output category (seconds)
_AUDIO_FORMATS: set[str] = {"mp3", "wav", "ogg", "flac", "aac", "m4a", "opus"}
_VIDEO_FORMATS: set[str] = {"mp4", "avi", "mkv", "mov", "webm"}
_VIDEO_TIMEOUT = 600
_AUDIO_TIMEOUT = 120

# Supported input → output format matrix
SUPPORTED: dict[str, set[str]] = {
    # audio → audio
    "mp3":  _AUDIO_FORMATS,
    "wav":  _AUDIO_FORMATS,
    "ogg":  _AUDIO_FORMATS,
    "flac": _AUDIO_FORMATS,
    "aac":  _AUDIO_FORMATS,
    "m4a":  _AUDIO_FORMATS,
    "opus": _AUDIO_FORMATS,
    "wma":  _AUDIO_FORMATS,
    # video → video / video → audio
    # 3gp is input-only per ROADMAP (listed in the video input column as +3gp,
    # absent from the output column) — never a target, so no _MIME/CODEC entry.
    "3gp":  _VIDEO_FORMATS | {"mp3", "wav", "ogg", "flac"},
    "mp4":  _VIDEO_FORMATS | {"mp3", "wav", "ogg", "flac"},
    "avi":  _VIDEO_FORMATS | {"mp3", "wav", "ogg", "flac"},
    "mkv":  _VIDEO_FORMATS | {"mp3", "wav", "ogg", "flac"},
    "mov":  _VIDEO_FORMATS | {"mp3", "wav", "ogg", "flac"},
    "webm": _VIDEO_FORMATS | {"mp3", "wav", "ogg", "flac"},
    "flv":  _VIDEO_FORMATS | {"mp3", "wav", "ogg", "flac"},
    "wmv":  _VIDEO_FORMATS | {"mp3", "wav", "ogg", "flac"},
}


async def run_ffmpeg(
    src: Path,
    out_path: Path,
    timeout: int,
) -> None:
    """Run ffmpeg converting *src* to *out_path*, choosing codec by extension."""
    out_fmt = out_path.suffix.lower().lstrip(".")
    codec = CODEC_MAP.get(out_fmt)

    argv = ["ffmpeg", "-i", str(src), "-y"]
    if codec:
        # Determine whether we are extracting audio from video
        src_fmt = src.suffix.lower().lstrip(".")
        if src_fmt in _VIDEO_FORMATS and out_fmt in _AUDIO_FORMATS:
            argv += ["-vn"]  # strip video stream
        argv += ["-c:a" if out_fmt in _AUDIO_FORMATS else "-c:v", codec]

    argv.append(str(out_path))

    proc = await asyncio.create_subprocess_exec(
        *argv,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
    )
    try:
        out_b, err_b = await asyncio.wait_for(proc.communicate(), timeout=timeout)
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        raise RuntimeError(f"ffmpeg timed out after {timeout}s")

    if proc.returncode != 0:
        err = err_b.decode("utf-8", "replace").strip()
        raise RuntimeError(f"ffmpeg failed: {err}")

    logger.debug("ffmpeg stdout: %s", out_b.decode("utf-8", "replace"))


class FfmpegWorker(StreamConsumerBase):
    """Stream worker for audio and video format conversions via FFmpeg."""

    CAPABILITIES: dict[str, Any] = {
        "routing_keys": ["audio", "video"],
        "matrix": SUPPORTED,
    }

    def convert(self, job: dict[str, Any]) -> tuple[str, str, str]:
        conv_id: int = job["conversionId"]
        src = Path(job["_localInput"])
        target_fmt: str = job["targetFormat"].lower().lstrip(".")
        src_fmt = str(job["sourceFormat"]).lower().lstrip(".")

        if not src.is_file():
            raise FileNotFoundError(f"input file not found: {src}")

        if src_fmt not in SUPPORTED:
            raise ValueError(f"unsupported input format: {src_fmt}")
        if target_fmt not in SUPPORTED[src_fmt]:
            raise ValueError(f"unsupported conversion: {src_fmt} -> {target_fmt}")

        WORK_DIR.mkdir(parents=True, exist_ok=True)
        out_path = WORK_DIR / f"out-{conv_id}-{uuid.uuid4().hex}.{target_fmt}"
        timeout = _VIDEO_TIMEOUT if target_fmt in _VIDEO_FORMATS else _AUDIO_TIMEOUT

        asyncio.run(run_ffmpeg(src, out_path, timeout))

        if not out_path.exists():
            raise RuntimeError("ffmpeg produced no output file")

        mime = _MIME.get(target_fmt, "application/octet-stream")
        logger.info(
            "converted %s -> %s (conversionId=%s)", src.name, out_path.name, conv_id
        )
        return str(out_path), mime, target_fmt


if __name__ == "__main__":
    FfmpegWorker().run()
