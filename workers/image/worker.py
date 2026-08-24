"""Image conversion worker — WS-транспорт (s1-10).

Транспорт: WS-клиент (StreamConsumerBase.run()); job['_localInput'] заполнен WsClient'ом.
Supported:
  - raster → raster/pdf via Pillow.
  - OCR (raster + pdf → txt/md/docx) inline via tesseract/poppler. The worker
    decides OCR by OUTPUT format: targetFormat ∈ {txt,md,docx} → OCR mode.

Missing registry formats that need extra packages:
  heic  → requires pillow-heif (not in base image)
  avif  (input only, partial) → Pillow >=10 + libavif at build time
"""

from __future__ import annotations

import logging
import os
import uuid
import xml.etree.ElementTree as ET
from io import BytesIO
from pathlib import Path
from typing import Any

import pdf2image
import pytesseract
from cairosvg.surface import PNGSurface
from PIL import Image, ImageOps

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

# SVG растеризуется только в восемь явно разрешённых CairoSVG/Pillow-форматов.
# Остальные векторные форматы (и анимация — CNV-82/CNV-106) вне зоны ответственности
# этого воркера. gif/bmp/tiff/ico добавлены CNV-75 (static SVG legacy targets):
# статичный однокадровый рендер, никакого browser runtime.
_SVG_TARGETS: set[str] = {"png", "jpg", "jpeg", "webp", "gif", "bmp", "tiff", "ico"}

# CNV-75: ICO — фиксированный набор PNG-кадров, независимо от исходных пропорций SVG.
_ICO_SIZES: tuple[tuple[int, int], ...] = ((16, 16), (32, 32), (48, 48), (256, 256))
_ICO_CANVAS = 256

# Поддерживаемые конвертации: Pillow для растра и отдельная ветка CairoSVG.
# heic/avif остаются исключёнными: для них нет optional-плагинов в базовом образе.
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
    "svg":  _SVG_TARGETS,
    "pdf":  {"txt", "md", "docx"},
}


def _pillow_fmt(ext: str) -> str:
    return _PILLOW_FORMAT.get(ext, ext.upper())


def _apply_image_options(image: Image.Image, options: dict[str, Any]) -> Image.Image:
    """Применяет уже провалидированные API параметры к растровому изображению."""
    width = options.get("width")
    height = options.get("height")
    if width is not None or height is not None:
        source_width, source_height = image.size
        if width is None:
            assert height is not None
            height = int(height)
            width = max(1, round(source_width * height / source_height))
        elif height is None:
            width = int(width)
            height = max(1, round(source_height * width / source_width))
        image.thumbnail((int(width), int(height)), Image.Resampling.LANCZOS)
    return image


def _save_image(image: Image.Image, out_path: Path, out_ext: str, options: dict[str, Any]) -> None:
    save_options: dict[str, Any] = {}
    if out_ext in ("jpg", "jpeg"):
        background = options.get("background", "#FFFFFF")
        if image.mode in ("RGBA", "LA") or (image.mode == "P" and "transparency" in image.info):
            canvas = Image.new("RGB", image.size, background)
            canvas.paste(image.convert("RGBA"), mask=image.convert("RGBA").getchannel("A"))
            image = canvas
        else:
            image = image.convert("RGB")
        if "quality" in options:
            save_options["quality"] = int(options["quality"])
        image.save(str(out_path), "JPEG", **save_options)
        return
    if out_ext == "webp" and "quality" in options:
        save_options["quality"] = int(options["quality"])
    image.save(str(out_path), _pillow_fmt(out_ext), **save_options)


def _do_convert(src: Path, out_path: Path, out_ext: str, options: dict[str, Any]) -> None:
    with Image.open(src) as img:
        img = _apply_image_options(img, options)
        if out_ext == "pdf":
            img.convert("RGB").save(str(out_path), "PDF", resolution=100.0)
        else:
            _save_image(img, out_path, out_ext, options)


def _reject_external_svg_resource(url: str, resource_type: str) -> bytes:
    """Запрещает CairoSVG читать сетевые или файловые ссылки из SVG."""
    raise ValueError("external SVG resources are not allowed")


def _validate_svg_well_formed(svg_bytes: bytes) -> None:
    """Малформед XML — permanent-ошибка (ValueError), не бесконечный ретрай.

    `xml.etree.ElementTree.ParseError` НЕ наследует `ValueError` — тот же класс
    дефекта, что задокументирован в CNV-128 для XML-ветки data-воркера
    (`ParseError` уходит в generic-except `StreamConsumerBase.process_job()` →
    `permanent=False` → бесконечный ретрай навсегда битого файла). Перехватываем
    здесь и перевыбрасываем как `ValueError` с безопасным сообщением (без деталей
    парсера — тот же принцип, что и `RuntimeError("SVG rasterization failed")`
    ниже). Вызывается ДО `try/except` в `_do_svg_convert()`, чтобы `ValueError`
    не был проглочен и переупакован в тот же `RuntimeError`.
    `ElementTree` не резолвит внешние entity/DTD по умолчанию — доп. SSRF/XXE
    поверхности эта проверка не добавляет."""
    try:
        ET.fromstring(svg_bytes)
    except ET.ParseError:
        raise ValueError("malformed SVG: input is not well-formed XML") from None


