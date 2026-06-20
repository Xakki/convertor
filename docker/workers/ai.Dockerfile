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

# ---------- worker-ai stage ----------
FROM base AS worker

RUN apt-get update && apt-get install -y --no-install-recommends \
        espeak-ng \
        ffmpeg \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Deps before COPY source for layer caching
COPY docker/workers/requirements-ai.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt

RUN mkdir -p /work && chown app:app /work

WORKDIR /app

COPY --chown=app:app workers/common/ /app/workers/common/
COPY --chown=app:app workers/ai/ /app/workers/ai/

# Whisper models are downloaded at first run into /home/app/.cache/huggingface
# Mount whisper-models volume there to persist across restarts
# To pre-load: docker run --rm -e WHISPER_MODEL=base worker-ai python3 -c
#   "from faster_whisper import WhisperModel; WhisperModel('base')"
ENV WORKER_MODULE=workers.ai.worker \
    WHISPER_MODEL=base \
    HOME=/home/app \
    HF_HOME=/home/app/.cache/huggingface

USER app

HEALTHCHECK --interval=60s --timeout=15s --start-period=30s --retries=3 \
    CMD python3 -c "import faster_whisper; print('ok')" || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["sh", "-c", "python3 -m ${WORKER_MODULE}"]
