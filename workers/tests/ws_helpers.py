"""Shared helpers for WS-transport worker tests."""

from __future__ import annotations

import asyncio
import json
from pathlib import Path

import httpx

from workers.common.ws_client import WsClientConfig


def make_ws_cfg(
    port: int,
    tmp_path: Path,
    worker_type: str,
    worker_id: str = "test-worker",
) -> WsClientConfig:
    return WsClientConfig(
        worker_id=worker_id,
        worker_type=worker_type,
        gateway_ws_url=f"ws://127.0.0.1:{port}",
        api_base_url="http://127.0.0.1:9999",
        worker_api_token="tok",
        version="0.1",
        work_dir=tmp_path,
        ws_ping_interval_s=999.0,
        ws_reconnect_backoff_base_s=0.05,
        ws_reconnect_backoff_max_s=0.1,
    )


async def wait_for(pred, timeout: float = 3.0, interval: float = 0.02) -> None:
    loop = asyncio.get_running_loop()
    deadline = loop.time() + timeout
    while loop.time() < deadline:
        if pred():
            return
        await asyncio.sleep(interval)
    raise TimeoutError(f"condition not met within {timeout}s")


def mock_http_transport(input_bytes: bytes = b"fake-input") -> httpx.MockTransport:
    """Mock Symfony API: /input → bytes, /result → OK, /register → OK."""
    def handler(req: httpx.Request) -> httpx.Response:
        if "/input" in req.url.path:
            return httpx.Response(200, content=input_bytes)
        if "/result" in req.url.path:
            return httpx.Response(200, json={"ok": True})
        if "/register" in req.url.path:
            return httpx.Response(200, json={"ok": True})
        return httpx.Response(404)
    return httpx.MockTransport(handler)
