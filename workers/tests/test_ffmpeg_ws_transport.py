"""WS-transport tests for FfmpegWorker — audio/video connections (s1-11)."""

from __future__ import annotations

import asyncio
import json
from contextlib import suppress
from unittest.mock import patch

import httpx

from workers.common.ws_client import WsClient
from workers.tests.ws_helpers import make_ws_cfg, wait_for, mock_http_transport


async def test_ffmpeg_audio_ws_ready_frame(tmp_path):
    """FfmpegWorker audio connection → WS ready{workerType:'audio'}."""
    from websockets.asyncio.server import serve
    from workers.ffmpeg.worker import FfmpegWorker

    received: list[dict] = []

    async def gw_handler(ws):
        with suppress(Exception):
            async for raw in ws:
                with suppress(Exception):
                    received.append(json.loads(raw))

    async with serve(gw_handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        cfg = make_ws_cfg(port, tmp_path, "audio", "ffmpeg-audio")
        client = WsClient(
            cfg, FfmpegWorker().process_job,
            http_client=httpx.AsyncClient(transport=mock_http_transport()),
        )
        runner = asyncio.create_task(client.run())
        try:
            await wait_for(lambda: any(f.get("type") == "ready" for f in received))
            rf = next(f for f in received if f.get("type") == "ready")
            assert rf["workerType"] == "audio"
            assert rf["workerId"] == "ffmpeg-audio"
        finally:
            client.stop()
            await asyncio.wait_for(runner, timeout=3.0)


async def test_ffmpeg_video_ws_ready_frame(tmp_path):
    """FfmpegWorker video connection → WS ready{workerType:'video'}."""
    from websockets.asyncio.server import serve
    from workers.ffmpeg.worker import FfmpegWorker

    received: list[dict] = []

    async def gw_handler(ws):
        with suppress(Exception):
            async for raw in ws:
                with suppress(Exception):
                    received.append(json.loads(raw))

    async with serve(gw_handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        cfg = make_ws_cfg(port, tmp_path, "video", "ffmpeg-video")
        client = WsClient(
            cfg, FfmpegWorker().process_job,
            http_client=httpx.AsyncClient(transport=mock_http_transport()),
        )
        runner = asyncio.create_task(client.run())
        try:
            await wait_for(lambda: any(f.get("type") == "ready" for f in received))
            rf = next(f for f in received if f.get("type") == "ready")
            assert rf["workerType"] == "video"
            assert rf["workerId"] == "ffmpeg-video"
        finally:
            client.stop()
            await asyncio.wait_for(runner, timeout=3.0)


async def test_ffmpeg_dual_connection_disjoint_ids(tmp_path):
    """ffmpeg dual-connection: TWO WS connections with disjoint workerIds/workerTypes.

    Verifies build_dual_configs() produces correct per-connection configs
    and that both WsClients connect and announce distinct identities.
    """
    from websockets.asyncio.server import serve
    from workers.ffmpeg.__main__ import build_dual_configs
    from workers.ffmpeg.worker import FfmpegWorker

    ready_frames: list[dict] = []

    async def gw_handler(ws):
        with suppress(Exception):
            async for raw in ws:
                with suppress(Exception):
                    frame = json.loads(raw)
                    if frame.get("type") == "ready":
                        ready_frames.append(frame)

    async with serve(gw_handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]

        base = make_ws_cfg(port, tmp_path, "audio", "ffmpeg")
        cfg_audio, cfg_video = build_dual_configs(base)

        # Static config assertions (disjoint PEL consumer names)
        assert cfg_audio.worker_id == "ffmpeg-audio"
        assert cfg_audio.worker_type == "audio"
        assert cfg_video.worker_id == "ffmpeg-video"
        assert cfg_video.worker_type == "video"

        worker = FfmpegWorker()
        http = httpx.AsyncClient(transport=mock_http_transport())
        client_audio = WsClient(cfg_audio, worker.process_job, http_client=http)
        client_video = WsClient(cfg_video, worker.process_job, http_client=http)

        task_audio = asyncio.create_task(client_audio.run())
        task_video = asyncio.create_task(client_video.run())
        try:
            await wait_for(lambda: len(ready_frames) >= 2, timeout=5.0)

            ids   = {f["workerId"]   for f in ready_frames}
            types = {f["workerType"] for f in ready_frames}
            assert ids   == {"ffmpeg-audio", "ffmpeg-video"}, f"unexpected workerIds: {ids}"
            assert types == {"audio", "video"}, f"unexpected workerTypes: {types}"
        finally:
            client_audio.stop()
            client_video.stop()
            await asyncio.gather(
                asyncio.wait_for(task_audio, timeout=3.0),
                asyncio.wait_for(task_video, timeout=3.0),
                return_exceptions=True,
            )


async def test_ffmpeg_audio_ws_inline_result(tmp_path):
    """FfmpegWorker audio connection: job{mp3→ogg} → inline result via WS (convert mocked)."""
    from websockets.asyncio.server import serve
    from workers.ffmpeg.worker import FfmpegWorker

    received: list[dict] = []
    ws_connections: list = []

    async def gw_handler(ws):
        ws_connections.append(ws)
        with suppress(Exception):
            async for raw in ws:
                with suppress(Exception):
                    received.append(json.loads(raw))

    out = tmp_path / "out.ogg"
    out.write_bytes(b"OggS\x00fake-ogg")
    worker = FfmpegWorker()

    async with serve(gw_handler, "127.0.0.1", 0) as server:
        port = server.sockets[0].getsockname()[1]
        cfg = make_ws_cfg(port, tmp_path, "audio", "ffmpeg-audio")
        client = WsClient(
            cfg, worker.process_job,
            http_client=httpx.AsyncClient(transport=mock_http_transport(b"fake-mp3")),
        )
        runner = asyncio.create_task(client.run())
        try:
            await wait_for(lambda: any(f.get("type") == "ready" for f in received))
            await wait_for(lambda: ws_connections)

            with patch.object(worker, "convert", return_value=(str(out), "audio/ogg", "ogg")):
                await ws_connections[0].send(json.dumps({
                    "type": "job",
                    "jobId": "j-ffmpeg-1",
                    "conversionId": 3,
                    "sourceFormat": "mp3",
                    "targetFormat": "ogg",
                    "inputKey": "inputs/test.mp3",
                }))
                await wait_for(
                    lambda: any(f.get("type") == "result" for f in received), 5.0
                )

            rf = next(f for f in received if f.get("type") == "result")
            assert rf["jobId"] == "j-ffmpeg-1"
            assert "inline" in rf
        finally:
            client.stop()
            await asyncio.wait_for(runner, timeout=3.0)
