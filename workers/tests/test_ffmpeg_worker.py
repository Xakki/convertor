"""Tests for FfmpegWorker.convert() — Phase 1 stream-based worker.

run_ffmpeg is mocked (no ffmpeg binary in the unit-test env): the mock writes a
dummy output file so the worker's post-conversion checks pass. This keeps the
tests fast and environment-independent while still exercising format validation,
output placement, MIME selection, and the streams subscription wiring.
"""

from pathlib import Path
from unittest.mock import patch

import pytest

from workers.ffmpeg.worker import (
    FfmpegWorker,
    _AUDIO_TIMEOUT,
    _VIDEO_TIMEOUT,
    SUPPORTED,
)


def _make_job(
    conv_id: int, input_path: Path, src_fmt: str, tgt_fmt: str, options=None,
) -> dict:
    return {
        "conversionId": conv_id,
        "inputBucket": "convertor-inputs",
        "inputKey": f"inputs/{Path(input_path).name}",
        "_localInput": str(input_path),
        "originalFilename": Path(input_path).name,
        "sourceFormat": src_fmt,
        "targetFormat": tgt_fmt,
        "category": "audio",
        "isAi": False,
        "options": [] if options is None else options,
    }


def _worker(tmp_path: Path) -> FfmpegWorker:
    import workers.common.stream_consumer as sc_mod
    import workers.ffmpeg.worker as fw_mod

    worker = FfmpegWorker()
    patch.object(fw_mod, "WORK_DIR", tmp_path).start()
    patch.object(sc_mod, "WORK_DIR", tmp_path).start()
    return worker


def _src(tmp_path: Path, name: str) -> Path:
    p = tmp_path / name
    p.write_bytes(b"\x00\x01\x02fake-media")
    return p


# ---------------------------------------------------------------------------
# Subscription wiring
# ---------------------------------------------------------------------------

def test_subscribes_to_audio_and_video_streams():
    assert FfmpegWorker.CAPABILITIES["routing_keys"] == ["audio", "video"]


# ---------------------------------------------------------------------------
# convert() — happy paths (ffmpeg mocked to write a dummy output)
# ---------------------------------------------------------------------------

class TestFfmpegConvert:
    def _patch_ffmpeg(self):
        async def fake(src, out_path, timeout, options=None):
            Path(out_path).write_bytes(b"converted")
        return patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake)

    def test_mp3_to_wav(self, tmp_path):
        src = _src(tmp_path, "in.mp3")
        worker = _worker(tmp_path)
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), self._patch_ffmpeg():
            out_path, mime, ext = worker.convert(_make_job(1, src, "mp3", "wav"))
        assert Path(out_path).exists()
        assert ext == "wav"
        assert mime == "audio/wav"

    def test_mp4_to_mp3_audio_extraction(self, tmp_path):
        src = _src(tmp_path, "in.mp4")
        worker = _worker(tmp_path)
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), self._patch_ffmpeg():
            out_path, mime, ext = worker.convert(_make_job(2, src, "mp4", "mp3"))
        assert ext == "mp3"
        assert mime == "audio/mpeg"

    def test_video_uses_video_timeout(self, tmp_path):
        src = _src(tmp_path, "in.mp4")
        worker = _worker(tmp_path)
        captured = {}

        async def fake(src_, out_path, timeout, options=None):
            captured["timeout"] = timeout
            Path(out_path).write_bytes(b"v")

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), \
             patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake):
            worker.convert(_make_job(3, src, "mp4", "webm"))
        assert captured["timeout"] == _VIDEO_TIMEOUT

    def test_audio_uses_audio_timeout(self, tmp_path):
        src = _src(tmp_path, "in.wav")
        worker = _worker(tmp_path)
        captured = {}

        async def fake(src_, out_path, timeout, options=None):
            captured["timeout"] = timeout
            Path(out_path).write_bytes(b"a")

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), \
             patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake):
            worker.convert(_make_job(4, src, "wav", "mp3"))
        assert captured["timeout"] == _AUDIO_TIMEOUT

    def test_output_in_work_dir_with_conv_id(self, tmp_path):
        src = _src(tmp_path, "in.mp3")
        worker = _worker(tmp_path)
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), self._patch_ffmpeg():
            out_path, _, ext = worker.convert(_make_job(99, src, "mp3", "ogg"))
        name = Path(out_path).name
        assert Path(out_path).parent == tmp_path
        assert name.startswith("out-99-")
        assert name.endswith(f".{ext}")


