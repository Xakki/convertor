# syntax=docker/dockerfile:1.7
# AI worker BASE image — CUDA flavor. Heavy deps + GPU runtime, NO worker code.
#
# Base nvidia/cuda:12.4.1-cudnn-runtime-ubuntu22.04 ships CUDA 12.4 + cuDNN 9
# (package libcudnn9-cuda-12=9.1.0.70-1) — exactly what ctranslate2 4.8.0 /
# faster-whisper 1.2.1 require (CUDA 12 + cuDNN 9). The PyPI ctranslate2 wheel
# links these libs at runtime; no extra cuDNN pip wheel needed.
#
# Ubuntu 22.04 only ships Python 3.10, so we add Python 3.12 via deadsnakes and
# install deps into an isolated venv. Putting the venv first on PATH makes
# `python3` resolve to 3.12 (matching the CPU flavor) without touching the
# system python that apt/cuda tooling depends on.

FROM nvidia/cuda:12.4.1-cudnn-runtime-ubuntu22.04

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    HOME=/home/app \
    HF_HOME=/home/app/.cache/huggingface \
    PATH=/opt/venv/bin:$PATH

RUN DEBIAN_FRONTEND=noninteractive apt-get update && apt-get install -y --no-install-recommends \
        software-properties-common \
        ca-certificates \
        curl \
    && add-apt-repository -y ppa:deadsnakes/ppa \
    && apt-get update && apt-get install -y --no-install-recommends \
        python3.12 \
        python3.12-venv \
        espeak-ng \
        ffmpeg \
        tini \
    && apt-get purge -y software-properties-common \
    && apt-get autoremove -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN useradd -m -u 1000 app
RUN mkdir -p /home/app/.cache/huggingface && chown -R app:app /home/app/.cache

RUN python3.12 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir --upgrade pip

COPY docker/workers/requirements-ai.txt /tmp/requirements.txt
RUN /opt/venv/bin/pip install --no-cache-dir -r /tmp/requirements.txt && rm /tmp/requirements.txt
