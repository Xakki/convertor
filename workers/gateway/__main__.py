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
from workers.gateway.dlq_consumer import run_dlq_consumer_loop
from workers.gateway.keydb import WORKER_TYPES, KeyDbGateway, build_client
from workers.gateway.liveness import run_liveness_push_loop
from workers.gateway.reclaim import run_reclaim_loop
from workers.gateway.relay import RelayClient
from workers.gateway.ws_server import WsGateway

logger = logging.getLogger(__name__)


async def main() -> None:
    configure_logging()
    cfg = load_config()

    client = build_client(cfg)
    keydb = KeyDbGateway(client)
    gateway = WsGateway(cfg, keydb)
    # Отдельные relay-клиенты для DLQ-consumer'а и liveness-push (не тот, что
    # лениво создаёт WsGateway для result/fail-relay) — независимые жизненные
    # циклы/aclose, без общего connection-pool с ack-путём result/fail.
    dlq_relay = RelayClient(cfg.symfony_internal_url, cfg.gateway_internal_token)
    liveness_relay = RelayClient(cfg.symfony_internal_url, cfg.gateway_internal_token)

    reclaim_task: asyncio.Task | None = None
    dlq_task: asyncio.Task | None = None
    liveness_task: asyncio.Task | None = None
    try:
        await client.ping()
        # Idle-reclaim (s1-06): запускаем после ping — KeyDB точно доступен.
        reclaim_task = asyncio.create_task(
            run_reclaim_loop(keydb, cfg, gateway.get_handoff_queues()),
            name="reclaim-loop",
        )
        # DLQ-consumer (conv-dead-no-consumer): финализирует Conversion.failed
        # по записям conv.dead — см. workers/gateway/dlq_consumer.py.
        dlq_task = asyncio.create_task(
            run_dlq_consumer_loop(keydb, dlq_relay, cfg),
            name="dlq-consumer",
        )
        # Liveness-push (registry-06): батчи agregированных ping'ов → PHP
        # internal endpoint, см. workers/gateway/liveness.py. Best-effort/
        # non-fatal — сбой push НИКОГДА не роняет gateway (телеметрия).
        liveness_task = asyncio.create_task(
            run_liveness_push_loop(gateway.get_liveness_aggregator(), liveness_relay, cfg),
            name="liveness-push",
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
                "dlqConsumerBlockMs": cfg.dlq_consumer_block_ms,
                "livenessPushIntervalS": cfg.liveness_push_interval_s,
                "streams": [f"conv.{t}" for t in WORKER_TYPES],
            },
        )
        await gateway.serve_forever()
    finally:
        for task in (reclaim_task, dlq_task, liveness_task):
            if task is not None:
                task.cancel()
        for task in (reclaim_task, dlq_task, liveness_task):
            if task is not None:
                with suppress(asyncio.CancelledError):
                    await task
        await dlq_relay.aclose()
        await liveness_relay.aclose()
        await client.aclose()


if __name__ == "__main__":
    asyncio.run(main())
