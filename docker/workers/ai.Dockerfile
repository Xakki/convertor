# syntax=docker/dockerfile:1.7
# AI worker RUNTIME image — thin code layer on top of a prebuilt base.
# Runs off-server (home WSL + GPU). No KeyDB/S3 access: all I/O via the HTTP pull-API.
#
# AI_BASE_IMAGE selects the flavor (worker-ai-base:cpu | worker-ai-base:cuda),
# so one Dockerfile serves both. Rebuilt on every worker code change; the heavy
# base is reused from cache/registry.
#
# Whisper model: downloaded at first run into /home/app/.cache/huggingface;
# mount a volume there to persist it across restarts.

ARG AI_BASE_IMAGE=harbor.xakki.ru/convertor/worker-ai-base:cpu
FROM ${AI_BASE_IMAGE}

WORKDIR /app
RUN mkdir -p /work && chown app:app /work /app

# Only the ai worker package is needed — no workers/common (no KeyDB/S3).
COPY --chown=app:app workers/ai/ /app/workers/ai/

ENV WORKER_MODULE=workers.ai.worker \
    WHISPER_MODEL=base \
    WHISPER_DEVICE=cpu \
    WHISPER_COMPUTE_TYPE=int8

USER app

HEALTHCHECK --interval=60s --timeout=15s --start-period=60s --retries=3 \
    CMD python3 -c "import faster_whisper; print('ok')" || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["sh", "-c", "python3 -m ${WORKER_MODULE}"]
