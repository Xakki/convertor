"""WS-transport tests for DataWorker (s1-11)."""

from __future__ import annotations

import asyncio
import json
from contextlib import suppress
from unittest.mock import patch

import httpx

from workers.common.ws_client import WsClient
from workers.tests.ws_helpers import make_ws_cfg, wait_for, mock_http_transport


async def test_data_ws_ready_frame(tmp_path):
    """DataWorker → WS ready{workerType:'data'}."""
    from websockets.asyncio.server import serve
    from workers.data.worker import DataWorker

    received: list[dict] = []

    async def gw_handler(ws):
        with suppress(Exception):
            async for raw in ws:
                with suppress(Exception):
                    received.append(json.loads(raw))

    async with serve(gw_handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        cfg = make_ws_cfg(port, tmp_path, "data", "test-data")
        client = WsClient(
            cfg, DataWorker().process_job,
            http_client=httpx.AsyncClient(transport=mock_http_transport()),
        )
        runner = asyncio.create_task(client.run())
        try:
            await wait_for(lambda: any(f.get("type") == "ready" for f in received))
            rf = next(f for f in received if f.get("type") == "ready")
            assert rf["workerType"] == "data"
            assert rf["workerId"] == "test-data"
        finally:
            client.stop()
            await asyncio.wait_for(runner, timeout=3.0)


async def test_data_ws_inline_result(tmp_path):
    """DataWorker: job{csv→json} → inline result frame via WS (convert mocked)."""
    from websockets.asyncio.server import serve
    from workers.data.worker import DataWorker

    received: list[dict] = []
    ws_connections: list = []

    async def gw_handler(ws):
        ws_connections.append(ws)
        with suppress(Exception):
            async for raw in ws:
                with suppress(Exception):
                    received.append(json.loads(raw))

    out = tmp_path / "out.json"
    out.write_bytes(b'[{"a":1}]')
    worker = DataWorker()

    async with serve(gw_handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        cfg = make_ws_cfg(port, tmp_path, "data", "test-data")
        client = WsClient(
            cfg, worker.process_job,
            http_client=httpx.AsyncClient(transport=mock_http_transport(b"a,b\n1,2\n")),
        )
        runner = asyncio.create_task(client.run())
        try:
            await wait_for(lambda: any(f.get("type") == "ready" for f in received))
            await wait_for(lambda: ws_connections)

            with patch.object(worker, "convert", return_value=(str(out), "application/json", "json")):
                await ws_connections[0].send(json.dumps({
                    "type": "job",
                    "jobId": "j-data-1",
                    "conversionId": 1,
                    "sourceFormat": "csv",
                    "targetFormat": "json",
                    "inputKey": "inputs/test.csv",
                }))
                await wait_for(
                    lambda: any(f.get("type") == "result" for f in received), 5.0
                )

            rf = next(f for f in received if f.get("type") == "result")
            assert rf["jobId"] == "j-data-1"
            assert "inline" in rf
        finally:
            client.stop()
            await asyncio.wait_for(runner, timeout=3.0)
