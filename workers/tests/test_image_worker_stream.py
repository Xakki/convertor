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
    return {
        "conversionId": conv_id,
        "inputPath": str(input_path),
        "sourceFormat": src_ext,
        "targetFormat": tgt_fmt,
        "category": "image",
        "isAi": False,
        "subType": None,
        "options": [],
    }


def _worker_with_share(tmp_path: Path) -> ImageWorker:
    """Return an ImageWorker with SHARE_DIR and redis mocked."""
    from unittest.mock import MagicMock
    import workers.common.stream_consumer as sc_mod
    import workers.image.worker as iw_mod

    mock_redis = MagicMock()
    mock_redis.xgroup_create.side_effect = None

    with patch.object(sc_mod, "REDIS_HOST", "localhost"), \
         patch("workers.common.stream_consumer.redis.Redis", return_value=mock_redis):
        worker = ImageWorker()

    # Redirect SHARE_DIR so safe_share_path and output dir resolve inside tmp_path
    patch.object(iw_mod, "SHARE_DIR", tmp_path).start()
    patch.object(sc_mod, "SHARE_DIR", tmp_path).start()
    return worker


# --------------------------------------------------------------------------
# (b) Image convert() — real Pillow conversions
# --------------------------------------------------------------------------

class TestImageConvert:

    def test_png_to_jpg(self, tmp_path):
        src = _make_png(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.SHARE_DIR", tmp_path):
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

        with patch("workers.image.worker.SHARE_DIR", tmp_path):
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

        with patch("workers.image.worker.SHARE_DIR", tmp_path):
            job = _make_job(3, src, "png", "bmp")
            out_path, mime, ext = worker.convert(job)

        assert Path(out_path).exists()
        assert ext == "bmp"
        assert mime == "image/bmp"

    def test_png_to_pdf(self, tmp_path):
        src = _make_png(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.SHARE_DIR", tmp_path):
            job = _make_job(4, src, "png", "pdf")
            out_path, mime, ext = worker.convert(job)

        assert Path(out_path).exists()
        assert ext == "pdf"
        assert mime == "application/pdf"

    def test_output_placed_in_output_subdir(self, tmp_path):
        src = _make_png(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.SHARE_DIR", tmp_path):
            job = _make_job(7, src, "png", "jpg")
            out_path, _, _ = worker.convert(job)

        assert Path(out_path).parent == tmp_path / "output"

    def test_output_filename_uses_conv_id(self, tmp_path):
        src = _make_png(tmp_path)
        worker = _worker_with_share(tmp_path)
        conv_id = 42

        with patch("workers.image.worker.SHARE_DIR", tmp_path):
            job = _make_job(conv_id, src, "png", "jpg")
            out_path, _, ext = worker.convert(job)

        assert Path(out_path).name == f"{conv_id}.{ext}"

    def test_existing_example_jpg(self, tmp_path):
        """Use the example_files/image.jpg shipped with the test suite."""
        import shutil

        example = Path(__file__).parent / "example_files" / "image.jpg"
        if not example.exists():
            pytest.skip("example_files/image.jpg not found")

        src = tmp_path / "image.jpg"
        shutil.copy(example, src)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.SHARE_DIR", tmp_path):
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

        with patch("workers.image.worker.SHARE_DIR", tmp_path):
            job = _make_job(5, fake_src, "svg", "png")
            with pytest.raises(ValueError, match="unsupported source format"):
                worker.convert(job)

    def test_unsupported_target_raises(self, tmp_path):
        src = _make_png(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.SHARE_DIR", tmp_path):
            job = _make_job(6, src, "png", "mp3")
            with pytest.raises(ValueError, match="unsupported conversion"):
                worker.convert(job)

    def test_missing_input_raises(self, tmp_path):
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.SHARE_DIR", tmp_path):
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
