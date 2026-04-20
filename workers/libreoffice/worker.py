"""LibreOffice document conversion worker.

Supports: doc/docx/odt/rtf/txt/html/epub/pdf → docx/odt/txt/md/pdf
Uses soffice, pandoc, pdftotext from libreoffice/app/main.py.
"""

import asyncio
import logging
import os
import sys
import tempfile
from pathlib import Path
from typing import Any

from workers.common.base_worker import SHARE_DIR, BaseWorker
from workers.common.safe_path import safe_share_path

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Import conversion helpers from the existing libreoffice microservice.
# We add the project root to sys.path so the import resolves regardless of
# working directory.
# ---------------------------------------------------------------------------
_PROJECT_ROOT = Path(__file__).resolve().parents[2]
if str(_PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(_PROJECT_ROOT))

try:
    from libreoffice.app.main import (  # type: ignore[import]
        PANDOC_NATIVE,
        SOFFICE_FILTER,
        _pandoc_format,
        convert,
        run_pandoc,
        run_pdftotext,
        run_soffice,
    )
    logger.info("imported conversion helpers from libreoffice.app.main")
except ImportError:
    logger.warning(
        "could not import libreoffice.app.main — using inline fallback implementations"
    )
    # -----------------------------------------------------------------------
    # Inline fallback (exact copies from libreoffice/app/main.py)
    # -----------------------------------------------------------------------
    SOFFICE_TIMEOUT = int(os.getenv("SOFFICE_TIMEOUT", "180"))

    SOFFICE_FILTER = {
        "docx": "docx",
        "txt":  "txt:Text (encoded):UTF8",
    }

    PANDOC_NATIVE: set[str] = {".docx", ".odt", ".html", ".htm", ".epub"}

    async def _run(argv: list[str], timeout: int = SOFFICE_TIMEOUT) -> tuple[str, str]:
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
            raise
        out = out_b.decode("utf-8", "replace")
        err = err_b.decode("utf-8", "replace")
        if proc.returncode != 0:
            raise RuntimeError(err.strip() or out.strip() or f"{argv[0]} exit {proc.returncode}")
        return out, err

    async def run_soffice(src: Path, out_dir: Path, convert_to: str) -> tuple[str, str]:
        with tempfile.TemporaryDirectory(prefix="lo-profile-") as profile:
            return await _run([
                "soffice",
                f"-env:UserInstallation={Path(profile).as_uri()}",
                "--headless", "--norestore", "--nologo", "--nofirststartwizard",
                "--convert-to", convert_to,
                "--outdir", str(out_dir),
                str(src),
            ])

    async def run_pdftotext(src: Path, out_path: Path) -> tuple[str, str]:
        return await _run(["pdftotext", "-layout", "-enc", "UTF-8", str(src), str(out_path)])

    def _pandoc_format(src: Path) -> str:
        return {
            ".docx": "docx", ".odt": "odt",
            ".html": "html", ".htm": "html", ".epub": "epub",
        }.get(src.suffix.lower(), "docx")

    async def run_pandoc(src: Path, out_path: Path, media_dir: Path) -> tuple[str, str]:
        return await _run([
            "pandoc",
            "--from", _pandoc_format(src),
            "--to", "gfm",
            "--wrap=none",
            f"--extract-media={media_dir}",
            "-o", str(out_path),
            str(src),
        ])

    async def convert(src: Path, target: str, work_dir: Path) -> Path:
        stem = src.stem
        suffix = src.suffix.lower()

        if suffix == ".pdf":
            with tempfile.TemporaryDirectory(prefix="pdf-tmp-") as tmp:
                tmp_dir = Path(tmp)
                txt_path = tmp_dir / f"{stem}.txt"
                await run_pdftotext(src, txt_path)
                out = work_dir / f"{stem}.{target}"
                if target in ("txt", "md"):
                    out.write_bytes(txt_path.read_bytes())
                    return out
                if target == "docx":
                    await run_soffice(txt_path, work_dir, SOFFICE_FILTER["docx"])
                    return out
                raise ValueError(f"unsupported target: {target}")

        if target == "txt":
            await run_soffice(src, work_dir, SOFFICE_FILTER["txt"])
            return work_dir / f"{stem}.txt"

        if target == "docx":
            await run_soffice(src, work_dir, SOFFICE_FILTER["docx"])
            return work_dir / f"{stem}.docx"

        if target == "md":
            out = work_dir / f"{stem}.md"
            media = work_dir / "media"
            if suffix in PANDOC_NATIVE:
                await run_pandoc(src, out, media)
                return out
            with tempfile.TemporaryDirectory(prefix="md-tmp-") as tmp:
                tmp_dir = Path(tmp)
                await run_soffice(src, tmp_dir, SOFFICE_FILTER["docx"])
                await run_pandoc(tmp_dir / f"{stem}.docx", out, media)
                return out

        # odt target: soffice handles it natively
        if target == "odt":
            await run_soffice(src, work_dir, "odt")
            return work_dir / f"{stem}.odt"

        # pdf target
        if target == "pdf":
            await run_soffice(src, work_dir, "pdf")
            return work_dir / f"{stem}.pdf"

        raise ValueError(f"unsupported target: {target}")


# Supported input → output format matrix
SUPPORTED: dict[str, set[str]] = {
    "doc":  {"docx", "odt", "txt", "md", "pdf"},
    "docx": {"odt", "txt", "md", "pdf"},
    "odt":  {"docx", "txt", "md", "pdf"},
    "rtf":  {"docx", "odt", "txt", "md", "pdf"},
    "txt":  {"docx", "odt", "md", "pdf"},
    "html": {"docx", "odt", "txt", "md", "pdf"},
    "htm":  {"docx", "odt", "txt", "md", "pdf"},
    "epub": {"docx", "odt", "txt", "md", "pdf"},
    "pdf":  {"docx", "txt", "md"},
    # spreadsheets
    "xls":  {"xlsx", "ods", "csv", "pdf"},
    "xlsx": {"ods", "csv", "pdf"},
    "ods":  {"xlsx", "csv", "pdf"},
    # presentations
    "ppt":  {"pptx", "odp", "pdf"},
    "pptx": {"odp", "pdf"},
    "odp":  {"pptx", "pdf"},
}


class LibreofficeWorker(BaseWorker):
    """Queue worker for document format conversions via LibreOffice/Pandoc."""

    queue_name = "convertor:documents"

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
            raise ValueError(
                f"unsupported conversion: {in_format} -> {output_format}"
            )

        work_dir = src.parent
        out_path = await convert(src, output_format, work_dir)

        if not out_path.exists():
            raise RuntimeError("conversion produced no output file")

        logger.info(
            "converted %s -> %s (task id=%s)", src.name, out_path.name, task.get("id")
        )
        return {"status": "ok", "output_path": str(out_path)}


if __name__ == "__main__":
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    worker = LibreofficeWorker()
    worker.run()
