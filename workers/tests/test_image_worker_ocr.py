"""Unit tests for ImageWorker OCR path — mocked tesseract/poppler.

The `tesseract` binary and poppler are NOT installed on the host, so these
tests MOCK `pytesseract.image_to_string` and `pdf2image.convert_from_path`.
They verify YOUR dispatch logic: targetFormat ∈ {txt,md,docx} → OCR branch,
output builders (txt/md/docx), pdf page handling, mime + target_ext, and that
image targets still take the raster branch.

Real-tesseract verification lives in test_image_worker_ocr_integration.py
(marker `integration`, skipped on host).
"""

from pathlib import Path
from unittest.mock import patch

import pytest
from PIL import Image

from workers.image.worker import ImageWorker, _MATRIX, _MIME


# --------------------------------------------------------------------------
# Helpers (mirror test_image_worker_stream.py patterns)
# --------------------------------------------------------------------------

def _make_png(tmp_path: Path, name: str = "input.png") -> Path:
    p = tmp_path / name
    Image.new("RGB", (20, 20), color=(200, 100, 50)).save(str(p), "PNG")
    return p


def _make_jpg(tmp_path: Path, name: str = "input.jpg") -> Path:
    p = tmp_path / name
    Image.new("RGB", (20, 20), color=(50, 100, 200)).save(str(p), "JPEG")
    return p


def _make_tiff(tmp_path: Path, name: str = "input.tiff") -> Path:
    p = tmp_path / name
    Image.new("RGB", (20, 20), color=(10, 220, 30)).save(str(p), "TIFF")
    return p


def _make_pdf_placeholder(tmp_path: Path, name: str = "input.pdf") -> Path:
    # Contents are irrelevant — pdf2image.convert_from_path is mocked.
    p = tmp_path / name
    p.write_bytes(b"%PDF-1.4 fake")
    return p


def _make_job(conv_id: int, input_path: Path, src_ext: str, tgt_fmt: str) -> dict:
    return {
        "conversionId": conv_id,
        "inputBucket": "convertor-inputs",
        "inputKey": f"inputs/{Path(input_path).name}",
        "_localInput": str(input_path),
        "originalFilename": Path(input_path).name,
        "sourceFormat": src_ext,
        "targetFormat": tgt_fmt,
        "category": "image",
        "isAi": False,
        "options": [],
    }


def _worker(tmp_path: Path) -> ImageWorker:
    return ImageWorker()


_OCR_TEXT = "Hello OCR 12345\nSecond line here"


# --------------------------------------------------------------------------
# Raster OCR sources → txt / md / docx
# --------------------------------------------------------------------------

class TestRasterOcr:

    def test_jpg_to_txt(self, tmp_path):
        src = _make_jpg(tmp_path)
        worker = _worker(tmp_path)
        with patch("workers.image.worker.WORK_DIR", tmp_path), \
             patch("workers.image.worker.pytesseract.image_to_string", return_value=_OCR_TEXT) as m:
            out_path, mime, ext = worker.convert(_make_job(1, src, "jpg", "txt"))

        assert m.called, "OCR branch must invoke pytesseract.image_to_string"
        assert ext == "txt"
        assert mime == "text/plain"
        assert Path(out_path).exists()
        assert Path(out_path).read_text(encoding="utf-8").strip() == _OCR_TEXT.strip()

    def test_png_to_md(self, tmp_path):
        src = _make_png(tmp_path)
        worker = _worker(tmp_path)
        with patch("workers.image.worker.WORK_DIR", tmp_path), \
             patch("workers.image.worker.pytesseract.image_to_string", return_value=_OCR_TEXT):
            out_path, mime, ext = worker.convert(_make_job(2, src, "png", "md"))

        assert ext == "md"
        assert mime == "text/markdown"
        content = Path(out_path).read_text(encoding="utf-8")
        assert "Hello OCR 12345" in content
        # single-page → no page separator
        assert "---" not in content

    def test_jpg_to_docx(self, tmp_path):
        import docx

        src = _make_jpg(tmp_path)
        worker = _worker(tmp_path)
        with patch("workers.image.worker.WORK_DIR", tmp_path), \
             patch("workers.image.worker.pytesseract.image_to_string", return_value=_OCR_TEXT):
            out_path, mime, ext = worker.convert(_make_job(3, src, "jpg", "docx"))

        assert ext == "docx"
        assert mime == (
            "application/vnd.openxmlformats-officedocument."
            "wordprocessingml.document"
        )
        assert Path(out_path).exists()
        # Reopen the docx and verify the OCR text landed in paragraphs.
        doc = docx.Document(str(out_path))
        joined = "\n".join(p.text for p in doc.paragraphs)
        assert "Hello OCR 12345" in joined

    def test_tiff_to_txt(self, tmp_path):
        src = _make_tiff(tmp_path)
        worker = _worker(tmp_path)
        with patch("workers.image.worker.WORK_DIR", tmp_path), \
             patch("workers.image.worker.pytesseract.image_to_string", return_value=_OCR_TEXT):
            out_path, mime, ext = worker.convert(_make_job(4, src, "tiff", "txt"))
        assert ext == "txt"
        assert mime == "text/plain"
        assert _OCR_TEXT.strip() in Path(out_path).read_text(encoding="utf-8")


# --------------------------------------------------------------------------
# PDF OCR source — pdf2image mocked to return fake PIL pages
# --------------------------------------------------------------------------

