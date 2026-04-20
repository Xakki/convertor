"""Image conversion worker using Pillow and pytesseract (OCR)."""

import logging
import os
from pathlib import Path
from typing import Any

from PIL import Image

from workers.common.base_worker import SHARE_DIR, BaseWorker
from workers.common.safe_path import safe_share_path

logger = logging.getLogger(__name__)

OCR_LANGS = os.getenv("OCR_LANGS", "rus+eng")

# Supported raster → raster/pdf conversions
SUPPORTED_IMAGE: dict[str, set[str]] = {
    "jpg":  {"png", "gif", "bmp", "webp", "tiff", "ico", "pdf"},
    "jpeg": {"png", "gif", "bmp", "webp", "tiff", "ico", "pdf"},
    "png":  {"jpg", "gif", "bmp", "webp", "tiff", "ico", "pdf"},
    "gif":  {"jpg", "png", "bmp", "webp", "tiff", "ico", "pdf"},
    "bmp":  {"jpg", "png", "gif", "webp", "tiff", "ico", "pdf"},
    "webp": {"jpg", "png", "gif", "bmp", "tiff", "ico", "pdf"},
    "tiff": {"jpg", "png", "gif", "bmp", "webp", "ico", "pdf"},
    "tif":  {"jpg", "png", "gif", "bmp", "webp", "ico", "pdf"},
    "ico":  {"jpg", "png", "gif", "bmp", "webp", "tiff", "pdf"},
    "avif": {"jpg", "png", "gif", "bmp", "webp", "tiff", "ico", "pdf"},
}

# OCR output formats
OCR_OUTPUTS: set[str] = {"txt", "md"}

# Pillow save format aliases
_PILLOW_FORMAT: dict[str, str] = {
    "jpg":  "JPEG",
    "jpeg": "JPEG",
    "tif":  "TIFF",
}


def _pillow_format(ext: str) -> str:
    return _PILLOW_FORMAT.get(ext, ext.upper())


def _convert_image(src: Path, out_path: Path) -> None:
    """Convert image file using Pillow."""
    with Image.open(src) as img:
        out_fmt = out_path.suffix.lower().lstrip(".")
        if out_fmt == "pdf":
            # PDF requires RGB
            rgb_img = img.convert("RGB")
            rgb_img.save(str(out_path), "PDF", resolution=100.0)
        elif out_fmt in {"jpg", "jpeg"}:
            rgb_img = img.convert("RGB")
            rgb_img.save(str(out_path), _pillow_format(out_fmt))
        else:
            img.save(str(out_path), _pillow_format(out_fmt))
    logger.debug("converted image %s -> %s", src.name, out_path.name)


def _ocr_image(src: Path, out_path: Path, lang: str, output_format: str) -> None:
    """Run OCR on *src* and write extracted text to *out_path*."""
    import pytesseract

    with Image.open(src) as img:
        text: str = pytesseract.image_to_string(img, lang=lang)

    if output_format == "md":
        # Wrap plain text in a minimal markdown code block? No — just write as-is.
        # Tesseract output is plain text; markdown format = same content.
        pass

    out_path.write_text(text, encoding="utf-8")
    logger.debug("OCR done: %s -> %s (%d chars)", src.name, out_path.name, len(text))


class ImageWorker(BaseWorker):
    """Queue worker for image format conversions and OCR via Pillow/pytesseract."""

    queue_name = "convertor:images"

    async def process_task(self, task: dict[str, Any]) -> dict[str, Any]:
        import asyncio

        input_path_raw: str = task["input_path"]
        output_format: str = task["output_format"].lower().lstrip(".")

        src = safe_share_path(input_path_raw, SHARE_DIR)
        if not src.is_file():
            raise FileNotFoundError(f"input file not found: {src}")

        in_format = src.suffix.lower().lstrip(".")

        if output_format in OCR_OUTPUTS:
            # OCR mode
            if in_format not in SUPPORTED_IMAGE:
                raise ValueError(f"unsupported input format for OCR: {in_format}")
            out_path = src.with_suffix(f".{output_format}")
            await asyncio.to_thread(_ocr_image, src, out_path, OCR_LANGS, output_format)
        else:
            # Regular image conversion
            if in_format not in SUPPORTED_IMAGE:
                raise ValueError(f"unsupported input format: {in_format}")
            if output_format not in SUPPORTED_IMAGE[in_format]:
                raise ValueError(f"unsupported conversion: {in_format} -> {output_format}")
            out_path = src.with_suffix(f".{output_format}")
            await asyncio.to_thread(_convert_image, src, out_path)

        if not out_path.exists():
            raise RuntimeError("image conversion produced no output file")

        logger.info(
            "converted %s -> %s (task id=%s)", src.name, out_path.name, task.get("id")
        )
        return {"status": "ok", "output_path": str(out_path)}


if __name__ == "__main__":
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    worker = ImageWorker()
    worker.run()
