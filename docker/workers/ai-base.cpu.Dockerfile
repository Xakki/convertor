# syntax=docker/dockerfile:1.7
# AI worker BASE image — CPU flavor. Heavy deps only, NO worker code.
# Rebuilt rarely (only when requirements-ai.txt changes). The thin worker
# image (ai.Dockerfile) builds FROM this and adds the code layer.

FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    HOME=/home/app \
    HF_HOME=/home/app/.cache/huggingface

RUN apt-get update && apt-get install -y --no-install-recommends \
        espeak-ng \
        ffmpeg \
        tini \
        ca-certificates \
        curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN useradd -m -u 1000 app
RUN mkdir -p /home/app/.cache/huggingface && chown -R app:app /home/app/.cache

COPY docker/workers/requirements-ai.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt && rm /tmp/requirements.txt
