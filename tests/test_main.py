import asyncio
import os
import subprocess
import sys
from contextlib import asynccontextmanager
from pathlib import Path

from aiohttp import FormData, web
from aiohttp.test_utils import TestClient, TestServer

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import libreoffice.main as main


@asynccontextmanager
async def create_client(*routes):
    app = web.Application()
    for path, handler in routes:
        app.router.add_post(path, handler)
    server = TestServer(app)
    await server.start_server()
    client = TestClient(server)
    await client.start_server()
    try:
        yield client
    finally:
        await client.close()
        await server.close()


def test_doc2text_sync_success(monkeypatch):
    async def scenario():
        def fake_run(command, stdout=None, stderr=None, text=None, timeout=None):
            input_path = command[-1]
            output_path = f"{input_path}.txt"
            with open(output_path, "w", encoding="utf-8") as f:
                f.write("converted text")
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")

        monkeypatch.setattr(subprocess, "run", fake_run)

        async with create_client(("/doc2textSync", main.doc2text_handleSync)) as client:
            form = FormData()
            form.add_field("file", b"hello", filename="test.doc")
            resp = await client.post("/doc2textSync", data=form)
            assert resp.status == 200
            assert resp.headers["Content-Type"] == "text/plain; charset=utf-8"
            assert await resp.text() == "converted text"

    asyncio.run(scenario())


def test_doc2text_sync_timeout(monkeypatch):
    async def scenario():
        def fake_run(command, stdout=None, stderr=None, text=None, timeout=None):
            raise subprocess.TimeoutExpired(cmd=command, timeout=timeout)

        monkeypatch.setattr(subprocess, "run", fake_run)

        async with create_client(("/doc2textSync", main.doc2text_handleSync)) as client:
            form = FormData()
            form.add_field("file", b"hello", filename="test.doc")
            resp = await client.post("/doc2textSync", data=form)
            assert resp.status == 504
            assert "Conversion timed out" in await resp.text()

    asyncio.run(scenario())


def test_doc2text_sync_missing_output(monkeypatch):
    async def scenario():
        def fake_run(command, stdout=None, stderr=None, text=None, timeout=None):
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")

        monkeypatch.setattr(subprocess, "run", fake_run)

        async with create_client(("/doc2textSync", main.doc2text_handleSync)) as client:
            form = FormData()
            form.add_field("file", b"hello", filename="test.doc")
            resp = await client.post("/doc2textSync", data=form)
            assert resp.status == 400
            assert "Converted file not found" in await resp.text()

    asyncio.run(scenario())


def test_doc2text_async_success(monkeypatch, tmp_path):
    async def scenario():
        def fake_popen(command, stdout=None, stderr=None, text=None, preexec_fn=None):
            class FakeProcess:
                def __init__(self):
                    self.command = command
                    self.returncode = 0
                    self.pid = 12345

                def communicate(self, timeout=None):
                    base, _ = os.path.splitext(self.command[-1])
                    output_path = f"{base}.txt"
                    with open(output_path, "w", encoding="utf-8") as f:
                        f.write("converted text")
                    return ("process stdout", "")

                def kill(self):
                    pass

            return FakeProcess()

        monkeypatch.setattr(subprocess, "Popen", fake_popen)

        input_file = tmp_path / "sample.doc"
        input_file.write_text("dummy")

        async with create_client(("/doc2text", main.doc2text_handle)) as client:
            resp = await client.post("/doc2text", data={"file": str(input_file)})
            assert resp.status == 200
            payload = await resp.json()
            assert payload == {"code": 0, "outs": "process stdout", "error": ""}

    asyncio.run(scenario())


def test_doc2text_async_missing_parameter():
    async def scenario():
        async with create_client(("/doc2text", main.doc2text_handle)) as client:
            resp = await client.post("/doc2text", data={})
            assert resp.status == 400
            payload = await resp.json()
            assert payload["exception"] == "Missing 'file' parameter"

    asyncio.run(scenario())


def test_doc2text_async_missing_file(tmp_path):
    async def scenario():
        missing = tmp_path / "missing.doc"
        async with create_client(("/doc2text", main.doc2text_handle)) as client:
            resp = await client.post("/doc2text", data={"file": str(missing)})
            assert resp.status == 400
            payload = await resp.json()
            assert payload["exception"] == "File not available"

    asyncio.run(scenario())


def test_doc2text_async_timeout(monkeypatch, tmp_path):
    async def scenario():
        class FakeProcess:
            def __init__(self, command):
                self.command = command
                self.returncode = 0
                self.pid = 12345

            def communicate(self, timeout=None):
                if timeout is not None:
                    raise subprocess.TimeoutExpired(cmd=self.command, timeout=timeout)
                return ("", "")

            def kill(self):
                pass

        def fake_popen(command, stdout=None, stderr=None, text=None, preexec_fn=None):
            return FakeProcess(command)

        monkeypatch.setattr(subprocess, "Popen", fake_popen)
        if hasattr(os, "killpg"):
            monkeypatch.setattr(os, "killpg", lambda pgid, sig: None)
        if hasattr(os, "getpgid"):
            monkeypatch.setattr(os, "getpgid", lambda pid: pid)

        input_file = tmp_path / "sample.doc"
        input_file.write_text("dummy")

        async with create_client(("/doc2text", main.doc2text_handle)) as client:
            resp = await client.post("/doc2text", data={"file": str(input_file)})
            assert resp.status == 504
            payload = await resp.json()
            assert payload["exception"] == "Conversion timed out"

    asyncio.run(scenario())


def test_doc2text_async_failure(monkeypatch, tmp_path):
    async def scenario():
        class FakeProcess:
            def __init__(self, command):
                self.command = command
                self.returncode = 1
                self.pid = 12345

            def communicate(self, timeout=None):
                return ("", "boom")

            def kill(self):
                pass

        def fake_popen(command, stdout=None, stderr=None, text=None, preexec_fn=None):
            return FakeProcess(command)

        monkeypatch.setattr(subprocess, "Popen", fake_popen)

        input_file = tmp_path / "sample.doc"
        input_file.write_text("dummy")

        async with create_client(("/doc2text", main.doc2text_handle)) as client:
            resp = await client.post("/doc2text", data={"file": str(input_file)})
            assert resp.status == 400
            payload = await resp.json()
            assert payload["exception"] == "boom"

    asyncio.run(scenario())
