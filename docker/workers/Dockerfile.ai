# syntax=docker/dockerfile:1.7
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    WHISPER_MODEL=base

RUN apt-get update && apt-get install -y --no-install-recommends \
        espeak-ng \
        curl \
        tini \
        # ffmpeg required by faster-whisper for audio decoding
        ffmpeg \
    && rm -rf /var/lib/apt/lists/*

RUN pip install --no-cache-dir \
    redis \
    aiohttp \
    structlog \
    faster-whisper \
    pyttsx3

WORKDIR /app

COPY workers/common/ /app/workers/common/
COPY workers/ai/ /app/workers/ai/

# Whisper models are downloaded at first run into /root/.cache/huggingface
# Mount whisper-models volume there to persist across restarts
# To pre-load: docker run --rm -e WHISPER_MODEL=base worker-ai python3 -c
#   "from faster_whisper import WhisperModel; WhisperModel('base')"

ENV WORKER_MODULE=workers.ai.worker

HEALTHCHECK --interval=60s --timeout=15s --start-period=30s --retries=3 \
    CMD python3 -c "import faster_whisper; print('ok')" || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["sh", "-c", "python3 -m ${WORKER_MODULE}"]
