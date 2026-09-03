#!/usr/bin/env python3
"""Generate and atomically activate the collector's logical worker mapping."""
from __future__ import annotations

import argparse
import json
import os
import re
import tempfile
from pathlib import Path

NAME = re.compile(r"^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$")
SERVICE = re.compile(r"^[a-z0-9][a-z0-9-]{0,127}$")


def build(services: list[str]) -> dict[str, object]:
    unique = list(dict.fromkeys(services))
    if not unique:
        raise ValueError("at least one worker service is required")
    if len(unique) > 32 or any(not SERVICE.fullmatch(item) for item in unique):
        raise ValueError("invalid worker service")
    return {
        "version": 1,
        "provenance": {"source": "deployment", "format": "logical-service-cgroup-v1"},
        "workers": {item: f"convertor-workers/{item}" for item in unique},
    }


def validate(data: dict[str, object]) -> None:
    if data.get("version") != 1:
        raise ValueError("unsupported allowlist version")
    provenance = data.get("provenance")
    workers = data.get("workers")
    if not isinstance(provenance, dict) or provenance.get("source") != "deployment":
        raise ValueError("missing deployment provenance")
    if not isinstance(workers, dict) or not workers:
        raise ValueError("allowlist must contain workers")
    for name, path in workers.items():
        if not isinstance(name, str) or not SERVICE.fullmatch(name):
            raise ValueError("invalid worker name")
        if not isinstance(path, str) or path.startswith("/") or ".." in Path(path).parts:
            raise ValueError("unsafe cgroup path")
        if path != f"convertor-workers/{name}":
            raise ValueError("allowlist path is not the logical worker path")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--worker", action="append", default=[])
    args = parser.parse_args()
    data = build(args.worker)
    validate(data)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix=f".{args.output.name}.", dir=args.output.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(data, stream, sort_keys=True, separators=(",", ":"))
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temp_name, args.output)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)


if __name__ == "__main__":
    main()
