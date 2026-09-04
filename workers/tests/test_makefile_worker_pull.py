"""Behavioral checks for the profile-scoped worker image pull targets."""

from __future__ import annotations

import os
import subprocess
import tempfile
from pathlib import Path


ROOT_DIR = Path(__file__).resolve().parents[2]


def _run_make(tmp_path: Path, *make_args: str) -> subprocess.CompletedProcess[str]:
    fake_docker = tmp_path / "docker"
    fake_docker.write_text(
        """#!/bin/sh
set -eu
printf '%s|profiles=%s\\n' "$*" "${COMPOSE_PROFILES-}" >> "${FAKE_LOG}"
if [ "$1" = compose ] && [ "$2" = config ] && [ "$3" = --services ]; then
    case "${COMPOSE_PROFILES-}" in
        ai|ai,data) printf '%s\\n' worker-data worker-image worker-ai ;;
        data) printf '%s\\n' worker-data ;;
        server,api) printf '%s\\n' php mariadb keydb worker-api ;;
        *) printf '%s\\n' worker-data worker-image ;;
    esac
elif [ "$1" = compose ] && [ "$2" = config ] && [ "$3" = --images ]; then
    case "${COMPOSE_PROFILES-}" in
        ai|ai,data) printf '%s\\n' harbor.test/convertor/worker-data:latest harbor.test/convertor/worker-image:latest harbor.test/convertor/worker-ai-cpu:latest ;;
        data) printf '%s\\n' harbor.test/convertor/worker-data:latest ;;
        server,api) printf '%s\\n' harbor.test/convertor/php:latest harbor.test/convertor/worker-api:latest ;;
        *) printf '%s\\n' harbor.test/convertor/worker-data:latest harbor.test/convertor/worker-image:latest ;;
    esac
elif [ "$1" = compose ] && [ "$2" = -p ] && [ "$4" = ps ] && [ "$5" = -q ]; then
    printf 'fake-container\\n'
elif [ "$1" = inspect ]; then
    printf '1\\n'
elif [ "$1" = compose ] && [ "$2" = pull ]; then
    printf 'PULL:%s\\n' "$*" >> "${FAKE_LOG}"
fi
""",
        encoding="utf-8",
    )
    fake_docker.chmod(0o755)
    probe_dir = Path(tempfile.mkdtemp(prefix="convertor-test-root-probe-", dir="/var/tmp"))
    log = tmp_path / "compose.log"
    env = os.environ | {
        "PATH": f"{tmp_path}:{os.environ['PATH']}",
        "FAKE_LOG": str(log),
        "IMAGE_NS": "harbor.test/convertor",
        "IMAGE_TAG": "latest",
        # This reproduces the shell value that must not leak into scoped pulls.
        "COMPOSE_PROFILES": "server,backup,monitoring",
        # Keep the harness probe on the same filesystem as /, not tmp_path (/tmp).
        "HOST_ROOT_PROBE_DIR": str(probe_dir),
    }
    result = subprocess.run(
        ["make", "--no-print-directory", "IMAGE_NS=harbor.test/convertor", "IMAGE_TAG=latest", *make_args],
        cwd=ROOT_DIR,
        capture_output=True,
        text=True,
        check=False,
        env=env,
    )
    result.log = log.read_text(encoding="utf-8") if log.exists() else ""  # type: ignore[attr-defined]
    return result


def test_workers_pull_scopes_ai_and_ignores_env_profiles(tmp_path: Path) -> None:
    result = _run_make(
        tmp_path,
        "workers-pull",
        "WORKER_RECREATE_PROFILE=remote",
        "WORKER_RECREATE_SERVICES=worker-data worker-ai",
    )

    assert result.returncode == 0, result.stderr
    assert "config --services|profiles=ai,data" in result.log  # type: ignore[attr-defined]
    assert "config --images|profiles=ai,data" in result.log  # type: ignore[attr-defined]
    assert "compose pull worker-ai worker-data|profiles=ai,data" in result.log  # type: ignore[attr-defined]
    assert "db-dump-cron" not in result.log  # type: ignore[attr-defined]
    assert "server,backup,monitoring" not in result.log  # type: ignore[attr-defined]


