"""Tests for ImageWorker.convert() — Phase 1 stream-based worker."""

import io
import struct
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

_PNG_SIGNATURE = b"\x89PNG\r\n\x1a\n"


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


def _make_transparent_svg(tmp_path: Path, name: str = "transparent.svg") -> Path:
    """SVG whose rect does NOT cover the full canvas — border stays transparent
    (unlike _make_svg(), whose opaque rect exactly matches the viewport)."""
    p = tmp_path / name
    p.write_text(
        '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="10">'
        '<rect x="5" y="2" width="10" height="6" fill="#c86432"/></svg>',
        encoding="utf-8",
    )
    return p


def _make_malformed_svg(tmp_path: Path, name: str = "malformed.svg") -> Path:
    """Not well-formed XML (unclosed tag) — must fail PERMANENTLY (ValueError),
    not loop forever (CNV-75 / same defect class as CNV-128)."""
    p = tmp_path / name
    p.write_text(
        '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="10">'
        '<rect width="20" height="10" fill="#c86432">',
        encoding="utf-8",
    )
    return p


def _parse_ico_entries(data: bytes) -> list[tuple[int, int, bytes]]:
    """Parse an ICO file's ICONDIR/ICONDIRENTRY records (raw byte-level, not via
    Pillow) — returns [(width, height, first_8_bytes_of_image_data), ...].
    A stored 0 in the 1-byte width/height field means 256 (ICO format quirk)."""
    reserved, img_type, count = struct.unpack_from("<HHH", data, 0)
    assert reserved == 0
    assert img_type == 1  # ICO (not CUR)
    entries = []
    for i in range(count):
        off = 6 + i * 16
        width, height, _color_count, _reserved2, _planes, _bit_count, _size, offset = (
            struct.unpack_from("<BBBBHHII", data, off)
        )
        width = width or 256
        height = height or 256
        entries.append((width, height, data[offset:offset + 8]))
    return entries


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
# CNV-75: SVG → GIF/BMP/TIFF/ICO legacy static targets
# --------------------------------------------------------------------------

class TestSvgLegacyTargets:

    def test_svg_to_gif_is_single_static_frame(self, tmp_path):
        src = _make_svg(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(_make_job(20, src, "svg", "gif"))

        assert ext == "gif"
        assert mime == "image/gif"
        with Image.open(out_path) as image:
            assert image.format == "GIF"
            # n_frames only exists on multi-frame-capable Pillow images; absence
            # (AttributeError-free default of 1) also proves single-frame.
            assert getattr(image, "n_frames", 1) == 1
            assert not image.info.get("duration")

    def test_svg_to_bmp_has_no_alpha_channel(self, tmp_path):
        src = _make_transparent_svg(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(_make_job(21, src, "svg", "bmp"))

        assert ext == "bmp"
        assert mime == "image/bmp"
        with Image.open(out_path) as image:
            assert image.format == "BMP"
            assert image.mode == "RGB"  # no "A" — alpha was composited away
            # Corner (0,0) was transparent in the source SVG → must be the
            # default white background now, not black/garbage.
            assert image.getpixel((0, 0)) == (255, 255, 255)

    def test_svg_to_bmp_uses_requested_background_color(self, tmp_path):
        src = _make_transparent_svg(tmp_path)
        worker = _worker_with_share(tmp_path)
        job = _make_job(22, src, "svg", "bmp")
        job["options"] = {"background": "#00FF00"}

        out_path, _, _ = worker.convert(job)

        with Image.open(out_path) as image:
            r, g, b = image.getpixel((0, 0))
            assert g > r and g > b

    def test_svg_to_tiff_is_single_page_lzw(self, tmp_path):
        src = _make_svg(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(_make_job(23, src, "svg", "tiff"))

        assert ext == "tiff"
        assert mime == "image/tiff"
        with Image.open(out_path) as image:
            assert image.format == "TIFF"
            assert getattr(image, "n_frames", 1) == 1
            # COMPRESSION tag (259) == 5 is the numeric TIFF/EXIF code for LZW;
            # info["compression"] is Pillow's own string mirror of the same tag.
            assert image.tag_v2[259] == 5
            assert image.info.get("compression") == "tiff_lzw"

    def test_svg_to_ico_has_expected_sizes_as_png_frames(self, tmp_path):
        src = _make_svg(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(_make_job(24, src, "svg", "ico"))

        assert ext == "ico"
        assert mime == "image/x-icon"
        entries = _parse_ico_entries(Path(out_path).read_bytes())
        sizes = {(w, h) for w, h, _ in entries}
        assert sizes == {(16, 16), (32, 32), (48, 48), (256, 256)}
        for _w, _h, head in entries:
            assert head == _PNG_SIGNATURE, "each ICO frame must be PNG-encoded"

    def test_svg_to_ico_ignores_width_height_options_by_design(self, tmp_path):
        """Documented decision (see _save_svg_ico docstring): job width/height
        must not influence the ICO output AT ALL — needs team-lead ack, same
        pattern as CNV-98's markdownDialect/pdf→md gap.

        Asserting only the {16,32,48,256} size set is NOT a discriminator: a
        pre-resize of the source before the 256x256 contain-fit step still
        lands on those exact four sizes (ImageOps.contain() re-normalizes
        whatever it is given), so a width/height leak into this branch would
        pass that assertion too. The real oracle is that converting the SAME
        source with and without width/height options yields byte-identical
        ICO files — any leak changes what gets pasted onto the canvas (a
        smaller/differently-cropped source raster), so the final PNG frame
        bytes would diverge even though the frame *dimensions* stay 16/32/48/256."""
        src = _make_svg(tmp_path)
        worker = _worker_with_share(tmp_path)

        job_no_options = _make_job(25, src, "svg", "ico")
        out_no_options, _, _ = worker.convert(job_no_options)

        job_with_options = _make_job(26, src, "svg", "ico")
        job_with_options["options"] = {"width": 5, "height": 5}
        out_with_options, _, _ = worker.convert(job_with_options)

        bytes_no_options = Path(out_no_options).read_bytes()
        bytes_with_options = Path(out_with_options).read_bytes()
        assert bytes_with_options == bytes_no_options

        # Sanity: still the documented size set (belt-and-braces, already the
        # subject of test_svg_to_ico_has_expected_sizes_as_png_frames above).
        sizes = {(w, h) for w, h, _ in _parse_ico_entries(bytes_with_options)}
        assert sizes == {(16, 16), (32, 32), (48, 48), (256, 256)}

    def test_malformed_svg_fails_permanently_not_retried(self, tmp_path):
        """ValueError (not RuntimeError/ParseError) is the contract StreamConsumerBase
        needs to classify this as permanent=True (DLQ), not an infinite retry."""
        src = _make_malformed_svg(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="malformed SVG"):
                worker.convert(_make_job(26, src, "svg", "gif"))

        assert not list(tmp_path.glob("out-26-*"))

    def test_malformed_svg_fails_permanently_for_existing_targets_too(self, tmp_path):
        """Same well-formedness guard covers the pre-existing png/jpg/webp targets,
        not only the four CNV-75 additions — shared _do_svg_convert() entry point."""
        src = _make_malformed_svg(tmp_path)
        worker = _worker_with_share(tmp_path)

        with patch("workers.image.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="malformed SVG"):
                worker.convert(_make_job(27, src, "svg", "png"))


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
    assert _MATRIX["svg"] == {"png", "jpg", "jpeg", "webp", "gif", "bmp", "tiff", "ico"}


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
