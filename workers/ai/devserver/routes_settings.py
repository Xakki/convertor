"""Settings tab routes — read/edit non-secret worker settings, persist to overlay.

GET  /api/settings → metadata list with effective values.
PUT  /api/settings → validate, persist overlay, re-derive effective config, apply
                     hot keys (update runner cfg), mark restart keys pending.
"""

from __future__ import annotations

import logging
from typing import Any

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse

from workers.ai.devserver.settings import (
    SETTINGS_BY_KEY,
    coerce_value,
    effective_config,
    read_overlay,
    settings_list,
    write_overlay,
)

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/api")


@router.get("/settings")
async def get_settings(request: Request) -> dict[str, Any]:
    return {"settings": settings_list(request.app.state.cfg)}


@router.put("/settings")
async def put_settings(request: Request) -> Any:
    try:
        body = await request.json()
    except Exception:
        return JSONResponse(status_code=422, content={"ok": False, "error": "invalid JSON body"})
    if not isinstance(body, dict) or not body:
        return JSONResponse(
            status_code=422,
            content={"ok": False, "error": "body must be a non-empty object of {KEY: value}"},
        )

    # Validate every key/value before persisting anything.
    validated: dict[str, Any] = {}
    for key, value in body.items():
        spec = SETTINGS_BY_KEY.get(key)
        if spec is None:
            return JSONResponse(
                status_code=422,
                content={"ok": False, "error": f"unknown or non-editable setting: {key}", "key": key},
            )
        try:
            validated[key] = coerce_value(spec, value)
        except ValueError as exc:
            return JSONResponse(
                status_code=422,
                content={"ok": False, "error": str(exc), "key": key},
            )

    # Persist: merge into the on-disk overlay.
    overlay = read_overlay()
    overlay.update(validated)
    try:
        write_overlay(overlay)
    except OSError as exc:
        return JSONResponse(
            status_code=500,
            content={"ok": False, "error": f"failed to persist settings: {exc}"},
        )

    # Re-derive effective config and apply.
    new_cfg = effective_config(overlay)
    old_cfg = request.app.state.cfg
    request.app.state.cfg = new_cfg

    runner = request.app.state.runner
    runner.update_cfg(new_cfg)

    applied = [k for k in validated if SETTINGS_BY_KEY[k].apply == "hot"]
    pending_restart = [k for k in validated if SETTINGS_BY_KEY[k].apply == "restart"]

    return {
        "ok": True,
        "applied": applied,
        "pendingRestart": pending_restart,
        "settings": settings_list(new_cfg),
    }