# ---------------------------------------------------------------------------
# Error / unsupported-format cases
# ---------------------------------------------------------------------------

class TestFfmpegConvertErrors:
    def test_unsupported_source_raises(self, tmp_path):
        src = _src(tmp_path, "in.xyz")
        worker = _worker(tmp_path)
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported input format"):
                worker.convert(_make_job(5, src, "xyz", "mp3"))

    def test_unsupported_conversion_raises(self, tmp_path):
        src = _src(tmp_path, "in.mp3")
        worker = _worker(tmp_path)
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported conversion"):
                worker.convert(_make_job(6, src, "mp3", "mp4"))

    def test_missing_input_raises(self, tmp_path):
        worker = _worker(tmp_path)
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            with pytest.raises(FileNotFoundError):
                worker.convert(_make_job(7, tmp_path / "nope.mp3", "mp3", "wav"))

    def test_no_output_produced_raises(self, tmp_path):
        src = _src(tmp_path, "in.mp3")
        worker = _worker(tmp_path)

        async def fake_noop(src_, out_path, timeout, options=None):
            pass  # produce nothing

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), \
             patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake_noop):
            with pytest.raises(RuntimeError, match="no output"):
                worker.convert(_make_job(8, src, "mp3", "wav"))

    def test_engine_failure_propagates(self, tmp_path):
        # A non-zero ffmpeg exit surfaces as RuntimeError from run_ffmpeg; the
        # worker must let it propagate so the base class retries/DLQs.
        src = _src(tmp_path, "in.mp3")
        worker = _worker(tmp_path)

        async def fake_fail(src_, out_path, timeout, options=None):
            raise RuntimeError("ffmpeg failed: Invalid data found when processing input")

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), \
             patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake_fail):
            with pytest.raises(RuntimeError, match="ffmpeg failed"):
                worker.convert(_make_job(9, src, "mp3", "wav"))

    def test_empty_input_passes_validation_and_reaches_engine(self, tmp_path):
        # Content-agnostic: a 0-byte file still passes worker-level format
        # validation and is handed to run_ffmpeg with the (empty) source intact.
        # Rejecting bad content is the engine's job, not the worker's — so here
        # the conversion succeeds and we assert run_ffmpeg saw the empty file.
        src = tmp_path / "empty.mp3"
        src.write_bytes(b"")
        worker = _worker(tmp_path)
        seen = {}

        async def fake(src_, out_path, timeout, options=None):
            seen["src"] = Path(src_)
            Path(out_path).write_bytes(b"converted")

        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), \
             patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake) as mock_ffmpeg:
            out_path, mime, ext = worker.convert(_make_job(10, src, "mp3", "wav"))

        assert mock_ffmpeg.called, "run_ffmpeg must be reached for an empty input"
        assert seen["src"] == src
        assert ext == "wav" and Path(out_path).exists()


def test_matrix_mime_coverage():
    """Every target format reachable in SUPPORTED must have a MIME entry."""
    from workers.ffmpeg.worker import _MIME
    missing = set()
    for targets in SUPPORTED.values():
        for t in targets:
            if t not in _MIME:
                missing.add(t)
    assert not missing, f"missing MIME for: {missing}"


# ---------------------------------------------------------------------------
# CNV-101 — preset → ffmpeg argv mapping
# ---------------------------------------------------------------------------

from workers.ffmpeg.worker import (  # noqa: E402
    _audio_quality_args,
    _video_option_args,
    _AUDIO_FORMATS,
    _AUDIO_QUALITY_BITRATE,
    _AUDIO_QUALITY_SAMPLE_RATE,
    _LOSSLESS_AUDIO_TARGETS,
    _LOSSY_FLOOR_SAMPLE_RATE,
    _VORBIS_SAMPLE_RATE,
    _OPUS_SAMPLE_RATE,
    _VIDEO_FPS,
    _VIDEO_RESOLUTION_SCALE,
)

_DUMMY_SRC = Path("/nonexistent/probe-not-expected-to-run.mp3")


def _run(coro):
    import asyncio
    return asyncio.run(coro)


