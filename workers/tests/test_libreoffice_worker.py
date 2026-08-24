"""Tests for LibreOfficeWorker.convert() — Phase 1 stream-based document worker.

soffice/pandoc/pdftotext/pdftoppm are mocked (no binaries in the unit-test env): each mock
writes a dummy output so the worker's post-conversion checks pass. This exercises
the (source,target) routing, format validation, output placement, MIME selection,
and the conv.document streams subscription — without real LibreOffice.
"""

import sys
from pathlib import Path
from unittest.mock import patch
from zipfile import ZipFile

import pytest

from workers.libreoffice.worker import (
    LibreOfficeWorker,
    _MATRIX,
    _MIME,
    _apply_docx_orientation,
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

    def test_pages_unconditional_in_matrix(self):
        """pages is a plain matrix entry now — libetonyek is an execution-time
        guard, not a matrix gate (see worker.py docstring)."""
        assert "pages" in _MATRIX

    def test_pages_source_rejected_without_libetonyek(self, tmp_path):
        """Execution-time guard: matrix accepts pages, but convert() must fail
        the job with a permanent (ValueError) error when libetonyek is
        missing, rather than attempting soffice and producing a confusing
        failure. _PAGES_IMPORT_OK is patched so this is deterministic
        regardless of whether the test image actually has libetonyek."""
        src = _src(tmp_path, "in.pages")
        worker = _worker(tmp_path)
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), \
             patch("workers.libreoffice.worker._PAGES_IMPORT_OK", False):
            with pytest.raises(ValueError, match="libetonyek"):
                worker.convert(_make_job(25, src, "pages", "pdf"))

    def test_pages_to_pdf_via_soffice(self, tmp_path):
        """Happy path — libetonyek present, conversion proceeds normally."""
        with patch("workers.libreoffice.worker._PAGES_IMPORT_OK", True):
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


# ---------------------------------------------------------------------------
# CNV-98 — document settings application (pageRange/orientation/markdownDialect)
# Routing/call-argument assertions with mocked engines. Real end-to-end fixture
# tests (actual page counts/sizes via pdfinfo) live in
# test_libreoffice_integration.py — real soffice/pandoc are required there
# because _apply_docx_orientation needs a real .docx (python-docx cannot open
# the dummy bytes _Engines writes).
# ---------------------------------------------------------------------------

