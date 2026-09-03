from __future__ import annotations

import json
import os
import time
import urllib.request

from .collector import HostTelemetryCollector


def main() -> None:
    collector = HostTelemetryCollector(os.getenv("HOST_NAME"), __import__("pathlib").Path(os.getenv("ALLOWLIST_PATH", "/etc/convertor/allowlist.json")), __import__("pathlib").Path("/host"))
    while True:
        snapshot = collector.collect()
        if snapshot is not None:
            req = urllib.request.Request(os.environ["TELEMETRY_URL"], data=json.dumps(snapshot).encode(), headers={"Content-Type": "application/json", "Authorization": "Bearer " + os.environ["GATEWAY_INTERNAL_TOKEN"]}, method="POST")
            try:
                urllib.request.urlopen(req, timeout=10).close()
            except OSError:
                pass
        time.sleep(600)


if __name__ == "__main__":
    main()
