"""Shared pytest fixtures for worker unit tests.

Additive only — existing test modules keep their own local `_worker` helpers.
New tests use `build_worker` / `example_files` to avoid re-implementing the
redis-mock + WORK_DIR-patch boilerplate that every worker test needs.
"""

from __future__ import annotations

from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

EXAMPLE_FILES = Path(__file__).parent / "example_files"


@pytest.fixture
def example_files() -> Path:
    """Absolute path to the committed fixture corpus (tests/example_files/)."""
    return EXAMPLE_FILES


@pytest.fixture
def mock_redis() -> MagicMock:
    """A stand-in redis client (no network); returned by build_worker too."""
    return MagicMock()


@pytest.fixture
def build_worker(tmp_path: Path, mock_redis: MagicMock):
    """Factory: construct a StreamConsumerBase subclass with redis + WORK_DIR mocked.

    Usage:
        worker = build_worker(FfmpegWorker, "workers.ffmpeg.worker")

    redis.Redis is patched during __init__ so no connection/stream setup hits a
    real server; WORK_DIR is redirected to the test's tmp_path in both the
    common module and the worker module (convert() imports WORK_DIR by value).
    All patches are torn down at fixture exit.
    """
    import workers.common.stream_consumer as sc_mod

    patchers: list = []

    def _make(worker_cls, worker_module: str):
        import importlib

        w_mod = importlib.import_module(worker_module)
        with patch.object(sc_mod, "REDIS_HOST", "localhost"), \
             patch("workers.common.stream_consumer.redis.Redis", return_value=mock_redis):
            worker = worker_cls()

        for target in (
            patch.object(sc_mod, "WORK_DIR", tmp_path),
            patch.object(w_mod, "WORK_DIR", tmp_path),
        ):
            target.start()
            patchers.append(target)
        return worker

    yield _make

    for p in patchers:
        p.stop()
