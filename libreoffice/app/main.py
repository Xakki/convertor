"""Headless LibreOffice + pandoc HTTP proxy.

Endpoints:
  GET  /health                    liveness probe
  POST /doc2docxOld               multipart upload -> .docx body
  POST /doc2textOld               multipart upload -> .txt  body
  POST /doc2mdOld                 multipart upload -> .md   body  (GitHub-flavoured)
  POST /doc2docx                  form 'file=<path under SHARE_DIR>' -> JSON
  POST /doc2text                  form 'file=<path under SHARE_DIR>' -> JSON
  POST /doc2md                    form 'file=<path under SHARE_DIR>' -> JSON

Pipeline:
  txt:    PDF -> pdftotext            other -> soffice
  docx:                                          soffice
  md:     PDF -> pdftotext -> wrap     docx/odt/html -> pandoc
                                       other -> soffice -> docx -> pandoc

soffice runs in a private UserInstallation per-request so concurrent
conversions don't fight over ~/.config/libreoffice.
"""

import asyncio
import os
import sys
import tempfile
from pathlib import Path

from aiohttp import web

PORT = int(os.getenv("PORT", "6000"))
SHARE_DIR = Path(os.getenv("SHARE_DIR", "/share")).resolve()
SOFFICE_TIMEOUT = int(os.getenv("SOFFICE_TIMEOUT", "180"))
MAX_UPLOAD = int(os.getenv("MAX_UPLOAD", str(256 * 1024 * 1024)))

SOFFICE_FILTER = {
    "docx": "docx",
    "txt":  "txt:Text (encoded):UTF8",
}

PANDOC_NATIVE = {".docx", ".odt", ".html", ".htm", ".epub"}


async def _run(argv: list[str], timeout: int = SOFFICE_TIMEOUT) -> tuple[str, str]:
    proc = await asyncio.create_subprocess_exec(
        *argv,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
    )
    try:
        out_b, err_b = await asyncio.wait_for(proc.communicate(), timeout=timeout)
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        raise
    out = out_b.decode("utf-8", "replace")
    err = err_b.decode("utf-8", "replace")
    if proc.returncode != 0:
        raise RuntimeError(err.strip() or out.strip() or f"{argv[0]} exit {proc.returncode}")
    return out, err


async def run_soffice(src: Path, out_dir: Path, convert_to: str) -> tuple[str, str]:
    with tempfile.TemporaryDirectory(prefix="lo-profile-") as profile:
        return await _run([
            "soffice",
            f"-env:UserInstallation={Path(profile).as_uri()}",
            "--headless", "--norestore", "--nologo", "--nofirststartwizard",
            "--convert-to", convert_to,
            "--outdir", str(out_dir),
            str(src),
        ])


async def run_pdftotext(src: Path, out_path: Path) -> tuple[str, str]:
    return await _run(["pdftotext", "-layout", "-enc", "UTF-8", str(src), str(out_path)])


async def run_pandoc(src: Path, out_path: Path, media_dir: Path) -> tuple[str, str]:
    return await _run([
        "pandoc",
        "--from", _pandoc_format(src),
        "--to", "gfm",
        "--wrap=none",
        f"--extract-media={media_dir}",
        "-o", str(out_path),
        str(src),
    ])


def _pandoc_format(src: Path) -> str:
    return {
        ".docx": "docx", ".odt": "odt",
        ".html": "html", ".htm": "html", ".epub": "epub",
    }.get(src.suffix.lower(), "docx")


async def convert(src: Path, target: str, work_dir: Path) -> Path:
    """Produce <work_dir>/<stem>.<target> from src. Returns the output path."""
    stem = src.stem
    suffix = src.suffix.lower()

    # PDF: LibreOffice opens PDFs as Draw documents and can't export them to
    # Writer targets, so use poppler's pdftotext and chain through soffice for docx.
    if suffix == ".pdf":
        with tempfile.TemporaryDirectory(prefix="pdf-tmp-") as tmp:
            tmp_dir = Path(tmp)
            txt_path = tmp_dir / f"{stem}.txt"
            await run_pdftotext(src, txt_path)
            out = work_dir / f"{stem}.{target}"
            if target == "txt" or target == "md":
                out.write_bytes(txt_path.read_bytes())
                return out
            if target == "docx":
                await run_soffice(txt_path, work_dir, SOFFICE_FILTER["docx"])
                return out
            raise ValueError(f"unsupported target: {target}")

    if target == "txt":
        await run_soffice(src, work_dir, SOFFICE_FILTER["txt"])
        return work_dir / f"{stem}.txt"

    if target == "docx":
        await run_soffice(src, work_dir, SOFFICE_FILTER["docx"])
        return work_dir / f"{stem}.docx"

    if target == "md":
        out = work_dir / f"{stem}.md"
        media = work_dir / "media"
        if suffix in PANDOC_NATIVE:
            await run_pandoc(src, out, media)
            return out
        # Convert to .docx in a private tempdir to avoid leaving intermediates
        # in the caller's share directory, then pandoc -> md.
        with tempfile.TemporaryDirectory(prefix="md-tmp-") as tmp:
            tmp_dir = Path(tmp)
            await run_soffice(src, tmp_dir, SOFFICE_FILTER["docx"])
            await run_pandoc(tmp_dir / f"{stem}.docx", out, media)
            return out

    raise ValueError(f"unsupported target: {target}")