def _save_svg_bmp(image: Image.Image, out_path: Path, options: dict[str, Any]) -> None:
    """CNV-75 AC: BMP без alpha-канала. Композит RGBA/LA/палитра-с-transparency на
    фон — тот же приём, что и JPEG-ветка `_save_image()` (не рефакторим её, чтобы
    не трогать уже протестированное поведение raster→jpeg)."""
    background = options.get("background", "#FFFFFF")
    if image.mode in ("RGBA", "LA") or (image.mode == "P" and "transparency" in image.info):
        rgba = image.convert("RGBA")
        canvas = Image.new("RGB", image.size, background)
        canvas.paste(rgba, mask=rgba.getchannel("A"))
        image = canvas
    else:
        image = image.convert("RGB")
    image.save(str(out_path), "BMP")


def _save_svg_tiff(image: Image.Image, out_path: Path) -> None:
    """CNV-75 AC: single-page, LZW-сжатие. `save_all` не передаём — Pillow пишет
    ровно один кадр по умолчанию."""
    image.save(str(out_path), "TIFF", compression="tiff_lzw")


def _save_svg_ico(image: Image.Image, out_path: Path) -> None:
    """CNV-75 AC: PNG-кадры 16/32/48/256, независимо от исходных пропорций SVG.

    Pillow's ICO-writer молча ОТБРАСЫВАЕТ любой запрошенный size больше im.size —
    для типичного SVG-рендера (меньше 256×256) все четыре записи иначе исчезли бы
    без единой ошибки. Поэтому контент сначала contain-fit'ится (аспект сохраняется,
    не искажается) на прозрачный 256×256 canvas, и только этот canvas идёт в save():
    im.size == (256, 256) гарантирует, что ни один из _ICO_SIZES не будет отфильтрован.

    CNV-75-open-question (нужен team-lead ack): job-level `width`/`height` НЕ
    применяются к этой ветке (в отличие от gif/bmp/tiff/png/jpg/webp) — ICO
    определяет собственный фиксированный набор размеров, single-size resize
    здесь семантически бессмысленен. Решение по образцу CNV-98 markdownDialect/
    pdf→md: явно НЕ применяем, а не "применяем и потом перезаписываем" (что было
    бы тем самым silently-inert паттерном, которого требует избегать CNV-75).
    Backend-профиль для svg→ico ещё не существует (CNV-95 идёт после этой
    карточки), поэтому в проде `options` для этой пары сегодня всегда `{}`."""
    rgba = image.convert("RGBA")
    canvas = Image.new("RGBA", (_ICO_CANVAS, _ICO_CANVAS), (0, 0, 0, 0))
    fitted = ImageOps.contain(rgba, (_ICO_CANVAS, _ICO_CANVAS), Image.Resampling.LANCZOS)
    offset = ((_ICO_CANVAS - fitted.width) // 2, (_ICO_CANVAS - fitted.height) // 2)
    canvas.paste(fitted, offset, fitted)
    canvas.save(str(out_path), "ICO", sizes=list(_ICO_SIZES))


def _do_svg_convert(src: Path, out_path: Path, out_ext: str, options: dict[str, Any]) -> None:
    """Растеризует SVG, не позволяя его ссылкам выйти за пределы загрузки."""
    svg_bytes = src.read_bytes()
    _validate_svg_well_formed(svg_bytes)
    try:
        png_bytes = PNGSurface.convert(
            bytestring=svg_bytes,
            unsafe=False,
            url_fetcher=_reject_external_svg_resource,
        )
        if out_ext == "png" and not options:
            out_path.write_bytes(png_bytes)
        else:
            with Image.open(BytesIO(png_bytes)) as image:
                if out_ext == "ico":
                    # options НЕ применяются — см. докстринг _save_svg_ico().
                    _save_svg_ico(image, out_path)
                elif out_ext == "bmp":
                    _save_svg_bmp(_apply_image_options(image, options), out_path, options)
                elif out_ext == "tiff":
                    _save_svg_tiff(_apply_image_options(image, options), out_path)
                else:
                    _save_image(_apply_image_options(image, options), out_path, out_ext, options)
    except Exception:
        try:
            out_path.unlink(missing_ok=True)
        except OSError:
            pass
        raise RuntimeError("SVG rasterization failed") from None


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
        "isAi": False,
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
        options = job.get("options") or {}
        if not isinstance(options, dict):
            raise ValueError("invalid image options")

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

        if src_ext == "svg":
            _do_svg_convert(src, out_path, target_fmt, options)
        else:
            _do_convert(src, out_path, target_fmt, options)

        if not out_path.exists():
            raise RuntimeError(f"image converter produced no output for conversionId={conv_id}")

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
