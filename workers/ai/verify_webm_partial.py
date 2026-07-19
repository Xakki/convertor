"""Verify truncated webm/opus partial decode on the real production stream path.

The AI dev-server (`workers/ai/devserver/routes_stream.py`) feeds browser
`MediaRecorder` webm/opus frames, as they arrive over the WS, straight into a
persistent `PcmStreamDecoder` (`workers/ai/devserver/pcm_decoder.py`): ONE PyAV
webm container opened once on a background daemon thread, fed via `.feed(data)`
on a non-seekable byte pipe (the first fed bytes must be the EBML header). PCM
is pulled with `.drain()` per frame and `.close()` at stream end, then handed to
`VadChunker.push()`/`.flush()` (`workers/ai/devserver/vad_chunker.py`) to cut
30ms-framed speech segments, each of which goes to
`StreamingWhisper.transcribe_pcm()` (`workers/ai/providers/streaming_stt.py`).
A mid-stream partial is therefore a truncated webm PREFIX fed into an
in-progress container (header + some bytes, tail may end mid-cluster).

A truncated/malformed webm prefix can fail two different ways:
  (a) `decoder.decode_error()` gets set inside the background decode thread —
      CATCHABLE: production checks it after every `.feed()`/`.close()`, sends
      an error frame over the WS, and breaks the loop (see routes_stream.py).
  (b) PyAV/ffmpeg C code SIGSEGVs, SIGABRTs, or hangs decoding a malformed
      container — an in-process `except Exception` around the WS route CANNOT
      catch a C-level crash, and a hang blocks the decode thread forever. Either
      would take down (or wedge) the WHOLE dev-server, not just one connection.

This harness determines which failure mode a truncated webm actually triggers.

Design: parent synthesizes a real webm/opus, truncates it as a PREFIX at many byte
offsets (server always has header+prefix), and runs EACH decode attempt in a SEPARATE
SUBPROCESS with a timeout, classifying by the child's exit status:
  exit 0                         -> clean (text non-empty / empty)
  nonzero + Python traceback     -> catchable exception (reported; mirrors decode_error())
  killed by signal (-11/-6/...)  -> CRASH
  timeout                        -> HANG

Run inside the worker-ai image (has PyAV, faster_whisper, ffmpeg):
  docker run --rm -v /home/xakki/convertor:/app -w /app \
    --entrypoint python xakki-convertor/worker-ai:cpu \
    workers/ai/verify_webm_partial.py
"""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
from pathlib import Path

# Repo root (…/convertor) on path so `workers.ai…` imports when run as a script.
_ROOT = str(Path(__file__).resolve().parents[2])
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

MODEL = "tiny"
DEVICE = "cpu"
COMPUTE = "int8"
CHILD_TIMEOUT = 60  # seconds; longer than a tiny-model decode of a ~5s clip


# --------------------------------------------------------------------------- #
# Child: run the PRODUCTION decode path over one truncated buffer, then exit.  #
# --------------------------------------------------------------------------- #
def run_child(bytes_path: str) -> int:
    data = Path(bytes_path).read_bytes()

    from workers.ai.devserver.pcm_decoder import PcmStreamDecoder
    from workers.ai.devserver.vad_chunker import VadChunker
    from workers.ai.providers.streaming_stt import StreamingWhisper

    decoder = PcmStreamDecoder(sample_rate=16000)
    decoder.feed(data)  # truncated prefix starts at offset 0 = EBML header (faithful)
    pcm = decoder.drain()
    pcm += decoder.close()  # flush tail
    err = decoder.decode_error()
    if err is not None:
        raise err  # CATCHABLE path — re-raise so parent classifies it EXCEPTION

    chunker = VadChunker()
    segs = list(chunker.push(pcm))
    last = chunker.flush()
    if last:
        segs.append(last)

    model = StreamingWhisper(MODEL, DEVICE, COMPUTE)
    texts = []
    language = None
    for seg in segs:
        r = model.transcribe_pcm(seg)
        t = (r.get("final") or "").strip()
        if t:
            texts.append(t)
        language = r.get("language") or language
    text = " ".join(texts).strip()

    out = {
        "ok": True,
        "text_len": len(text),
        "text_preview": text[:80],
        "language": language,
        "segments": len(segs),
        "pcm_bytes": len(pcm),
    }
    print("RESULT " + json.dumps(out))
    return 0


