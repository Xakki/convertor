
from aiohttp import web
import io
import json
import os
import signal
import subprocess
import sys
import tempfile
from typing import List
import uuid

SOFFICE_TIMEOUT = 120
SHARE_PATH = "/shared-files/"


def _build_soffice_command(input_path: str, output_dir: str, convert_to: str) -> List[str]:
    """Build a command for invoking LibreOffice without shell interpolation."""
    return [
        "soffice",
        "--headless",
        "--convert-to",
        convert_to,
        "--outdir",
        output_dir,
        input_path,
    ]


def _content_type_for_extension(ext: str) -> str:
    if ext == "docx":
        return "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
    return "text/plain"

#https://pythonexamples.org/run.php

async def hello(request):
    return web.Response(text="HELLO")


async def doc2docx_multipartFile(request):
    return await convertMultipartFileHandle(request, 'docx', 'docx')

async def doc2text_multipartFile(request):
    return await convertMultipartFileHandle(request, 'txt:Text (encoded):UTF8', 'txt')


async def doc2docx_SharedFile(request):
    return await convertSharedFileHandle(request, 'docx', 'docx')

async def doc2text_SharedFile(request):
    return await convertSharedFileHandle(request, 'txt:Text (encoded):UTF8', 'txt')


async def convertMultipartFileHandle(request, convertTo, ext):
    reader = await request.multipart()
    if reader is None:
        return web.Response(
            text="Missing multipart data",
            status=400,
            reason="Bad request",
            content_type="text/plain",
            charset="utf-8",
        )

    field = await reader.next()
    if field is None:
        return web.Response(
            text="Missing file field",
            status=400,
            reason="Bad request",
            content_type="text/plain",
            charset="utf-8",
        )

    unique_id = uuid.uuid4()
    origin_filename = field.filename or "noname.txt"
    out_filename = os.path.splitext(origin_filename)[0]
    out_filename = f"{out_filename}.{ext}"
    output_dir = os.path.join(SHARE_PATH, str(unique_id))

    try:
        os.makedirs(output_dir, exist_ok=True)
    except OSError as e:
        return web.Response(
            text=f"Failed to create directory: {e}",
            status=500,
            reason="Internal Server Error",
            content_type="text/plain",
            charset="utf-8",
        )

    with tempfile.NamedTemporaryFile(delete=False) as output:
        input_path = output.name
        while True:
            chunk = await field.read_chunk()
            if not chunk:
                break
            output.write(chunk)

        output.flush()

    try:
        command = _build_soffice_command(input_path, output_dir, convertTo)
        completed = subprocess.run(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=SOFFICE_TIMEOUT,
        )

        if completed.stdout:
            print(completed.stdout, file=sys.stderr)

        if completed.returncode != 0:
            raise RuntimeError(completed.stderr or "LibreOffice conversion failed")

        output_path = f"{input_path}.{ext}"
        if not os.path.exists(output_path):
            raise FileNotFoundError("Converted file not found")

        response = web.StreamResponse(
            status=200,
            reason="OK",
        )
        response.headers['Content-Disposition'] = f'attachment; filename="{out_filename}"'
        content_type = _content_type_for_extension(ext)
        response.content_type = content_type
        if content_type == "text/plain":
            response.charset = "utf-8"
        await response.prepare(request)

        with io.open(output_path, mode="rb") as f:
            await response.write(f.read())

        await response.write_eof()
    except subprocess.TimeoutExpired:
        response = web.Response(
            text="Conversion timed out",
            status=504,
            reason="Gateway Timeout",
            content_type="text/plain",
            charset="utf-8",
        )
    except Exception as e:
        print("Exception: ", e, file=sys.stderr)
        response = web.Response(
            text=str(e),
            status=400,
            reason="Bad request",
            content_type="text/plain",
            charset="utf-8",
        )
    finally:
        if output_path and os.path.exists(output_path):
            try:
                os.unlink(output_path)
            except OSError:
                pass
        if os.path.exists(input_path):
            try:
                os.unlink(input_path)
            except OSError:
                pass

    return response


async def convertSharedFileHandle(request, convertTo, ext):
    data = await request.post()
    code = 1
    outs = ""
    errs = ""

    try:

        file_path = data.get("file")
        if not file_path:
            raise ValueError("Missing 'file' parameter")
        file_path = SHARE_PATH + file_path.replace('..', '')
        #TODO: if file_path is dir, need convert all files in dir
        if not os.path.isfile(file_path):
            raise FileNotFoundError("File not available")

        command = _build_soffice_command(
            os.path.abspath(file_path),
            os.path.abspath(os.path.dirname(file_path)),
            convertTo,
        )

        preexec_fn = os.setsid if hasattr(os, "setsid") else None
        proc = subprocess.Popen(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            preexec_fn=preexec_fn,
        )

        try:
            outs, errs = proc.communicate(timeout=SOFFICE_TIMEOUT)
        except subprocess.TimeoutExpired:
            if preexec_fn is not None and hasattr(os, "killpg"):
                os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
            else:
                proc.kill()
            outs, errs = proc.communicate()
            raise TimeoutError("Conversion timed out")

        code = proc.returncode
        if code != 0:
            raise RuntimeError(errs or "LibreOffice conversion failed")

        base, _ = os.path.splitext(file_path)
        path = f"{base}.{ext}"
        if not os.path.exists(path):
            raise FileNotFoundError("Converted file not found")

        response = web.Response(
            body=json.dumps({'path': path, 'code': code, 'outs': outs, 'error': errs}).encode('utf-8'),
            status=200,
            reason="OK",
            content_type='application/json',
        )

    except TimeoutError as e:
        print("Exception: ", e, file=sys.stderr)
        response = web.Response(
            body=json.dumps({'code': code, 'outs': outs, 'error': errs, 'exception': str(e)}).encode('utf-8'),
            status=504,
            reason="Gateway Timeout",
            content_type='application/json',
        )
    except Exception as e:
        print("Exception: ", e, file=sys.stderr)
        response = web.Response(
            body=json.dumps({'code': code, 'outs': outs, 'error': errs, 'exception': str(e)}).encode('utf-8'),
            status=400,
            reason="Bad request",
            content_type='application/json',
        )

    return response

if __name__ == '__main__':
    app = web.App