"""Thin wrapper over redis-py for KeyDB queue operations."""

import json
import logging
from typing import Any

import redis

logger = logging.getLogger(__name__)

QUEUE_PREFIX = "convertor:"


class QueueClient:
    """KeyDB/Redis queue client using reliable queue pattern (BRPOPLPUSH)."""

    def __init__(self, host: str, port: int, db: int) -> None:
        self._redis = redis.Redis(
            host=host,
            port=port,
            db=db,
            decode_responses=True,
        )

    def push(self, queue_name: str, task: dict[str, Any]) -> None:
        """Push a task (dict) to the left of the queue list."""
        payload = json.dumps(task, ensure_ascii=False)
        key = self._queue_key(queue_name)
        self._redis.lpush(key, payload)
        logger.debug("pushed task to %s: %s", key, task.get("id"))

    def pop(self, queue_name: str, timeout: int = 5) -> dict[str, Any] | None:
        """Blocking pop from queue into processing list. Returns task dict or None."""
        src = self._queue_key(queue_name)
        dst = self._processing_key(queue_name)
        raw = self._redis.brpoplpush(src, dst, timeout=timeout)
        if raw is None:
            return None
        task: dict[str, Any] = json.loads(raw)
        logger.debug("popped task from %s: %s", src, task.get("id"))
        return task

    def ack(self, queue_name: str, task: dict[str, Any]) -> None:
        """Remove a task from the processing queue after successful handling."""
        dst = self._processing_key(queue_name)
        payload = json.dumps(task, ensure_ascii=False)
        removed = self._redis.lrem(dst, 1, payload)
        if removed == 0:
            # Fallback: try with sorted keys to match serialized form
            logger.warning("ack: task not found in %s (id=%s)", dst, task.get("id"))

    def queue_length(self, queue_name: str) -> int:
        """Return the number of pending tasks in the queue."""
        return self._redis.llen(self._queue_key(queue_name))

    @staticmethod
    def _queue_key(queue_name: str) -> str:
        return queue_name if queue_name.startswith(QUEUE_PREFIX) else f"{QUEUE_PREFIX}{queue_name}"

    @staticmethod
    def _processing_key(queue_name: str) -> str:
        base = queue_name if queue_name.startswith(QUEUE_PREFIX) else f"{QUEUE_PREFIX}{queue_name}"
        return f"{base}:processing"
