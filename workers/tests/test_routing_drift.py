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

import ast
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
# PHP registry loader — register-round-trip source of truth
# ---------------------------------------------------------------------------

def _container_name() -> str:
    # Makefile exports COMPOSE_PROJECT_NAME for the stand it runs against (the
    # test stand under `make test`), so the env wins over the tracked .env file —
    # otherwise this drift check would read the DEV database's matrix.
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
    """Run `dump-matrix.php --json` and return the parsed live registry snapshot.

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
            f"{PHP_SCRIPT} --json — cannot execute the register-round-trip drift "
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
            f"(DB unreachable/empty, missing tool, or a tool bug), not a "
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
# ---------------------------------------------------------------------------

def _eval_caps_node(node: ast.AST, env: dict[str, Any]) -> Any:
    """Evaluate a narrow subset of AST nodes used by CAPABILITIES / matrices.

    Supported: constants, Names resolved from *env*, list/tuple/set/dict
    literals, and set-union via `|` (BitOr) — enough for every current
    workers/*/worker.py CAPABILITIES graph. Anything else raises ValueError
    so callers can skip non-capability assignments (e.g. `**imported_dict`).
    """
    if isinstance(node, ast.Constant):
        return node.value
    if isinstance(node, ast.Name):
        if node.id not in env:
            raise ValueError(f"unresolved name {node.id!r}")
        return env[node.id]
    if isinstance(node, ast.Set):
        return {_eval_caps_node(elt, env) for elt in node.elts}
    if isinstance(node, ast.List):
        return [_eval_caps_node(elt, env) for elt in node.elts]
    if isinstance(node, ast.Tuple):
        return tuple(_eval_caps_node(elt, env) for elt in node.elts)
    if isinstance(node, ast.Dict):
        out: dict[Any, Any] = {}
        for key_node, val_node in zip(node.keys, node.values, strict=True):
            if key_node is None:
                raise ValueError("dict **unpack not supported in CAPABILITIES graph")
            out[_eval_caps_node(key_node, env)] = _eval_caps_node(val_node, env)
        return out
    if isinstance(node, ast.BinOp) and isinstance(node.op, ast.BitOr):
        return _eval_caps_node(node.left, env) | _eval_caps_node(node.right, env)
    if isinstance(node, ast.UnaryOp) and isinstance(node.op, ast.UAdd):
        return +_eval_caps_node(node.operand, env)
    if isinstance(node, ast.UnaryOp) and isinstance(node.op, ast.USub):
        return -_eval_caps_node(node.operand, env)
    if (
        isinstance(node, ast.Call)
        and isinstance(node.func, ast.Name)
        and node.func.id == "set"
    ):
        if not node.args:
            return set()
        if len(node.args) == 1:
            return set(_eval_caps_node(node.args[0], env))
    raise ValueError(f"unsupported AST node {type(node).__name__}")


def _try_store_name(target: ast.AST, value: ast.AST, env: dict[str, Any]) -> None:
    if not isinstance(target, ast.Name):
        return
    try:
        env[target.id] = _eval_caps_node(value, env)
    except ValueError:
        # Non-literal / unresolved (imports, **unpack, calls) — irrelevant for
        # CAPABILITIES as long as the capability graph itself stays evaluable.
        pass


def _extract_capabilities_ast(worker_file: Path) -> dict[str, Any] | None:
    """Return CAPABILITIES dict from worker.py via AST, or None if absent.

    Prefers a module-level CAPABILITIES (ai worker) over a class attribute
    (data/ffmpeg/image/libreoffice) — same precedence as the old exec path.
    """
    try:
        source = worker_file.read_text(encoding="utf-8")
        tree = ast.parse(source, filename=str(worker_file))
    except (OSError, SyntaxError) as exc:
        pytest.fail(
            f"Could not parse CAPABILITIES from {worker_file.relative_to(REPO_ROOT)}:\n"
            f"{exc}"
        )

    env: dict[str, Any] = {}
    module_caps: dict[str, Any] | None = None
    class_caps: dict[str, Any] | None = None

    for node in tree.body:
        if isinstance(node, ast.Assign):
            for target in node.targets:
                _try_store_name(target, node.value, env)
                if (
                    isinstance(target, ast.Name)
                    and target.id == "CAPABILITIES"
                    and "CAPABILITIES" in env
                    and isinstance(env["CAPABILITIES"], dict)
                ):
                    module_caps = env["CAPABILITIES"]
        elif isinstance(node, ast.AnnAssign) and node.value is not None:
            _try_store_name(node.target, node.value, env)
            if (
                isinstance(node.target, ast.Name)
                and node.target.id == "CAPABILITIES"
                and "CAPABILITIES" in env
                and isinstance(env["CAPABILITIES"], dict)
            ):
                module_caps = env["CAPABILITIES"]
        elif isinstance(node, ast.ClassDef):
            for item in node.body:
                caps_value: ast.AST | None = None
                if isinstance(item, ast.Assign):
                    for target in item.targets:
                        if isinstance(target, ast.Name) and target.id == "CAPABILITIES":
                            caps_value = item.value
                            break
                elif (
                    isinstance(item, ast.AnnAssign)
                    and item.value is not None
                    and isinstance(item.target, ast.Name)
                    and item.target.id == "CAPABILITIES"
                ):
                    caps_value = item.value
                if caps_value is None:
                    continue
                try:
                    evaluated = _eval_caps_node(caps_value, env)
                except ValueError as exc:
                    pytest.fail(
                        f"Could not statically evaluate class CAPABILITIES in "
                        f"{worker_file.relative_to(REPO_ROOT)}:\n{exc}"
                    )
                if isinstance(evaluated, dict):
                    class_caps = evaluated

    return module_caps if module_caps is not None else class_caps


def _serialize_capabilities(caps: dict[str, Any]) -> dict[str, Any]:
    """Normalize CAPABILITIES for JSON-stable comparison (sets → sorted lists)."""
    missing_keys = [k for k in ("routing_keys", "matrix") if k not in caps]
    if missing_keys:
        pytest.fail(
            f"CAPABILITIES missing required key(s): {missing_keys}"
        )

    def ser(v: Any) -> list[Any]:
        if isinstance(v, (set, frozenset, list)):
            return sorted(v)
        return sorted(str(x) for x in v)

    return {
        "routing_keys": list(caps["routing_keys"]),
        "matrix": {k: ser(v) for k, v in caps["matrix"].items()},
    }


def _load_workers() -> list[tuple[str, dict[str, Any]]]:
    """Return [(worker_name, capabilities), …] for every workers/*/worker.py.

    Fails loudly rather than silently shrinking its own output — two distinct risks
    of that shape, both closed here (registry-04 review):
    (1) a worker.py exists but no CAPABILITIES can be located in it — previously a
        silent `continue` dropped that worker from the comparison's input entirely;
    (2) the directory scan itself turns up zero worker.py files (wrong REPO_ROOT,
        moved directory, empty checkout) — previously nothing would have noticed,
        and both drift assertions below would have passed vacuously against nothing.
    """
    results: list[tuple[str, dict[str, Any]]] = []
    found_any_worker_file = False
    for worker_dir in sorted(WORKERS_DIR.iterdir()):
        if not worker_dir.is_dir():
            continue
        worker_file = worker_dir / "worker.py"
        if not worker_file.exists():
            # Not every workers/* directory is a worker package — common/, gateway/,
            # metrics_exporter/, tests/ have no worker.py by design. This is a
            # structural skip, not an input-completeness risk.
            continue
        found_any_worker_file = True
        raw = _extract_capabilities_ast(worker_file)
        if raw is None:
            pytest.fail(
                f"workers/{worker_dir.name}/worker.py has no CAPABILITIES (neither a "
                "module-level constant nor a class attribute) — silently dropping this "
                "worker from the drift comparison is exactly the failure mode this test "
                "file exists to prevent (see module docstring). If this worker genuinely "
                "must not declare capabilities, exclude it here explicitly with a comment "
                "explaining why — do not let it vanish via a silent `None`."
            )
        results.append((worker_dir.name, _serialize_capabilities(raw)))
    if not found_any_worker_file:
        pytest.fail(
            f"Found ZERO workers/*/worker.py files under {WORKERS_DIR} — the worker scan "
            "came back empty. Both drift assertions would otherwise pass vacuously while "
            "comparing against nothing, which is exactly the silent-guard failure mode "
            "this test file exists to prevent."
        )
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
