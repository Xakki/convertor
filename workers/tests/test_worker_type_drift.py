"""Worker-type drift guard: PHP `WorkerType` enum vs its 3 independent mirrors.

What this checks: the canonical worker-type SET declared by the PHP enum
`App\\Enum\\WorkerType` (`app-symfony/src/Enum/WorkerType.php`) must equal the
set declared by each of three PHP-independent static whitelists:

  - `workers/common/ws_client.py`  — `ALLOWED_WORKER_TYPES` tuple
  - `workers/gateway/keydb.py`     — `WORKER_TYPES` tuple
  - `app-symfony/config/packages/messenger.yaml` — the `conv.<type>` streams
    of the 6 `conv_*` Messenger transports

These mirrors are INTENTIONALLY independent of the enum at runtime, not an
oversight to "fix" by importing/deriving from it: the Python worker process
validates its own `WORKER_TYPE` env var BEFORE it ever connects to the
gateway, and the gateway decides which `conv.<type>` streams to
`XREADGROUP` — both must be able to start and self-validate even if
PHP/the DB is completely unreachable (resilience at a process boundary).
messenger.yaml is PHP-side config but likewise a static list, not something
generated from the enum. Keeping all four in sync is this file's job.

Sources are parsed by REGEX over their raw file text — none of the three
Python/PHP/YAML sources are imported/executed. Importing `ws_client.py` or
`keydb.py` would pull heavy deps (redis, boto3, websockets…) into a plain
`pytest` run; text-parsing keeps this test cheap and dependency-free (same
approach as `test_routing_drift.py`'s worker-capability extraction).

WHY THIS FILE MUST NEVER SKIP: `test_routing_drift.py` (registry-04) already
documents a ~12-day regression where a routing drift-guard silently reported
green via `pytest.skip()` instead of failing when its data source vanished
— nobody noticed for 12 days. This file follows the exact same discipline:
every failure mode (a source file missing/empty, a regex matching nothing,
an empty parsed set) is a hard `pytest.fail()`, never a skip and never a
vacuous pass. If a source's format changes and the regex here stops
matching, that MUST show up as a loud failure, not a silently-empty set
sailing through the comparison.

Run standalone: make test-drift (from repo root — workers/Makefile is `include`d
by the root Makefile; its recipe paths are repo-root-relative, so `make -C
workers test-drift` fails with a file-not-found).
"""
from __future__ import annotations

import re
from pathlib import Path

import pytest

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------

REPO_ROOT = Path(__file__).resolve().parents[2]
WORKERS_DIR = REPO_ROOT / "workers"

PHP_ENUM_PATH = REPO_ROOT / "app-symfony" / "src" / "Enum" / "WorkerType.php"
WS_CLIENT_PATH = WORKERS_DIR / "common" / "ws_client.py"
KEYDB_PATH = WORKERS_DIR / "gateway" / "keydb.py"
MESSENGER_YAML_PATH = REPO_ROOT / "app-symfony" / "config" / "packages" / "messenger.yaml"

# ---------------------------------------------------------------------------
# Regexes
# ---------------------------------------------------------------------------

_ENUM_BODY_RE = re.compile(r"enum\s+WorkerType\s*:\s*string\s*\{(.*?)\n\}", re.DOTALL)
_ENUM_CASE_RE = re.compile(r"case\s+\w+\s*=\s*'([a-z]+)'")
# Python tuples in this repo use double-quoted string literals (ws_client.py,
# keydb.py: `("ai", "document", ...)`) — unlike the PHP enum's single quotes.
# Accept either quote style so this doesn't silently break on a re-quote.
_STRING_LITERAL_RE = re.compile(r"['\"]([a-z]+)['\"]")
_MESSENGER_STREAM_RE = re.compile(r"stream:\s*conv\.([a-z]+)")


# ---------------------------------------------------------------------------
# Pure parsing helpers — no docker/subprocess, plain text-parse. Kept as
# standalone functions (not fixtures) so they can be unit-exercised directly.
# ---------------------------------------------------------------------------

def _read_text(path: Path, *, label: str) -> str:
    if not path.exists():
        pytest.fail(
            f"{label} not found at {path} — cannot run the worker-type drift "
            "guard without its source file. Do NOT skip: fix the path or "
            "restore the file."
        )
    text = path.read_text()
    if not text.strip():
        pytest.fail(f"{label} at {path} is EMPTY — refusing to compare against nothing.")
    return text


def _parse_php_enum_types(text: str) -> set[str]:
    """Extract the case values of PHP `enum WorkerType: string { ... }`."""
    match = _ENUM_BODY_RE.search(text)
    if match is None:
        pytest.fail(
            f"Could not locate `enum WorkerType: string {{ ... }}` body in "
            f"{PHP_ENUM_PATH} — the enum's shape changed; update the regex "
            "in test_worker_type_drift.py, don't loosen/skip the assertion."
        )
    types = set(_ENUM_CASE_RE.findall(match.group(1)))
    if not types:
        pytest.fail(
            f"`case \\w+ = '...'` regex matched ZERO cases inside the "
            f"WorkerType enum body in {PHP_ENUM_PATH} — parsing is broken, "
            "not that the enum is genuinely empty."
        )
    return types


