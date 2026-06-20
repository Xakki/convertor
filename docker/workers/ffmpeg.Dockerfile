# syntax=docker/dockerfile:1.7
# ---------- shared base stage (BuildKit deduplicates identical layers) ----------
FROM python:3.12-slim AS base

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        tini \
        ca-certificates \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN useradd -m -u 1000 app

# ---------- worker-ffmpeg stage ----------
FROM base AS worker

RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Deps before COPY source for layer caching
COPY docker/workers/requirements-ffmpeg.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt

RUN mkdir -p /work && chown app:app /work

WORKDIR /app

COPY --chown=app:app workers/common/ /app/workers/common/
COPY --chown=app:app workers/ffmpeg/ /app/workers/ffmpeg/

ENV WORKER_MODULE=workers.ffmpeg.worker

USER app

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ffmpeg -version > /dev/null 2>&1 || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["sh", "-c", "python3 -m ${WORKER_MODULE}"]