class TestPdfOcr:

    def _fake_pages(self, n: int):
        return [Image.new("RGB", (8, 8), color=(0, 0, 0)) for _ in range(n)]

    def test_pdf_to_txt_single_page(self, tmp_path):
        src = _make_pdf_placeholder(tmp_path)
        worker = _worker(tmp_path)
        with patch("workers.image.worker.WORK_DIR", tmp_path), \
             patch("workers.image.worker.pdf2image.convert_from_path",
                   return_value=self._fake_pages(1)) as conv, \
             patch("workers.image.worker.pytesseract.image_to_string",
                   return_value="page one text"):
            out_path, mime, ext = worker.convert(_make_job(5, src, "pdf", "txt"))

        assert conv.called, "pdf source must render pages via pdf2image"
        assert ext == "txt"
        assert mime == "text/plain"
        assert "page one text" in Path(out_path).read_text(encoding="utf-8")

    def test_pdf_to_txt_multipage_concat(self, tmp_path):
        src = _make_pdf_placeholder(tmp_path)
        worker = _worker(tmp_path)
        page_texts = iter(["alpha page", "beta page", "gamma page"])
        with patch("workers.image.worker.WORK_DIR", tmp_path), \
             patch("workers.image.worker.pdf2image.convert_from_path",
                   return_value=self._fake_pages(3)), \
             patch("workers.image.worker.pytesseract.image_to_string",
                   side_effect=lambda *a, **k: next(page_texts)):
            out_path, mime, ext = worker.convert(_make_job(6, src, "pdf", "txt"))

        content = Path(out_path).read_text(encoding="utf-8")
        assert "alpha page" in content
        assert "beta page" in content
        assert "gamma page" in content

    def test_pdf_to_md_multipage_separator(self, tmp_path):
        src = _make_pdf_placeholder(tmp_path)
        worker = _worker(tmp_path)
        page_texts = iter(["first", "second"])
        with patch("workers.image.worker.WORK_DIR", tmp_path), \
             patch("workers.image.worker.pdf2image.convert_from_path",
                   return_value=self._fake_pages(2)), \
             patch("workers.image.worker.pytesseract.image_to_string",
                   side_effect=lambda *a, **k: next(page_texts)):
            out_path, mime, ext = worker.convert(_make_job(7, src, "pdf", "md"))

        content = Path(out_path).read_text(encoding="utf-8")
        assert ext == "md"
        assert "first" in content and "second" in content
        # multi-page md → pages separated by a markdown rule
        assert "---" in content


# --------------------------------------------------------------------------
# Empty-OCR handling
# --------------------------------------------------------------------------

class TestEmptyOcr:

    def test_empty_text_warns_but_succeeds(self, tmp_path, caplog):
        """No recognizable text → warning, but still produce the output file."""
        import logging

        src = _make_jpg(tmp_path)
        worker = _worker(tmp_path)
        with patch("workers.image.worker.WORK_DIR", tmp_path), \
             patch("workers.image.worker.pytesseract.image_to_string", return_value="   \n  "), \
             caplog.at_level(logging.WARNING, logger="workers.image.worker"):
            out_path, mime, ext = worker.convert(_make_job(11, src, "jpg", "txt"))

        assert Path(out_path).exists()
        assert ext == "txt"
        assert any("no text" in r.message.lower() for r in caplog.records), \
            "expected an empty-OCR warning"


# --------------------------------------------------------------------------
# Branch selection & rejections
# --------------------------------------------------------------------------

class TestBranchSelection:

    def test_image_target_takes_raster_branch_not_ocr(self, tmp_path):
        """jpg→png must NOT call OCR; raster branch produces a real image."""
        src = _make_jpg(tmp_path)
        worker = _worker(tmp_path)
        with patch("workers.image.worker.WORK_DIR", tmp_path), \
             patch("workers.image.worker.pytesseract.image_to_string") as m:
            out_path, mime, ext = worker.convert(_make_job(8, src, "jpg", "png"))

        assert not m.called, "raster target must not trigger OCR"
        assert ext == "png"
        assert mime == "image/png"
        with Image.open(out_path) as img:
            assert img.format == "PNG"

    def test_unsupported_ocr_source_raises(self, tmp_path):
        """gif→txt: OCR branch entered (text target) but gif not an OCR source."""
        src = tmp_path / "input.gif"
        Image.new("RGB", (8, 8)).save(str(src), "GIF")
        worker = _worker(tmp_path)
        with patch("workers.image.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError):
                worker.convert(_make_job(9, src, "gif", "txt"))

    def test_pdf_with_image_target_rejected(self, tmp_path):
        """pdf→png falls to raster branch → clean unsupported, never Image.open."""
        src = _make_pdf_placeholder(tmp_path)
        worker = _worker(tmp_path)
        with patch("workers.image.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError):
                worker.convert(_make_job(10, src, "pdf", "png"))


# --------------------------------------------------------------------------
# Matrix advertisement vs ROADMAP OCR row
# --------------------------------------------------------------------------

def test_ocr_pairs_advertised_in_matrix():
    """ROADMAP OCR row: jpg/png/pdf/tiff → txt/md/docx must be in _MATRIX."""
    for src in ("jpg", "png", "pdf", "tiff"):
        assert src in _MATRIX, f"{src} missing as OCR source in _MATRIX"
        for tgt in ("txt", "md", "docx"):
            assert tgt in _MATRIX[src], f"{src}→{tgt} not advertised"


def test_ocr_targets_have_mime():
    for tgt in ("txt", "md", "docx"):
        assert tgt in _MIME, f"missing MIME for OCR target {tgt}"


def test_ocr_targets_not_added_to_pure_raster_sources():
    """Honest advertisement: gif/bmp/webp/ico are NOT OCR sources."""
    for src in ("gif", "bmp", "webp", "ico"):
        for tgt in ("txt", "md", "docx"):
            assert tgt not in _MATRIX.get(src, set()), \
                f"{src}→{tgt} should not be advertised (not an OCR source)"
