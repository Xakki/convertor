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

# ---------- worker-data stage ----------
FROM base AS worker

# Deps before COPY source for layer caching
COPY docker/workers/requirements-data.txt /tmp/requirements.txt
RUN --mount=type=cache,target=/root/.cache/pip \
    PIP_CACHE_DIR=/root/.cache/pip PIP_NO_CACHE_DIR=0 pip install -r /tmp/requirements.txt

RUN mkdir -p /work /app && chown app:app /work /app

WORKDIR /app

COPY --chown=app:app workers/common/ /app/workers/common/
COPY --chown=app:app workers/data/ /app/workers/data/

ENV WORKER_MODULE=workers.data.worker

# Запекаем version в образ (§4/§8): ARG объявлены В stage worker (иначе не видны).
# APP_VER → ENV (ws_client читает os.getenv); WORKER_BUILD → /app/.i (ws_client читает файл).
ARG APP_VER=0
ARG WORKER_BUILD=0
ENV APP_VER=${APP_VER}
RUN printf '%s' "${WORKER_BUILD}" > /app/.i

USER app

HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD python3 -c "import pandas, yaml, lxml; print('ok')" || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["sh", "-c", "python3 -m ${WORKER_MODULE}"]