# --------------------------------------------------------------------------- #
# Parent: synthesize, truncate, fan out children, classify, print the table.  #
# --------------------------------------------------------------------------- #
SPEECH = (
    "The quick brown fox jumps over the lazy dog near the river "
    "while the morning sun rises slowly over the quiet green valley."
)


def synth_webm(dst: Path, live: bool) -> None:
    """Synthesize a webm/opus baseline.

    live=True mimics MediaRecorder output: `-live 1` writes a streaming-profile
    webm (unknown segment/cluster sizes, no trailing Cues/SeekHead) — structurally
    what a browser sends. live=False is ffmpeg's default file mux (known sizes +
    trailer Cues). Prefer real speech (espeak-ng) so the baseline transcribes to
    non-empty text; fall back to a 440Hz sine (decodes fine, whisper returns empty).
    """
    import shutil

    live_opts = ["-live", "1", "-cluster_time_limit", "1000"] if live else []
    if shutil.which("espeak-ng"):
        wav = dst.with_suffix(".wav")
        subprocess.run(["espeak-ng", "-s", "150", "-w", str(wav), SPEECH], check=True)
        subprocess.run(
            ["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", str(wav),
             "-ar", "48000", "-ac", "1", "-c:a", "libopus", "-f", "webm",
             *live_opts, str(dst)],
            check=True,
        )
        wav.unlink(missing_ok=True)
        return
    subprocess.run(
        ["ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
         "-f", "lavfi", "-i", "sine=frequency=440:duration=5",
         "-c:a", "libopus", "-f", "webm", *live_opts, str(dst)],
        check=True,
    )


def classify(cp: subprocess.CompletedProcess | None, timed_out: bool) -> tuple[str, str]:
    if timed_out:
        return "HANG", "no exit within %ds" % CHILD_TIMEOUT
    assert cp is not None
    rc = cp.returncode
    stdout = cp.stdout or ""
    stderr = cp.stderr or ""
    if rc == 0:
        line = next((l for l in stdout.splitlines() if l.startswith("RESULT ")), "")
        detail = line[len("RESULT "):] if line else "(no RESULT line)"
        try:
            obj = json.loads(detail)
            if obj.get("text_len", 0) > 0:
                return "CLEAN/TEXT", f"{obj['text_len']}ch: {obj['text_preview']!r} pcm_bytes={obj['pcm_bytes']} segments={obj['segments']}"
            return "CLEAN/EMPTY", f"pcm_bytes={obj['pcm_bytes']} segments={obj['segments']}"
        except Exception:
            return "CLEAN/?", detail
    if rc < 0:
        return "CRASH", f"killed by signal {-rc}"
    # nonzero exit: expect a Python traceback -> catchable exception
    exc_line = ""
    for l in reversed(stderr.splitlines()):
        if l.strip() and ":" in l and not l.startswith(" "):
            exc_line = l.strip()
            break
    kind = "EXCEPTION" if "Traceback" in stderr else f"EXIT {rc}"
    return kind, exc_line or (stderr.strip().splitlines()[-1] if stderr.strip() else f"rc={rc}")


