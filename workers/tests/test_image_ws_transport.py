"""WS-transport tests for ImageWorker (s1-11)."""

from __future__ import annotations

import asyncio
import json
from contextlib import suppress

import httpx

from workers.common.ws_client import WsClient
from workers.tests.ws_helpers import make_ws_cfg, wait_for, mock_http_transport


from unittest.mock import patch


async def test_image_ws_ready_frame(tmp_path):
    """ImageWorker → WS ready{workerType:'image'}."""
    from websockets.asyncio.server import serve
    from workers.image.worker import ImageWorker

    received: list[dict] = []

    async def gw_handler(ws):
        with suppress(Exception):
            async for raw in ws:
                with suppress(Exception):
                    received.append(json.loads(raw))

    async with serve(gw_handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        cfg = make_ws_cfg(port, tmp_path, "image", "test-image")
        client = WsClient(
            cfg, ImageWorker().process_job,
            http_client=httpx.AsyncClient(transport=mock_http_transport()),
        )
        runner = asyncio.create_task(client.run())
        try:
            await wait_for(lambda: any(f.get("type") == "ready" for f in received))
            rf = next(f for f in received if f.get("type") == "ready")
            assert rf["workerType"] == "image"
            assert rf["workerId"] == "test-image"
        finally:
            client.stop()
            await asyncio.wait_for(runner, timeout=3.0)


async def test_image_ws_inline_result(tmp_path):
    """ImageWorker: job{png→jpg} → inline result frame via WS (convert mocked)."""
    from websockets.asyncio.server import serve
    from workers.image.worker import ImageWorker

    received: list[dict] = []
    ws_connections: list = []

    async def gw_handler(ws):
        ws_connections.append(ws)
        with suppress(Exception):
            async for raw in ws:
                with suppress(Exception):
                    received.append(json.loads(raw))

    out = tmp_path / "out.jpg"
    out.write_bytes(b"\xff\xd8\xff\xe0fake-jpeg")
    worker = ImageWorker()

    async with serve(gw_handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        cfg = make_ws_cfg(port, tmp_path, "image", "test-image")
        client = WsClient(
            cfg, worker.process_job,
            http_client=httpx.AsyncClient(transport=mock_http_transport(b"fake-png")),
        )
        runner = asyncio.create_task(client.run())
        try:
            await wait_for(lambda: any(f.get("type") == "ready" for f in received))
            await wait_for(lambda: ws_connections)

            with patch.object(worker, "convert", return_value=(str(out), "image/jpeg", "jpg")):
                await ws_connections[0].send(json.dumps({
                    "type": "job",
                    "jobId": "j-image-1",
                    "conversionId": 2,
                    "sourceFormat": "png",
                    "targetFormat": "jpg",
                    "inputKey": "inputs/test.png",
                }))
                await wait_for(
                    lambda: any(f.get("type") == "result" for f in received), 5.0
                )

            rf = next(f for f in received if f.get("type") == "result")
            assert rf["jobId"] == "j-image-1"
            assert "inline" in rf
        finally:
            client.stop()
            await asyncio.wait_for(runner, timeout=3.0)
