"""Routing-contract drift test (registry-04; CNV-71-02 review update).

What this checks: what Python workers actually DECLARE in their CAPABILITIES
(union across every `workers/*/worker.py`) vs what `bin/dump-matrix.php --json`
reports. CNV-71-02 rewrote `dump-matrix.php` to read the committed static
catalog (`config/catalog/conversion_pairs.json` via `ConversionRegistry`), NOT
the DB — it no longer touches `WorkerCapabilityRepository`, no live registry
read, and no `register()` round-trip. "LIVE PHP registry"/"register
round-trip" in earlier versions of this docstring described the pre-CNV-71-02
DB-backed tool; that is no longer what runs here. What IS still checked is
identical in shape: the same `dump-matrix.php --json` output, now sourced from
the static catalog.

Two assertions:

(A) Every routing-key (`stream`, i.e. `streamFor()` output) the catalog can
    route a job to has ≥1 Python worker declaring it in `routing_keys`.
    Catches "stream without consumer" — a job would pile up forever. This
    assertion is INDEPENDENT of `test_catalog_drift.py` below — nothing else
    in the suite checks routing-key coverage — and remains meaningful.

(B) Every (from→to) pair a Python worker declares in CAPABILITIES.matrix is
    present in the catalog. A worker cannot be handed a pair the catalog
    doesn't know about — it would never get routed there.
    Direction matters and is intentionally ONE-WAY: the catalog can contain
    pairs no *currently loaded* worker code declares without that being
    drift (e.g. a worker temporarily rolled back to an older matrix).
    Enforcing catalog ⊆ workers here would flap on ordinary operational
    differences, not genuine drift; enforcing workers ⊆ catalog (this
    assertion) is what actually gates "this worker's code declares something
    the router has never heard of."

    NOTE — largely subsumed by `test_catalog_drift.py`: since CNV-71-02 the
    catalog is a pure, deterministic reduction of the SAME committed
    `worker_capabilities.json` that `test_catalog_drift.py` diffs byte-for-byte
    against a fresh AST extraction of `workers/*/worker.py` (with a much more
    precise diff — full line-level unified diff vs. this assertion's
    pair-list). If `worker_capabilities.json` matches the workers' source,
    the catalog matches it too, and assertion (B) here can only fail in the
    same situations `test_catalog_drift.py` already catches — plus format
    aliasing (yml/yaml, jpeg/jpg, tif/tiff, htm/html), which is the one thing
    `test_catalog_drift.py` does not normalise. Do not treat (B) as a
    stronger or independent guarantee than it actually gives; assertion (A)
    has no such overlap and stays fully independent.

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
therefore treats every failure mode (tool missing/non-executable, catalog
missing/empty, non-zero exit, unparsable output) as a hard `pytest.fail()`.
If you are tempted to add a `pytest.skip()` back to "unblock CI" — don't;
that is the exact regression this file exists to prevent. Fix the actual
tool/catalog/environment problem instead.

Run standalone: `make test-drift` (from repo root) — and it is wired into the
`make test` chain (root `Makefile`: `test: test-up → test-php test-python
test-drift`). BUT it does NOT actually execute in a normal `make test` run
today: `test-php` currently fails first on the known
`ConversionTextInputControllerTest` BillingMode-mock failures (CNV-60), and
Make stops the chain there before `test-python`/`test-drift` ever run. Run
`make TEST=1 test-drift` directly until CNV-60 is fixed.

If either assertion FAILS, it means there is a real routing gap. Do NOT
suppress the failure by loosening the assertions or patching routing. File a
grooming card and fix the underlying mismatch.
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
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
# PHP catalog-snapshot loader — dump-matrix.php --json wrapper
# ---------------------------------------------------------------------------

def _container_name() -> str:
    # Makefile exports COMPOSE_PROJECT_NAME for the stand it runs against (the
    # test stand under `make test`), so the env wins over the tracked .env file —
    # keeps the docker-exec fallback pointed at the same php container/checkout
    # as the rest of the test-stand run, not a stray dev container.
    project = os.environ.get("COMPOSE_PROJECT_NAME", "").strip()
    if project:
        return f"{project}-php"
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
        data = json.loads(stdout)
    except json.JSONDecodeError as exc:
        pytest.fail(
            f"{PHP_SCRIPT} --json ({source}) produced unparsable output: {exc}\n"
            f"First 500 chars of stdout:\n{stdout[:500]!r}"
        )
    # Belt-and-suspenders backstop: the tool's own docblock says it exit(1)s BEFORE
    # ever printing an empty matrix/routingKeys (empty DB → refuse to print "nothing"
    # as if it were a valid snapshot). If that guard itself ever regresses upstream
    # and this somehow still exits 0, don't let an empty-but-"successful" response
    # sail through and make both drift assertions pass vacuously — fail here too.
    if not data.get("matrix") or not data.get("routingKeys"):
        pytest.fail(
            f"{PHP_SCRIPT} --json ({source}) returned exit 0 with an EMPTY "
            "matrix/routingKeys — refusing to compare against nothing. The tool's own "
            "docblock says it should exit(1) before this point; if you see this, that "
            "guard has itself regressed (same shape as the ~12-day skip regression "
            "this test file exists to prevent)."
        )
    return data


def _load_registry() -> dict[str, Any]:
    """Run `dump-matrix.php --json` and return the parsed catalog snapshot.

    NEVER skips (see module docstring). Tries native `php` first (portable
    when a compatible host PHP is installed), then falls back to `docker exec`
    into the running php container. Native failure alone is NOT fatal — host
    `php` may exist but be too old for Composer platform_check (GHA
    ubuntu-latest ships 8.3 while this project needs ≥8.4); only fail hard
    when BOTH paths fail. Set `DRIFT_FORCE_DOCKER=1` to skip the native attempt
    (CI prefers the test-stand container).
    """
    script_path = REPO_ROOT / "app-symfony" / PHP_SCRIPT
    force_docker = os.environ.get("DRIFT_FORCE_DOCKER", "").strip().lower() in (
        "1", "true", "yes",
    )
    native_diag: str | None = None

    if not force_docker and shutil.which("php"):
        env = {**os.environ, "APP_ENV": "test"}
        res = subprocess.run(
            ["php", str(script_path), "--json"],
            capture_output=True, text=True,
            cwd=str(REPO_ROOT / "app-symfony"),
            env=env,
        )
        if res.returncode == 0:
            return _parse_registry_json(res.stdout, source="native php")
        # Wrong host PHP / platform_check / missing extensions → try docker.
        # Real tool/DB failures will surface again on the docker path (or both).
        native_diag = (
            f"native php exit {res.returncode}: {res.stderr.strip()[:1500]}"
        )

    docker = shutil.which("docker")
    if docker is None:
        detail = f"\nNative path already failed:\n{native_diag}" if native_diag else ""
        pytest.fail(
            "Neither a working native `php` nor `docker` is available to run "
            f"{PHP_SCRIPT} --json — cannot execute the routing drift "
            "test in this environment. Install one of them; do NOT skip this "
            "test to work around it (see module docstring — that is exactly the "
            f"regression that hid a real drift for ~12 days).{detail}"
        )
    container = _container_name()
    try:
        res = subprocess.run(
            [docker, "exec", container, "php", PHP_SCRIPT, "--json"],
            capture_output=True, text=True,
        )
    except OSError as exc:
        detail = f"\nNative path already failed:\n{native_diag}" if native_diag else ""
        pytest.fail(
            f"Failed to `docker exec` into container {container!r}: {exc}{detail}"
        )
    if res.returncode != 0:
        detail = f"\nNative path already failed:\n{native_diag}" if native_diag else ""
        pytest.fail(
            f"{PHP_SCRIPT} --json failed inside container {container!r} "
            f"(exit {res.returncode}) — this is a genuine drift-test failure "
            f"(catalog missing/empty, missing tool, or a tool bug), not a "
            f"missing-tool skip:\n{res.stderr.strip()[:2000]}{detail}"
        )
    return _parse_registry_json(res.stdout, source=f"docker exec {container}")


# ---------------------------------------------------------------------------
# Python worker loader — AST/static CAPABILITIES extract (no worker imports)
#
# WHY NOT exec_module / subprocess import: every workers/*/worker.py pulls
# `workers.common.ws_client` (httpx/websockets) and some pull Pillow/pdf2image/
# pytesseract. CI's host `.venv-ci` only has pytest — importing worker.py fails
# with ImportError while the actual CAPABILITIES dict is a pure literal (or
# references same-file module-level literal matrices). Static extract keeps
# `make TEST=1 test-drift` green on a bare host venv without weakening coverage
# or installing worker runtime deps just for this guard.
#
# The extractor itself lives in workers/tools/capabilities_ast.py (registry-05:
# shared with gen_worker_capabilities.py, the format-catalog generator) — this
# file only wires its CapabilitiesExtractionError into pytest.fail() so failure
# messages/behaviour stay identical to the old inline implementation.
# ---------------------------------------------------------------------------

from workers.tools.capabilities_ast import (
    CapabilitiesExtractionError,
    load_worker_capabilities,
)


def _load_workers() -> list[tuple[str, dict[str, Any]]]:
    """Return [(worker_name, capabilities), …] for every workers/*/worker.py.

    Thin pytest.fail() wrapper around
    workers.tools.capabilities_ast.load_worker_capabilities — see that
    module's docstring for the fail-loud rationale (registry-04/registry-05).
    """
    try:
        return load_worker_capabilities(WORKERS_DIR)
    except CapabilitiesExtractionError as exc:
        pytest.fail(str(exc))


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
# Assertion (A): every catalog routing-key is consumed by ≥1 worker
# ---------------------------------------------------------------------------

def test_all_routing_keys_have_worker(
    registry: dict[str, Any],
    workers: list[tuple[str, dict[str, Any]]],
) -> None:
    """
    Every stream the catalog can route a job to must have at least one
    Python worker that declares it in routing_keys. A missing worker means
    jobs pile up in that stream forever.
    """
    uncovered = _uncovered_routing_keys(registry, workers)
    assert not uncovered, (
        "Routing keys emitted by the catalog with NO Python worker:\n"
        + "\n".join(f"  - {k}" for k in sorted(uncovered))
        + "\n\nFor each key above: either add the missing worker, or the "
        + "registered capability that advertises this stream is stale/wrong."
    )


# ---------------------------------------------------------------------------
# Assertion (B): worker matrix ⊆ catalog
# ---------------------------------------------------------------------------

def test_worker_matrix_subset_of_registry(
    registry: dict[str, Any],
    workers: list[tuple[str, dict[str, Any]]],
) -> None:
    """
    Every (from→to) pair a worker's CAPABILITIES.matrix declares must be
    present in the catalog (`dump-matrix.php --json`). A worker cannot
    convert a pair the catalog doesn't know about — it would never be
    routed there.

    One-directional on purpose — see module docstring ("category vs stream").
    Format aliases (yml/yaml, jpeg/jpg, tif/tiff, htm/html) are normalised on
    both sides before comparison. Genuine format differences are NOT
    normalised and will surface as failures if missing from the catalog.

    See the module docstring for why this assertion is largely subsumed by
    `test_catalog_drift.py` (both ultimately compare against the same
    `worker_capabilities.json` source), while assertion (A) above is not.
    """
    failures = _worker_pairs_missing_from_registry(registry, workers)
    assert not failures, (
        f"Worker pairs absent from the catalog — {len(failures)} violation(s):\n"
        + "\n".join(sorted(failures))
        + "\n\nFor each pair above: either the worker never (re-)registered this "
        + "pair, or the pair was dropped/renamed on the registry side — "
        + "investigate before assuming it's stale."
    )
