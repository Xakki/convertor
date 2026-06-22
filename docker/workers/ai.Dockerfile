# syntax=docker/dockerfile:1.7
# AI worker image — runs off-server (home WSL + GPU).
# No KeyDB or S3 access: all I/O goes through the HTTP pull-API.
#
# GPU (CUDA): keep WHISPER_DEVICE=cpu default; set WHISPER_DEVICE=cuda at runtime
# (requires nvidia-container-toolkit on the host). No GPU-specific base image here
# so the image stays CPU-capable by default and is pulled / run on home hardware.
#
# Whisper model: downloaded at first run into /home/app/.cache/huggingface
# (mount whisper-models volume there to persist across restarts).
# Pre-baking is deprioritised — home host has egress + the volume acts as cache.

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

# ---------- worker-ai stage ----------
FROM base AS worker

RUN apt-get update && apt-get install -y --no-install-recommends \
        espeak-ng \
        ffmpeg \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

COPY docker/workers/requirements-ai.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt

RUN mkdir -p /work /app && chown app:app /work /app

WORKDIR /app

# Only the ai worker package is needed — no workers/common (no KeyDB/S3).
COPY --chown=app:app workers/ai/ /app/workers/ai/

ENV WORKER_MODULE=workers.ai.worker \
    WHISPER_MODEL=base \
    WHISPER_DEVICE=cpu \
    WHISPER_COMPUTE_TYPE=int8 \
    HOME=/home/app \
    HF_HOME=/home/app/.cache/huggingface

USER app

HEALTHCHECK --interval=60s --timeout=15s --start-period=30s --retries=3 \
    CMD python3 -c "import faster_whisper; print('ok')" || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["sh", "-c", "python3 -m ${WORKER_MODULE}"]
