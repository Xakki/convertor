"""FFmpeg audio/video conversion worker — WS-транспорт (s1-10).

Транспорт: WS-клиент (StreamConsumerBase.run()).
Задача доставляется через process_job; job['_localInput'] уже заполнен WsClient'ом.
convert() запускает ffmpeg в WORK_DIR и возвращает (out_path, mime, target_ext).
"""

from __future__ import annotations

import asyncio
import logging
import uuid
from pathlib import Path
from typing import Any

from workers.common.stream_consumer import WORK_DIR, StreamConsumerBase
from workers.common.subprocess_runner import run_capture

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

# --- Media preset application (CNV-101) -------------------------------------
# job['options'] arrives already validated by the backend catalog (CNV-100,
# closed grammar). The worker still maps every value through its OWN
# whitelist table below before it can reach an argv element — a value is
# used only as a dict KEY, never interpolated into the command line — so no
# raw ffmpeg argument can ever pass through, and an unexpected value (schema
# drift) fails loudly as a permanent ValueError instead of being silently
# dropped or executed.

# Audio "quality" preset → concrete args, chosen per target codec family:
# lossy codecs (mp3/aac/m4a/ogg/opus) get a bitrate ladder (CBR-ish via
# -b:a, common practice, well inside each encoder's safe operating range —
# verified live, see the sample-rate handling below). Lossless targets
# (wav=pcm_s16le, flac) have NO bitrate knob at all — bit depth is fixed by
# the codec — so "quality" maps to sample rate instead: the only safe,
# measurable lever a lossless codec offers, and downsampling genuinely
# changes both fidelity and file size (not a no-op).
#
# "high" is 192k, not a rounder 256k/320k: verified live that libvorbis
# REFUSES to open its encoder ("encoder setup failed", non-fatal ffmpeg exit
# but zero output — i.e. a permanent job failure) above ~240kbps for a MONO
# source, which is common (phone-recorded video, extracted voice tracks).
# 192k stays safely below that ceiling for every target codec here while
# still giving a clearly higher bitrate than "medium".
_AUDIO_QUALITY_BITRATE: dict[str, str] = {
    "low":    "96k",
    "medium": "160k",
    "high":   "192k",
}
_AUDIO_QUALITY_SAMPLE_RATE: dict[str, str] = {
    "low":    "22050",
    "medium": "44100",
    "high":   "48000",
}
_LOSSLESS_AUDIO_TARGETS: set[str] = {"wav", "flac"}

# --- Sample rate handling for lossy targets ---------------------------------
# A source's own sample rate can silently prevent the requested bitrate from
# being honoured at all (mp3's legacy MPEG2/2.5 tables cap max bitrate by
# sample rate — verified live: a 22050 Hz source clamped a 256k request down
# to 160k with no error, "medium" and "high" became indistinguishable), so
# some codecs need `-ar` forced. But forcing it UNCONDITIONALLY punishes a
# source that already meets or exceeds a safe rate — e.g. a 48 kHz source
# picking `quality: high` must not come back downsampled to 44100; that is
# an unrequested fidelity loss on the tier that promises the opposite.
#
# mp3/aac/m4a: verified live to be safe at ANY source rate from 8 kHz up to
# 96 kHz (mp3 self-clamps to its own 48 kHz format ceiling with no error;
# aac accepts up to 96 kHz outright) — so these get a FLOOR, not a pin:
# `-ar` is forced only when the source is BELOW it, never above.
_LOSSY_FLOOR_SAMPLE_RATE: dict[str, str] = {
    "mp3": "44100",
    "aac": "44100",
    "m4a": "44100",
}

# ogg (libvorbis): NOT floor-safe — verified live that libvorbis refuses to
# open its encoder ("encoder setup failed") for mono content BOTH at a
# native 8 kHz source (any bitrate tier) AND at a native 96 kHz source (our
# "high" tier) — a real crash window on both ends, not just the low end a
# floor would fix. So ogg is PINNED to a rate proven safe across our whole
# bitrate ladder, same as before this floor/ceiling distinction existed.
_VORBIS_SAMPLE_RATE: str = "44100"