def _parse_python_tuple(text: str, *, varname: str, path: Path) -> set[str]:
    """Extract string-literal members of a top-level `VARNAME = (...)` tuple."""
    match = re.search(rf"{varname}\s*=\s*\(([^)]*)\)", text)
    if match is None:
        pytest.fail(
            f"Could not find `{varname} = (...)` tuple assignment in {path} — "
            "the source changed shape; update the regex, don't skip."
        )
    items = set(_STRING_LITERAL_RE.findall(match.group(1)))
    if not items:
        pytest.fail(
            f"`{varname}` in {path} parsed to an EMPTY set — the regex found "
            "the tuple but no string literals inside it; parsing is broken."
        )
    return items


def _parse_messenger_streams(text: str) -> set[str]:
    """Extract the `<type>` from every `stream: conv.<type>` line."""
    types = set(_MESSENGER_STREAM_RE.findall(text))
    if not types:
        pytest.fail(
            f"`stream: conv.<type>` regex matched ZERO transports in "
            f"{MESSENGER_YAML_PATH} — the yaml shape changed; update the "
            "regex, don't skip."
        )
    return types


def _diff(canon: set[str], other: set[str]) -> tuple[set[str], set[str]]:
    """Return (missing_from_other, extra_in_other), both relative to canon."""
    return canon - other, other - canon


# ---------------------------------------------------------------------------
# Session-scoped fixtures — one file read + parse per source per pytest run
# ---------------------------------------------------------------------------

@pytest.fixture(scope="session")
def php_enum_types() -> set[str]:
    return _parse_php_enum_types(_read_text(PHP_ENUM_PATH, label="PHP enum WorkerType"))


@pytest.fixture(scope="session")
def ws_client_types() -> set[str]:
    text = _read_text(WS_CLIENT_PATH, label="workers/common/ws_client.py")
    return _parse_python_tuple(text, varname="ALLOWED_WORKER_TYPES", path=WS_CLIENT_PATH)


@pytest.fixture(scope="session")
def keydb_types() -> set[str]:
    text = _read_text(KEYDB_PATH, label="workers/gateway/keydb.py")
    return _parse_python_tuple(text, varname="WORKER_TYPES", path=KEYDB_PATH)


@pytest.fixture(scope="session")
def messenger_types() -> set[str]:
    text = _read_text(MESSENGER_YAML_PATH, label="app-symfony/config/packages/messenger.yaml")
    return _parse_messenger_streams(text)


# ---------------------------------------------------------------------------
# Assertions — each mirror compared independently against the PHP enum canon,
# so a failure names exactly which source drifted and how.
# ---------------------------------------------------------------------------

def test_ws_client_allowed_worker_types_match_canon(
    php_enum_types: set[str], ws_client_types: set[str]
) -> None:
    missing, extra = _diff(php_enum_types, ws_client_types)
    assert not missing and not extra, (
        "workers/common/ws_client.py ALLOWED_WORKER_TYPES drifted from the "
        f"PHP canon App\\Enum\\WorkerType (canon={sorted(php_enum_types)}):\n"
        f"  missing here (in canon, absent from ws_client.py): {sorted(missing) or '-'}\n"
        f"  extra here   (in ws_client.py, absent from canon):  {sorted(extra) or '-'}\n"
        "Update ALLOWED_WORKER_TYPES to match WorkerType.php."
    )


def test_keydb_worker_types_match_canon(
    php_enum_types: set[str], keydb_types: set[str]
) -> None:
    missing, extra = _diff(php_enum_types, keydb_types)
    assert not missing and not extra, (
        "workers/gateway/keydb.py WORKER_TYPES drifted from the PHP canon "
        f"App\\Enum\\WorkerType (canon={sorted(php_enum_types)}):\n"
        f"  missing here (in canon, absent from keydb.py): {sorted(missing) or '-'}\n"
        f"  extra here   (in keydb.py, absent from canon):  {sorted(extra) or '-'}\n"
        "Update WORKER_TYPES to match WorkerType.php."
    )


def test_messenger_transports_match_canon(
    php_enum_types: set[str], messenger_types: set[str]
) -> None:
    missing, extra = _diff(php_enum_types, messenger_types)
    assert not missing and not extra, (
        "app-symfony/config/packages/messenger.yaml conv_* transports drifted "
        f"from the PHP canon App\\Enum\\WorkerType (canon={sorted(php_enum_types)}):\n"
        f"  missing here (in canon, no conv_<type> transport): {sorted(missing) or '-'}\n"
        f"  extra here   (conv_<type> transport, absent from canon): {sorted(extra) or '-'}\n"
        "Add/remove a conv_<type> transport (dsn+stream: conv.<type>) to match WorkerType.php."
    )
