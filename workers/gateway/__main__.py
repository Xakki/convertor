"""Точка входа WS-Gateway.

Загрузить конфиг → собрать async KeyDB-клиент → поднять WS-сервер (§4): handshake,
auth-граница, кредитный dispatch. Result/ack, ping/pong, progress — s1-04/05/07.
"""

from __future__ import annotations

import asyncio
import logging
from contextlib import suppress

from workers.common.logging_config import configure_logging
from workers.gateway.config import load_config
from workers.gateway.keydb import WORKER_TYPES, KeyDbGateway, build_client
from workers.gateway.reclaim import run_reclaim_loop
from workers.gateway.ws_server import WsGateway

logger = logging.getLogger(__name__)


async def main() -> None:
    configure_logging()
    cfg = load_config()

    client = build_client(cfg)
    keydb = KeyDbGateway(client)
    gateway = WsGateway(cfg, keydb)

    reclaim_task: asyncio.Task | None = None
    try:
        await client.ping()
        # Idle-reclaim (s1-06): запускаем после ping — KeyDB точно доступен.
        reclaim_task = asyncio.create_task(
            run_reclaim_loop(keydb, cfg, gateway.get_handoff_queues()),
            name="reclaim-loop",
        )
        logger.info(
            "ws-gateway starting",
            extra={
                "redisHost": cfg.redis_host,
                "redisDb": cfg.redis_db,
                "wsHost": cfg.ws_host,
                "wsPort": cfg.ws_port,
                "wsBlockMs": cfg.ws_block_ms,
                "reclaimIntervalS": cfg.reclaim_interval_s,
                "streams": [f"conv.{t}" for t in WORKER_TYPES],
            },
        )
        await gateway.serve_forever()
    finally:
        if reclaim_task is not None:
            reclaim_task.cancel()
            with suppress(asyncio.CancelledError):
                await reclaim_task
        await client.aclose()


if __name__ == "__main__":
    asyncio.run(main())
