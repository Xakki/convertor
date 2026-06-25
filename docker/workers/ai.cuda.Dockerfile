ARG CUDA_IMAGE=nvidia/cuda:13.3.0-cudnn-runtime-ubuntu24.04
ARG AI_BASE_IMAGE=harbor.xakki.ru/convertor/worker-ai-base:latest

FROM ${CUDA_IMAGE}

ARG DEBIAN_FRONTEND=noninteractive

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    HOME=/home/app \
    HF_HOME=/home/app/.cache/huggingface \
    PATH=/opt/venv/bin:$PATH \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8 \
    NVIDIA_VISIBLE_DEVICES=all \
    NVIDIA_DRIVER_CAPABILITIES=compute,utility

# -----------------------------------------------------------------------------
# System packages
# -----------------------------------------------------------------------------

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        python3 \
        python3-venv \
        python3-pip \
        ffmpeg \
        espeak-ng \
        tini \
        ca-certificates \
        curl && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# Runtime user
# -----------------------------------------------------------------------------

RUN useradd -m -u 1000 app && \
    mkdir -p /work && \
    mkdir -p /home/app/.cache/huggingface && \
    chown -R app:app /work /home/app

# -----------------------------------------------------------------------------
# Python venv
# -----------------------------------------------------------------------------

RUN python3 -m venv /opt/venv && \
    /opt/venv/bin/pip install --upgrade pip setuptools wheel


WORKDIR /app

COPY --from=${AI_BASE_IMAGE} /app /app

RUN pip install --no-cache-dir -r /app/requirements-ai.txt

# -----------------------------------------------------------------------------
# Defaults
# -----------------------------------------------------------------------------

ENV WORKER_MODULE=workers.ai.worker \
    WORK_DIR=/work \
    WHISPER_MODEL=base \
    WHISPER_DEVICE=cuda \
    WHISPER_COMPUTE_TYPE=float16

# -----------------------------------------------------------------------------
# Healthcheck
# -----------------------------------------------------------------------------

HEALTHCHECK --interval=60s \
            --timeout=15s \
            --start-period=60s \
            --retries=3 \
    CMD python -c "import faster_whisper; print('ok')" || exit 1

USER app

ENTRYPOINT ["/usr/bin/tini","--"]

CMD ["sh","-c","python -m ${WORKER_MODULE}"]
