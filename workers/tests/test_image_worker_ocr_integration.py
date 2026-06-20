"""Integration test for ImageWorker OCR — real tesseract, marker `integration`.

Unlike test_image_worker_ocr.py (pytesseract mocked), this drives the worker's
actual OCR code path against the REAL tesseract binary. It synthesises a clean
high-contrast image with PIL.ImageDraw and asserts the extracted text contains
the rendered word. Skipped when `tesseract` is not on PATH so a plain
`pytest workers/tests` run stays green on the host (tesseract is only present
inside the built worker-image container).
"""

import shutil
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest
from PIL import Image, ImageDraw, ImageFont

pytestmark = pytest.mark.integration

from workers.image.worker import ImageWorker  # noqa: E402

requires_tesseract = pytest.mark.skipif(
    shutil.which("tesseract") is None,
    reason="tesseract not installed / not on PATH",
)

requires_tesseract_poppler = pytest.mark.skipif(
    shutil.which("tesseract") is None or shutil.which("pdftoppm") is None,
    reason="tesseract or poppler (pdftoppm) not installed / not on PATH",
)

RESUME_PDF = Path(__file__).parent / "example_files" / "resume.pdf"


def _build_worker() -> ImageWorker:
    import workers.common.stream_consumer as sc_mod

    with patch.object(sc_mod, "REDIS_HOST", "localhost"), \
         patch("workers.common.stream_consumer.redis.Redis", return_value=MagicMock()):
        return ImageWorker()


def _job(conv_id: int, src: Path, src_fmt: str, tgt_fmt: str) -> dict:
    return {
        "conversionId": conv_id,
        "inputBucket": "convertor-inputs",
        "inputKey": f"inputs/{src.name}",
        "_localInput": str(src),
        "originalFilename": src.name,
        "sourceFormat": src_fmt,
        "targetFormat": tgt_fmt,
        "category": "image",
        "isAi": False,
        "subType": None,
        "options": [],
    }


def _render_text_png(dest: Path, text: str = "HELLO 12345") -> None:
    """Large, clear black text on white — friendly to OCR without training."""
    img = Image.new("RGB", (640, 200), color=(255, 255, 255))
    draw = ImageDraw.Draw(img)
    font = None
    for path in (
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
        "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
    ):
        if Path(path).exists():
            try:
                font = ImageFont.truetype(path, 96)
                break
            except OSError:
                pass
    draw.text((20, 40), text, fill=(0, 0, 0), font=font)
    img.save(str(dest), "PNG")


@requires_tesseract
def test_png_to_txt_real_tesseract(tmp_path):
    src = tmp_path / "ocr.png"
    _render_text_png(src, "HELLO 12345")

    worker = _build_worker()
    with patch("workers.image.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = worker.convert(_job(1, src, "png", "txt"))

    assert ext == "txt"
    assert mime == "text/plain"
    out = Path(out_path)
    assert out.exists() and out.stat().st_size > 0
    extracted = out.read_text(encoding="utf-8")
    assert "HELLO" in extracted.upper(), f"OCR output missing word: {extracted!r}"


@requires_tesseract
def test_png_to_md_real_tesseract(tmp_path):
    src = tmp_path / "ocr.png"
    _render_text_png(src, "HELLO 12345")

    worker = _build_worker()
    with patch("workers.image.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = worker.convert(_job(2, src, "png", "md"))

    assert ext == "md"
    assert mime == "text/markdown"
    content = Path(out_path).read_text(encoding="utf-8")
    assert "HELLO" in content.upper(), f"OCR md missing word: {content!r}"


@requires_tesseract
def test_png_to_docx_real_tesseract(tmp_path):
    """Exercises the real python-docx generation path end to end."""
    import docx

    src = tmp_path / "ocr.png"
    _render_text_png(src, "HELLO 12345")

    worker = _build_worker()
    with patch("workers.image.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = worker.convert(_job(3, src, "png", "docx"))

    assert ext == "docx"
    assert mime == (
        "application/vnd.openxmlformats-officedocument."
        "wordprocessingml.document"
    )
    out = Path(out_path)
    assert out.exists() and out.stat().st_size > 0
    document = docx.Document(str(out))
    joined = "\n".join(p.text for p in document.paragraphs)
    assert "HELLO" in joined.upper(), f"OCR docx missing word: {joined!r}"


@requires_tesseract_poppler
def test_pdf_to_txt_real_poppler_tesseract(tmp_path):
    """Drives the REAL pdf2image(poppler)→tesseract path on a fixture PDF.

    resume.pdf is an image-rendered resume (no extractable text layer), so a
    plain pdftotext yields nothing — OCR is what produces text here. Asserts
    the output is non-empty and contains an expected word from the resume.
    """
    assert RESUME_PDF.is_file(), f"fixture missing: {RESUME_PDF}"
    src = tmp_path / "resume.pdf"
    src.write_bytes(RESUME_PDF.read_bytes())

    worker = _build_worker()
    with patch("workers.image.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = worker.convert(_job(4, src, "pdf", "txt"))

    assert ext == "txt"
    assert mime == "text/plain"
    out = Path(out_path)
    assert out.exists() and out.stat().st_size > 0
    extracted = out.read_text(encoding="utf-8")
    # Real OCR must yield substantive alphabetic content, not whitespace.
    assert len(extracted.strip()) > 20, f"OCR output too short: {extracted!r}"
    # resume.pdf is a Russian CV; assert on clear latin tokens that OCR
    # reproduces reliably (verified against a real in-container run).
    upper = extracted.upper()
    assert any(w in upper for w in ("DOCKER", "PYTHON", "ARCHITECT")), (
        f"expected resume word not found in OCR output: {extracted!r}"
    )
