# syntax=docker/dockerfile:1.7
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
        curl \
        tini \
    && rm -rf /var/lib/apt/lists/*

RUN pip install --no-cache-dir \
    redis \
    aiohttp \
    structlog

WORKDIR /app

COPY workers/common/ /app/workers/common/
COPY workers/ffmpeg/ /app/workers/ffmpeg/

ENV WORKER_MODULE=workers.ffmpeg.worker

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ffmpeg -version > /dev/null 2>&1 || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["sh", "-c", "python3 -m ${WORKER_MODULE}"]
