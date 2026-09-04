from __future__ import annotations

import json
import os
import time
import asyncio
import uuid
from pathlib import Path

import websockets

from .collector import HostTelemetryCollector


async def deliver_snapshot(snapshot: dict, gateway_url: str, token: str, host_name: str) -> bool:
    """Send one snapshot over the existing authenticated gateway WS channel."""
    try:
        async with websockets.connect(
            gateway_url,
            additional_headers={"Authorization": f"Bearer {token}"},
            max_size=65536,
        ) as socket:
            await socket.send(json.dumps({
                "type": "ready",
                "workerId": f"host-telemetry-{host_name}-{uuid.uuid4().hex}",
                "workerType": "telemetry",
                "slots": 1,
            }))
            ready_ack = json.loads(await socket.recv())
            if not isinstance(ready_ack, dict) or ready_ack.get("type") != "ready-ack":
                return False
            await socket.send(json.dumps({"type": "host-telemetry", "snapshot": snapshot}))
            response = json.loads(await socket.recv())
            return isinstance(response, dict) and response.get("type") == "host-telemetry-ack"
    except (OSError, ValueError, TypeError, websockets.WebSocketException):
        return False


def main() -> None:
    host_name = os.getenv("HOST_NAME")
    collector = HostTelemetryCollector(host_name, Path(os.getenv("ALLOWLIST_PATH", "/etc/convertor/allowlist.json")), Path("/host"))
    gateway_url = os.environ["GATEWAY_WS_URL"]
    token = os.environ["WORKER_API_TOKEN"]
    while True:
        snapshot = collector.collect()
        if snapshot is not None and host_name:
            asyncio.run(deliver_snapshot(snapshot, gateway_url, token, host_name))
        time.sleep(600)


if __name__ == "__main__":
    main()
