"""FastAPI app factory + uvicorn launcher для AI-worker dev-server.

Binds 127.0.0.1:8877 by default (DEVSERVER_HOST/DEVSERVER_PORT). Optional bearer
(DEVSERVER_TOKEN): when set, every /api/* request and the /ws/* handshake must
present it (header `Authorization: Bearer <token>` or `?token=`). Static UI is
served from ./static.

Lifespan: load the effective config (env + overlay), build the shared Stats +
WsRunner, and start the runner unconditionally (WsClient.validate() handles the
"no gateway configured" case gracefully — it logs and returns instead of looping).
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
from workers.ai.devserver.settings import effective_config
from workers.ai.devserver.stats import Stats
from workers.ai.devserver.ws_runner import WsRunner

logger = logging.getLogger(__name__)

STATIC_DIR = Path(__file__).parent / "static"


@asynccontextmanager
async def lifespan(app: FastAPI):
    cfg = effective_config()
    app.state.cfg = cfg
    app.state.stats = Stats()
    app.state.runner = WsRunner(cfg, app.state.stats)
    app.state.results = OrderedDict()  # resultId → {path, mime, name}

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
        token = os.getenv("DEVSERVER_TOKEN")
        if token and request.url.path.startswith("/api/"):
            if request.headers.get("authorization") != f"Bearer {token}":
                return JSONResponse(status_code=401, content={"ok": False, "error": "unauthorized"})
        return await call_next(request)

    app.include_router(routes_methods.router)
    app.include_router(routes_stats.router)
    app.include_router(routes_settings.router)
    app.include_router(routes_stream.router)

    STATIC_DIR.mkdir(parents=True, exist_ok=True)
    app.mount("/", StaticFiles(directory=str(STATIC_DIR), html=True), name="static")
    return app


app = create_app()


def serve() -> None:
    """Launch uvicorn (used by `python -m workers.ai devserver|--devserver`)."""
    import uvicorn

    host = os.getenv("DEVSERVER_HOST", "127.0.0.1")
    try:
        port = int(os.getenv("DEVSERVER_PORT", "8877"))
    except ValueError:
        port = 8877
    logger.info("starting AI-worker dev-server on http://%s:%d", host, port)
    uvicorn.run(app, host=host, port=port)
