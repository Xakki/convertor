#!/usr/bin/env python3
"""Generate the collector mapping from cgroups actually used by workers."""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import tempfile
from pathlib import Path

SERVICE = re.compile(r"^[a-z0-9][a-z0-9-]{0,127}$")
CGROUP = re.compile(r"^[a-zA-Z0-9_.@+:-]+(?:/[a-zA-Z0-9_.@+:-]+)*$")


def _safe_relative(path: str) -> str:
    path = path.lstrip("/")
    if not path or ".." in Path(path).parts or not CGROUP.fullmatch(path):
        raise ValueError("invalid cgroup path")
    return path


def discover_cgroup(service: str, project: str, cgroup_root: Path) -> str:
    container = subprocess.check_output(
        ["docker", "compose", "-p", project, "ps", "-q", service], text=True
    ).strip()
    if not container:
        raise ValueError(f"worker service is not running: {service}")
    pid = subprocess.check_output(["docker", "inspect", "--format", "{{.State.Pid}}", container], text=True).strip()
    if not pid.isdigit() or int(pid) <= 0:
        raise ValueError(f"worker has no running pid: {service}")
    entries = Path(f"/proc/{pid}/cgroup").read_text(encoding="utf-8").splitlines()
    paths = [line.split(":", 2)[2] for line in entries if line.count(":") == 2 and line.split(":", 2)[1] == ""]
    if len(paths) != 1:
        raise ValueError(f"cannot determine cgroup v2 path: {service}")
    relative = _safe_relative(paths[0])
    if not (cgroup_root / relative).is_dir():
        raise ValueError(f"cgroup path is not visible under collector root: {service}")
    return relative


def build(services: list[str], cgroup_paths: dict[str, str] | None = None) -> dict[str, object]:
    unique = list(dict.fromkeys(services))
    if not unique or len(unique) > 32 or any(not SERVICE.fullmatch(item) for item in unique):
        raise ValueError("invalid worker service list")
    if cgroup_paths is None or set(cgroup_paths) != set(unique):
        raise ValueError("actual cgroup mappings are required")
    workers = {item: _safe_relative(cgroup_paths[item]) for item in unique}
    return {
        "version": 1,
        "provenance": {"source": "deployment", "format": "actual-cgroup-v2-service-v1"},
        "workers": workers,
    }


def build_initial() -> dict[str, object]:
    """Return the safe pre-worker mapping used before the first activation."""
    return {
        "version": 1,
        "provenance": {"source": "deployment", "format": "initial-empty-v1"},
        "workers": {},
    }


def validate(data: dict[str, object], cgroup_root: Path | None = None) -> None:
    if not isinstance(data, dict) or data.get("version") != 1:
        raise ValueError("invalid deployment allowlist")
    provenance = data.get("provenance")
    workers = data.get("workers")
    if not isinstance(provenance, dict) or provenance.get("source") != "deployment":
        raise ValueError("invalid deployment allowlist")
    if not isinstance(workers, dict) or len(workers) > 32:
        raise ValueError("allowlist must contain workers")
    if not workers and provenance.get("format") != "initial-empty-v1":
        raise ValueError("allowlist must contain workers")
    for name, path in workers.items():
        if not isinstance(name, str) or not SERVICE.fullmatch(name) or not isinstance(path, str):
            raise ValueError("invalid worker mapping")
        relative = _safe_relative(path)
        if cgroup_root is not None and not (cgroup_root / relative).is_dir():
            raise ValueError("allowlist points outside actual cgroup tree")


def write_atomic(output: Path, data: dict[str, object]) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix=f".{output.name}.", dir=output.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(data, stream, sort_keys=True, separators=(",", ":"))
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temp_name, output)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--worker", action="append", default=[])
    parser.add_argument("--initial", action="store_true")
    parser.add_argument("--service-cgroup", action="append", default=[], metavar="SERVICE=PATH")
    parser.add_argument("--docker-project", default=os.getenv("COMPOSE_PROJECT_NAME", "convertor"))
    parser.add_argument("--cgroup-root", type=Path, default=Path("/sys/fs/cgroup"))
    args = parser.parse_args()
    if args.initial and args.worker:
        raise SystemExit("--initial cannot be combined with --worker")
    mappings = {}
    for item in args.service_cgroup:
        service, sep, path = item.partition("=")
        if not sep or service in mappings:
            raise SystemExit("invalid --service-cgroup")
        mappings[service] = path
    for service in args.worker:
        if service not in mappings:
            mappings[service] = discover_cgroup(service, args.docker_project, args.cgroup_root)
    data = build_initial() if args.initial else build(args.worker, mappings)
    validate(data, args.cgroup_root)
    write_atomic(args.output, data)


if __name__ == "__main__":
    main()