class TestLibreOfficeDocumentSettings:
    def test_reversed_page_range_rejected(self, tmp_path):
        src = _src(tmp_path, "in.txt")
        worker = _worker(tmp_path)
        job = _make_job(200, src, "txt", "pdf")
        job["options"] = {"pageRange": "5-3"}
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="reversed"):
                worker.convert(job)

    def test_reversed_page_range_in_later_element_rejected(self, tmp_path):
        src = _src(tmp_path, "in.txt")
        worker = _worker(tmp_path)
        job = _make_job(2001, src, "txt", "pdf")
        job["options"] = {"pageRange": "1,9-7"}
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="reversed"):
                worker.convert(job)

    def test_equal_page_range_bounds_not_reversed(self, tmp_path):
        """a==b (e.g. "3-3") is a valid single-page range, not reversed."""
        src = _src(tmp_path, "in.txt")
        worker = _worker(tmp_path)
        job = _make_job(201, src, "txt", "pdf")
        job["options"] = {"pageRange": "3-3"}
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), _Engines():
            out_path, mime, ext = worker.convert(job)
        assert ext == "pdf"

    def test_docx_target_ignores_orientation_and_page_range_even_if_present(self, tmp_path):
        """DOCX/ODT pairs always arrive with options={} in production (CNV-97
        assigns them no profile) — but even if orientation/pageRange somehow
        showed up in the payload, the worker must not apply them to a non-pdf
        target: only target=="pdf" reads these keys."""
        src = _src(tmp_path, "in.odt")
        worker = _worker(tmp_path)
        job = _make_job(202, src, "odt", "docx")
        job["options"] = {"orientation": "landscape", "pageRange": "1,3"}
        calls: list[str] = []

        async def fake_soffice(src_, out_dir, convert_to):
            calls.append(convert_to)
            (Path(out_dir) / f"{Path(src_).stem}.docx").write_bytes(b"soffice-out")

        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), \
             patch("workers.libreoffice.worker.run_soffice", side_effect=fake_soffice):
            worker.convert(job)
        assert calls == ["docx"], f"orientation/pageRange must not affect a non-pdf target, got {calls}"

    def test_pdf_target_no_options_stays_single_step(self, tmp_path):
        """No orientation/pageRange → the original single soffice call, unchanged
        (no intermediate .docx materialized) for every non-triangle pdf-target
        pair (docx/xlsx/pptx/html/pages→pdf etc., which always get options={})."""
        src = _src(tmp_path, "in.docx")
        worker = _worker(tmp_path)
        job = _make_job(2021, src, "docx", "pdf")  # options == [] by default → {}
        calls: list[str] = []

        async def fake_soffice(src_, out_dir, convert_to):
            calls.append(convert_to)
            ext = convert_to.split(":")[0]
            (Path(out_dir) / f"{Path(src_).stem}.{ext}").write_bytes(b"soffice-out")

        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), \
             patch("workers.libreoffice.worker.run_soffice", side_effect=fake_soffice):
            worker.convert(job)
        assert calls == ["pdf"], f"expected a single plain PDF export, got {calls}"

    def test_page_range_translates_comma_to_semicolon_in_filter_data(self, tmp_path):
        src = _src(tmp_path, "in.txt")
        worker = _worker(tmp_path)
        job = _make_job(203, src, "txt", "pdf")
        job["options"] = {"pageRange": "1,3-4"}
        calls: list[str] = []

        async def fake_soffice(src_, out_dir, convert_to):
            calls.append(convert_to)
            ext = convert_to.split(":")[0]
            (Path(out_dir) / f"{Path(src_).stem}.{ext}").write_bytes(b"soffice-out")

        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), \
             patch("workers.libreoffice.worker.run_soffice", side_effect=fake_soffice):
            worker.convert(job)
        assert len(calls) == 1
        assert calls[0].startswith("pdf:writer_pdf_Export:")
        assert '"value": "1;3-4"' in calls[0], f"comma not translated to semicolon: {calls[0]}"

    def test_orientation_routes_through_intermediate_docx_then_pdf_filter(self, tmp_path):
        """orientation requires materializing an intermediate .docx (page setup is
        edited there, see _apply_docx_orientation) BEFORE the final PDF export —
        this pins the call SEQUENCE and that pageRange still rides along on the
        final export. _apply_docx_orientation itself is mocked here (it needs a
        real .docx, which _Engines-style dummy bytes are not) — its real effect
        on PDF page geometry is proven in test_libreoffice_integration.py."""
        src = _src(tmp_path, "in.txt")
        worker = _worker(tmp_path)
        job = _make_job(204, src, "txt", "pdf")
        job["options"] = {"orientation": "landscape", "pageRange": "1,3"}
        calls: list[str] = []

        async def fake_soffice(src_, out_dir, convert_to):
            calls.append(convert_to)
            ext = convert_to.split(":")[0]
            (Path(out_dir) / f"{Path(src_).stem}.{ext}").write_bytes(b"soffice-out")

        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), \
             patch("workers.libreoffice.worker.run_soffice", side_effect=fake_soffice), \
             patch("workers.libreoffice.worker._apply_docx_orientation") as mock_orient:
            worker.convert(job)

        assert calls[0] == "docx", "must materialize an intermediate .docx first"
        assert calls[1].startswith("pdf:writer_pdf_Export:")
        assert '"value": "1;3"' in calls[1]
        mock_orient.assert_called_once()
        assert mock_orient.call_args[0][1] == "landscape"

    def test_apply_docx_orientation_swaps_landscape_back_to_portrait(self, tmp_path):
        """Direct unit test on _apply_docx_orientation itself (no soffice needed
        — pure python-docx round-trip, unlike the mocked-call test above). Pins
        the swap-BACK-to-portrait direction: a reviewer mutation of the guard
        from `if is_landscape != wants_landscape:` to `if wants_landscape and
        is_landscape != wants_landscape:` silently drops this branch (portrait
        never triggers a swap) while every existing test (incl. the mocked one
        above, which never inspects real geometry) stayed green. See CNV-98
        Execution Log / grooming card for the can-fail proof of this test."""
        docx_lib = pytest.importorskip("docx", reason="python-docx only in the libreoffice worker image")
        from docx.enum.section import WD_ORIENT
        from docx.shared import Inches

        docx_path = tmp_path / "landscape-default.docx"
        document = docx_lib.Document()
        section = document.sections[0]
        section.page_width, section.page_height = Inches(11), Inches(8.5)
        section.orientation = WD_ORIENT.LANDSCAPE
        document.save(str(docx_path))

        _apply_docx_orientation(docx_path, "portrait")

        result = docx_lib.Document(str(docx_path))
        section = result.sections[0]
        assert section.page_width < section.page_height, (
            f"expected portrait geometry after swap-back, got "
            f"{section.page_width}x{section.page_height}"
        )
        assert section.orientation == WD_ORIENT.PORTRAIT

    def test_orientation_missing_python_docx_fails_permanent_not_retried(self, tmp_path, monkeypatch):
        """CNV-98 hardening: on a stale worker image (built before python-docx
        was added to requirements-libreoffice.txt), `import docx` inside
        _apply_docx_orientation raises ImportError. Uncaught, that ImportError
        would fall into stream_consumer.py's generic `except Exception`
        (permanent=False) and the job would retry forever on an error no retry
        can ever fix. It must surface as ValueError (permanent=True — same
        contract as the reversed-pageRange rejection above), with a message
        naming the actual cause. Simulates the missing dependency by forcing
        `import docx` to fail (sys.modules[name] = None is the standard trick:
        the import system raises ImportError for any module present in
        sys.modules with value None) rather than actually uninstalling the
        package from this test image."""
        src = _src(tmp_path, "in.txt")
        worker = _worker(tmp_path)
        job = _make_job(2041, src, "txt", "pdf")
        job["options"] = {"orientation": "landscape"}

        async def fake_soffice(src_, out_dir, convert_to):
            (Path(out_dir) / f"{Path(src_).stem}.docx").write_bytes(b"soffice-out")

        monkeypatch.setitem(sys.modules, "docx", None)

        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), \
             patch("workers.libreoffice.worker.run_soffice", side_effect=fake_soffice):
            with pytest.raises(ValueError, match="python-docx"):
                worker.convert(job)

    def test_txt_to_md_threads_markdown_dialect_to_pandoc_writer(self, tmp_path):
        src = _src(tmp_path, "in.txt")
        worker = _worker(tmp_path)
        job = _make_job(205, src, "txt", "md")
        job["options"] = {"markdownDialect": "markdown_strict"}
        pandoc_calls: list[tuple[str, str]] = []

        async def fake_pandoc(src_, out_path, reader, writer):
            pandoc_calls.append((reader, writer))
            Path(out_path).write_bytes(b"pandoc-out")

        async def fake_soffice(src_, out_dir, convert_to):
            (Path(out_dir) / f"{Path(src_).stem}.docx").write_bytes(b"soffice-out")

        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), \
             patch("workers.libreoffice.worker.run_soffice", side_effect=fake_soffice), \
             patch("workers.libreoffice.worker.run_pandoc", side_effect=fake_pandoc):
            worker.convert(job)
        assert pandoc_calls == [("docx", "markdown_strict")]

    def test_txt_to_md_default_dialect_is_gfm(self, tmp_path):
        """No markdownDialect option → writer stays "gfm" (pre-CNV-98 default,
        unchanged for every non-triangle →md pair too)."""
        src = _src(tmp_path, "in.txt")
        worker = _worker(tmp_path)
        job = _make_job(206, src, "txt", "md")  # options == [] by default → {}
        pandoc_calls: list[tuple[str, str]] = []

        async def fake_pandoc(src_, out_path, reader, writer):
            pandoc_calls.append((reader, writer))
            Path(out_path).write_bytes(b"pandoc-out")

        async def fake_soffice(src_, out_dir, convert_to):
            (Path(out_dir) / f"{Path(src_).stem}.docx").write_bytes(b"soffice-out")

        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), \
             patch("workers.libreoffice.worker.run_soffice", side_effect=fake_soffice), \
             patch("workers.libreoffice.worker.run_pandoc", side_effect=fake_pandoc):
            worker.convert(job)
        assert pandoc_calls == [("docx", "gfm")]

    def test_pdf_to_md_dialect_option_has_no_effect_on_verbatim_wrap(self, tmp_path):
        """CNV-98 scoped decision (Execution Log, ack team-lead): pdf→md keeps its
        pre-existing verbatim pdftotext wrap — routing raw -layout text through
        pandoc risks corrupting extraction (4-space indent → code blocks).
        markdownDialect is assigned to this pair by CNV-97 but has NO effect
        here; only txt→md genuinely threads it. This pins that scoped no-op
        (no can-fail mutation: there is no dialect-reading code on this path
        to break — see report point 6)."""
        src = _src(tmp_path, "in.pdf")
        worker = _worker(tmp_path)
        job = _make_job(207, src, "pdf", "md")
        job["options"] = {"markdownDialect": "markdown_strict"}
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path), _Engines():
            out_path, mime, ext = worker.convert(job)
        assert ext == "md"
        assert Path(out_path).read_bytes() == b"pdf-text", "must stay the raw pdftotext wrap"
