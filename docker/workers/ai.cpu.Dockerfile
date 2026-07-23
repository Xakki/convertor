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

# App user at a stable, host-matchable UID/GID (default 1000 so host bind mounts and
# the /data volume stay writable). Configurable via build args, e.g.
# `make build-ai-cpu APP_UID=$(id -u)`. Free any pre-existing occupant of the target
# uid/gid before creating `app` so the RUN is idempotent across base images.
ARG APP_UID=1000
ARG APP_GID=1000
RUN set -eux; \
    if u="$(getent passwd "${APP_UID}" | cut -d: -f1)"; [ -n "$u" ]; then userdel -r "$u" 2>/dev/null || true; fi; \
    if g="$(getent group "${APP_GID}" | cut -d: -f1)"; [ -n "$g" ]; then groupdel "$g" 2>/dev/null || true; fi; \
    groupadd -g "${APP_GID}" app; \
    useradd -m -u "${APP_UID}" -g "${APP_GID}" app; \
    mkdir -p /work /data /home/app/.cache/huggingface; \
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

# llama-cpp-python for the local llamacpp LLM backend (text→text over GGUF).
# Prebuilt CPU wheel (py3-none manylinux2014) from the abetlen index → no source compile.
RUN pip install --no-cache-dir llama-cpp-python==0.3.30 \
    --extra-index-url https://abetlen.github.io/llama-cpp-python/whl/cpu

WORKDIR /app

# Запекаем WORKER_TYPE в образ — этот образ ВСЕГДА ai-воркер, передавать флаг
# руками (bare `docker run`) не нужно; при желании всё ещё переопределим -e/compose.
ENV WORKER_TYPE=ai

# WHISPER_DEVICE/WHISPER_COMPUTE_TYPE больше НЕ запекаются — автоопределяются в
# workers/ai/config.py (_autodetect_device): cuda+float16, если torch видит GPU, иначе
# cpu+int8. Запечённый ENV замаскировал бы автодетект; переопределяются через -e/compose.

# Import-based healthcheck — образ самодостаточен вне compose (параметры согласованы
# с worker-ai в docker-compose.yml). Обязательно python3 (не python).
HEALTHCHECK --interval=60s --timeout=15s --start-period=60s --retries=3 \
    CMD python3 -c "import faster_whisper, webrtcvad, workers.common" || exit 1

USER app
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["python3", "-m", "workers.ai"]
