"""FFmpeg worker entry point — два WS-соединения: audio и video.

Один FfmpegWorker обслуживает оба stream'а через два отдельных WsClient:
  - workerType="audio", workerId="{WORKER_ID}-audio"
  - workerType="video", workerId="{WORKER_ID}-video"

Диsjoint workerId → независимые PEL в KeyDB (нет дублирования in-flight задач).
"""

from __future__ import annotations

import asyncio
import logging
import signal
from dataclasses import replace

from workers.common.logging_config import configure_logging
from workers.common.ws_client import WsClient, WsClientConfig
from workers.ffmpeg.worker import AUDIO_CAPABILITIES, VIDEO_CAPABILITIES, FfmpegWorker

logger = logging.getLogger(__name__)


def build_dual_configs(base: WsClientConfig) -> tuple[WsClientConfig, WsClientConfig]:
    """Produce (audio_cfg, video_cfg) from *base*.

    Overrides worker_id with <base_id>-audio / -video and sets worker_type.
    Caller should validate each config before use.
    """
    base_id = base.worker_id or "ffmpeg"
    return (
        replace(base, worker_id=f"{base_id}-audio", worker_type="audio"),
        replace(base, worker_id=f"{base_id}-video", worker_type="video"),
    )


async def run_dual(
    base: WsClientConfig | None = None,
    *,
    http_client=None,
) -> None:
    """Run audio and video WsClients concurrently, sharing one FfmpegWorker.

    *base* is used as the template config; worker_type and worker_id are
    overridden per connection. If *base* is None, reads from environment.
    """
    if base is None:
        base = WsClientConfig.from_env()

    worker = FfmpegWorker()
    cfg_audio, cfg_video = build_dual_configs(base)
    cfg_audio.validate()
    cfg_video.validate()

    client_audio = WsClient(
        cfg_audio, worker.process_job, http_client=http_client,
        capabilities=AUDIO_CAPABILITIES,
    )
    client_video = WsClient(
        cfg_video, worker.process_job, http_client=http_client,
        capabilities=VIDEO_CAPABILITIES,
    )

    loop = asyncio.get_running_loop()

    def _stop_both() -> None:
        client_audio.stop()
        client_video.stop()

    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, _stop_both)

    logger.info(
        "ffmpeg worker starting — audio: %s, video: %s, gateway: %s",
        cfg_audio.worker_id, cfg_video.worker_id, cfg_audio.gateway_ws_url,
    )
    try:
        await asyncio.gather(client_audio.run(), client_video.run())
    finally:
        for sig in (signal.SIGTERM, signal.SIGINT):
            loop.remove_signal_handler(sig)


def main() -> None:
    """Entry point for `python -m workers.ffmpeg`."""
    configure_logging()
    asyncio.run(run_dual())


if __name__ == "__main__":
    main()
