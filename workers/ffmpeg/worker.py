"""FFmpeg audio/video conversion worker."""

import asyncio
import logging
from pathlib import Path
from typing import Any

from workers.common.base_worker import SHARE_DIR, BaseWorker
from workers.common.safe_path import safe_share_path

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

# Timeout by output category (seconds)
_AUDIO_FORMATS: set[str] = {"mp3", "wav", "ogg", "flac", "aac", "m4a", "opus", "wma"}
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
    # video → video
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


class FfmpegWorker(BaseWorker):
    """Queue worker for audio and video format conversions via FFmpeg."""

    queue_name = "convertor:media"

    async def process_task(self, task: dict[str, Any]) -> dict[str, Any]:
        input_path_raw: str = task["input_path"]
        output_format: str = task["output_format"].lower().lstrip(".")

        src = safe_share_path(input_path_raw, SHARE_DIR)
        if not src.is_file():
            raise FileNotFoundError(f"input file not found: {src}")

        in_format = src.suffix.lower().lstrip(".")
        if in_format not in SUPPORTED:
            raise ValueError(f"unsupported input format: {in_format}")
        if output_format not in SUPPORTED[in_format]:
            raise ValueError(f"unsupported conversion: {in_format} -> {output_format}")

        out_path = src.with_suffix(f".{output_format}")
        timeout = _VIDEO_TIMEOUT if output_format in _VIDEO_FORMATS else _AUDIO_TIMEOUT

        await run_ffmpeg(src, out_path, timeout)

        if not out_path.exists():
            raise RuntimeError("ffmpeg produced no output file")

        logger.info(
            "converted %s -> %s (task id=%s)", src.name, out_path.name, task.get("id")
        )
        return {"status": "ok", "output_path": str(out_path)}


if __name__ == "__main__":
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    worker = FfmpegWorker()
    worker.run()