# opus: only accepts a fixed set of native rates (8k/12k/16k/24k/48k) — any
# other rate, including 44100, fails to open outright ("Specified sample
# rate 44100 is not supported"). Its bitstream is also nominally always
# clocked at 48kHz regardless of the chosen rate (RFC 6716), so pinning to
# the top of its own supported range is not a "downgrade" the way it would
# be for the other codecs — advisor-confirmed as a codec-mandated exception.
_OPUS_SAMPLE_RATE: str = "48000"


async def _probe_audio_sample_rate(src: Path) -> int | None:
    """Best-effort ffprobe of the source's own audio sample rate.

    None (probe failure — corrupt/unusual input, no audio stream, ffprobe
    error) makes the caller fall back to forcing the floor — never worse
    than forcing it unconditionally.
    """
    try:
        out_b, _ = await run_capture(
            [
                "ffprobe", "-v", "error", "-select_streams", "a:0",
                "-show_entries", "stream=sample_rate", "-of", "csv=p=0",
                str(src),
            ],
            10, full_error=False,
        )
        return int(out_b.decode("utf-8", "replace").strip())
    except Exception:
        return None


# Video "resolution" preset → `scale=-2:<height>`: `-2` lets ffmpeg pick an
# even-numbered width that preserves the source aspect ratio around the
# fixed target height. Even dimensions are required by libx264/libvpx-vp9
# (4:2:0 chroma subsampling needs even width/height).
_VIDEO_RESOLUTION_SCALE: dict[str, str] = {
    "480p":  "-2:480",
    "720p":  "-2:720",
    "1080p": "-2:1080",
}
# Video "fps" preset → output frame rate (ffmpeg duplicates/drops frames as
# needed to hit it).
_VIDEO_FPS: dict[str, str] = {"24": "24", "30": "30"}


async def _audio_quality_args(src: Path, out_fmt: str, quality: Any) -> list[str]:
    """Map the whitelisted `quality` preset to concrete ffmpeg args for *out_fmt*."""
    if out_fmt in _LOSSLESS_AUDIO_TARGETS:
        if quality not in _AUDIO_QUALITY_SAMPLE_RATE:
            raise ValueError(f"unsupported audio quality preset: {quality!r}")
        return ["-ar", _AUDIO_QUALITY_SAMPLE_RATE[quality]]

    if quality not in _AUDIO_QUALITY_BITRATE:
        raise ValueError(f"unsupported audio quality preset: {quality!r}")
    bitrate_args = ["-b:a", _AUDIO_QUALITY_BITRATE[quality]]

    if out_fmt == "ogg":
        return ["-ar", _VORBIS_SAMPLE_RATE, *bitrate_args]
    if out_fmt == "opus":
        return ["-ar", _OPUS_SAMPLE_RATE, *bitrate_args]

    floor = _LOSSY_FLOOR_SAMPLE_RATE.get(out_fmt)
    if floor is None:
        raise ValueError(f"no configured sample-rate floor for lossy audio target: {out_fmt!r}")
    source_rate = await _probe_audio_sample_rate(src)
    if source_rate is None or source_rate < int(floor):
        return ["-ar", floor, *bitrate_args]
    return bitrate_args  # source already meets the floor — leave sample rate untouched


