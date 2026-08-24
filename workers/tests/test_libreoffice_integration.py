"""Integration test for LibreOfficeWorker — real soffice/pandoc, marker `integration`.

Unlike test_libreoffice_worker.py (engines mocked), this drives the worker's
actual conversion path on real fixtures (workers/tests/example_files/Test.doc,
Test.odt, Test.pdf). Skipped when soffice/pandoc/pdftotext are not on PATH so a
plain `pytest workers/tests` run stays green.

CNV-98 additions (page range / orientation / markdownDialect, real soffice —
mocked-engine equivalents with call-argument assertions live in
test_libreoffice_worker.py): page-count / page-size discriminators
(_pdf_page_count/_pdf_page_size) were tuned against live pdfinfo output —
150 plain-text lines paginate to a stable 3 pages, 150 markdown paragraphs to
6 (see Execution Log CNV-98 for the probe that established these numbers).
"""

import shutil
import subprocess
from pathlib import Path
from unittest.mock import patch

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


def _pdf_page_count(pdf_path: Path) -> int:
    out = subprocess.run(["pdfinfo", str(pdf_path)], capture_output=True, text=True, check=True)
    for line in out.stdout.splitlines():
        if line.startswith("Pages:"):
            return int(line.split(":", 1)[1].strip())
    raise RuntimeError("pdfinfo: no 'Pages:' line")


def _pdf_page_size(pdf_path: Path) -> tuple[float, float]:
    out = subprocess.run(["pdfinfo", str(pdf_path)], capture_output=True, text=True, check=True)
    for line in out.stdout.splitlines():
        if line.startswith("Page size:"):
            parts = line.split(":", 1)[1].strip().split()
            return float(parts[0]), float(parts[2])
    raise RuntimeError("pdfinfo: no 'Page size:' line")


# ---------------------------------------------------------------------------
# CNV-98 — pageRange / orientation applied to real PDF output
# ---------------------------------------------------------------------------

@requires_soffice
def test_txt_to_pdf_applies_page_range_multi_element_real(tmp_path):
    """Multi-element pageRange (','-separated, CNV-85/97 grammar) end-to-end.

    150 plain-text lines paginate to a stable 3 pages (see module docstring).
    pageRange "1,3" must translate ',' → ';' for LibreOffice FilterData and
    come back as a 2-page PDF (pages 1 and 3, page 2 dropped). The baseline
    (no options) page count is asserted first so a future font/LO-version
    bump that shifts pagination fails with an obvious "3 != N" message here,
    instead of masquerading as a pageRange regression below.
    """
    text = "\n".join(f"Line {i}" for i in range(150)) + "\n"

    baseline_src = tmp_path / "baseline.txt"
    baseline_src.write_text(text, encoding="utf-8")
    with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
        baseline_out, _, _ = _build_worker().convert(_job(2200, baseline_src, "txt", "pdf"))
    assert _pdf_page_count(Path(baseline_out)) == 3, "pagination assumption drifted — see docstring"

    src = tmp_path / "many-lines.txt"
    src.write_text(text, encoding="utf-8")
    worker = _build_worker()
    job = _job(220, src, "txt", "pdf")
    job["options"] = {"pageRange": "1,3"}
    with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = worker.convert(job)

    assert ext == "pdf" and mime == "application/pdf"
    assert _pdf_page_count(Path(out_path)) == 2


@requires_soffice
def test_txt_to_pdf_applies_orientation_real(tmp_path):
    src = _stage(tmp_path, "smoke.txt")
    worker = _build_worker()
    job = _job(221, src, "txt", "pdf")
    job["options"] = {"orientation": "landscape"}
    with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = worker.convert(job)
    assert ext == "pdf" and mime == "application/pdf"
    width, height = _pdf_page_size(Path(out_path))
    assert width > height, f"expected landscape page, got {width}x{height}"


@requires_soffice
def test_txt_to_pdf_no_options_stays_portrait_real(tmp_path):
    """Baseline for the orientation test above — no options → default (portrait)."""
    src = _stage(tmp_path, "smoke.txt")
    worker = _build_worker()
    with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
        out_path, _, _ = worker.convert(_job(2211, src, "txt", "pdf"))
    width, height = _pdf_page_size(Path(out_path))
    assert width < height, f"expected portrait by default, got {width}x{height}"


