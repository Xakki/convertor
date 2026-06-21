"""Integration test for LibreOfficeWorker — real soffice/pandoc, marker `integration`.

Unlike test_libreoffice_worker.py (engines mocked), this drives the worker's
actual conversion path on real fixtures (workers/tests/example_files/Test.doc,
Test.odt, Test.pdf). Skipped when soffice/pandoc/pdftotext are not on PATH so a
plain `pytest workers/tests` run stays green.
"""

import shutil
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

from workers.libreoffice.worker import LibreOfficeWorker

pytestmark = pytest.mark.integration

EXAMPLES = Path(__file__).parent / "example_files"

requires_soffice = pytest.mark.skipif(
    shutil.which("soffice") is None, reason="soffice not installed / not on PATH"
)
requires_pandoc = pytest.mark.skipif(
    shutil.which("pandoc") is None, reason="pandoc not installed / not on PATH"
)
requires_pdftotext = pytest.mark.skipif(
    shutil.which("pdftotext") is None, reason="pdftotext not installed / not on PATH"
)


def _build_worker() -> LibreOfficeWorker:
    import workers.common.stream_consumer as sc_mod

    with patch.object(sc_mod, "REDIS_HOST", "localhost"), \
         patch("workers.common.stream_consumer.redis.Redis", return_value=MagicMock()):
        return LibreOfficeWorker()


def _job(conv_id: int, src: Path, src_fmt: str, tgt_fmt: str) -> dict:
    return {
        "conversionId": conv_id,
        "inputBucket": "convertor-inputs",
        "inputKey": f"inputs/{src.name}",
        "_localInput": str(src),
        "originalFilename": src.name,
        "sourceFormat": src_fmt,
        "targetFormat": tgt_fmt,
        "category": "document",
        "isAi": False,
        "subType": None,
        "options": [],
    }


def _stage(tmp_path: Path, fixture: str) -> Path:
    src = tmp_path / fixture
    src.write_bytes((EXAMPLES / fixture).read_bytes())
    return src


@requires_soffice
def test_doc_to_pdf_real(tmp_path):
    src = _stage(tmp_path, "Test.doc")
    worker = _build_worker()
    with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = worker.convert(_job(1, src, "doc", "pdf"))
    out = Path(out_path)
    assert out.exists() and out.stat().st_size > 0
    assert ext == "pdf" and mime == "application/pdf"
    assert out.read_bytes()[:4] == b"%PDF", "output is not a PDF"


@requires_soffice
@requires_pandoc
def test_odt_to_md_real(tmp_path):
    src = _stage(tmp_path, "Test.odt")
    worker = _build_worker()
    with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = worker.convert(_job(2, src, "odt", "md"))
    out = Path(out_path)
    assert out.exists() and out.stat().st_size > 0
    assert ext == "md" and mime == "text/markdown"


@requires_pdftotext
def test_pdf_to_txt_real(tmp_path):
    src = _stage(tmp_path, "Test.pdf")
    worker = _build_worker()
    with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = worker.convert(_job(3, src, "pdf", "txt"))
    out = Path(out_path)
    assert out.exists() and out.stat().st_size > 0
    assert ext == "txt" and mime == "text/plain"
