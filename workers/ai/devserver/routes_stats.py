"""Pull stats tab route — read the shared in-memory Stats."""

from __future__ import annotations

from typing import Any

from fastapi import APIRouter, Request

router = APIRouter(prefix="/api")


@router.get("/stats")
async def get_stats(request: Request) -> dict[str, Any]:
    return request.app.state.stats.snapshot()
