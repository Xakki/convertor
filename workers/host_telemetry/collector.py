from __future__ import annotations

import json
import os
import re
import time
from pathlib import Path
from typing import Any

HOST_TELEMETRY_VERSION = 1
_MIN_INTERVAL_SECONDS = 600
_HOST_NAME = re.compile(r"^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$")


def validate_host_name(value: str | None) -> str | None:
    """Return the exact deployment name, or None for an unknown host."""
    if not isinstance(value, str) or not _HOST_NAME.fullmatch(value):
        return None
    return value


class HostTelemetryCollector:
    """Collect only public host counters and allowlisted worker counters."""

    def __init__(self, host_name: str | None, allowlist_path: Path, root: Path = Path("/"), clock=time.time):
        self.host_name = validate_host_name(host_name)
        self.allowlist_path = allowlist_path
        self.root = root
        self.clock = clock
        self._last_collected: float | None = None

    def collect(self) -> dict[str, Any] | None:
        now = float(self.clock())
        if self.host_name is None or (self._last_collected is not None and now - self._last_collected < _MIN_INTERVAL_SECONDS):
            return None
        allowlist = self._load_allowlist()
        self._last_collected = now
        mem = self._read_meminfo()
        load = self._read_load()
        disk = self._read_disk()
        return {
            "contractVersion": HOST_TELEMETRY_VERSION,
            "host": self.host_name,
            "observedAt": now,
            "freshUntil": now + 1200,
            "source": "host-collector",
            "scope": "host",
            "cpuCount": self._read_cpu_count(),
            "memTotalBytes": mem[0],
            "memAvailableBytes": mem[1],
            "diskTotalBytes": disk[0],
            "diskUsedBytes": disk[1],
            "load1": load,
            "workers": self._read_workers(allowlist),
        }

    def _load_allowlist(self) -> dict[str, Any]:
        raw = json.loads(self.allowlist_path.read_text(encoding="utf-8"))
        if raw.get("version") != 1 or raw.get("provenance", {}).get("source") != "deployment" or not isinstance(raw.get("workers"), dict) or not raw["workers"]:
            raise ValueError("invalid allowlist")
        for name, rel in raw["workers"].items():
            if not isinstance(name, str) or not isinstance(rel, str) or not rel or rel.startswith("/") or any(part in {"..", "."} for part in Path(rel).parts):
                raise ValueError("invalid relative worker cgroup path")
        return raw["workers"]

    def _read(self, path: str) -> str:
        return (self.root / path.lstrip("/")).read_text(encoding="utf-8")

    def _read_meminfo(self) -> tuple[int | None, int | None]:
        vals: dict[str, int] = {}
        try:
            for line in self._read("/proc/meminfo").splitlines():
                key, _, rest = line.partition(":")
                if key in {"MemTotal", "MemAvailable"}:
                    vals[key] = int(rest.split()[0]) * 1024
        except (OSError, ValueError, IndexError):
            pass
        return vals.get("MemTotal"), vals.get("MemAvailable")

    def _read_load(self) -> float | None:
        try:
            return float(self._read("/proc/loadavg").split()[0])
        except (OSError, ValueError, IndexError):
            return None

    def _read_cpu_count(self) -> int | None:
        try:
            possible = self._read("/sys/devices/system/cpu/possible").strip()
            count = 0
            for part in possible.split(","):
                bounds = [int(value) for value in part.split("-")]
                count += bounds[-1] - bounds[0] + 1
            return count if count > 0 else None
        except (OSError, ValueError, IndexError):
            pass
        try:
            count = sum(1 for line in self._read("/proc/stat").splitlines() if re.match(r"^cpu[0-9]+ ", line))
            return count or None
        except OSError:
            return None

    def _read_disk(self) -> tuple[int | None, int | None]:
        try:
            stat = os.statvfs(self.root / "root")
            total = stat.f_blocks * stat.f_frsize
            free = stat.f_bavail * stat.f_frsize
            return total, total - free
        except OSError:
            return None, None

    def _read_workers(self, allowlist: dict[str, Any]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        root = (self.root / "sys/fs/cgroup").resolve()
        for worker, rel in allowlist.items():
            path = (root / rel).resolve()
            if root not in path.parents or not path.is_dir():
                result[worker] = {"cpuUsageUsec": None, "memoryBytes": None}
                continue
            try:
                cpu = None
                for line in (path / "cpu.stat").read_text().splitlines():
                    if line.startswith("usage_usec "):
                        cpu = int(line.split()[1])
                        break
                memory = int((path / "memory.current").read_text().strip())
                result[worker] = {"cpuUsageUsec": cpu, "memoryBytes": memory}
            except (OSError, ValueError, IndexError):
                result[worker] = {"cpuUsageUsec": None, "memoryBytes": None}
        return result
