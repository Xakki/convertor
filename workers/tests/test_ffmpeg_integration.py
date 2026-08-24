"""Integration test for FfmpegWorker — real ffmpeg, marker `integration`.

Unlike test_ffmpeg_worker.py (run_ffmpeg mocked), this drives the worker's
actual conversion code path on real fixtures (workers/tests/example_files/
video.3gp — h263+aac 8kHz mono, 3s; story.mp3 — mp3 22050Hz mono, 3s) and
asserts real output properties (probed with ffprobe, not just exit status).
Skipped when ffmpeg/ffprobe is not on PATH so a plain `pytest workers/tests`
run stays green.
"""

import json
import shutil
import subprocess
from pathlib import Path
from unittest.mock import patch

import pytest

from workers.ffmpeg.worker import FfmpegWorker

pytestmark = pytest.mark.integration

FIXTURE = Path(__file__).parent / "example_files" / "video.3gp"
AUDIO_FIXTURE = Path(__file__).parent / "example_files" / "story.mp3"

requires_ffmpeg = pytest.mark.skipif(
    shutil.which("ffmpeg") is None or shutil.which("ffprobe") is None,
    reason="ffmpeg/ffprobe not installed / not on PATH",
)


def _build_worker() -> FfmpegWorker:
    return FfmpegWorker()


def _job(conv_id: int, src: Path, src_fmt: str, tgt_fmt: str, options=None) -> dict:
    return {
        "conversionId": conv_id,
        "inputBucket": "convertor-inputs",
        "inputKey": f"inputs/{src.name}",
        "_localInput": str(src),
        "originalFilename": src.name,
        "sourceFormat": src_fmt,
        "targetFormat": tgt_fmt,
        "category": "video",
        "isAi": False,
        "options": {} if options is None else options,
    }


def _ffprobe(path: Path) -> dict:
    """Probe *path* with real ffprobe; returns the parsed JSON (format + streams)."""
    proc = subprocess.run(
        [
            "ffprobe", "-v", "error", "-print_format", "json",
            "-show_format", "-show_streams", str(path),
        ],
        capture_output=True, check=True,
    )
    return json.loads(proc.stdout)


def _audio_stream(probe: dict) -> dict:
    return next(s for s in probe["streams"] if s["codec_type"] == "audio")


def _video_stream(probe: dict) -> dict:
    return next(s for s in probe["streams"] if s["codec_type"] == "video")


@requires_ffmpeg
def test_3gp_to_mp4_real_ffmpeg(tmp_path):
    assert FIXTURE.is_file(), f"fixture missing: {FIXTURE}"
    src = tmp_path / "video.3gp"
    src.write_bytes(FIXTURE.read_bytes())

    worker = _build_worker()
    with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
        out_path, mime, ext = worker.convert(_job(1, src, "3gp", "mp4"))

    out = Path(out_path)
    assert out.exists() and out.stat().st_size > 0
    assert ext == "mp4"
    assert mime == "video/mp4"

    # ffmpeg must fully decode the output without error (container is valid).
    probe = subprocess.run(
        ["ffmpeg", "-v", "error", "-i", str(out), "-f", "null", "-"],
        capture_output=True,
    )
    assert probe.returncode == 0, probe.stderr.decode("utf-8", "replace")
    assert out.read_bytes()[4:8] == b"ftyp", "output is not an ISO/MP4 container"


# ---------------------------------------------------------------------------
# CNV-101 — preset application, real output properties (ffprobe)
# ---------------------------------------------------------------------------

