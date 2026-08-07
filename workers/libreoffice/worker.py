"""Document / markup conversion worker — WS-транспорт (s1-10).

Транспорт: WS-клиент (StreamConsumerBase.run()); job['_localInput'] заполнен WsClient'ом.
PHP фолдит категорию `markup` в `document` при роутинге — воркер обрабатывает оба.
convert() запускает LibreOffice (headless soffice) + pandoc + poppler (pdftotext/pdftoppm),
пишет вывод в WORK_DIR, возвращает (out_path, mime, target_ext).

Engine selection is by the (sourceFormat, targetFormat) pair — the worker is
flag-agnostic (it never reads the ocr flag):
  - target md            → pandoc (pdf source: pdftotext text wrapped as md)
  - pdf source           → poppler pdftotext (txt/md) or pdftotext→soffice (docx)
                           or pdftoppm (jpg-per-page)
  - md / rst / latex / wiki → pandoc to a soffice-importable form, then soffice
                           handles pdf/odt/rtf/epub/etc.
  - epub source          → pandoc (soffice cannot IMPORT epub); epub→pdf blocked
  - calc / impress       → soffice --convert-to <filter>
  - everything else       → soffice --convert-to <filter>

pdf→jpg: pdftoppm рендерит постранично; 1 стр. → один .jpg; 2+ стр. → zip
(`page-001.jpg`, …) — тот же паттерн упаковки, что у multi-page OCR-текста
(несколько страниц → один составной артеfact, здесь zip вместо concat).

`pages` (Apple Pages): безусловно в матрице (официальный образ всегда содержит
libetonyek — сборка падает без него, см. docker/workers/libreoffice.Dockerfile).
Проба libetonyek — только execution-time guard: сторонний образ без библиотеки
роняет job с pages-источником явной permanent-ошибкой, а не невнятным
soffice-фейлом.

soffice runs in a private UserInstallation per job so concurrent conversions
don't fight over ~/.config/libreoffice.
"""

from __future__ import annotations

import asyncio
import glob
import logging
import os
import shutil
import tempfile
import uuid
import zipfile
from pathlib import Path
from typing import Any

from workers.common.mime import DOC_TEXT_MIME
from workers.common.stream_consumer import WORK_DIR, StreamConsumerBase
from workers.common.subprocess_runner import run_capture

logger = logging.getLogger(__name__)

SOFFICE_TIMEOUT = int(os.getenv("SOFFICE_TIMEOUT", "180"))
PDFTOPPM_DPI = int(os.getenv("PDFTOPPM_DPI", "150"))
# Лимит страниц pdf→jpg: защита от DoS (pdftoppm рендерит каждую стр. в JPEG).
PDFTOPPM_MAX_PAGES = int(os.getenv("PDFTOPPM_MAX_PAGES", "50"))


def _libetonyek_available() -> bool:
    """Проверка libetonyek в образе — без него soffice не импортирует Apple Pages."""
    return bool(glob.glob("/usr/lib/*/libetonyek-*.so*")) or bool(
        glob.glob("/usr/lib/x86_64-linux-gnu/libetonyek-*.so*")
    )


# Execution-time guard (НЕ matrix-gate): `pages` — безусловная запись в _MATRIX
# (см. ниже), т.к. официальный document-worker образ всегда содержит libetonyek
# (docker/workers/libreoffice.Dockerfile хард-фейлит билд без неё). Проба нужна
# только для стороннего образа без библиотеки — convert() ловит этот случай и
# роняет job permanent-ошибкой (ValueError) до попытки soffice-импорта.
_PAGES_IMPORT_OK = _libetonyek_available()
if not _PAGES_IMPORT_OK:
    logger.info("libetonyek not found — Apple Pages source will fail at conversion time")

# MIME types for output formats (docx/pdf/txt/md — общие, из workers.common.mime)
_MIME: dict[str, str] = {
    **DOC_TEXT_MIME,
    "odt":   "application/vnd.oasis.opendocument.text",
    "rtf":   "application/rtf",
    "html":  "text/html",
    "epub":  "application/epub+zip",
    "rst":   "text/x-rst",
    "jpg":   "image/jpeg",
    "jpeg":  "image/jpeg",
    "zip":   "application/zip",
    "xlsx":  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "xls":   "application/vnd.ms-excel",
    "ods":   "application/vnd.oasis.opendocument.spreadsheet",
    "csv":   "text/csv",
    "pptx":  "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    "ppt":   "application/vnd.ms-powerpoint",
    "odp":   "application/vnd.oasis.opendocument.presentation",
    "latex": "application/x-latex",
    "tex":   "application/x-tex",
    "wiki":  "text/x-wiki",
}

