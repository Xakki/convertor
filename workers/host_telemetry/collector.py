from __future__ import annotations

import json
import os
import re
import time
from pathlib import Path
from typing import Any

HOST_TELEMETRY_VERSION = 1
_MIN_INTERVAL_SECONDS = 600
_MAX_WORKERS = 32
_HOST_NAME = re.compile(r"^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$")
_SERVICE = re.compile(r"^[a-z0-9][a-z0-9-]{0,127}$")
_CGROUP = re.compile(r"^[a-zA-Z0-9_.@+:-]+(?:/[a-zA-Z0-9_.@+:-]+)*$")
# Keep this boundary local to the collector: it must fail closed when a
# deployment file is replaced independently of the compose manifest.
_SUPPORTED_SERVICES = frozenset({
    "worker-libreoffice", "worker-ffmpeg-audio", "worker-ffmpeg-video",
    "worker-image", "worker-data", "worker-ai",
})
_ALLOWLIST_FORMATS = frozenset({"actual-cgroup-v2-service-v1", "initial-empty-v1"})


def validate_host_name(value: str | None) -> str | None:
    """Return the exact deployment name, or None for an unknown host."""
    if not isinstance(value, str) or not _HOST_NAME.fullmatch(value):
        return None
    return value


class HostTelemetryCollector:
    """Collect only public host counters and allowlisted worker counters."""

    def __init__(self, host_name: str | None, allowlist_path: Path, root: Path = Path("/"), clock=time.time, disk_probe: str = "/root-probe"):
        self.host_name = validate_host_name(host_name)
        self.allowlist_path = allowlist_path
        self.root = root
        self.clock = clock
        self.disk_probe = disk_probe
        self._last_collected: float | None = None
        self._previous_worker_cpu: dict[str, int] | None = None
        self._previous_host_cpu: tuple[int, int] | None = None
        self._previous_sample_at: float | None = None

    def collect(self) -> dict[str, Any] | None:
        now = float(self.clock())
        if self.host_name is None or (self._last_collected is not None and now - self._last_collected < _MIN_INTERVAL_SECONDS):
            return None
        allowlist = self._load_allowlist()
        self._last_collected = now
        mem = self._read_meminfo()
        load = self._read_load()
        disk = self._read_disk()
        cpu_count = self._read_cpu_count()
        workers = self._read_workers(allowlist)
        worker_cpu = {
            name: value["cpuUsageUsec"] for name, value in workers.items()
            if value["cpuUsageUsec"] is not None
        }
        window_usec = None
        worker_delta = None
        host_delta = None
        host_window_usec = None
        host_utilization = None
        previous_sample_at = self._previous_sample_at
        if previous_sample_at is not None and now > previous_sample_at:
            window_usec = int((now - previous_sample_at) * 1_000_000)
            if self._previous_worker_cpu is not None and len(worker_cpu) == len(workers):
                deltas = [worker_cpu[name] - self._previous_worker_cpu.get(name, -1) for name in workers]
                if all(delta >= 0 for delta in deltas):
                    worker_delta = min(sum(deltas), window_usec * max(cpu_count or 1, 1))
            current_host_cpu = self._read_host_cpu()
            if self._previous_host_cpu is not None and current_host_cpu is not None:
                busy_delta = current_host_cpu[0] - self._previous_host_cpu[0]
                total_delta = current_host_cpu[1] - self._previous_host_cpu[1]
                if busy_delta >= 0 and total_delta > 0:
                    host_delta = busy_delta
                    host_window_usec = total_delta
                    host_utilization = busy_delta / total_delta
        self._previous_worker_cpu = worker_cpu if len(worker_cpu) == len(workers) else None
        self._previous_host_cpu = self._read_host_cpu()
        self._previous_sample_at = now
        return {
            "contractVersion": HOST_TELEMETRY_VERSION,
            "host": self.host_name,
            "observedAt": now,
            "freshUntil": now + 1200,
            "source": "host-collector",
            "scope": "host",
            "cpuCount": cpu_count,
            "memTotalBytes": mem[0],
            "memAvailableBytes": mem[1],
            "diskTotalBytes": disk[0],
            "diskUsedBytes": disk[1],
            "load1": load,
            "sampleWindowStart": previous_sample_at if previous_sample_at is not None else None,
            "sampleWindowEnd": now,
            "sampleWindowUsec": window_usec,
            "workerCpuUsageUsec": worker_delta,
            "workerCpuWindowUsec": window_usec if worker_delta is not None else None,
            "hostCpuUsageUsec": host_delta,
            "hostCpuWindowUsec": host_window_usec,
            "hostCpuUtilization": host_utilization,
            "workers": workers,
        }

    def _load_allowlist(self) -> dict[str, Any]:
        def reject_duplicate(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
            result: dict[str, Any] = {}
            for key, value in pairs:
                if key in result:
                    raise ValueError("duplicate allowlist key")
                result[key] = value
            return result

        try:
            raw = json.loads(
                self.allowlist_path.read_text(encoding="utf-8"),
                object_pairs_hook=reject_duplicate,
            )
        except (OSError, UnicodeError, json.JSONDecodeError, ValueError) as exc:
            raise ValueError("invalid allowlist") from exc

        if not isinstance(raw, dict) or set(raw) != {"version", "provenance", "workers"} or raw["version"] != 1:
            raise ValueError("invalid allowlist")
        provenance = raw["provenance"]
        if (
            not isinstance(provenance, dict)
            or set(provenance) != {"source", "format"}
            or provenance["source"] != "deployment"
            or provenance["format"] not in _ALLOWLIST_FORMATS
        ):
            raise ValueError("invalid allowlist provenance")
        workers = raw["workers"]
        if not isinstance(workers, dict) or len(workers) > _MAX_WORKERS:
            raise ValueError("invalid allowlist worker count")
        if not workers and provenance["format"] != "initial-empty-v1":
            raise ValueError("invalid empty allowlist")
        if workers and provenance["format"] != "actual-cgroup-v2-service-v1":
            raise ValueError("invalid allowlist provenance")

        validated: dict[str, str] = {}
        for name, rel in workers.items():
            if not isinstance(name, str) or not _SERVICE.fullmatch(name) or name not in _SUPPORTED_SERVICES:
                raise ValueError("invalid worker service")
            if (
                not isinstance(rel, str)
                or not _CGROUP.fullmatch(rel)
                or rel.startswith("/")
                or any(part in {".", ".."} for part in Path(rel).parts)
            ):
                raise ValueError("invalid relative worker cgroup path")
            validated[name] = rel
        return validated

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

    def _read_host_cpu(self) -> tuple[int, int] | None:
        try:
            fields = self._read("/proc/stat").splitlines()[0].split()[1:]
            values = [int(value) for value in fields]
            if len(values) < 4:
                return None
            idle = values[3] + (values[4] if len(values) > 4 else 0)
            total = sum(values)
            return total - idle, total
        except (OSError, ValueError, IndexError):
            return None

    def _read_disk(self) -> tuple[int | None, int | None]:
        try:
            probe = Path(self.disk_probe)
            if not probe.is_absolute() or any(part in {"..", "."} for part in probe.parts):
                return None, None
            stat = os.statvfs(self.root / probe.relative_to("/"))
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
