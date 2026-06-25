"""FastAPI app factory + uvicorn launcher for the AI-worker dev-server.

Binds 127.0.0.1:8765 by default (DEVSERVER_HOST/DEVSERVER_PORT). Optional bearer
(DEVSERVER_TOKEN): when set, every /api/* request and the /ws/* handshake must
present it (header `Authorization: Bearer <token>` or `?token=`). Static UI is
served from ./static.

Lifespan: load the effective config (env + overlay), build the shared Stats +
PullRunner, and start the runner if effective PULL_ENABLED is true.
"""

from __future__ import annotations

import logging
import os
from collections import OrderedDict
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from fastapi.staticfiles import StaticFiles

from workers.ai.devserver import (
    routes_methods,
    routes_settings,
    routes_stats,
    routes_stream,
)
from workers.ai.devserver.pull_runner import PullRunner
from workers.ai.devserver.settings import effective_config
from workers.ai.devserver.stats import Stats

logger = logging.getLogger(__name__)

STATIC_DIR = Path(__file__).parent / "static"


@asynccontextmanager
async def lifespan(app: FastAPI):
    cfg = effective_config()
    app.state.cfg = cfg
    app.state.stats = Stats()
    app.state.runner = PullRunner(cfg, app.state.stats)
    app.state.results = OrderedDict()  # resultId → {path, mime, name}

    if cfg.pull_enabled:
        await app.state.runner.start()
    try:
        yield
    finally:
        await app.state.runner.stop()
        for entry in list(app.state.results.values()):
            try:
                Path(entry["path"]).unlink(missing_ok=True)
            except OSError:
                pass


def create_app() -> FastAPI:
    app = FastAPI(title="AI worker dev-server", lifespan=lifespan)

    @app.middleware("http")
    async def auth_guard(request: Request, call_next):
        # /api/* requires the Authorization header only. Query-param token is NOT
        # accepted here (it would leak into access logs); it's reserved for the WS
        # handshake, which can't set headers from the browser and checks ?token=
        # inside routes_stream.
        token = os.getenv("DEVSERVER_TOKEN")
        if token and request.url.path.startswith("/api/"):
            if request.headers.get("authorization") != f"Bearer {token}":
                return JSONResponse(status_code=401, content={"ok": False, "error": "unauthorized"})
        return await call_next(request)

    app.include_router(routes_methods.router)
    app.include_router(routes_stats.router)
    app.include_router(routes_settings.router)
    app.include_router(routes_stream.router)

    # Static SPA last so it acts as the catch-all root mount (API/WS routes win).
    STATIC_DIR.mkdir(parents=True, exist_ok=True)
    app.mount("/", StaticFiles(directory=str(STATIC_DIR), html=True), name="static")
    return app


app = create_app()


def serve() -> None:
    """Launch uvicorn (used by `python -m workers.ai devserver|--devserver`)."""
    import uvicorn

    host = os.getenv("DEVSERVER_HOST", "127.0.0.1")
    try:
        port = int(os.getenv("DEVSERVER_PORT", "8765"))
    except ValueError:
        port = 8765
    logger.info("starting AI-worker dev-server on http://%s:%d", host, port)
    uvicorn.run(app, host=host, port=port)
