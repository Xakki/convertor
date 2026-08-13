"""Tests for ImageWorker.convert() — Phase 1 stream-based worker."""

import io
from pathlib import Path
from unittest.mock import patch

import pytest
from PIL import Image

from workers.image.worker import (
    ImageWorker,
    _MIME,
    _MATRIX,
    _reject_external_svg_resource,
)


# --------------------------------------------------------------------------
# Helpers
# --------------------------------------------------------------------------

def _make_png(tmp_path: Path, name: str = "input.png", size: tuple = (20, 20)) -> Path:
    p = tmp_path / name
    img = Image.new("RGB", size, color=(200, 100, 50))
    img.save(str(p), "PNG")
    return p


def _make_transparent_png(tmp_path: Path, name: str = "transparent.png") -> Path:
    p = tmp_path / name
    Image.new("RGBA", (20, 10), color=(0, 0, 0, 0)).save(str(p), "PNG")
    return p


def _make_jpg(tmp_path: Path, name: str = "input.jpg") -> Path:
    p = tmp_path / name
    img = Image.new("RGB", (20, 20), color=(50, 100, 200))
    img.save(str(p), "JPEG")
    return p


def _make_svg(tmp_path: Path, name: str = "input.svg") -> Path:
    p = tmp_path / name
    p.write_text(
        '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="10">'
        '<rect width="20" height="10" fill="#c86432"/></svg>',
        encoding="utf-8",
    )
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

    @pytest.mark.parametrize(
        ("target_fmt", "expected_format"),
        [("png", "PNG"), ("jpg", "JPEG"), ("jpeg", "JPEG"), ("webp", "WEBP")],
    )
    def test_svg_to_raster_targets(self, tmp_path, target_fmt, expected_format):
        src = _make_svg(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(
                _make_job(11, src, "svg", target_fmt),
            )

        assert Path(out_path).exists()
        assert ext == target_fmt
        assert mime == _MIME[target_fmt]
        with Image.open(out_path) as image:
            assert image.format == expected_format
            if target_fmt in ("jpg", "jpeg"):
                assert image.mode == "RGB"

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

    def test_image_options_resize_preserves_aspect_ratio(self, tmp_path):
        src = _make_png(tmp_path, size=(20, 10))
        worker = _worker_with_share(tmp_path)
        job = _make_job(14, src, "png", "webp")
        job["options"] = {"width": 10, "quality": 20}

        out_path, _, _ = worker.convert(job)

        with Image.open(out_path) as image:
            assert image.size == (10, 5)

    def test_jpeg_background_replaces_transparency(self, tmp_path):
        src = _make_transparent_png(tmp_path)
        worker = _worker_with_share(tmp_path)
        job = _make_job(15, src, "png", "jpg")
        job["options"] = {"background": "#00FF00"}

        out_path, _, _ = worker.convert(job)

        with Image.open(out_path) as image:
            assert image.mode == "RGB"
            red, green, blue = image.getpixel((10, 5))
            assert green > red and green > blue

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

    def test_unsupported_svg_target_raises(self, tmp_path):
        worker = _worker_with_share(tmp_path)
        src = _make_svg(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            job = _make_job(5, src, "svg", "pdf")
            with pytest.raises(ValueError, match="unsupported conversion"):
                worker.convert(job)

    def test_svg_external_resource_is_blocked_without_leaking_details(self, tmp_path):
        worker = _worker_with_share(tmp_path)
        src = tmp_path / "external.svg"
        src.write_text(
            '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="10">'
            '<image href="https://attacker.invalid/image.png" width="20" height="10"/></svg>',
            encoding="utf-8",
        )

        with patch(
            "workers.image.worker._reject_external_svg_resource",
            wraps=_reject_external_svg_resource,
        ) as fetcher:
            with pytest.raises(RuntimeError, match=r"^SVG rasterization failed$"):
                worker.convert(_make_job(12, src, "svg", "png"))

        assert fetcher.call_args.args[0] == "https://attacker.invalid/image.png"
        assert not list(tmp_path.glob("out-12-*"))

    def test_svg_renderer_error_does_not_leak_input_details(self, tmp_path):
        worker = _worker_with_share(tmp_path)
        src = _make_svg(tmp_path)

        with patch(
            "workers.image.worker.PNGSurface.convert",
            side_effect=ValueError("/private/input.svg: <svg> secret content"),
        ) as render:
            with pytest.raises(RuntimeError, match=r"^SVG rasterization failed$"):
                worker.convert(_make_job(13, src, "svg", "png"))

        assert render.call_args.kwargs["unsafe"] is False
        assert not list(tmp_path.glob("out-13-*"))

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

def test_svg_matrix_has_only_raster_targets():
    assert _MATRIX["svg"] == {"png", "jpg", "jpeg", "webp"}


def test_matrix_mime_coverage():
    """Every target format in _MATRIX must have a MIME entry."""
    missing = set()
    for targets in _MATRIX.values():
        for t in targets:
            if t not in _MIME:
                missing.add(t)
    assert not missing, f"missing MIME for: {missing}"


def test_capabilities_is_ai_declared_false():
    """registry-02: image-воркер объявляет isAi=False явно (не non-AI по умолчанию).

    Проверяется здесь (а не только параметризованным `test_ws_client.py`), потому что
    этот модуль импортирует `pdf2image` — доступен только в образе `worker-image:test`;
    `make test-gateway` гоняет `test_ws_client.py` в образе `worker-data:test`, где такого
    импорта нет, и там этот кейс skip'ается (см. `test_is_ai_false_for_non_ai_worker_capabilities`)."""
    assert ImageWorker.CAPABILITIES["isAi"] is False
