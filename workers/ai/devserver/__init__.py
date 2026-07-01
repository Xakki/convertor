"""AI-worker local dev-server (FastAPI + web UI).

A localhost tool to manually exercise every conversion method, live-stream audio
for STT, watch real pull-processing stats, and edit worker settings — all without
touching the production worker mode. See .claude/skills/devserver-api-contract.

FastAPI/uvicorn are imported lazily (only `app`/`serve` pull them in), so importing
`workers.ai.worker` / `workers.ai.convert` in the lean prod image stays dependency-free.
"""

from __future__ import annotations
