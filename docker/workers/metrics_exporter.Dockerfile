# syntax=docker/dockerfile:1.7
# ---------- shared base stage ----------
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

# ---------- exporter stage ----------
FROM base AS exporter

COPY docker/workers/requirements-metrics-exporter.txt /tmp/requirements.txt
RUN --mount=type=cache,target=/root/.cache/pip \
    PIP_CACHE_DIR=/root/.cache/pip PIP_NO_CACHE_DIR=0 pip install -r /tmp/requirements.txt

RUN mkdir -p /app && chown app:app /app

WORKDIR /app

COPY --chown=app:app workers/common/ /app/workers/common/
COPY --chown=app:app workers/metrics_exporter/ /app/workers/metrics_exporter/

EXPOSE 9472

USER app

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost:9472/metrics > /dev/null || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["python3", "-m", "workers.metrics_exporter.exporter"]