def safe_share_path(raw: str) -> Path:
    candidate = Path(raw)
    if not candidate.is_absolute():
        candidate = SHARE_DIR / candidate
    resolved = candidate.resolve()
    if resolved != SHARE_DIR and SHARE_DIR not in resolved.parents:
        raise ValueError(f"path escapes {SHARE_DIR}: {raw}")
    return resolved


CONTENT_TYPES = {
    "docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "txt":  "text/plain",
    "md":   "text/markdown",
}
CONTENT_CHARSETS = {"txt": "utf-8", "md": "utf-8"}


async def upload_handle(request: web.Request, target: str) -> web.Response:
    reader = await request.multipart()
    field = await reader.next()
    if field is None:
        return web.json_response({"error": "no multipart part"}, status=400)

    with tempfile.TemporaryDirectory(prefix="lo-in-") as work:
        work_dir = Path(work)
        safe_name = os.path.basename(field.filename or "input") or "input"
        in_path = work_dir / safe_name
        with in_path.open("wb") as f:
            while True:
                chunk = await field.read_chunk()
                if not chunk:
                    break
                f.write(chunk)

        try:
            out_path = await convert(in_path, target, work_dir)
        except asyncio.TimeoutError:
            return web.json_response({"error": "conversion timeout"}, status=504)
        except RuntimeError as e:
            return web.json_response({"error": str(e)}, status=400)

        if not out_path.exists():
            return web.json_response(
                {"error": "conversion produced no output"}, status=500)

        return web.Response(
            body=out_path.read_bytes(),
            content_type=CONTENT_TYPES[target],
            charset=CONTENT_CHARSETS.get(target),
            headers={"Content-Disposition": f'attachment; filename="{out_path.name}"'},
        )


async def shared_handle(request: web.Request, target: str) -> web.Response:
    data = await request.post()
    raw = data.get("file")
    if not raw:
        return web.json_response({"error": "missing 'file' field"}, status=400)

    try:
        src = safe_share_path(str(raw))
    except ValueError as e:
        return web.json_response({"error": str(e)}, status=400)

    if not src.is_file():
        return web.json_response({"error": "file not found"}, status=404)

    try:
        out_path = await convert(src, target, src.parent)
    except asyncio.TimeoutError:
        return web.json_response({"error": "conversion timeout"}, status=504)
    except RuntimeError as e:
        return web.json_response({"code": 1, "outs": "", "error": str(e)}, status=400)

    return web.json_response({"code": 0, "outs": f"-> {out_path}", "error": ""})


async def doc2docxOld(r): return await upload_handle(r, "docx")
async def doc2textOld(r): return await upload_handle(r, "txt")
async def doc2mdOld(r):   return await upload_handle(r, "md")
async def doc2docx(r):    return await shared_handle(r, "docx")
async def doc2text(r):    return await shared_handle(r, "txt")
async def doc2md(r):      return await shared_handle(r, "md")


async def health(_request):
    return web.Response(text="ok\n")


def make_app() -> web.Application:
    app = web.Application(client_max_size=MAX_UPLOAD)
    app.router.add_get("/health", health)
    app.router.add_post("/doc2docxOld", doc2docxOld)
    app.router.add_post("/doc2textOld", doc2textOld)
    app.router.add_post("/doc2mdOld",   doc2mdOld)
    app.router.add_post("/doc2docx",    doc2docx)
    app.router.add_post("/doc2text",    doc2text)
    app.router.add_post("/doc2md",      doc2md)
    return app


if __name__ == "__main__":
    print(f"Listening on :{PORT}, SHARE_DIR={SHARE_DIR}", file=sys.stderr)
    web.run_app(make_app(), port=PORT, print=None)