# soffice --convert-to filter string per target extension. Bare aliases work for
# most; txt needs the explicit encoded-text writer; epub uses the EPUB filter.
_SOFFICE_FILTER: dict[str, str] = {
    "docx": "docx",
    "odt":  "odt",
    "rtf":  "rtf",
    "pdf":  "pdf",
    "html": "html",
    "epub": "epub",
    "txt":  "txt:Text (encoded):UTF8",
    "xlsx": "xlsx",
    "xls":  "xls",
    "ods":  "ods",
    "csv":  "csv",
    "pptx": "pptx",
    "ppt":  "ppt",
    "odp":  "odp",
}

# pandoc reader name per source extension.
_PANDOC_READER: dict[str, str] = {
    "md":    "gfm",
    "html":  "html",
    "htm":   "html",
    "docx":  "docx",
    "odt":   "odt",
    "epub":  "epub",
    "rst":   "rst",
    "latex": "latex",
    "tex":   "latex",
    "wiki":  "mediawiki",
}

# Markup sources — pandoc→docx→soffice для office/pdf целей (как md).
_MARKUP_SOURCES: set[str] = {"md", "rst", "latex", "tex", "wiki"}

# Calc / Impress — только soffice (фильтры в _SOFFICE_FILTER).
_IMPRESS_SOURCES: set[str] = {"ppt", "pptx", "odp"}
_IMPRESS_TARGETS: set[str] = {"pptx", "odp", "pdf"}

# Conversion matrix the worker advertises (CAPABILITIES) and validates against.
# Stage-7: spreadsheets, presentations, markup (rst/latex/wiki), epub-input,
# pdf→jpg. epub→pdf blocked (conversion-chaining). pages — безусловно в матрице
# (libetonyek — execution-time guard в convert(), не matrix-gate, см. выше).
_OFFICE_TARGETS: set[str] = {"docx", "odt", "pdf", "txt", "html", "md", "rtf", "epub"}
_EPUB_PANDOC_TARGETS: set[str] = {"md", "docx", "odt", "html", "rtf", "txt"}
_MARKUP_TARGETS: set[str] = set(_OFFICE_TARGETS)

_MATRIX: dict[str, set[str]] = {
    # office documents (Writer)
    "doc":   _OFFICE_TARGETS,
    "docx":  _OFFICE_TARGETS,
    "odt":   _OFFICE_TARGETS,
    "rtf":   _OFFICE_TARGETS,
    "txt":   _OFFICE_TARGETS,
    "html":  _OFFICE_TARGETS,
    "htm":   _OFFICE_TARGETS,
    # epub source: pandoc only (soffice cannot import epub); epub→pdf blocked
    "epub":  _EPUB_PANDOC_TARGETS,
    # pdf → text extraction + jpg-per-page (NOT OCR)
    "pdf":   {"docx", "txt", "md", "jpg"},
    # markup (Stage-7: rst/latex/wiki + Stage-1 md)
    "md":    _MARKUP_TARGETS,
    "rst":   _MARKUP_TARGETS,
    "latex": _MARKUP_TARGETS,
    "tex":   _MARKUP_TARGETS,
    "wiki":  _MARKUP_TARGETS,
    # Calc (Stage-7)
    "xls":   _OFFICE_TARGETS,
    "xlsx":  _OFFICE_TARGETS,
    "ods":   _OFFICE_TARGETS,
    "csv":   _OFFICE_TARGETS,
    # Impress (Stage-7)
    "ppt":   _IMPRESS_TARGETS,
    "pptx":  _IMPRESS_TARGETS,
    "odp":   _IMPRESS_TARGETS,
    # Apple Pages (Stage-7+): безусловная запись — см. докстринг модуля и
    # _PAGES_IMPORT_OK выше про execution-time guard в convert().
    "pages": _OFFICE_TARGETS,
}


# --------------------------------------------------------------------------
# subprocess helpers
# --------------------------------------------------------------------------

async def _run(argv: list[str], timeout: int = SOFFICE_TIMEOUT) -> None:
    await run_capture(argv, timeout)


async def run_soffice(src: Path, out_dir: Path, convert_to: str) -> None:
    with tempfile.TemporaryDirectory(prefix="lo-profile-") as profile:
        await _run([
            "soffice",
            f"-env:UserInstallation={Path(profile).as_uri()}",
            "--headless", "--norestore", "--nologo", "--nofirststartwizard",
            "--convert-to", convert_to,
            "--outdir", str(out_dir),
            str(src),
        ])


