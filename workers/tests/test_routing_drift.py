"""Routing-contract drift test.

Two assertions enforcing the PHP ConversionRegistry ↔ Python worker contract:

(A) Every routing-key emitted by the PHP registry has ≥1 Python worker
    declaring it.  Catches "stream without consumer" bugs.

(B) Every (from→to) pair in each Python worker's CAPABILITIES.matrix exists
    in the PHP registry matrix (worker matrix ⊆ registry).
    Direction matters: the PHP registry may contain Stage-7 deferred pairs
    that no worker handles yet — that is intentional and is NOT checked here.

Run standalone:  make test-drift
Run as part of CI gate:  make test-python  (no extra flag needed)

If either assertion FAILS, it means there is a real routing gap.
Do NOT suppress the failure by loosening the assertions or patching routing.
File a grooming card and fix the underlying mismatch.
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Any

import pytest

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------

REPO_ROOT = Path(__file__).resolve().parents[2]
WORKERS_DIR = REPO_ROOT / "workers"
PHP_SCRIPT = "bin/dump-matrix.php"  # path inside the php container (workdir=/app-symfony)

# ---------------------------------------------------------------------------
# Format alias normalisation
# Aliases: formats that are functionally identical and treated as the same key.
# Applied to BOTH sides before comparing.
# ---------------------------------------------------------------------------

_ALIASES: dict[str, str] = {
    "yml":  "yaml",
    "jpeg": "jpg",
    "tif":  "tiff",
    "htm":  "html",
}


def _canon(fmt: str) -> str:
    return _ALIASES.get(fmt.lower(), fmt.lower())


# ---------------------------------------------------------------------------
# PHP registry loader
# ---------------------------------------------------------------------------

def _container_name() -> str:
    env_file = REPO_ROOT / ".env"
    project = "xakki-convertor"
    try:
        for line in env_file.read_text().splitlines():
            if line.startswith("COMPOSE_PROJECT_NAME="):
                project = line.split("=", 1)[1].strip()
                break
    except OSError:
        pass
    return f"{project}-php"


def _load_registry() -> dict[str, Any]:
    """Run dump-matrix.php --json; try native php, fall back to docker exec."""
    if shutil.which("php"):
        script_path = str(REPO_ROOT / "app-symfony" / PHP_SCRIPT)
        res = subprocess.run(
            ["php", script_path, "--json"],
            capture_output=True, text=True,
            cwd=str(REPO_ROOT / "app-symfony"),
        )
        if res.returncode == 0:
            return json.loads(res.stdout)

    docker = shutil.which("docker") or "/usr/bin/docker"
    res = subprocess.run(
        [docker, "exec", _container_name(), "php", PHP_SCRIPT, "--json"],
        capture_output=True, text=True,
    )
    if res.returncode != 0:
        pytest.skip(
            f"PHP/docker required for drift test (docker exec exit {res.returncode}): {res.stderr.strip()[:200]}"
        )
    return json.loads(res.stdout)


# ---------------------------------------------------------------------------
# Python worker loader
# Runs each worker.py in a subprocess to isolate heavy imports (redis, boto3…).
# ---------------------------------------------------------------------------

_EXTRACT_CAPS = """\
import sys, json, importlib.util
spec = importlib.util.spec_from_file_location('worker', sys.argv[1])
mod  = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
# CAPABILITIES may be a module-level constant OR a class attribute (most workers).
caps = getattr(mod, 'CAPABILITIES', None)
if caps is None:
    for _name in dir(mod):
        _obj = getattr(mod, _name, None)
        if isinstance(_obj, type) and hasattr(_obj, 'CAPABILITIES'):
            caps = _obj.CAPABILITIES
            break
if caps is None:
    print('null')
    sys.exit(0)
def ser(v):
    return sorted(v) if isinstance(v, (set, frozenset, list)) else sorted(str(x) for x in v)