class TestAudioQualityArgs:
    """`_audio_quality_args` is async and probes the source's sample rate
    for the FLOOR codecs (mp3/aac/m4a) — `_probe_audio_sample_rate` is
    monkeypatched here so these stay pure unit tests; the real-ffprobe proof
    lives in test_ffmpeg_integration.py."""

    def _mock_probe(self, monkeypatch, return_value):
        async def fake(src):
            return return_value
        monkeypatch.setattr("workers.ffmpeg.worker._probe_audio_sample_rate", fake)

    # --- floor codecs (mp3/aac/m4a): forced only BELOW the floor ----------

    @pytest.mark.parametrize("quality", ["low", "medium", "high"])
    @pytest.mark.parametrize("out_fmt", ["mp3", "aac", "m4a"])
    def test_floor_codec_forces_ar_when_source_below_floor(self, monkeypatch, out_fmt, quality):
        self._mock_probe(monkeypatch, 8000)  # below the 44100 floor
        args = _run(_audio_quality_args(_DUMMY_SRC, out_fmt, quality))
        assert args == [
            "-ar", _LOSSY_FLOOR_SAMPLE_RATE[out_fmt],
            "-b:a", _AUDIO_QUALITY_BITRATE[quality],
        ]

    @pytest.mark.parametrize("out_fmt", ["mp3", "aac", "m4a"])
    def test_floor_codec_leaves_ar_untouched_when_source_at_or_above_floor(self, monkeypatch, out_fmt):
        self._mock_probe(monkeypatch, 48000)  # above the 44100 floor — no downgrade
        args = _run(_audio_quality_args(_DUMMY_SRC, out_fmt, "high"))
        assert args == ["-b:a", "192k"]
        assert "-ar" not in args

    @pytest.mark.parametrize("out_fmt", ["mp3", "aac", "m4a"])
    def test_floor_codec_forces_ar_when_probe_fails(self, monkeypatch, out_fmt):
        """Probe failure (None) is conservative — same as pre-floor behaviour."""
        self._mock_probe(monkeypatch, None)
        args = _run(_audio_quality_args(_DUMMY_SRC, out_fmt, "high"))
        assert args == ["-ar", "44100", "-b:a", "192k"]

    def test_floor_codec_exact_boundary_not_forced(self, monkeypatch):
        """Source exactly AT the floor must not be re-forced (>= floor, not > floor)."""
        self._mock_probe(monkeypatch, 44100)
        args = _run(_audio_quality_args(_DUMMY_SRC, "mp3", "high"))
        assert args == ["-b:a", "192k"]

    # --- pinned codecs (ogg/opus): fixed rate, no probing needed -----------

    @pytest.mark.parametrize("quality", ["low", "medium", "high"])
    def test_ogg_is_pinned_and_never_probes(self, monkeypatch, quality):
        async def fail_if_called(src):
            raise AssertionError("ogg must not probe — it is pinned")
        monkeypatch.setattr("workers.ffmpeg.worker._probe_audio_sample_rate", fail_if_called)
        args = _run(_audio_quality_args(_DUMMY_SRC, "ogg", quality))
        assert args == ["-ar", _VORBIS_SAMPLE_RATE, "-b:a", _AUDIO_QUALITY_BITRATE[quality]]

    @pytest.mark.parametrize("quality", ["low", "medium", "high"])
    def test_opus_is_pinned_and_never_probes(self, monkeypatch, quality):
        async def fail_if_called(src):
            raise AssertionError("opus must not probe — it is pinned")
        monkeypatch.setattr("workers.ffmpeg.worker._probe_audio_sample_rate", fail_if_called)
        args = _run(_audio_quality_args(_DUMMY_SRC, "opus", quality))
        assert args == ["-ar", _OPUS_SAMPLE_RATE, "-b:a", _AUDIO_QUALITY_BITRATE[quality]]

    # --- lossless (wav/flac): quality maps directly to sample rate --------

    @pytest.mark.parametrize("quality", ["low", "medium", "high"])
    @pytest.mark.parametrize("out_fmt", ["wav", "flac"])
    def test_lossless_target_uses_sample_rate(self, monkeypatch, out_fmt, quality):
        async def fail_if_called(src):
            raise AssertionError("lossless targets must not probe")
        monkeypatch.setattr("workers.ffmpeg.worker._probe_audio_sample_rate", fail_if_called)
        args = _run(_audio_quality_args(_DUMMY_SRC, out_fmt, quality))
        assert args == ["-ar", _AUDIO_QUALITY_SAMPLE_RATE[quality]]

    # --- ladders / consistency ---------------------------------------------

    def test_bitrate_ladder_strictly_increasing(self):
        low = int(_AUDIO_QUALITY_BITRATE["low"].rstrip("k"))
        medium = int(_AUDIO_QUALITY_BITRATE["medium"].rstrip("k"))
        high = int(_AUDIO_QUALITY_BITRATE["high"].rstrip("k"))
        assert low < medium < high

    def test_sample_rate_ladder_strictly_increasing(self):
        low = int(_AUDIO_QUALITY_SAMPLE_RATE["low"])
        medium = int(_AUDIO_QUALITY_SAMPLE_RATE["medium"])
        high = int(_AUDIO_QUALITY_SAMPLE_RATE["high"])
        assert low < medium < high

    def test_every_lossy_audio_target_has_sample_rate_handling(self):
        """Tripwire (CNV-100/CNV-98 style): every _AUDIO_FORMATS entry that
        is neither lossless nor pinned (ogg/opus) MUST have a floor entry —
        a new lossy target added to CODEC_MAP/_AUDIO_FORMATS without also
        being added here would hit the bare-subscript-turned-.get() branch
        and raise ValueError (permanent, not a silent KeyError-as-retry)."""
        floor_expected = _AUDIO_FORMATS - _LOSSLESS_AUDIO_TARGETS - {"ogg", "opus"}
        assert floor_expected == set(_LOSSY_FLOOR_SAMPLE_RATE)

    def test_unknown_quality_rejected(self, monkeypatch):
        self._mock_probe(monkeypatch, 44100)
        with pytest.raises(ValueError, match="unsupported audio quality preset"):
            _run(_audio_quality_args(_DUMMY_SRC, "mp3", "ultra"))

    def test_unknown_quality_rejected_for_pinned_codec(self):
        with pytest.raises(ValueError, match="unsupported audio quality preset"):
            _run(_audio_quality_args(_DUMMY_SRC, "ogg", "ultra"))

    def test_unknown_quality_rejected_for_lossless(self):
        with pytest.raises(ValueError, match="unsupported audio quality preset"):
            _run(_audio_quality_args(_DUMMY_SRC, "wav", "ultra"))

    def test_unconfigured_lossy_target_raises_not_keyerror(self):
        """Simulates a future CODEC_MAP/_AUDIO_FORMATS addition that forgot
        to add a floor entry — must fail loudly with ValueError (permanent),
        never a bare KeyError (which stream_consumer would retry forever)."""
        with pytest.raises(ValueError, match="no configured sample-rate floor"):
            _run(_audio_quality_args(_DUMMY_SRC, "newfmt", "high"))


