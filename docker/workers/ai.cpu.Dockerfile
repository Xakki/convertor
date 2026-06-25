# syntax=docker/dockerfile:1.7
# AI worker — CPU flavor. Self-contained runnable image.
# Pulls the published code artifact from Harbor via COPY --from; installs its own
# Python + CPU ML stack. NOT published to Harbor — build locally on demand.
#
# Usage: make build-ai-cpu

# Global ARG — must be declared before any FROM that references it
ARG AI_BASE_IMAGE=harbor.xakki.ru/convertor/worker-ai-base:latest

# Stage: code artifact from Harbor (FROM scratch — just files, no OS)
FROM ${AI_BASE_IMAGE} AS aibase

# ── Runtime ────────────────────────────────────────────────────────────────────
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    HOME=/home/app \
    HF_HOME=/home/app/.cache/huggingface \
    PATH=/opt/venv/bin:$PATH \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

RUN apt-get update && apt-get install -y --no-install-recommends \
        tini \
        ffmpeg \
        espeak-ng \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN useradd -m -u 1000 app && \
    mkdir -p /work /data /home/app/.cache/huggingface && \
    chown -R app:app /work /data /home/app

RUN python3 -m venv /opt/venv && \
    /opt/venv/bin/pip install --upgrade pip setuptools wheel

# Pull code + requirements from the Harbor code artifact
COPY --from=aibase /app /app

# CPU PyTorch wheel — lightweight, no CUDA runtime bundled
RUN pip install --no-cache-dir torch \
    --index-url https://download.pytorch.org/whl/cpu

RUN pip install --no-cache-dir \
    -r /app/requirements-ai-base.txt \
    -r /app/requirements-ai-ml.txt

WORKDIR /app

ENV WHISPER_DEVICE=cpu \
    WHISPER_COMPUTE_TYPE=int8

USER app
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["python3", "-m", "workers.ai"]