print(json.dumps({
    'routing_keys': caps.get('routing_keys', []),
    'matrix':       {k: ser(v) for k, v in caps.get('matrix', {}).items()},
}))
"""


def _load_workers() -> list[tuple[str, dict[str, Any]]]:
    """Return [(worker_name, capabilities), …] for every workers/*/worker.py."""
    results: list[tuple[str, dict[str, Any]]] = []
    env = {**os.environ, "PYTHONPATH": str(REPO_ROOT)}
    for worker_dir in sorted(WORKERS_DIR.iterdir()):
        if not worker_dir.is_dir():
            continue
        worker_file = worker_dir / "worker.py"
        if not worker_file.exists():
            continue
        proc = subprocess.run(
            [sys.executable, "-c", _EXTRACT_CAPS, str(worker_file)],
            capture_output=True, text=True, env=env,
        )
        if proc.returncode != 0 or not proc.stdout.strip():
            pytest.fail(
                f"Could not load CAPABILITIES from workers/{worker_dir.name}/worker.py:\n"
                f"{proc.stderr[:400]}"
            )
        parsed = json.loads(proc.stdout.strip())
        if parsed is None:
            continue
        results.append((worker_dir.name, parsed))
    return results


# ---------------------------------------------------------------------------
# Session-scoped fixtures — one PHP call and one worker-scan per pytest run
# ---------------------------------------------------------------------------

@pytest.fixture(scope="session")
def registry() -> dict[str, Any]:
    return _load_registry()


@pytest.fixture(scope="session")
def workers() -> list[tuple[str, dict[str, Any]]]:
    return _load_workers()


# ---------------------------------------------------------------------------
# Assertion (A): every PHP routing-key is consumed by ≥1 worker
# ---------------------------------------------------------------------------

def test_all_routing_keys_have_worker(
    registry: dict[str, Any],
    workers: list[tuple[str, dict[str, Any]]],
) -> None:
    """
    Every stream the PHP registry can route to must have at least one Python
    worker that declares it in routing_keys.  A missing worker means jobs
    pile up in the stream forever.
    """
    worker_keys: set[str] = set()
    for _name, caps in workers:
        worker_keys.update(caps.get("routing_keys", []))

    registry_keys = set(registry["routingKeys"])
    uncovered = registry_keys - worker_keys

    assert not uncovered, (
        "Routing keys emitted by PHP ConversionRegistry with NO Python worker:\n"
        + "\n".join(f"  - {k}" for k in sorted(uncovered))
        + "\n\nFor each key above: either add the missing worker or remove the "
        + "routing key from workerCapabilities() in ConversionRegistry.php."
    )


# ---------------------------------------------------------------------------
# Assertion (B): worker matrix ⊆ PHP registry
# ---------------------------------------------------------------------------

def test_worker_matrix_subset_of_registry(
    registry: dict[str, Any],
    workers: list[tuple[str, dict[str, Any]]],
) -> None:
    """
    Every (from→to) pair advertised in a worker's CAPABILITIES.matrix must exist
    in the PHP registry.  A worker cannot convert a pair the registry doesn't
    know about — the pair would never be routed to that worker.

    Format aliases (yml/yaml, jpeg/jpg, tif/tiff, htm/html) are normalised on
    both sides before comparison.  Genuine format differences (toml, wma, 3gp…)
    are NOT normalised and will surface as failures if missing from PHP.
    """
    # Build normalised set from registry (skip AI virtual sources like mp3_stt / txt_tts)
    registry_pairs: set[tuple[str, str]] = {
        (_canon(e["from"]), _canon(e["to"]))
        for e in registry["matrix"]
        if not (e["from"].endswith("_stt") or e["from"].endswith("_tts"))
    }

    failures: list[str] = []
    for worker_name, caps in workers:
        matrix: dict[str, list[str]] = caps.get("matrix", {})
        if not matrix:
            continue  # AI worker has empty matrix — vacuously satisfied

        for src, targets in matrix.items():
            canon_src = _canon(src)
            for tgt in targets:
                canon_tgt = _canon(tgt)
                if canon_src == canon_tgt:
                    continue  # skip self-pairs
                if (canon_src, canon_tgt) not in registry_pairs:
                    alias_note = (
                        f" (normalised {canon_src}→{canon_tgt})"
                        if (src != canon_src or tgt != canon_tgt)
                        else ""
                    )
                    failures.append(
                        f"  workers/{worker_name}: {src}→{tgt}{alias_note}"
                    )

    assert not failures, (
        f"Worker pairs absent from PHP registry — {len(failures)} violation(s):\n"
        + "\n".join(sorted(failures))
        + "\n\nFor each pair above: either add it to ConversionRegistry.php or "
        + "remove it from the worker's CAPABILITIES.matrix."
    )