def _video_option_args(options: dict[str, Any]) -> list[str]:
    """Map the whitelisted `resolution`/`fps` presets to concrete ffmpeg args."""
    args: list[str] = []
    resolution = options.get("resolution")
    if resolution is not None:
        if resolution not in _VIDEO_RESOLUTION_SCALE:
            raise ValueError(f"unsupported video resolution preset: {resolution!r}")
        args += ["-vf", f"scale={_VIDEO_RESOLUTION_SCALE[resolution]}"]
    fps = options.get("fps")
    if fps is not None:
        fps_key = str(fps)
        if fps_key not in _VIDEO_FPS:
            raise ValueError(f"unsupported video fps preset: {fps!r}")
        args += ["-r", _VIDEO_FPS[fps_key]]
    return args


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
    options: dict[str, Any] | None = None,
) -> None:
    """Run ffmpeg converting *src* to *out_path*, choosing codec by extension.

    *options* is the normalized job preset (CNV-100/101). An audio target
    reads ONLY `quality`; a video target reads ONLY `resolution`/`fps` — an
    audio-only target extracted from a video source therefore never applies
    video controls, by construction of the branch below (the video-options
    branch is simply never reached for an audio out_fmt), not by filtering
    keys out of *options*.
    """
    options = options or {}
    out_fmt = out_path.suffix.lower().lstrip(".")
    codec = CODEC_MAP.get(out_fmt)

    argv = ["ffmpeg", "-i", str(src), "-y"]
    if codec:
        # Determine whether we are extracting audio from video
        src_fmt = src.suffix.lower().lstrip(".")
        if src_fmt in _VIDEO_FORMATS and out_fmt in _AUDIO_FORMATS:
            argv += ["-vn"]  # strip video stream
        argv += ["-c:a" if out_fmt in _AUDIO_FORMATS else "-c:v", codec]

    if out_fmt in _AUDIO_FORMATS:
        quality = options.get("quality")
        if quality is not None:
            argv += await _audio_quality_args(src, out_fmt, quality)
    elif out_fmt in _VIDEO_FORMATS:
        argv += _video_option_args(options)

    argv.append(str(out_path))

    out_b, _err_b = await run_capture(argv, timeout, full_error=False)

    logger.debug("ffmpeg stdout: %s", out_b.decode("utf-8", "replace"))


_AUDIO_INPUTS: set[str] = _AUDIO_FORMATS | {"wma"}
_VIDEO_INPUTS: set[str] = {"3gp", "mp4", "avi", "mkv", "mov", "webm", "flv", "wmv"}

AUDIO_CAPABILITIES: dict[str, Any] = {
    "routing_keys": ["audio"],
    "isAi": False,
    "matrix": {k: v for k, v in SUPPORTED.items() if k in _AUDIO_INPUTS},
}
VIDEO_CAPABILITIES: dict[str, Any] = {
    "routing_keys": ["video"],
    "isAi": False,
    "matrix": {k: v for k, v in SUPPORTED.items() if k in _VIDEO_INPUTS},
}


class FfmpegWorker(StreamConsumerBase):
    """Stream worker for audio and video format conversions via FFmpeg."""

    CAPABILITIES: dict[str, Any] = {
        "routing_keys": ["audio", "video"],
        "isAi": False,
        "matrix": SUPPORTED,
    }

    def convert(self, job: dict[str, Any]) -> tuple[str, str, str]:
        conv_id: int = job["conversionId"]
        src = Path(job["_localInput"])
        target_fmt: str = job["targetFormat"].lower().lstrip(".")
        src_fmt = str(job["sourceFormat"]).lower().lstrip(".")
        options = job.get("options") or {}
        if not isinstance(options, dict):
            raise ValueError("invalid media options")

        if not src.is_file():
            raise FileNotFoundError(f"input file not found: {src}")

        if src_fmt not in SUPPORTED:
            raise ValueError(f"unsupported input format: {src_fmt}")
        if target_fmt not in SUPPORTED[src_fmt]:
            raise ValueError(f"unsupported conversion: {src_fmt} -> {target_fmt}")

        out_dir = Path(job.get("_jobDir") or str(WORK_DIR))
        out_dir.mkdir(parents=True, exist_ok=True)
        out_path = out_dir / f"out-{conv_id}-{uuid.uuid4().hex}.{target_fmt}"
        timeout = _VIDEO_TIMEOUT if target_fmt in _VIDEO_FORMATS else _AUDIO_TIMEOUT

        asyncio.run(run_ffmpeg(src, out_path, timeout, options))

        if not out_path.exists():
            raise RuntimeError("ffmpeg produced no output file")

        mime = _MIME.get(target_fmt, "application/octet-stream")
        logger.info(
            "converted %s -> %s (conversionId=%s)", src.name, out_path.name, conv_id
        )
        return str(out_path), mime, target_fmt


if __name__ == "__main__":
    from workers.ffmpeg.__main__ import main
    main()
