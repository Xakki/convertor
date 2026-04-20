# syntax=docker/dockerfile:1.7
# Base image for Python workers
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        tini \
    && rm -rf /var/lib/apt/lists/*

RUN pip install --no-cache-dir \
    redis \
    aiohttp \
    structlog

WORKDIR /app

# Workers common modules are copied here
COPY workers/common/ /app/workers/common/

# WORKER_MODULE env var determines which worker to launch, e.g. workers.ffmpeg.worker
ENV WORKER_MODULE=workers.base.worker

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["sh", "-c", "python3 -m ${WORKER_MODULE}"]
