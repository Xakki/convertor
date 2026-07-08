"""Image conversion worker — WS-транспорт (s1-10).

Транспорт: WS-клиент (StreamConsumerBase.run()); job['_localInput'] заполнен WsClient'ом.
Supported:
  - raster → raster/pdf via Pillow.
  - OCR (raster + pdf → txt/md/docx) inline via tesseract/poppler. The worker
    decides OCR by OUTPUT format: targetFormat ∈ {txt,md,docx} → OCR mode.

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

import pdf2image
import pytesseract
from PIL import Image

from workers.common.mime import DOC_TEXT_MIME
from workers.common.stream_consumer import WORK_DIR, StreamConsumerBase

logger = logging.getLogger(__name__)

OCR_LANGS = os.getenv("OCR_LANGS", "rus+eng")

# OCR (text) output targets the WORKER recognises by output format.
_OCR_TARGETS: set[str] = {"txt", "md", "docx"}

# Sources that the OCR branch accepts (raster via PIL + pdf via poppler).
_OCR_SOURCES: set[str] = {"jpg", "jpeg", "png", "tiff", "tif", "pdf"}

# MIME types for output formats (pdf/txt/md/docx — общие, из workers.common.mime)
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
    **DOC_TEXT_MIME,
}

# Pillow save-format aliases (ext → PIL format name)
_PILLOW_FORMAT: dict[str, str] = {
    "jpg":  "JPEG",
    "jpeg": "JPEG",
    "tif":  "TIFF",
}

# Supported conversions (raster-native Pillow only).
# svg/heic/avif excluded — need optional plugins not present in base image.
# OCR targets (txt/md/docx) are advertised ONLY on the ROADMAP OCR-row sources
# jpg/jpeg/png/tiff/tif (+ a pdf source that ONLY produces OCR text). They are
# routing/advertisement hints; the OCR branch in convert() validates sources
# against _OCR_SOURCES itself and bypasses this matrix.
_MATRIX: dict[str, set[str]] = {
    "jpg":  {"png", "gif", "bmp", "webp", "tiff", "ico", "pdf", "txt", "md", "docx"},
    "jpeg": {"png", "gif", "bmp", "webp", "tiff", "ico", "pdf", "txt", "md", "docx"},
    "png":  {"jpg", "gif", "bmp", "webp", "tiff", "ico", "pdf", "txt", "md", "docx"},
    "gif":  {"jpg", "png", "bmp", "webp", "tiff", "ico", "pdf"},
    "bmp":  {"jpg", "png", "gif", "webp", "tiff", "ico", "pdf"},
    "webp": {"jpg", "png", "gif", "bmp", "tiff", "ico", "pdf"},
    "tiff": {"jpg", "png", "gif", "bmp", "webp", "ico", "pdf", "txt", "md", "docx"},
    "tif":  {"jpg", "png", "gif", "bmp", "webp", "ico", "pdf", "txt", "md", "docx"},
    "ico":  {"jpg", "png", "gif", "bmp", "webp", "tiff", "pdf"},
    "pdf":  {"txt", "md", "docx"},
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


# --------------------------------------------------------------------------
# OCR helpers
# --------------------------------------------------------------------------

def _ocr_image(img: "Image.Image") -> str:
    return pytesseract.image_to_string(img, lang=OCR_LANGS)


def _extract_text(src: Path, src_ext: str) -> list[str]:
    """Return per-page OCR text. Raster sources yield a single page."""
    if src_ext == "pdf":
        pages = pdf2image.convert_from_path(str(src))
        out: list[str] = []
        for page in pages:
            try:
                out.append(_ocr_image(page).strip())
            finally:
                page.close()
        return out
    with Image.open(src) as img:
        return [_ocr_image(img).strip()]


def _write_txt(pages: list[str], out_path: Path) -> None:
    out_path.write_text("\n\n".join(pages).strip() + "\n", encoding="utf-8")


def _write_md(pages: list[str], out_path: Path) -> None:
    # Each page's blank-line-separated blocks become markdown paragraphs;
    # multiple pages are joined by a horizontal rule.
    blocks: list[str] = []
    for page in pages:
        para = "\n\n".join(b.strip() for b in page.split("\n\n") if b.strip())
        blocks.append(para)
    sep = "\n\n---\n\n" if len(pages) > 1 else "\n\n"
    out_path.write_text(sep.join(blocks).strip() + "\n", encoding="utf-8")


def _write_docx(pages: list[str], out_path: Path) -> None:
    import docx  # local import: only needed on the docx path

    document = docx.Document()
    for idx, page in enumerate(pages):
        for block in page.split("\n\n"):
            block = block.strip()
            if block:
                document.add_paragraph(block)
        if idx < len(pages) - 1:
            document.add_page_break()
    document.save(str(out_path))


_OCR_WRITERS = {
    "txt": _write_txt,
    "md": _write_md,
    "docx": _write_docx,
}


class ImageWorker(StreamConsumerBase):
    """Image format converter: raster → raster / raster → pdf via Pillow."""

    CAPABILITIES: dict[str, Any] = {
        "routing_keys": ["image"],
        "matrix": _MATRIX,
    }

    def convert(self, job: dict[str, Any]) -> tuple[str, str, str]:
        """Convert image as described by *job*.

        Reads the local input path the base class prepared in job['_localInput']
        (загружен WsClient'ом через GET /jobs/{id}/input), writes the output to a
        tmp file under WORK_DIR, and returns (local_output_path, output_mime,
        target_ext) for the base class to relay via WS. Both tmp files are cleaned
        by the base.

        Raises ValueError for unsupported conversions, FileNotFoundError for
        missing input, RuntimeError if Pillow produces no output.
        """
        conv_id: int = job["conversionId"]
        src = Path(job["_localInput"])
        target_fmt: str = job["targetFormat"].lower().lstrip(".")
        src_ext = str(job["sourceFormat"]).lower().lstrip(".")

        if not src.is_file():
            raise FileNotFoundError(f"input not found: {src}")

        out_dir = Path(job.get("_jobDir") or str(WORK_DIR))
        out_dir.mkdir(parents=True, exist_ok=True)

        # --- OCR branch: decided by OUTPUT format (txt/md/docx) -------------
        if target_fmt in _OCR_TARGETS:
            return self._convert_ocr(conv_id, src, src_ext, target_fmt, out_dir)

        # --- Raster branch (raster → raster / pdf) -------------------------
        if src_ext not in _MATRIX:
            raise ValueError(f"unsupported source format: {src_ext!r}")
        if target_fmt not in _MATRIX[src_ext]:
            raise ValueError(f"unsupported conversion: {src_ext} → {target_fmt}")

        out_path = out_dir / f"out-{conv_id}-{uuid.uuid4().hex}.{target_fmt}"

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

    def _convert_ocr(
        self, conv_id: int, src: Path, src_ext: str, target_fmt: str,
        out_dir: Path | None = None,
    ) -> tuple[str, str, str]:
        """OCR a raster/pdf source into txt/md/docx text output."""
        if src_ext not in _OCR_SOURCES:
            raise ValueError(f"unsupported OCR source format: {src_ext!r}")

        pages = _extract_text(src, src_ext)

        if not any(p.strip() for p in pages):
            logger.warning(
                "OCR extracted no text — producing empty output",
                extra={"conversionId": conv_id, "src": src.name, "pages": len(pages)},
            )

        _out_dir = out_dir if out_dir is not None else WORK_DIR
        out_path = _out_dir / f"out-{conv_id}-{uuid.uuid4().hex}.{target_fmt}"
        _OCR_WRITERS[target_fmt](pages, out_path)

        if not out_path.exists():
            raise RuntimeError(f"OCR produced no output for conversionId={conv_id}")

        mime = _MIME[target_fmt]
        logger.info(
            "image OCR completed",
            extra={
                "conversionId": conv_id,
                "src": src.name,
                "out": out_path.name,
                "mime": mime,
                "pages": len(pages),
                "langs": OCR_LANGS,
            },
        )
        return str(out_path), mime, target_fmt


if __name__ == "__main__":
    worker = ImageWorker()
    worker.run()
