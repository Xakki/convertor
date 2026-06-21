"""Document / markup conversion worker — Phase 1, XREADGROUP-based.

Consumes stream conv.document (consumer group convertor). PHP folds the `markup`
category into `document` at routing time, so this single worker handles both
office documents and markup (md/html) sources. Reads the local input the base
class downloaded from S3 (job['_localInput']), runs LibreOffice (headless
soffice) + pandoc + poppler (pdftotext), writes the output under WORK_DIR, and
returns (out_path, mime, target_ext) for the base class to upload to -results.

Engine selection is by the (sourceFormat, targetFormat) pair — the worker is
flag-agnostic (it never reads ocr/subType):
  - target md            → pandoc (pdf source: pdftotext text wrapped as md)
  - pdf source           → poppler pdftotext (txt/md) or pdftotext→soffice (docx)
  - md                   → pandoc to a soffice-importable form, then soffice
                           handles pdf/odt/rtf/epub/etc.
  - epub source          → md only (pandoc); soffice cannot IMPORT epub
  - everything else       → soffice --convert-to <filter>

Note: `pages` (Apple Pages) source is deferred — soffice import unverified.

Stage-7 (deferred, NOT handled here): spreadsheets (xls/xlsx/ods/csv),
presentations (ppt/pptx/odp), CAD (dwg/dxf), and PDF→jpg-per-page. Those pairs
are rejected with ValueError → retried then DLQ'd by the base class.

soffice runs in a private UserInstallation per job so concurrent conversions
don't fight over ~/.config/libreoffice.
"""

from __future__ import annotations

import asyncio
import logging
import os
import shutil
import tempfile
import uuid
from pathlib import Path
from typing import Any

from workers.common.stream_consumer import WORK_DIR, StreamConsumerBase

logger = logging.getLogger(__name__)

SOFFICE_TIMEOUT = int(os.getenv("SOFFICE_TIMEOUT", "180"))

_DOCX_MIME = (
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
)

# MIME types for output formats
_MIME: dict[str, str] = {
    "docx": _DOCX_MIME,
    "odt":  "application/vnd.oasis.opendocument.text",
    "rtf":  "application/rtf",
    "pdf":  "application/pdf",
    "txt":  "text/plain",
    "html": "text/html",
    "md":   "text/markdown",
    "epub": "application/epub+zip",
    "rst":  "text/x-rst",
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
}

# pandoc reader name per source extension.
_PANDOC_READER: dict[str, str] = {
    "md":   "gfm",
    "html": "html",
    "htm":  "html",
    "docx": "docx",
    "odt":  "odt",
    "epub": "epub",
}

# Conversion matrix the worker advertises (CAPABILITIES) and validates against.
# Mirrors ROADMAP Stage-1 rows (Документы / PDF / Разметка md+html). Stage-7 pairs
# (spreadsheets, presentations, CAD, pdf→jpg, rst/latex/wiki) are intentionally
# absent → rejected by convert(). epub is a valid TARGET (soffice exports epub),
# but epub SOURCE = md only (soffice cannot import epub; epub→md works via pandoc).
# `pages` (Apple Pages) SOURCE is deferred — import unverified.
_OFFICE_TARGETS: set[str] = {"docx", "odt", "pdf", "txt", "html", "md", "rtf", "epub"}

_MATRIX: dict[str, set[str]] = {
    # office documents
    "doc":   _OFFICE_TARGETS,
    "docx":  _OFFICE_TARGETS,
    "odt":   _OFFICE_TARGETS,
    "rtf":   _OFFICE_TARGETS,
    "txt":   _OFFICE_TARGETS,
    "html":  _OFFICE_TARGETS,
    "htm":   _OFFICE_TARGETS,
    # epub source: only →md (pandoc); soffice cannot import epub
    "epub":  {"md"},
    # pdf → text extraction (NOT OCR; jpg-per-page is Stage 7)
    "pdf":   {"docx", "txt", "md"},
    # markup (Stage-1: md/html only)
    "md":    {"md", "html", "pdf", "docx", "odt", "rtf", "txt", "epub"},
}


# --------------------------------------------------------------------------
# subprocess helpers
# --------------------------------------------------------------------------

async def _run(argv: list[str], timeout: int = SOFFICE_TIMEOUT) -> None:
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
        raise RuntimeError(f"{argv[0]} timed out after {timeout}s")
    if proc.returncode != 0:
        err = err_b.decode("utf-8", "replace").strip()
        out = out_b.decode("utf-8", "replace").strip()
        raise RuntimeError(f"{argv[0]} failed: {err or out or proc.returncode}")


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


async def run_pandoc(src: Path, out_path: Path, reader: str, writer: str) -> None:
    argv = ["pandoc", "--from", reader, "--to", writer, "-o", str(out_path)]
    if writer == "gfm":
        argv += ["--wrap=none", f"--extract-media={out_path.parent / 'media'}"]
    argv.append(str(src))
    await _run(argv)