@requires_ffmpeg
class TestAudioQualityPresetReal:
    def test_low_vs_high_quality_changes_bitrate(self, tmp_path):
        src = tmp_path / "story.mp3"
        src.write_bytes(AUDIO_FIXTURE.read_bytes())
        worker = _build_worker()

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            out_low, _, _ = worker.convert(
                _job(10, src, "mp3", "mp3", options={"quality": "low"}),
            )
            out_high, _, _ = worker.convert(
                _job(11, src, "mp3", "mp3", options={"quality": "high"}),
            )

        bitrate_low = int(_audio_stream(_ffprobe(Path(out_low)))["bit_rate"])
        bitrate_high = int(_audio_stream(_ffprobe(Path(out_high)))["bit_rate"])
        assert bitrate_low < bitrate_high
        # requested 96k/192k — allow encoder slack, but must land near the tier.
        assert 80_000 <= bitrate_low <= 115_000
        assert 170_000 <= bitrate_high <= 210_000

    def test_lossless_target_quality_changes_sample_rate(self, tmp_path):
        src = tmp_path / "story.mp3"
        src.write_bytes(AUDIO_FIXTURE.read_bytes())
        worker = _build_worker()

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            out_low, _, _ = worker.convert(
                _job(12, src, "mp3", "wav", options={"quality": "low"}),
            )
            out_high, _, _ = worker.convert(
                _job(13, src, "mp3", "wav", options={"quality": "high"}),
            )

        rate_low = int(_audio_stream(_ffprobe(Path(out_low)))["sample_rate"])
        rate_high = int(_audio_stream(_ffprobe(Path(out_high)))["sample_rate"])
        assert rate_low == 22050
        assert rate_high == 48000

    def test_high_rate_source_is_not_downgraded(self, tmp_path):
        """Advisor-flagged case, proven end-to-end with real ffprobe/ffmpeg:
        a 48kHz source picking quality=high must come back at 48kHz, not
        silently downsampled to 44100 just because 'quality' was requested —
        that would be a fidelity loss on the tier promising the opposite."""
        src = tmp_path / "src48k.wav"
        gen = subprocess.run(
            [
                "ffmpeg", "-v", "error", "-y", "-f", "lavfi",
                "-i", "sine=frequency=440:duration=1", "-ar", "48000", str(src),
            ],
            capture_output=True,
        )
        assert gen.returncode == 0, gen.stderr.decode("utf-8", "replace")
        assert int(_audio_stream(_ffprobe(src))["sample_rate"]) == 48000

        worker = _build_worker()
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            out, _, _ = worker.convert(
                _job(15, src, "wav", "mp3", options={"quality": "high"}),
            )

        rate = int(_audio_stream(_ffprobe(Path(out)))["sample_rate"])
        assert rate == 48000, f"source sample rate was downgraded to {rate}"

    def test_no_options_matches_pre_cnv101_defaults(self, tmp_path):
        """options=={} must leave behaviour unchanged: no forced -ar/-b:a."""
        src = tmp_path / "story.mp3"
        src.write_bytes(AUDIO_FIXTURE.read_bytes())
        worker = _build_worker()

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            out, _, _ = worker.convert(_job(14, src, "mp3", "wav"))

        # source is 22050 Hz (see AUDIO_FIXTURE header) — with no `quality`
        # option ffmpeg must pass the source rate through untouched, not
        # snap it to one of the quality tiers.
        rate = int(_audio_stream(_ffprobe(Path(out)))["sample_rate"])
        assert rate == 22050


@requires_ffmpeg
class TestVideoPresetReal:
    def test_resolution_applied(self, tmp_path):
        src = tmp_path / "video.3gp"
        src.write_bytes(FIXTURE.read_bytes())
        worker = _build_worker()

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            out, _, _ = worker.convert(
                _job(20, src, "3gp", "mp4", options={"resolution": "720p"}),
            )

        stream = _video_stream(_ffprobe(Path(out)))
        # source is 176x144 (see FIXTURE) — -2:720 must land exactly on the
        # height requested; width is derived (even, aspect-preserving).
        assert int(stream["height"]) == 720
        assert int(stream["width"]) % 2 == 0

    def test_fps_applied(self, tmp_path):
        src = tmp_path / "video.3gp"
        src.write_bytes(FIXTURE.read_bytes())
        worker = _build_worker()

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            out_24, _, _ = worker.convert(
                _job(21, src, "3gp", "mp4", options={"fps": "24"}),
            )
            out_30, _, _ = worker.convert(
                _job(22, src, "3gp", "mp4", options={"fps": "30"}),
            )

        assert _video_stream(_ffprobe(Path(out_24)))["r_frame_rate"] == "24/1"
        assert _video_stream(_ffprobe(Path(out_30)))["r_frame_rate"] == "30/1"

    def test_no_options_keeps_source_resolution_and_fps(self, tmp_path):
        src = tmp_path / "video.3gp"
        src.write_bytes(FIXTURE.read_bytes())
        worker = _build_worker()

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            out, _, _ = worker.convert(_job(23, src, "3gp", "mp4"))

        stream = _video_stream(_ffprobe(Path(out)))
        # source is 176x144 @ 12fps (see FIXTURE) — unchanged without options.
        assert int(stream["width"]) == 176
        assert int(stream["height"]) == 144
        assert stream["r_frame_rate"] == "12/1"


@requires_ffmpeg
class TestVideoSourceAudioTargetIgnoresVideoControls:
    def test_resolution_and_fps_have_no_effect_on_audio_extraction(self, tmp_path):
        """AC: audio-only target from a video source applies ONLY the audio
        preset; resolution/fps must have zero effect — proven here by
        passing all three together and confirming (a) no video stream
        exists at all in the output and (b) quality alone still lands on
        the requested bitrate tier."""
        src = tmp_path / "video.3gp"
        src.write_bytes(FIXTURE.read_bytes())
        worker = _build_worker()

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            out, mime, ext = worker.convert(
                _job(
                    30, src, "3gp", "mp3",
                    options={"quality": "low", "resolution": "1080p", "fps": "30"},
                ),
            )

        probe = _ffprobe(Path(out))
        assert not any(s["codec_type"] == "video" for s in probe["streams"]), (
            "video controls must not resurrect a video stream on an audio-only target"
        )
        bitrate = int(_audio_stream(probe)["bit_rate"])
        assert 80_000 <= bitrate <= 115_000  # "low" tier (96k), not left at source default
        assert ext == "mp3" and mime == "audio/mpeg"