async def run_pdftotext(src: Path, out_path: Path) -> None:
    await _run(["pdftotext", "-layout", "-enc", "UTF-8", str(src), str(out_path)])


async def _pdf_page_count(src: Path) -> int:
    """Число страниц PDF через pdfinfo (poppler-utils)."""
    out, _ = await run_capture(["pdfinfo", str(src)], SOFFICE_TIMEOUT)
    for line in out.decode("utf-8", "replace").splitlines():
        if line.startswith("Pages:"):
            return int(line.split(":", 1)[1].strip())
    raise RuntimeError("pdfinfo: поле Pages не найдено")


async def run_pdftoppm(src: Path, out_dir: Path, prefix: str) -> list[Path]:
    """Рендер PDF в JPEG через poppler; возвращает отсортированный список .jpg."""
    page_count = await _pdf_page_count(src)
    if page_count > PDFTOPPM_MAX_PAGES:
        raise ValueError(
            f"pdf has {page_count} pages, exceeds PDFTOPPM_MAX_PAGES={PDFTOPPM_MAX_PAGES}"
        )
    await _run([
        "pdftoppm", "-jpeg", "-r", str(PDFTOPPM_DPI),
        "-f", "1", "-l", str(page_count),
        str(src), str(out_dir / prefix),
    ])
    pages = sorted(out_dir.glob(f"{prefix}-*.jpg"))
    if not pages:
        # одностраничный PDF: pdftoppm может выдать prefix.jpg без суффикса
        single = out_dir / f"{prefix}.jpg"
        if single.is_file():
            pages = [single]
    return pages


async def run_pandoc(src: Path, out_path: Path, reader: str, writer: str) -> None:
    argv = ["pandoc", "--from", reader, "--to", writer, "-o", str(out_path)]
    if writer == "gfm":
        argv += ["--wrap=none", f"--extract-media={out_path.parent / 'media'}"]
    argv.append(str(src))
    await _run(argv)


def _pack_jpg_pages(
    pages: list[Path], work_dir: Path, stem: str
) -> tuple[Path, str, str]:
    """1 стр. → jpg; 2+ → zip с page-NNN.jpg внутри."""
    if len(pages) == 1:
        out = work_dir / f"{stem}.jpg"
        shutil.copy2(pages[0], out)
        return out, "image/jpeg", "jpg"
    zip_path = work_dir / f"{stem}-pages.zip"
    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for idx, page in enumerate(pages, start=1):
            zf.write(page, arcname=f"page-{idx:03d}.jpg")
    return zip_path, "application/zip", "zip"


# --------------------------------------------------------------------------
# conversion routing
# --------------------------------------------------------------------------

async def _convert_markup(
    src: Path, src_fmt: str, target: str, work_dir: Path, stem: str
) -> Path:
    """Markup (md/rst/latex/wiki): pandoc → docx → soffice при необходимости."""
    out = work_dir / f"{stem}.{target}"
    reader = _PANDOC_READER[src_fmt]

    if target == "md":
        await run_pandoc(src, out, reader, "gfm")
        return out
    if target == "docx":
        await run_pandoc(src, out, reader, "docx")
        return out

    with tempfile.TemporaryDirectory(prefix="mk-tmp-") as tmp:
        tmp_dir = Path(tmp)
        docx_path = tmp_dir / f"{stem}.docx"
        await run_pandoc(src, docx_path, reader, "docx")
        await run_soffice(docx_path, work_dir, _SOFFICE_FILTER[target])
        return out