# --------------------------------------------------------------------------
# conversion routing
# --------------------------------------------------------------------------

async def _convert(src: Path, src_fmt: str, target: str, work_dir: Path) -> Path:
    """Produce <work_dir>/<stem>.<target> from src, choosing the engine by pair."""
    stem = src.stem
    out = work_dir / f"{stem}.{target}"

    # --- PDF source: poppler text extraction, optionally chained to soffice ---
    if src_fmt == "pdf":
        with tempfile.TemporaryDirectory(prefix="pdf-tmp-") as tmp:
            txt_path = Path(tmp) / f"{stem}.txt"
            await run_pdftotext(src, txt_path)
            if target in ("txt", "md"):
                out.write_bytes(txt_path.read_bytes())
                return out
            if target == "docx":
                await run_soffice(txt_path, work_dir, _SOFFICE_FILTER["docx"])
                return out
        raise ValueError(f"unsupported pdf target: {target}")

    # --- target md: always pandoc (soffice can't write markdown) -------------
    if target == "md":
        if src_fmt in _PANDOC_READER:
            await run_pandoc(src, out, _PANDOC_READER[src_fmt], "gfm")
            return out
        # office source pandoc can't read directly → soffice to docx, then pandoc
        with tempfile.TemporaryDirectory(prefix="md-tmp-") as tmp:
            tmp_dir = Path(tmp)
            await run_soffice(src, tmp_dir, _SOFFICE_FILTER["docx"])
            await run_pandoc(tmp_dir / f"{stem}.docx", out, "docx", "gfm")
            return out

    # --- markup source (md): pandoc to a soffice form, then soffice ----------
    if src_fmt == "md":
        # md is not soffice-importable. Render to docx via pandoc; soffice then
        # produces the requested office/pdf target. (html IS soffice-importable
        # and falls through to the plain soffice path below.)
        if target == "docx":
            await run_pandoc(src, out, _PANDOC_READER[src_fmt], "docx")
            return out
        with tempfile.TemporaryDirectory(prefix="mk-tmp-") as tmp:
            tmp_dir = Path(tmp)
            await run_pandoc(src, tmp_dir / f"{stem}.docx", _PANDOC_READER[src_fmt], "docx")
            await run_soffice(tmp_dir / f"{stem}.docx", work_dir, _SOFFICE_FILTER[target])
            return out

    # --- plain soffice path (office sources + html) --------------------------
    if target not in _SOFFICE_FILTER:
        raise ValueError(f"unsupported target: {target}")
    await run_soffice(src, work_dir, _SOFFICE_FILTER[target])
    return out


class LibreOfficeWorker(StreamConsumerBase):
    """Document/markup converter via LibreOffice (soffice) + pandoc + poppler."""

    CAPABILITIES: dict[str, Any] = {
        "routing_keys": ["document"],
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

        WORK_DIR.mkdir(parents=True, exist_ok=True)
        # soffice/pandoc name outputs by the input stem, so convert inside a
        # unique per-job dir to avoid collisions (and to isolate pandoc's media/
        # dir from concurrent jobs), then expose the produced file flat in
        # WORK_DIR for the base class to upload + clean. job_dir is torn down here
        # since the base only unlinks the two flat tmp files it knows about.
        job_dir = WORK_DIR / f"lo-{conv_id}-{uuid.uuid4().hex}"
        job_dir.mkdir(parents=True, exist_ok=True)
        out_path = WORK_DIR / f"out-{conv_id}-{uuid.uuid4().hex}.{target_fmt}"
        try:
            # Pass the base-downloaded _localInput (already a unique in-<uuid>.<ext>
            # tmp under WORK_DIR) straight in — no full-file staging copy (OOM risk
            # at the 500MB paid tier). job_dir isolates pandoc's media/ + soffice
            # outputs; src.stem is uuid-unique so there's no output stem collision.
            produced = asyncio.run(_convert(src, src_fmt, target_fmt, job_dir))

            if not produced.exists():
                raise RuntimeError(
                    f"conversion produced no output for conversionId={conv_id}"
                )

            # Atomic move out of job_dir (same filesystem under WORK_DIR) — no copy.
            os.replace(str(produced), str(out_path))
        finally:
            shutil.rmtree(job_dir, ignore_errors=True)

        mime = _MIME.get(target_fmt, "application/octet-stream")
        logger.info(
            "document converted",
            extra={
                "conversionId": conv_id,
                "src": src.name,
                "out": out_path.name,
                "mime": mime,
            },
        )
        return str(out_path), mime, target_fmt


if __name__ == "__main__":
    LibreOfficeWorker().run()