@requires_soffice
def test_txt_to_pdf_explicit_portrait_real(tmp_path):
    """orientation:"portrait" is a real catalog value (CNV-97 select: portrait/
    landscape) and CNV-99's frontend will send it explicitly — exercise the
    wants_landscape=False branch, not just the "option absent" default."""
    src = _stage(tmp_path, "smoke.txt")
    worker = _build_worker()
    job = _job(2212, src, "txt", "pdf")
    job["options"] = {"orientation": "portrait"}
    with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
        out_path, _, _ = worker.convert(job)
    width, height = _pdf_page_size(Path(out_path))
    assert width < height, f"expected portrait, got {width}x{height}"


@requires_soffice
@requires_pandoc
def test_md_to_pdf_applies_page_range_and_orientation_real(tmp_path):
    """md→pdf — the second document.pdf profile (CNV-97): orientation goes through
    the pandoc intermediate .docx, pageRange through FilterData on the final
    soffice export. 150 paragraphs paginate to a stable 6 pages (asserted as a
    baseline first — see the analogous txt→pdf test above for why)."""
    md_text = "\n\n".join(f"Paragraph number {i} of the probe document." for i in range(150)) + "\n"

    baseline_src = tmp_path / "baseline.md"
    baseline_src.write_text(md_text, encoding="utf-8")
    with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
        baseline_out, _, _ = _build_worker().convert(_job(2220, baseline_src, "md", "pdf"))
    assert _pdf_page_count(Path(baseline_out)) == 6, "pagination assumption drifted — see docstring"

    src = tmp_path / "many-paras.md"
    src.write_text(md_text, encoding="utf-8")
    worker = _build_worker()
    job = _job(222, src, "md", "pdf")
    job["options"] = {"pageRange": "1-2", "orientation": "landscape"}
    with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = worker.convert(job)

    assert ext == "pdf" and mime == "application/pdf"
    out = Path(out_path)
    assert _pdf_page_count(out) == 2
    width, height = _pdf_page_size(out)
    assert width > height, f"expected landscape page, got {width}x{height}"


# ---------------------------------------------------------------------------
# CNV-98 — TXT/Markdown UTF-8 + markdownDialect (real pdftotext/soffice/pandoc)
# ---------------------------------------------------------------------------

@requires_soffice
@requires_pdftotext
def test_txt_pdf_txt_roundtrip_preserves_cyrillic_utf8_real(tmp_path):
    """CNV-98 AC: TXT result is UTF-8 — round-tripped through real soffice+pdftotext."""
    cyrillic = "Привет, мир! Кириллица должна сохраниться в UTF-8.\n"
    src = tmp_path / "in.txt"
    src.write_text(cyrillic, encoding="utf-8")

    worker = _build_worker()
    with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
        pdf_path, _, _ = worker.convert(_job(230, src, "txt", "pdf"))

    worker2 = _build_worker()
    with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
        txt_out, mime, ext = worker2.convert(_job(231, Path(pdf_path), "pdf", "txt"))

    assert ext == "txt" and mime == "text/plain"
    result_text = Path(txt_out).read_text(encoding="utf-8")  # raises UnicodeDecodeError if not UTF-8
    assert "Привет" in result_text and "Кириллица" in result_text


@requires_soffice
@requires_pandoc
def test_txt_to_md_is_utf8_and_dialect_affects_output_real(tmp_path):
    """CNV-98 AC: Markdown result is UTF-8, and markdownDialect genuinely changes
    output (real pandoc, not just threaded-and-ignored). gfm leaves an intraword
    underscore unescaped; markdown_strict escapes it (see Execution Log probe)."""
    src = tmp_path / "in.txt"
    src.write_text("hello_world и немного кириллицы\n", encoding="utf-8")

    def _convert(conv_id: int, dialect: str | None) -> str:
        worker = _build_worker()
        job = _job(conv_id, src, "txt", "md")
        if dialect:
            job["options"] = {"markdownDialect": dialect}
        with patch("workers.libreoffice.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(job)
        assert ext == "md" and mime == "text/markdown"
        return Path(out_path).read_text(encoding="utf-8")

    default_out = _convert(240, None)
    strict_out = _convert(241, "markdown_strict")

    assert "кириллицы" in default_out and "кириллицы" in strict_out, "UTF-8 must survive both dialects"
    assert "hello_world" in default_out, "default dialect (gfm) must not escape an intraword underscore"
    assert "hello\\_world" in strict_out, "markdownDialect=markdown_strict must escape it"
    assert default_out != strict_out, "markdownDialect must actually affect Markdown output"