async def _convert(
    src: Path, src_fmt: str, target: str, work_dir: Path
) -> tuple[Path, str, str]:
    """Produce output from src; returns (path, mime, delivery_ext)."""
    stem = src.stem
    out = work_dir / f"{stem}.{target}"

    # --- PDF source: poppler text / jpg-per-page / chain to soffice ---
    if src_fmt == "pdf":
        if target == "jpg":
            with tempfile.TemporaryDirectory(prefix="pdf-jpg-") as tmp:
                tmp_dir = Path(tmp)
                pages = await run_pdftoppm(src, tmp_dir, stem)
                if not pages:
                    raise RuntimeError("pdftoppm produced no JPEG pages")
                return _pack_jpg_pages(pages, work_dir, stem)

        with tempfile.TemporaryDirectory(prefix="pdf-tmp-") as tmp:
            txt_path = Path(tmp) / f"{stem}.txt"
            await run_pdftotext(src, txt_path)
            if target in ("txt", "md"):
                out.write_bytes(txt_path.read_bytes())
                return out, _MIME[target], target
            if target == "docx":
                await run_soffice(txt_path, work_dir, _SOFFICE_FILTER["docx"])
                return out, _MIME["docx"], target
        raise ValueError(f"unsupported pdf target: {target}")

    # --- epub source: pandoc only (no epub→pdf) ---
    if src_fmt == "epub":
        if target not in _EPUB_PANDOC_TARGETS:
            raise ValueError(f"unsupported conversion: {src_fmt} → {target}")
        writer = "gfm" if target == "md" else target
        await run_pandoc(src, out, _PANDOC_READER["epub"], writer)
        return out, _MIME.get(target, "application/octet-stream"), target

    # --- target md: always pandoc ---
    if target == "md":
        if src_fmt in _PANDOC_READER:
            await run_pandoc(src, out, _PANDOC_READER[src_fmt], "gfm")
            return out, _MIME["md"], target
        with tempfile.TemporaryDirectory(prefix="md-tmp-") as tmp:
            tmp_dir = Path(tmp)
            await run_soffice(src, tmp_dir, _SOFFICE_FILTER["docx"])
            await run_pandoc(tmp_dir / f"{stem}.docx", out, "docx", "gfm")
            return out, _MIME["md"], target

    # --- markup sources ---
    if src_fmt in _MARKUP_SOURCES:
        produced = await _convert_markup(src, src_fmt, target, work_dir, stem)
        return produced, _MIME.get(target, "application/octet-stream"), target

    # --- plain soffice path (office / calc / impress / html) ---
    if target not in _SOFFICE_FILTER:
        raise ValueError(f"unsupported target: {target}")
    if src_fmt in _IMPRESS_SOURCES and target not in _IMPRESS_TARGETS:
        raise ValueError(f"unsupported conversion: {src_fmt} → {target}")
    await run_soffice(src, work_dir, _SOFFICE_FILTER[target])
    return out, _MIME.get(target, "application/octet-stream"), target


class LibreOfficeWorker(StreamConsumerBase):
    """Document/markup converter via LibreOffice (soffice) + pandoc + poppler."""

    CAPABILITIES: dict[str, Any] = {
        "routing_keys": ["document"],
        "isAi": False,
        "matrix": _MATRIX,
    }

    def convert(self, job: dict[str, Any]) -> tuple[str, str, str]:
        conv_id: int = job["conversionId"]
        src = Path(job["_localInput"])
        target_fmt: str = job["targetFormat"].lower().lstrip(".")
        src_fmt = str(job["sourceFormat"]).lower().lstrip(".")

        if not src.is_file():
            raise FileNotFoundError(f"input not found: {src}")

        if src_fmt not in _MATRIX:
            raise ValueError(f"unsupported source format: {src_fmt!r}")
        if target_fmt not in _MATRIX[src_fmt]:
            raise ValueError(f"unsupported conversion: {src_fmt} → {target_fmt}")
        if src_fmt == "pages" and not _PAGES_IMPORT_OK:
            raise ValueError(
                "pages source requires libetonyek, which is missing from this "
                "worker image — cannot import Apple Pages files"
            )

        out_dir = Path(job.get("_jobDir") or str(WORK_DIR))
        out_dir.mkdir(parents=True, exist_ok=True)
        job_dir = out_dir / f"lo-{conv_id}-{uuid.uuid4().hex}"
        job_dir.mkdir(parents=True, exist_ok=True)
        out_token = uuid.uuid4().hex
        delivery_ext = target_fmt
        try:
            produced, mime, delivery_ext = asyncio.run(
                _convert(src, src_fmt, target_fmt, job_dir)
            )

            if not produced.exists():
                raise RuntimeError(
                    f"conversion produced no output for conversionId={conv_id}"
                )

            # Фактический суффикс (pdf→jpg multi-page → .zip).
            out_path = out_dir / f"out-{conv_id}-{out_token}.{delivery_ext}"
            os.replace(str(produced), str(out_path))
        finally:
            shutil.rmtree(job_dir, ignore_errors=True)

        logger.info(
            "document converted",
            extra={
                "conversionId": conv_id,
                "src": src.name,
                "out": out_path.name,
                "mime": mime,
                "deliveryExt": delivery_ext,
            },
        )
        return str(out_path), mime, delivery_ext


if __name__ == "__main__":
    LibreOfficeWorker().run()
