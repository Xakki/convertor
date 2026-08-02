"""Tests for LibreOfficeWorker.convert() — Phase 1 stream-based document worker.

soffice/pandoc/pdftotext/pdftoppm are mocked (no binaries in the unit-test env): each mock
writes a dummy output so the worker's post-conversion checks pass. This exercises
the (source,target) routing, format validation, output placement, MIME selection,
and the conv.document streams subscription — without real LibreOffice.
"""

from pathlib import Path
from unittest.mock import patch
from zipfile import ZipFile

import pytest

from workers.libreoffice.worker import (
    LibreOfficeWorker,
    _MATRIX,
    _MIME,
    _PAGES_IMPORT_OK,
)


def _make_job(conv_id: int, input_path: Path, src_fmt: str, tgt_fmt: str) -> dict:
    return {
        "conversionId": conv_id,
        "inputBucket": "convertor-inputs",
        "inputKey": f"inputs/{Path(input_path).name}",
        "_localInput": str(input_path),
        "originalFilename": Path(input_path).name,
        "sourceFormat": src_fmt,
        "targetFormat": tgt_fmt,
        "category": "document",
        "isAi": False,
        "options": [],
    }


def _worker(tmp_path: Path) -> LibreOfficeWorker:
    import workers.common.stream_consumer as sc_mod
    import workers.libreoffice.worker as lo_mod

    worker = LibreOfficeWorker()
    patch.object(lo_mod, "WORK_DIR", tmp_path).start()
    patch.object(sc_mod, "WORK_DIR", tmp_path).start()
    return worker


def _src(tmp_path: Path, name: str) -> Path:
    p = tmp_path / name
    p.write_bytes(b"fake-document-bytes")
    return p


# ---------------------------------------------------------------------------
# Subscription wiring
# ---------------------------------------------------------------------------

def test_subscribes_to_document_stream():
    assert LibreOfficeWorker.CAPABILITIES["routing_keys"] == ["document"]


def test_matrix_mime_coverage():
    """Every target format reachable in the matrix must have a MIME entry."""
    missing = {t for targets in _MATRIX.values() for t in targets if t not in _MIME}
    assert not missing, f"missing MIME for: {missing}"


# ---------------------------------------------------------------------------
# convert() — happy paths (engines mocked to write a dummy output)
# ---------------------------------------------------------------------------

class _Engines:
    """Patch every subprocess helper to drop a dummy file at the expected path."""

    def __enter__(self):
        async def fake_soffice(src, out_dir, convert_to):
            ext = convert_to.split(":")[0]
            (Path(out_dir) / f"{Path(src).stem}.{ext}").write_bytes(b"soffice-out")

        async def fake_pandoc(src, out_path, reader, writer):
            Path(out_path).write_bytes(b"pandoc-out")

        async def fake_pdftotext(src, out_path):
            Path(out_path).write_bytes(b"pdf-text")

        async def fake_pdftoppm(src, out_dir, prefix):
            page = Path(out_dir) / f"{prefix}-1.jpg"
            page.write_bytes(b"jpeg-bytes")
            return [page]

        self._patches = [
            patch("workers.libreoffice.worker.run_soffice", side_effect=fake_soffice),
            patch("workers.libreoffice.worker.run_pandoc", side_effect=fake_pandoc),
            patch("workers.libreoffice.worker.run_pdftotext", side_effect=fake_pdftotext),
            patch("workers.libreoffice.worker.run_pdftoppm", side_effect=fake_pdftoppm),
        ]
        for p in self._patches:
            p.start()
        return self

    def __exit__(self, *exc):
        for p in self._patches:
            p.stop()


