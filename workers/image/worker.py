"""Image conversion worker — Phase 1, XREADGROUP-based.

Consumes stream conv.image, consumer group convertor.
Supported: raster → raster/pdf via Pillow.
OCR (isAi=true) routes to conv.ai — not handled here.

Missing registry formats that need extra packages:
  svg   → requires cairosvg  (not in base image)
  heic  → requires pillow-heif (not in base image)
  avif  (input only, partial) → Pillow >=10 + libavif at build time
"""

from __future__ import annotations

import logging
import os
import uuid
from pathlib import Path
from typing import Any

from PIL import Image

from workers.common.stream_consumer import WORK_DIR, StreamConsumerBase

logger = logging.getLogger(__name__)

OCR_LANGS = os.getenv("OCR_LANGS", "rus+eng")

# MIME types for output formats
_MIME: dict[str, str] = {
    "jpg":  "image/jpeg",
    "jpeg": "image/jpeg",
    "png":  "image/png",
    "gif":  "image/gif",
    "bmp":  "image/bmp",
    "webp": "image/webp",
    "tiff": "image/tiff",
    "tif":  "image/tiff",
    "ico":  "image/x-icon",
    "pdf":  "application/pdf",
}

# Pillow save-format aliases (ext → PIL format name)
_PILLOW_FORMAT: dict[str, str] = {
    "jpg":  "JPEG",
    "jpeg": "JPEG",
    "tif":  "TIFF",
}

# Supported conversions (raster-native Pillow only).
# svg/heic/avif excluded — need optional plugins not present in base image.
_MATRIX: dict[str, set[str]] = {
    "jpg":  {"png", "gif", "bmp", "webp", "tiff", "ico", "pdf"},
    "jpeg": {"png", "gif", "bmp", "webp", "tiff", "ico", "pdf"},
    "png":  {"jpg", "gif", "bmp", "webp", "tiff", "ico", "pdf"},
    "gif":  {"jpg", "png", "bmp", "webp", "tiff", "ico", "pdf"},
    "bmp":  {"jpg", "png", "gif", "webp", "tiff", "ico", "pdf"},
    "webp": {"jpg", "png", "gif", "bmp", "tiff", "ico", "pdf"},
    "tiff": {"jpg", "png", "gif", "bmp", "webp", "ico", "pdf"},
    "tif":  {"jpg", "png", "gif", "bmp", "webp", "ico", "pdf"},
    "ico":  {"jpg", "png", "gif", "bmp", "webp", "tiff", "pdf"},
}


def _pillow_fmt(ext: str) -> str:
    return _PILLOW_FORMAT.get(ext, ext.upper())


def _do_convert(src: Path, out_path: Path, out_ext: str) -> None:
    with Image.open(src) as img:
        if out_ext == "pdf":
            img.convert("RGB").save(str(out_path), "PDF", resolution=100.0)
        elif out_ext in ("jpg", "jpeg"):
            img.convert("RGB").save(str(out_path), "JPEG")
        else:
            img.save(str(out_path), _pillow_fmt(out_ext))


class ImageWorker(StreamConsumerBase):
    """Image format converter: raster → raster / raster → pdf via Pillow."""

    CAPABILITIES: dict[str, Any] = {
        "routing_keys": ["image"],
        "matrix": _MATRIX,
    }

    def convert(self, job: dict[str, Any]) -> tuple[str, str, str]:
        """Convert image as described by *job*.

        Reads the local input path the base class prepared in job['_localInput']
        (downloaded from S3), writes the output to a tmp file under WORK_DIR, and
        returns (local_output_path, output_mime, target_ext) for the base class
        to upload to the results bucket. Both tmp files are cleaned by the base.

        Raises ValueError for unsupported conversions, FileNotFoundError for
        missing input, RuntimeError if Pillow produces no output.
        """
        conv_id: int = job["conversionId"]
        src = Path(job["_localInput"])
        target_fmt: str = job["targetFormat"].lower().lstrip(".")
        src_ext = str(job["sourceFormat"]).lower().lstrip(".")

        if not src.is_file():
            raise FileNotFoundError(f"input not found: {src}")

        if src_ext not in _MATRIX:
            raise ValueError(f"unsupported source format: {src_ext!r}")
        if target_fmt not in _MATRIX[src_ext]:
            raise ValueError(f"unsupported conversion: {src_ext} → {target_fmt}")

        WORK_DIR.mkdir(parents=True, exist_ok=True)
        out_path = WORK_DIR / f"out-{conv_id}-{uuid.uuid4().hex}.{target_fmt}"

        _do_convert(src, out_path, target_fmt)

        if not out_path.exists():
            raise RuntimeError(f"Pillow produced no output for conversionId={conv_id}")

        mime = _MIME.get(target_fmt, "application/octet-stream")
        logger.info(
            "image converted",
            extra={
                "conversionId": conv_id,
                "src": src.name,
                "out": out_path.name,
                "mime": mime,
            },
        )
        return str(out_path), mime, target_fmt


if __name__ == "__main__":
    worker = ImageWorker()
    worker.run()
