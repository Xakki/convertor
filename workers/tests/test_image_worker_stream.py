"""Tests for ImageWorker.convert() — Phase 1 stream-based worker."""

import io
from pathlib import Path
from unittest.mock import patch

import pytest
from PIL import Image

from workers.image.worker import ImageWorker, _MIME, _MATRIX


# --------------------------------------------------------------------------
# Helpers
# --------------------------------------------------------------------------

def _make_png(tmp_path: Path, name: str = "input.png", size: tuple = (20, 20)) -> Path:
    p = tmp_path / name
    img = Image.new("RGB", size, color=(200, 100, 50))
    img.save(str(p), "PNG")
    return p


def _make_jpg(tmp_path: Path, name: str = "input.jpg") -> Path:
    p = tmp_path / name
    img = Image.new("RGB", (20, 20), color=(50, 100, 200))
    img.save(str(p), "JPEG")
    return p


def _make_job(conv_id: int, input_path: Path, src_ext: str, tgt_fmt: str) -> dict:
    """Build a job dict. input_path is the local file convert() reads
    (base class injects it as _localInput after the S3 download)."""
    return {
        "conversionId": conv_id,
        "inputBucket": "convertor-input",
        "inputKey": f"input/{Path(input_path).name}",
        "_localInput": str(input_path),
        "originalFilename": Path(input_path).name,
        "sourceFormat": src_ext,
        "targetFormat": tgt_fmt,
        "category": "image",
        "isAi": False,
        "options": [],
    }


def _worker_with_share(tmp_path: Path) -> ImageWorker:
    """Return an ImageWorker with WORK_DIR mocked."""
    import workers.common.stream_consumer as sc_mod
    import workers.image.worker as iw_mod

    worker = ImageWorker()
    # Redirect WORK_DIR so output tmp files resolve inside tmp_path
    patch.object(iw_mod, "WORK_DIR", tmp_path).start()
    patch.object(sc_mod, "WORK_DIR", tmp_path).start()
    return worker


# --------------------------------------------------------------------------
# (b) Image convert() — real Pillow conversions
# --------------------------------------------------------------------------

class TestImageConvert:

    def test_png_to_jpg(self, tmp_path):
        src = _make_png(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            job = _make_job(1, src, "png", "jpg")
            out_path, mime, ext = worker.convert(job)

        assert Path(out_path).exists()
        assert ext == "jpg"
        assert mime == "image/jpeg"
        # Verify Pillow can open the output
        with Image.open(out_path) as img:
            assert img.format == "JPEG"

    def test_jpg_to_webp(self, tmp_path):
        src = _make_jpg(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            job = _make_job(2, src, "jpg", "webp")
            out_path, mime, ext = worker.convert(job)

        assert Path(out_path).exists()
        assert ext == "webp"
        assert mime == "image/webp"
        with Image.open(out_path) as img:
            assert img.format == "WEBP"

    def test_png_to_bmp(self, tmp_path):
        src = _make_png(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            job = _make_job(3, src, "png", "bmp")
            out_path, mime, ext = worker.convert(job)

        assert Path(out_path).exists()
        assert ext == "bmp"
        assert mime == "image/bmp"

    def test_png_to_pdf(self, tmp_path):
        src = _make_png(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            job = _make_job(4, src, "png", "pdf")
            out_path, mime, ext = worker.convert(job)

        assert Path(out_path).exists()
        assert ext == "pdf"
        assert mime == "application/pdf"

    def test_output_placed_in_work_dir(self, tmp_path):
        src = _make_png(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            job = _make_job(7, src, "png", "jpg")
            out_path, _, _ = worker.convert(job)

        assert Path(out_path).parent == tmp_path

    def test_output_filename_includes_conv_id_and_ext(self, tmp_path):
        src = _make_png(tmp_path)
        worker = _worker_with_share(tmp_path)
        conv_id = 42

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            job = _make_job(conv_id, src, "png", "jpg")
            out_path, _, ext = worker.convert(job)

        name = Path(out_path).name
        assert name.startswith(f"out-{conv_id}-")
        assert name.endswith(f".{ext}")

    def test_existing_example_jpg(self, tmp_path):
        """Use the example_files/image.jpg shipped with the test suite."""
        import shutil

        example = Path(__file__).parent / "example_files" / "image.jpg"
        if not example.exists():
            pytest.skip("example_files/image.jpg not found")

        src = tmp_path / "image.jpg"
        shutil.copy(example, src)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            job = _make_job(10, src, "jpg", "png")
            out_path, mime, ext = worker.convert(job)

        assert Path(out_path).exists()
        assert ext == "png"
        assert mime == "image/png"


# --------------------------------------------------------------------------
# Error / unsupported-format cases
# --------------------------------------------------------------------------

class TestImageConvertErrors:

    def test_unsupported_source_raises(self, tmp_path):
        worker = _worker_with_share(tmp_path)
        fake_src = tmp_path / "doc.svg"
        fake_src.write_bytes(b"<svg/>")

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            job = _make_job(5, fake_src, "svg", "png")
            with pytest.raises(ValueError, match="unsupported source format"):
                worker.convert(job)

    def test_unsupported_target_raises(self, tmp_path):
        src = _make_png(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            job = _make_job(6, src, "png", "mp3")
            with pytest.raises(ValueError, match="unsupported conversion"):
                worker.convert(job)

    def test_missing_input_raises(self, tmp_path):
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            job = _make_job(8, tmp_path / "nonexistent.jpg", "jpg", "png")
            with pytest.raises(FileNotFoundError):
                worker.convert(job)


# --------------------------------------------------------------------------
# Matrix sanity
# --------------------------------------------------------------------------

def test_matrix_mime_coverage():
    """Every target format in _MATRIX must have a MIME entry."""
    missing = set()
    for targets in _MATRIX.values():
        for t in targets:
            if t not in _MIME:
                missing.add(t)
    assert not missing, f"missing MIME for: {missing}"