class TestLibreOfficeConvert:
    def _run(self, tmp_path, conv_id, name, src_fmt, tgt_fmt):
        src = _src(tmp_path, name)
        worker = _worker(tmp_path)
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), _Engines():
            return worker.convert(_make_job(conv_id, src, src_fmt, tgt_fmt))

    def test_docx_to_pdf(self, tmp_path):
        out_path, mime, ext = self._run(tmp_path, 1, "in.docx", "docx", "pdf")
        assert Path(out_path).exists()
        assert ext == "pdf"
        assert mime == "application/pdf"

    def test_doc_to_docx(self, tmp_path):
        out_path, mime, ext = self._run(tmp_path, 2, "in.doc", "doc", "docx")
        assert ext == "docx"
        assert mime == _MIME["docx"]

    def test_doc_to_odt(self, tmp_path):
        _, mime, ext = self._run(tmp_path, 3, "in.doc", "doc", "odt")
        assert ext == "odt"
        assert mime == _MIME["odt"]

    def test_doc_to_txt(self, tmp_path):
        _, _, ext = self._run(tmp_path, 4, "in.doc", "doc", "txt")
        assert ext == "txt"

    def test_doc_to_epub(self, tmp_path):
        _, mime, ext = self._run(tmp_path, 5, "in.doc", "doc", "epub")
        assert ext == "epub"
        assert mime == _MIME["epub"]

    def test_docx_to_md_via_pandoc(self, tmp_path):
        out_path, mime, ext = self._run(tmp_path, 6, "in.docx", "docx", "md")
        assert ext == "md"
        assert mime == "text/markdown"
        assert Path(out_path).exists()

    def test_doc_to_md_chains_soffice_then_pandoc(self, tmp_path):
        _, _, ext = self._run(tmp_path, 7, "in.doc", "doc", "md")
        assert ext == "md"

    def test_md_to_docx_via_pandoc(self, tmp_path):
        out_path, _, ext = self._run(tmp_path, 8, "in.md", "md", "docx")
        assert ext == "docx"
        assert Path(out_path).exists()

    def test_md_to_pdf_chains_pandoc_then_soffice(self, tmp_path):
        _, mime, ext = self._run(tmp_path, 9, "in.md", "md", "pdf")
        assert ext == "pdf"
        assert mime == "application/pdf"

    def test_pdf_to_txt_via_pdftotext(self, tmp_path):
        out_path, _, ext = self._run(tmp_path, 10, "in.pdf", "pdf", "txt")
        assert ext == "txt"
        assert Path(out_path).read_bytes() == b"pdf-text"

    def test_pdf_to_md_via_pdftotext(self, tmp_path):
        _, mime, ext = self._run(tmp_path, 11, "in.pdf", "pdf", "md")
        assert ext == "md"
        assert mime == "text/markdown"

    def test_pdf_to_docx_chains_pdftotext_then_soffice(self, tmp_path):
        _, mime, ext = self._run(tmp_path, 12, "in.pdf", "pdf", "docx")
        assert ext == "docx"
        assert mime == _MIME["docx"]

    def test_pdf_to_jpg_single_page(self, tmp_path):
        out_path, mime, ext = self._run(tmp_path, 13, "in.pdf", "pdf", "jpg")
        assert ext == "jpg"
        assert mime == "image/jpeg"
        assert Path(out_path).exists()

    def test_pdf_to_jpg_multipage_zip(self, tmp_path):
        src = _src(tmp_path, "in.pdf")
        worker = _worker(tmp_path)

        async def fake_multipage(src_, out_dir, prefix):
            pages = []
            for i in (1, 2):
                p = Path(out_dir) / f"{prefix}-{i}.jpg"
                p.write_bytes(f"page{i}".encode())
                pages.append(p)
            return pages

        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), \
             patch("workers.libreoffice.worker.run_pdftoppm", side_effect=fake_multipage):
            out_path, mime, ext = worker.convert(_make_job(14, src, "pdf", "jpg"))

        assert ext == "zip"
        assert mime == "application/zip"
        with ZipFile(out_path) as zf:
            names = sorted(zf.namelist())
        assert names == ["page-001.jpg", "page-002.jpg"]

    def test_pdf_to_jpg_rejects_oversized_pdf(self, tmp_path):
        import workers.libreoffice.worker as lo_mod

        src = _src(tmp_path, "in.pdf")
        worker = _worker(tmp_path)

        async def fake_page_count(_src):
            return 51

        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), \
             patch.object(lo_mod, "PDFTOPPM_MAX_PAGES", 50), \
             patch("workers.libreoffice.worker._pdf_page_count", side_effect=fake_page_count):
            with pytest.raises(ValueError, match="exceeds PDFTOPPM_MAX_PAGES"):
                worker.convert(_make_job(27, src, "pdf", "jpg"))

    def test_html_to_pdf_via_soffice(self, tmp_path):
        _, _, ext = self._run(tmp_path, 15, "in.html", "html", "pdf")
        assert ext == "pdf"

    def test_epub_to_md_via_pandoc(self, tmp_path):
        out_path, mime, ext = self._run(tmp_path, 16, "in.epub", "epub", "md")
        assert ext == "md"
        assert mime == "text/markdown"
        assert Path(out_path).exists()

    def test_epub_to_docx_via_pandoc(self, tmp_path):
        _, mime, ext = self._run(tmp_path, 17, "in.epub", "epub", "docx")
        assert ext == "docx"
        assert mime == _MIME["docx"]

    def test_xlsx_to_pdf_via_soffice(self, tmp_path):
        _, mime, ext = self._run(tmp_path, 18, "in.xlsx", "xlsx", "pdf")
        assert ext == "pdf"
        assert mime == "application/pdf"

    def test_pptx_to_pdf_via_soffice(self, tmp_path):
        _, mime, ext = self._run(tmp_path, 19, "in.pptx", "pptx", "pdf")
        assert ext == "pdf"
        assert mime == "application/pdf"

    def test_rst_to_html_via_pandoc_soffice(self, tmp_path):
        _, mime, ext = self._run(tmp_path, 20, "in.rst", "rst", "html")
        assert ext == "html"
        assert mime == _MIME["html"]

    def test_output_in_work_dir_with_conv_id(self, tmp_path):
        out_path, _, ext = self._run(tmp_path, 99, "in.docx", "docx", "pdf")
        name = Path(out_path).name
        assert Path(out_path).parent == tmp_path
        assert name.startswith("out-99-")
        assert name.endswith(f".{ext}")

    def test_job_dir_cleaned_up(self, tmp_path):
        self._run(tmp_path, 100, "in.docx", "docx", "pdf")
        assert not list(tmp_path.glob("lo-*")), "job_dir leaked"