def sweep(tmpdir: Path, profile: str, live: bool) -> list[tuple]:
    full = tmpdir / f"full_{profile}.webm"
    synth_webm(full, live)
    blob = full.read_bytes()
    total = len(blob)
    print(f"\n[parent] profile={profile} (MediaRecorder-like={live}): {total} bytes")

    # Offsets: header-only tiny slices + 10%..90% + full baseline (100%).
    offsets: list[tuple[str, int]] = [("hdr-1KB", min(1024, total)), ("hdr-4KB", min(4096, total))]
    for pct in range(10, 100, 10):
        offsets.append((f"{pct}%", max(1, total * pct // 100)))
    offsets.append(("100%", total))

    rows = []
    for label, nbytes in offsets:
        slice_path = tmpdir / f"trunc_{profile}_{label.replace('%','pct')}.webm"
        slice_path.write_bytes(blob[:nbytes])
        timed_out = False
        cp = None
        try:
            cp = subprocess.run(
                [sys.executable, __file__, "--child", str(slice_path)],
                capture_output=True, text=True, timeout=CHILD_TIMEOUT,
            )
        except subprocess.TimeoutExpired:
            timed_out = True
        cls, detail = classify(cp, timed_out)
        rows.append((profile, label, nbytes, cls, detail))
        print(f"[parent] {profile:<4} {label:>8} {nbytes:>7}B -> {cls}")
    return rows


def run_parent() -> int:
    tmpdir = Path(tempfile.mkdtemp(prefix="webm_verify_"))

    # Warm the HF model cache once so per-child timeouts measure DECODE, not download.
    print("[parent] warming faster-whisper 'tiny' cache (one-time download)...")
    from workers.ai.providers.streaming_stt import StreamingWhisper

    StreamingWhisper(MODEL, DEVICE, COMPUTE)
    print("[parent] cache warm.")

    rows = []
    rows += sweep(tmpdir, "live", live=True)   # MediaRecorder-faithful profile
    rows += sweep(tmpdir, "file", live=False)  # ffmpeg default file mux

    # Results table.
    print("\n================ RESULTS ================")
    print(f"{'prof':<4} | {'offset':>8} | {'bytes':>7} | {'classification':<12} | detail")
    print("-" * 108)
    for profile, label, nbytes, cls, detail in rows:
        print(f"{profile:<4} | {label:>8} | {nbytes:>7} | {cls:<12} | {detail}")
    print("-" * 108)

    crashed = [r for r in rows if r[3] == "CRASH"]
    hung = [r for r in rows if r[3] == "HANG"]

    # Baseline self-assert: the untruncated (100%) input on BOTH profiles must
    # decode+transcribe cleanly with non-empty text, else the sweep below is not
    # trustworthy (a broken pipeline would report every truncation as "clean").
    # NOTE: CLEAN/TEXT requires espeak-ng in the image — the 440Hz sine fallback
    # in synth_webm() decodes fine but transcribes to empty text, which would
    # (correctly) fail this baseline assert.
    def _find_100pct(profile: str):
        return next((r for r in rows if r[0] == profile and r[1] == "100%"), None)

    baseline_live = _find_100pct("live")
    baseline_file = _find_100pct("file")

    print("\n================ VERDICT ================")
    if crashed or hung:
        print(f"UNSAFE: {len(crashed)} CRASH, {len(hung)} HANG -> in-process except/decode_error() is INSUFFICIENT.")
        return 1

    baseline_ok = True
    for name, row in (("live", baseline_live), ("file", baseline_file)):
        if row is None:
            print(f"UNSAFE: baseline 100% row missing for profile={name!r}.")
            baseline_ok = False
        elif row[3] != "CLEAN/TEXT":
            print(f"UNSAFE: baseline 100% for profile={name!r} is not CLEAN/TEXT: {row[3]} ({row[4]})")
            baseline_ok = False
    if not baseline_ok:
        return 1

    print("SAFE: no CRASH, no HANG across both webm profiles, and both 100% baselines are CLEAN/TEXT.")
    print("All partials -> catchable exception (decode_error()/raised) or clean (cumulative text / empty) result.")
    print("=> the in-process except Exception / decoder.decode_error() check in the WS route is SUFFICIENT.")
    return 0


if __name__ == "__main__":
    if len(sys.argv) >= 3 and sys.argv[1] == "--child":
        sys.exit(run_child(sys.argv[2]))
    sys.exit(run_parent())
