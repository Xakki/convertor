"""Methods tab routes — run any conversion locally on an uploaded file.

Never touches the backend pull-API: upload → temp file under WORK_DIR →
convert() → result, with an in-memory resultId→path registry for download.
"""

from __future__ import annotations

import logging
import re
import time
import uuid
from collections import OrderedDict
from pathlib import Path
from typing import Any

from fastapi import APIRouter, File, Form, Request, UploadFile
from fastapi.responses import FileResponse, JSONResponse

from workers.ai.convert import (
    LLM_INPUTS,
    LLM_OUTPUTS,
    STT_INPUTS,
    STT_OUTPUTS,
    TTS_INPUTS,
    TTS_OUTPUTS,
    convert,
)
from workers.ai.worker import _safe_err

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/api")

# Cap the result registry; evicting an entry deletes its temp file (best-effort).
_MAX_RESULTS = 50

# Format tokens are short alnum strings (mp3, txt, json…). Anything else is
# rejected up-front: sourceFormat/targetFormat are untrusted form fields and get
# interpolated into temp filenames, so a value like '../../x' would otherwise
# escape WORK_DIR on the input-file write (path traversal).
_FORMAT_RE = re.compile(r"[a-z0-9]{1,8}")


def _allowed_formats() -> set[str]:
    """Union of every source/target the worker advertises (same list as /api/methods)."""
    allowed: set[str] = set()
    for m in _methods():
        allowed.update(m["sources"])
        allowed.update(m["targets"])
    return allowed


def _valid_format(fmt: str, allowed: set[str]) -> bool:
    return bool(_FORMAT_RE.fullmatch(fmt)) and fmt in allowed


def _within(parent: Path, child: Path) -> bool:
    """True iff resolved `child` lives under resolved `parent` (traversal guard)."""
    try:
        child.resolve().relative_to(parent.resolve())
        return True
    except ValueError:
        return False


def _ordered(values: set[str], preferred: list[str]) -> list[str]:
    """Stable ordering: preferred order first, then any extras sorted."""
    extras = sorted(values - set(preferred))
    return [v for v in preferred if v in values] + extras


def _methods() -> list[dict[str, Any]]:
    """Derive the method list from convert.py's format sets (no hardcoded drift)."""
    audio = _ordered(STT_INPUTS, ["mp3", "wav", "ogg", "m4a", "opus", "flac"])
    text = _ordered(TTS_INPUTS, ["txt", "md"])
    return [
        {"mode": "stt", "label": "Speech → Text",
         "sources": audio, "targets": _ordered(STT_OUTPUTS, ["txt", "srt", "vtt"])},
        {"mode": "stt_stream", "label": "Speech → Segments",
         "sources": audio, "targets": ["json"]},
        {"mode": "tts", "label": "Text → Speech",
         "sources": text, "targets": _ordered(TTS_OUTPUTS, ["mp3", "wav", "ogg"])},
        {"mode": "embedding", "label": "Text → Embedding",
         "sources": _ordered(LLM_INPUTS, ["txt", "md"]), "targets": ["json"]},
        {"mode": "llm", "label": "Text → Text (LLM)",
         "sources": _ordered(LLM_INPUTS, ["txt", "md"]),
         "targets": _ordered(LLM_OUTPUTS, ["txt", "md"])},
    ]


@router.get("/methods")
async def get_methods() -> dict[str, Any]:
    return {"methods": _methods()}


def _register_result(results: OrderedDict, result_id: str, path: Path, mime: str, name: str) -> None:
    results[result_id] = {"path": path, "mime": mime, "name": name}
    while len(results) > _MAX_RESULTS:
        _, old = results.popitem(last=False)
        try:
            Path(old["path"]).unlink(missing_ok=True)
        except OSError:
            pass


@router.post("/run")
async def run_method(
    request: Request,
    file: UploadFile = File(...),
    sourceFormat: str = Form(...),
    targetFormat: str = Form(...),
    model: str | None = Form(None),
) -> Any:
    cfg = request.app.state.cfg
    src_fmt = sourceFormat.lower().lstrip(".")
    tgt_fmt = targetFormat.lower().lstrip(".")

    allowed = _allowed_formats()
    if not _valid_format(src_fmt, allowed) or not _valid_format(tgt_fmt, allowed):
        return JSONResponse(
            status_code=422,
            content={"ok": False, "error": "invalid sourceFormat/targetFormat (not an advertised conversion format)"},
        )

    cfg.work_dir.mkdir(parents=True, exist_ok=True)
    in_path = cfg.work_dir / f"devin-{uuid.uuid4().hex}.{src_fmt}"
    # Belt-and-suspenders: even with the allowlist, never write outside WORK_DIR.
    if not _within(cfg.work_dir, in_path):
        return JSONResponse(status_code=422, content={"ok": False, "error": "invalid input path"})
    try:
        with in_path.open("wb") as f:
            while True:
                chunk = await file.read(65536)
                if not chunk:
                    break
                f.write(chunk)
    except Exception as exc:  # noqa: BLE001 — surface any upload write error as 422
        in_path.unlink(missing_ok=True)
        return JSONResponse(status_code=422, content={"ok": False, "error": _safe_err(exc)})

    job: dict[str, Any] = {
        "_localInput": str(in_path),
        "conversionId": f"dev-{uuid.uuid4().hex[:8]}",
        "sourceFormat": src_fmt,
        "targetFormat": tgt_fmt,
        "model": model,
    }

    started = time.monotonic()
    try:
        out_str, mime, ext = await convert(job, cfg)
    except Exception as exc:  # noqa: BLE001 — any conversion error → 422 per contract
        logger.warning("dev /api/run failed (%s→%s): %s", src_fmt, tgt_fmt, _safe_err(exc))
        return JSONResponse(status_code=422, content={"ok": False, "error": _safe_err(exc)})
    finally:
        in_path.unlink(missing_ok=True)

    elapsed_ms = round((time.monotonic() - started) * 1000)
    out_path = Path(out_str)
    # Output path is server-built inside convert() from a server-generated id + the
    # already-validated tgt_fmt; assert containment anyway before exposing it.
    if not _within(cfg.work_dir, out_path):
        out_path.unlink(missing_ok=True)
        return JSONResponse(status_code=422, content={"ok": False, "error": "invalid output path"})
    size = out_path.stat().st_size

    text: str | None = None
    if mime.startswith("text/") or mime == "application/json":
        try:
            text = out_path.read_text(encoding="utf-8")
        except (OSError, UnicodeDecodeError):
            text = None

    result_id = uuid.uuid4().hex
    _register_result(request.app.state.results, result_id, out_path, mime, out_path.name)

    return {
        "ok": True,
        "resultId": result_id,
        "mime": mime,
        "ext": ext,
        "bytes": size,
        "text": text,
        "downloadUrl": f"/api/result/{result_id}",
        "elapsedMs": elapsed_ms,
    }


@router.get("/result/{result_id}")
async def get_result(request: Request, result_id: str) -> Any:
    entry = request.app.state.results.get(result_id)
    if entry is None or not Path(entry["path"]).exists():
        return JSONResponse(status_code=404, content={"ok": False, "error": "result not found"})
    return FileResponse(
        path=str(entry["path"]),
        media_type=entry["mime"],
        filename=entry["name"],
    )
