"""Unit tests for BaseWorker: queue consumption, callback, ack."""

import asyncio
import json
from typing import Any
from unittest.mock import AsyncMock, MagicMock, call, patch

import pytest

# ---------------------------------------------------------------------------
# We need to patch redis before importing the module under test, so that no
# real connection is attempted at import time.
# ---------------------------------------------------------------------------
import sys
import types

# Stub out redis so tests run without the package installed
if "redis" not in sys.modules:
    redis_stub = types.ModuleType("redis")
    redis_class = MagicMock()
    redis_stub.Redis = redis_class
    sys.modules["redis"] = redis_stub

# Stub out requests
if "requests" not in sys.modules:
    req_stub = types.ModuleType("requests")
    session_mock = MagicMock()
    req_stub.Session = MagicMock(return_value=session_mock)
    adapters_stub = types.ModuleType("requests.adapters")
    adapters_stub.HTTPAdapter = MagicMock()
    retry_stub = types.ModuleType("urllib3.util.retry")
    retry_stub.Retry = MagicMock()
    sys.modules["requests"] = req_stub
    sys.modules["requests.adapters"] = adapters_stub
    sys.modules["urllib3"] = types.ModuleType("urllib3")
    sys.modules["urllib3.util"] = types.ModuleType("urllib3.util")
    sys.modules["urllib3.util.retry"] = retry_stub

from workers.common.base_worker import BaseWorker  # noqa: E402
from workers.common.keydb_client import QueueClient  # noqa: E402


# ---------------------------------------------------------------------------
# Concrete worker for testing
# ---------------------------------------------------------------------------

class EchoWorker(BaseWorker):
    """Minimal concrete worker that echoes input_path as output_path."""

    queue_name = "convertor:test"

    async def process_task(self, task: dict[str, Any]) -> dict[str, Any]:
        return {"status": "ok", "output_path": task["input_path"] + ".out"}


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

@pytest.fixture()
def worker() -> EchoWorker:
    with patch("workers.common.base_worker.QueueClient") as mock_client_cls:
        mock_client = MagicMock()
        mock_client_cls.return_value = mock_client
        w = EchoWorker()
        w._client = mock_client
        w._http_session = MagicMock()
    return w


SAMPLE_TASK: dict[str, Any] = {
    "id": "task-001",
    "input_path": "/shared-files/doc.txt",
    "output_format": "md",
    "callback_url": "http://app/api/convert/task-001",
}


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

class TestQueueClient:
    """Tests for the thin QueueClient wrapper."""

    def test_queue_key_adds_prefix(self) -> None:
        assert QueueClient._queue_key("documents") == "convertor:documents"

    def test_queue_key_no_double_prefix(self) -> None:
        assert QueueClient._queue_key("convertor:documents") == "convertor:documents"

    def test_processing_key(self) -> None:
        assert QueueClient._processing_key("convertor:documents") == "convertor:documents:processing"


class TestBaseWorkerCallback:
    """Tests for _callback retry logic."""

    def test_callback_success_on_first_attempt(self, worker: EchoWorker) -> None:
        response_mock = MagicMock()
        response_mock.raise_for_status = MagicMock()
        response_mock.status_code = 200
        worker._http_session.patch.return_value = response_mock

        worker._callback(SAMPLE_TASK, {"status": "ok", "output_path": "/shared-files/doc.md"})

        worker._http_session.patch.assert_called_once()
        args, kwargs = worker._http_session.patch.call_args
        assert args[0] == SAMPLE_TASK["callback_url"]
        payload = kwargs["json"]
        assert payload["id"] == "task-001"
        assert payload["status"] == "ok"

    def test_callback_retries_on_failure(self, worker: EchoWorker) -> None:
        worker._http_session.patch.side_effect = ConnectionError("refused")

        with patch("time.sleep") as mock_sleep:
            worker._callback(SAMPLE_TASK, {"status": "error", "error": "boom"})

        assert worker._http_session.patch.call_count == 3
        # backoff sleeps: 1.0, 2.0
        assert mock_sleep.call_count == 2

    def test_callback_skipped_when_no_url(self, worker: EchoWorker) -> None:
        task_no_cb = {**SAMPLE_TASK, "callback_url": None}
        worker._callback(task_no_cb, {"status": "ok"})
        worker._http_session.patch.assert_not_called()


class TestBaseWorkerAck:
    """Tests for _ack delegation."""

    def test_ack_calls_client(self, worker: EchoWorker) -> None:
        worker._ack(SAMPLE_TASK)
        worker._client.ack.assert_called_once_with(worker._queue_name, SAMPLE_TASK)


class TestBaseWorkerMainLoop:
    """Tests for the async main loop."""

    @pytest.mark.asyncio
    async def test_processes_single_task_then_stops(self, worker: EchoWorker) -> None:
        """Loop pops one task, processes it, calls callback + ack, then stops."""
        task = {**SAMPLE_TASK}

        pop_calls = 0

        def fake_pop(queue_name: str, timeout: int = 5) -> dict[str, Any] | None:
            nonlocal pop_calls
            pop_calls += 1
            if pop_calls == 1:
                return task
            worker._running = False
            return None

        worker._client.pop.side_effect = fake_pop

        response_mock = MagicMock()
        response_mock.raise_for_status = MagicMock()
        response_mock.status_code = 200
        worker._http_session.patch.return_value = response_mock

        await worker._main_loop()

        worker._client.ack.assert_called_once_with(worker._queue_name, task)
        worker._http_session.patch.assert_called_once()
        payload = worker._http_session.patch.call_args[1]["json"]
        assert payload["output_path"] == "/shared-files/doc.txt.out"
        assert payload["status"] == "ok"

    @pytest.mark.asyncio
    async def test_error_in_process_task_reported_via_callback(
        self, worker: EchoWorker
    ) -> None:
        """If process_task raises, callback is still called with error status."""

        async def fail_task(task: dict[str, Any]) -> dict[str, Any]:
            raise RuntimeError("something went wrong")

        worker.process_task = fail_task  # type: ignore[method-assign]

        pop_calls = 0

        def fake_pop(queue_name: str, timeout: int = 5) -> dict[str, Any] | None:
            nonlocal pop_calls
            pop_calls += 1
            if pop_calls == 1:
                return {**SAMPLE_TASK}
            worker._running = False
            return None

        worker._client.pop.side_effect = fake_pop

        response_mock = MagicMock()
        response_mock.raise_for_status = MagicMock()
        response_mock.status_code = 200
        worker._http_session.patch.return_value = response_mock

        await worker._main_loop()

        payload = worker._http_session.patch.call_args[1]["json"]
        assert payload["status"] == "error"
        assert "something went wrong" in payload["error"]