def test_workers_pull_rejects_unknown_profile(tmp_path: Path) -> None:
    result = _run_make(tmp_path, "workers-pull", "WORKER_RECREATE_PROFILE=server")

    assert result.returncode == 2
    assert "unknown profile 'server'" in result.stdout
    assert "compose" not in result.log  # type: ignore[attr-defined]


def test_workers_pull_rejects_disallowed_service_before_compose(tmp_path: Path) -> None:
    result = _run_make(
        tmp_path,
        "workers-pull",
        "WORKER_RECREATE_PROFILE=saVpn",
        "WORKER_RECREATE_SERVICES=worker-api",
    )

    assert result.returncode == 2
    assert "not allowed by profile saVpn" in result.stdout
    assert "compose" not in result.log  # type: ignore[attr-defined]


def test_documented_savpn_pull_and_recreate_select_only_data_and_image(tmp_path: Path) -> None:
    docs = (ROOT_DIR / "docs" / "workers-remote-deploy.md").read_text(encoding="utf-8")
    documented_args = (
        "WORKER_RECREATE_PROFILE=saVpn",
        'WORKER_RECREATE_SERVICES="worker-data worker-image"',
    )
    make_args = (
        "WORKER_RECREATE_PROFILE=saVpn",
        "WORKER_RECREATE_SERVICES=worker-data worker-image",
    )
    assert "make workers-pull " + " ".join(documented_args) in docs
    assert "make workers-recreate " + " ".join(documented_args) in docs

    pull = _run_make(tmp_path, "workers-pull", *make_args)
    assert pull.returncode == 0, pull.stderr
    assert "PULL:compose pull worker-data worker-image" in pull.log  # type: ignore[attr-defined]
    assert "worker-libreoffice" not in pull.log  # type: ignore[attr-defined]
    assert "worker-ffmpeg" not in pull.log  # type: ignore[attr-defined]
    assert "worker-ai" not in pull.log  # type: ignore[attr-defined]

    recreate = _run_make(tmp_path, "workers-recreate", *make_args)
    assert recreate.returncode == 0, recreate.stderr
    assert "up -d --force-recreate --no-deps worker-data worker-image" in recreate.log  # type: ignore[attr-defined]
    assert "worker-libreoffice" not in recreate.log  # type: ignore[attr-defined]
    assert "worker-ffmpeg" not in recreate.log  # type: ignore[attr-defined]
    assert "worker-ai" not in recreate.log  # type: ignore[attr-defined]


def test_workers_recreate_service_selects_only_its_profile(tmp_path: Path) -> None:
    result = _run_make(tmp_path, "workers-recreate", "SERVICE=worker-data")

    assert result.returncode == 0, result.stderr
    assert "config --services|profiles=data" in result.log  # type: ignore[attr-defined]
    assert "up -d --force-recreate --no-deps worker-data|profiles=data" in result.log  # type: ignore[attr-defined]


def test_workers_recreate_rejects_unknown_service_before_compose(tmp_path: Path) -> None:
    result = _run_make(tmp_path, "workers-recreate", "SERVICE=worker-api")

    assert result.returncode == 2
    assert "unknown SERVICE 'worker-api'" in result.stdout
    assert "compose" not in result.log  # type: ignore[attr-defined]


def test_worker_api_pull_is_separate_and_scoped(tmp_path: Path) -> None:
    result = _run_make(tmp_path, "worker-api-pull")

    assert result.returncode == 0, result.stderr
    assert "config --services|profiles=server,api" in result.log  # type: ignore[attr-defined]
    assert "compose pull worker-api|profiles=server,api" in result.log  # type: ignore[attr-defined]
    assert "db-dump-cron" not in result.log  # type: ignore[attr-defined]
