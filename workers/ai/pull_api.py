"""Thin HTTP client for the backend worker pull-API.

Wraps the four endpoints under /api/v1/worker/*:
  POST   /api/v1/worker/claim                 → claim a job (204 = queue empty)
  GET    /api/v1/worker/jobs/{id}/input       → stream the input file
  POST   /api/v1/worker/jobs/{id}/result      → upload the converted result
  POST   /api/v1/worker/jobs/{id}/fail        → report a failure

Request paths are built as absolute strings f"{api_base}/api/v1/..." rather than via
httpx base_url, because httpx drops a base_url path prefix when the request path is
absolute — building full URLs preserves any path component in API_BASE_URL.
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from typing import Any

import httpx


class PullApiClient:
    def __init__(self, client: httpx.AsyncClient, api_base: str) -> None:
        self._client = client
        self._api_base = api_base

    def _url(self, path: str) -> str:
        return f"{self._api_base}/api/v1/worker/{path.lstrip('/')}"

    async def claim(self, worker_type: str, consumer: str) -> dict[str, Any] | None:
        """Claim one job. Returns the job meta dict, or None when the queue is empty (204)."""
        resp = await self._client.post(
            self._url("claim"),
            json={"type": worker_type, "consumer": consumer},
        )
        if resp.status_code == 204:
            return None
        resp.raise_for_status()
        return resp.json()

    @asynccontextmanager
    async def stream_input(self, job_id: str) -> AsyncIterator[httpx.Response]:
        """Stream the input file for a job (caller iterates `resp.aiter_bytes`)."""
        async with self._client.stream("GET", self._url(f"jobs/{job_id}/input")) as resp:
            resp.raise_for_status()
            yield resp

    async def upload_result(
        self,
        job_id: str,
        filename: str,
        fileobj: Any,
        mime: str,
    ) -> None:
        """Upload the conversion result via multipart POST (longer read timeout)."""
        resp = await self._client.post(
            self._url(f"jobs/{job_id}/result"),
            files={"file": (filename, fileobj, mime)},
            timeout=httpx.Timeout(30.0, read=300.0, write=None),
        )
        resp.raise_for_status()

    async def fail(self, job_id: str, error: str) -> None:
        """Report a job failure."""
        resp = await self._client.post(
            self._url(f"jobs/{job_id}/fail"),
            json={"error": error},
        )
        resp.raise_for_status()