class TestVideoOptionArgs:
    @pytest.mark.parametrize("resolution", ["480p", "720p", "1080p"])
    def test_resolution_maps_to_scale_filter(self, resolution):
        assert _video_option_args({"resolution": resolution}) == [
            "-vf", f"scale={_VIDEO_RESOLUTION_SCALE[resolution]}",
        ]

    @pytest.mark.parametrize("fps", ["24", "30"])
    def test_fps_maps_to_r_flag(self, fps):
        assert _video_option_args({"fps": fps}) == ["-r", _VIDEO_FPS[fps]]

    def test_resolution_and_fps_combine(self):
        args = _video_option_args({"resolution": "720p", "fps": "30"})
        assert args == ["-vf", "scale=-2:720", "-r", "30"]

    def test_empty_options_produce_no_args(self):
        assert _video_option_args({}) == []

    def test_unknown_resolution_rejected(self):
        with pytest.raises(ValueError, match="unsupported video resolution preset"):
            _video_option_args({"resolution": "4k"})

    def test_unknown_fps_rejected(self):
        with pytest.raises(ValueError, match="unsupported video fps preset"):
            _video_option_args({"fps": "60"})


class TestRunFfmpegArgvConstruction:
    """Command mapping asserted directly on the argv passed to run_capture —
    no real ffmpeg needed here, the real-tool proof lives in
    test_ffmpeg_integration.py."""

    def _capture_argv(self, monkeypatch, coro_factory, probe_sample_rate=b""):
        """*probe_sample_rate* is what the fake ffprobe (argv[0]=='ffprobe')
        subprocess call returns as stdout; the final captured argv is always
        the real ffmpeg command (the LAST run_capture call — probing, if
        any, always happens first inside _audio_quality_args)."""
        captured = {}

        async def fake_run_capture(argv, timeout, *, full_error=False):
            captured["argv"] = argv
            if argv[0] == "ffprobe":
                return probe_sample_rate, b""
            return b"", b""

        monkeypatch.setattr("workers.ffmpeg.worker.run_capture", fake_run_capture)
        import asyncio
        asyncio.run(coro_factory())
        return captured["argv"]

    def test_audio_quality_applied_to_argv(self, tmp_path, monkeypatch):
        from workers.ffmpeg.worker import run_ffmpeg
        src = tmp_path / "in.mp3"
        src.write_bytes(b"x")
        out = tmp_path / "out.mp3"
        argv = self._capture_argv(
            monkeypatch, lambda: run_ffmpeg(src, out, 30, {"quality": "high"}),
        )
        assert "-b:a" in argv
        assert argv[argv.index("-b:a") + 1] == "192k"
        assert "-ar" in argv
        assert argv[argv.index("-ar") + 1] == "44100"

    def test_high_rate_source_is_not_downgraded_end_to_end(self, tmp_path, monkeypatch):
        """The advisor-flagged case: a 48kHz source picking quality=high
        must NOT come back forced down to 44100 — end-to-end through
        run_ffmpeg (probe → floor decision → final argv), not just the
        isolated _audio_quality_args unit test above."""
        from workers.ffmpeg.worker import run_ffmpeg
        src = tmp_path / "in.mp3"
        src.write_bytes(b"x")
        out = tmp_path / "out.mp3"
        argv = self._capture_argv(
            monkeypatch,
            lambda: run_ffmpeg(src, out, 30, {"quality": "high"}),
            probe_sample_rate=b"48000\n",
        )
        assert "-ar" not in argv  # source already meets the floor — untouched
        assert "-b:a" in argv
        assert argv[argv.index("-b:a") + 1] == "192k"

    def test_video_resolution_and_fps_applied_to_argv(self, tmp_path, monkeypatch):
        from workers.ffmpeg.worker import run_ffmpeg
        src = tmp_path / "in.mp4"
        src.write_bytes(b"x")
        out = tmp_path / "out.mp4"
        argv = self._capture_argv(
            monkeypatch,
            lambda: run_ffmpeg(src, out, 30, {"resolution": "1080p", "fps": "24"}),
        )
        assert "-vf" in argv
        assert argv[argv.index("-vf") + 1] == "scale=-2:1080"
        assert "-r" in argv
        assert argv[argv.index("-r") + 1] == "24"

    def test_video_source_audio_target_ignores_video_controls(self, tmp_path, monkeypatch):
        """AC: audio-only target from a video source applies ONLY the audio
        preset — resolution/fps must never reach argv, even if a malformed
        job somehow carries them (defense in depth; backend CNV-100 already
        rejects this combination before it is ever produced)."""
        from workers.ffmpeg.worker import run_ffmpeg
        src = tmp_path / "in.mp4"
        src.write_bytes(b"x")
        out = tmp_path / "out.mp3"
        argv = self._capture_argv(
            monkeypatch,
            lambda: run_ffmpeg(
                src, out, 30,
                {"quality": "low", "resolution": "1080p", "fps": "30"},
            ),
        )
        assert "-vf" not in argv
        assert "-r" not in argv
        assert "-b:a" in argv
        assert argv[argv.index("-b:a") + 1] == "96k"
        assert "-vn" in argv  # video stream still stripped from the audio-only output

    def test_no_options_leaves_argv_unchanged_from_pre_cnv101_shape(self, tmp_path, monkeypatch):
        from workers.ffmpeg.worker import run_ffmpeg
        src = tmp_path / "in.mp3"
        src.write_bytes(b"x")
        out = tmp_path / "out.wav"
        argv = self._capture_argv(monkeypatch, lambda: run_ffmpeg(src, out, 30))
        assert argv == ["ffmpeg", "-i", str(src), "-y", "-c:a", "pcm_s16le", str(out)]


class TestConvertPassesOptionsThrough:
    def test_options_dict_reaches_run_ffmpeg(self, tmp_path):
        src = _src(tmp_path, "in.mp3")
        worker = _worker(tmp_path)
        captured = {}

        async def fake(src_, out_path, timeout, options=None):
            captured["options"] = options
            Path(out_path).write_bytes(b"converted")

        job = _make_job(20, src, "mp3", "mp3", options={"quality": "medium"})
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path), \
             patch("workers.ffmpeg.worker.run_ffmpeg", side_effect=fake):
            worker.convert(job)
        assert captured["options"] == {"quality": "medium"}

    def test_non_dict_options_rejected(self, tmp_path):
        src = _src(tmp_path, "in.mp3")
        worker = _worker(tmp_path)
        job = _make_job(21, src, "mp3", "wav", options="raw string not allowed")
        with patch("workers.ffmpeg.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="invalid media options"):
                worker.convert(job)