# ---------------------------------------------------------------------------
# Error / unsupported-format cases
# ---------------------------------------------------------------------------

class TestLibreOfficeConvertErrors:
    def test_unsupported_source_raises(self, tmp_path):
        src = _src(tmp_path, "in.dwg")
        worker = _worker(tmp_path)
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported source format"):
                worker.convert(_make_job(20, src, "dwg", "pdf"))

    def test_unsupported_conversion_raises(self, tmp_path):
        src = _src(tmp_path, "in.docx")
        worker = _worker(tmp_path)
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported conversion"):
                worker.convert(_make_job(21, src, "docx", "jpg"))

    def test_pdf_to_jpg_in_matrix(self, tmp_path):
        assert "jpg" in _MATRIX["pdf"]

    def test_epub_source_pandoc_targets(self, tmp_path):
        assert _MATRIX["epub"] == {"docx", "html", "md", "odt", "rtf", "txt"}

    def test_epub_to_pdf_rejected(self, tmp_path):
        src = _src(tmp_path, "in.epub")
        worker = _worker(tmp_path)
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported conversion"):
                worker.convert(_make_job(24, src, "epub", "pdf"))

    def test_pptx_to_docx_rejected(self, tmp_path):
        src = _src(tmp_path, "in.pptx")
        worker = _worker(tmp_path)
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported conversion"):
                worker.convert(_make_job(26, src, "pptx", "docx"))

    @pytest.mark.skipif(_PAGES_IMPORT_OK, reason="pages supported when libetonyek present")
    def test_pages_source_rejected_without_libetonyek(self, tmp_path):
        assert "pages" not in _MATRIX
        src = _src(tmp_path, "in.pages")
        worker = _worker(tmp_path)
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported source format"):
                worker.convert(_make_job(25, src, "pages", "pdf"))

    @pytest.mark.skipif(not _PAGES_IMPORT_OK, reason="pages requires libetonyek in image")
    def test_pages_to_pdf_via_soffice(self, tmp_path):
        out_path, mime, ext = TestLibreOfficeConvert()._run(
            tmp_path, 28, "in.pages", "pages", "pdf"
        )
        assert ext == "pdf"
        assert mime == "application/pdf"
        assert Path(out_path).exists()

    def test_missing_input_raises(self, tmp_path):
        worker = _worker(tmp_path)
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
            with pytest.raises(FileNotFoundError):
                worker.convert(_make_job(22, tmp_path / "nope.docx", "docx", "pdf"))

    def test_no_output_produced_raises(self, tmp_path):
        src = _src(tmp_path, "in.docx")
        worker = _worker(tmp_path)

        async def fake_noop(src_, out_dir, convert_to):
            pass

        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), \
             patch("workers.libreoffice.worker.run_soffice", side_effect=fake_noop):
            with pytest.raises(RuntimeError, match="no output"):
                worker.convert(_make_job(23, src, "docx", "pdf"))
