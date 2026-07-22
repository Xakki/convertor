"""Register-round-trip routing-contract drift test (registry-04).

What this checks: what Python workers actually DECLARE in their CAPABILITIES
(union across every `workers/*/worker.py`) vs what the LIVE PHP registry
reports via `bin/dump-matrix.php --json` — the same tool a worker's
`POST /api/v1/worker/register` call feeds into (`WorkerCapabilityRepository` →
`ConversionRegistry`). There is no PHP hardcode fallback in this comparison
any more: since registry-03 seeded the DB, `ConversionRegistry`'s hardcoded
`workerCapabilities()` path is unreachable in any migrated environment, and
`dump-matrix.php` forces a fresh DB read (`invalidateMatrix()`) rather than
trusting a possibly-stale cache — see its docblock for the full contract.

Two assertions:

(A) Every routing-key (`stream`, i.e. `streamFor()` output) the live registry
    can route a job to has ≥1 Python worker declaring it in `routing_keys`.
    Catches "stream without consumer" — a job would pile up forever.

(B) Every (from→to) pair a Python worker declares in CAPABILITIES.matrix is
    present in the live registry. A worker cannot be handed a pair the
    registry doesn't know about — it would never get routed there.
    Direction matters and is intentionally ONE-WAY: the registry can contain
    pairs no *currently loaded* worker code declares without that being
    drift. Per the epic's eviction design (registry-00: "long-TTL GC, not
    liveness gating"), a capability row survives long after the worker that
    registered it goes away or gets redeployed with a different matrix — the
    DB can legitimately lag a code change until that worker instance
    re-registers. Enforcing registry ⊆ workers here would make the test flap
    on ordinary operational staleness, not genuine drift; enforcing
    workers ⊆ registry (this assertion) is what actually gates "this worker's
    code declares something the router has never heard of."

    `category` vs `stream`: dump-matrix.php's per-pair `category` (raw stored
    FileCategory) can differ from `stream` (actual `streamFor()` routing
    target — e.g. `markup` folds into `document`). NEITHER assertion here
    reads `category` at all — (A) only uses `stream`/`routingKeys`, (B) only
    uses the bare (from, to) pair identity. So category/stream divergence is
    a non-issue for this file; do not "fix" it by normalising category into
    the comparison, there is nothing to normalise.

WHY THIS FILE MUST NEVER SKIP: `app-symfony/bin/dump-matrix.php` was
accidentally deleted 2026-07-10 by an unrelated commit (`2105d70`). The old
version of this test reacted to the missing tool with `pytest.skip()` —
so for ~12 days this drift guard reported a green "skipped" result while
checking literally nothing, and nobody noticed. `_load_registry()` below
therefore treats every failure mode (tool missing/non-executable, DB
unreachable, non-zero exit, unparsable output) as a hard `pytest.fail()`.
If you are tempted to add a `pytest.skip()` back to "unblock CI" — don't;
that is the exact regression this file exists to prevent. Fix the actual
tool/DB/environment problem instead.

Run standalone:      make test-drift
Wiring caveat (verified 2026-07-22): `make test-drift` is NOT a prerequisite
of `make test` or `make test-python` — `test-python`'s own `##` help text
says "excludes e2e + routing-drift; see test-e2e / test-drift", and `test`
= `test-php test-python` only. There is also no CI workflow config in this
repo (no `.github/workflows`, no `.gitlab-ci.yml`) that would invoke it
automatically. So today this guard ONLY runs when a human/agent calls
`make test-drift` by hand — reported to the team-lead as-is; NOT fixed here
(out of the Python zone / needs a Makefile-wiring decision).

If either assertion FAILS, it means there is a real routing gap. Do NOT
suppress the failure by loosening the assertions or patching routing. File a
grooming card and fix the underlying mismatch.
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
# Applied to BOTH sides before comparing. (The DB-backed registry now genuinely
# contains alias pairs — e.g. jpeg alongside jpg — that the old hardcode never
# advertised; that is an expected, non-drift difference this normalisation
# absorbs, not something to report.)
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
# PHP registry loader — register-round-trip source of truth
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


def _parse_registry_json(stdout: str, *, source: str) -> dict[str, Any]:
    try:
        return json.loads(stdout)
    except json.JSONDecodeError as exc:
        pytest.fail(
            f"{PHP_SCRIPT} --json ({source}) produced unparsable output: {exc}\n"
            f"First 500 chars of stdout:\n{stdout[:500]!r}"
        )


def _load_registry() -> dict[str, Any]:
    """Run `dump-matrix.php --json` and return the parsed live registry snapshot.

    NEVER skips (see module docstring). Tries native `php` first (portable to
    an environment where it happens to be installed, e.g. a future CI image),
    then falls back to `docker exec` into the running php container — this
    host has no native `php`, so in practice every real run today takes the
    docker path. Any failure along either path is a hard test failure with
    the tool's own STDERR surfaced, not a skip.
    """
    script_path = REPO_ROOT / "app-symfony" / PHP_SCRIPT
    if shutil.which("php"):
        res = subprocess.run(
            ["php", str(script_path), "--json"],
            capture_output=True, text=True,
            cwd=str(REPO_ROOT / "app-symfony"),
        )
        if res.returncode == 0:
            return _parse_registry_json(res.stdout, source="native php")
        pytest.fail(
            f"{PHP_SCRIPT} --json failed via native php (exit {res.returncode}) — "
            "this is a genuine drift-test failure (DB unreachable/empty or a tool "
            f"bug), not a missing-tool skip:\n{res.stderr.strip()[:2000]}"
        )

    docker = shutil.which("docker")
    if docker is None:
        pytest.fail(
            "Neither native `php` nor `docker` is available to run "
            f"{PHP_SCRIPT} --json — cannot execute the register-round-trip drift "
            "test in this environment. Install one of them; do NOT skip this "
            "test to work around it (see module docstring — that is exactly the "
            "regression that hid a real drift for ~12 days)."
        )
    container = _container_name()
    try:
        res = subprocess.run(
            [docker, "exec", container, "php", PHP_SCRIPT, "--json"],
            capture_output=True, text=True,
        )
    except OSError as exc:
        pytest.fail(f"Failed to `docker exec` into container {container!r}: {exc}")
    if res.returncode != 0:
        pytest.fail(
            f"{PHP_SCRIPT} --json failed inside container {container!r} "
            f"(exit {res.returncode}) — this is a genuine drift-test failure "
            f"(DB unreachable/empty, missing tool, or a tool bug), not a "
            f"missing-tool skip:\n{res.stderr.strip()[:2000]}"
        )
    return _parse_registry_json(res.stdout, source=f"docker exec {container}")


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
# Pure comparison helpers — separated from the pytest test functions so they
# can be exercised directly (real or crafted inputs) without shelling out to
# docker/php or spawning worker subprocesses. Used to empirically verify this
# file can actually fail (see registry-04 Execution Log for the drill).
# ---------------------------------------------------------------------------

def _uncovered_routing_keys(
    registry: dict[str, Any], workers: list[tuple[str, dict[str, Any]]]
) -> set[str]:
    worker_keys: set[str] = set()
    for _name, caps in workers:
        worker_keys.update(caps.get("routing_keys", []))
    return set(registry["routingKeys"]) - worker_keys


def _worker_pairs_missing_from_registry(
    registry: dict[str, Any], workers: list[tuple[str, dict[str, Any]]]
) -> list[str]:
    registry_pairs: set[tuple[str, str]] = {
        (_canon(e["from"]), _canon(e["to"])) for e in registry["matrix"]
    }
    failures: list[str] = []
    for worker_name, caps in workers:
        matrix: dict[str, list[str]] = caps.get("matrix", {})
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
                    failures.append(f"  workers/{worker_name}: {src}→{tgt}{alias_note}")
    return failures


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
# Assertion (A): every live registry routing-key is consumed by ≥1 worker
# ---------------------------------------------------------------------------

def test_all_routing_keys_have_worker(
    registry: dict[str, Any],
    workers: list[tuple[str, dict[str, Any]]],
) -> None:
    """
    Every stream the live registry can route a job to must have at least one
    Python worker that declares it in routing_keys. A missing worker means
    jobs pile up in that stream forever.
    """
    uncovered = _uncovered_routing_keys(registry, workers)
    assert not uncovered, (
        "Routing keys emitted by the live PHP registry with NO Python worker:\n"
        + "\n".join(f"  - {k}" for k in sorted(uncovered))
        + "\n\nFor each key above: either add the missing worker, or the "
        + "registered capability that advertises this stream is stale/wrong."
    )


# ---------------------------------------------------------------------------
# Assertion (B): worker matrix ⊆ live registry (register-round-trip)
# ---------------------------------------------------------------------------

def test_worker_matrix_subset_of_registry(
    registry: dict[str, Any],
    workers: list[tuple[str, dict[str, Any]]],
) -> None:
    """
    Every (from→to) pair a worker's CAPABILITIES.matrix declares must round-trip
    through register() into the live PHP registry. A worker cannot convert a
    pair the registry doesn't know about — it would never be routed there.

    One-directional on purpose — see module docstring ("category vs stream").
    Format aliases (yml/yaml, jpeg/jpg, tif/tiff, htm/html) are normalised on
    both sides before comparison. Genuine format differences are NOT
    normalised and will surface as failures if missing from the registry.
    """
    failures = _worker_pairs_missing_from_registry(registry, workers)
    assert not failures, (
        f"Worker pairs absent from the live PHP registry — {len(failures)} violation(s):\n"
        + "\n".join(sorted(failures))
        + "\n\nFor each pair above: either the worker never (re-)registered this "
        + "pair, or the pair was dropped/renamed on the registry side — "
        + "investigate before assuming it's stale."
    )
