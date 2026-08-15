"""Изолированная проверка read-only Makefile-таргета gateway-verify-sha."""

from __future__ import annotations

import os
import subprocess
from pathlib import Path


ROOT_DIR = Path(__file__).resolve().parents[2]


def _run_verify_target(
    tmp_path: Path,
    *,
    container_sha: str = "",
    source_state: str = "",
    running: bool = True,
) -> subprocess.CompletedProcess[str]:
    """Подменяет Docker CLI, чтобы проверить ветки сравнения без Docker daemon."""
    fake_docker = tmp_path / "docker"
    fake_docker.write_text(
        """#!/bin/sh
set -eu
if [ \"$1\" = \"compose\" ] && [ \"$2\" = \"ps\" ]; then
    if [ \"${GATEWAY_RUNNING}\" = \"1\" ]; then
        printf '%s\\n' gateway-container-id
    fi
    exit 0
fi
if [ \"$1\" = \"inspect\" ]; then
    case \"$4\" in
        *org.opencontainers.image.revision*) printf '%s\\n' \"${CONTAINER_SHA}\" ;;
        *org.opencontainers.image.source-state*) printf '%s\\n' \"${SOURCE_STATE}\" ;;
        *) exit 64 ;;
    esac
    exit 0
fi
exit 64
""",
        encoding="utf-8",
    )
    fake_docker.chmod(0o755)
    env = os.environ | {
        "PATH": f"{tmp_path}:{os.environ['PATH']}",
        "CONTAINER_SHA": container_sha,
        "SOURCE_STATE": source_state,
        "GATEWAY_RUNNING": "1" if running else "0",
    }
    return subprocess.run(
        ["make", "--no-print-directory", "gateway-verify-sha"],
        cwd=ROOT_DIR,
        capture_output=True,
        text=True,
        check=False,
        env=env,
    )


def test_gateway_verify_sha_reports_a_match(tmp_path: Path) -> None:
    local_sha = subprocess.check_output(
        ["git", "rev-parse", "--short", "HEAD"], cwd=ROOT_DIR, text=True
    ).strip()

    result = _run_verify_target(
        tmp_path, container_sha=local_sha, source_state="clean"
    )

    assert result.returncode == 0, result.stderr
    assert f"Local HEAD SHA: {local_sha}" in result.stdout
    assert f"Container SHA: {local_sha}" in result.stdout
    assert "Container source state: clean" in result.stdout
    assert "SHA match: yes" in result.stdout


def test_gateway_verify_sha_fails_for_a_mismatch(tmp_path: Path) -> None:
    result = _run_verify_target(
        tmp_path, container_sha="deadbeef", source_state="clean"
    )

    assert result.returncode != 0
    assert "Container SHA: deadbeef" in result.stdout
    assert "SHA match: no" in result.stdout


def test_gateway_verify_sha_refuses_a_dirty_artifact(tmp_path: Path) -> None:
    local_sha = subprocess.check_output(
        ["git", "rev-parse", "--short", "HEAD"], cwd=ROOT_DIR, text=True
    ).strip()

    result = _run_verify_target(
        tmp_path, container_sha=local_sha, source_state="dirty"
    )

    assert result.returncode != 0
    assert f"Local HEAD SHA: {local_sha}" in result.stdout
    assert f"Container SHA: {local_sha}" in result.stdout
    assert "Container source state: dirty" in result.stdout
    assert "SHA match: no (container source state is dirty)" in result.stdout


def test_gateway_verify_sha_fails_when_gateway_is_not_running(tmp_path: Path) -> None:
    result = _run_verify_target(tmp_path, running=False)

    assert result.returncode != 0
    assert "Local HEAD SHA:" in result.stdout
    assert "Container SHA: unavailable (ws-gateway is not running)" in result.stdout


def test_gateway_verify_sha_fails_without_source_state_metadata(tmp_path: Path) -> None:
    local_sha = subprocess.check_output(
        ["git", "rev-parse", "--short", "HEAD"], cwd=ROOT_DIR, text=True
    ).strip()

    result = _run_verify_target(tmp_path, container_sha=local_sha)

    assert result.returncode != 0
    assert f"Local HEAD SHA: {local_sha}" in result.stdout
    assert f"Container SHA: {local_sha}" in result.stdout
    assert "Container source state: unavailable" in result.stdout


def test_gateway_verify_sha_fails_without_revision_label(tmp_path: Path) -> None:
    result = _run_verify_target(tmp_path, source_state="clean")

    assert result.returncode != 0
    assert "Local HEAD SHA:" in result.stdout
    assert (
        "Container SHA: unavailable "
        "(image has no org.opencontainers.image.revision label)" in result.stdout
    )