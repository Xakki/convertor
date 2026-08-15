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

# ---------- ws-gateway stage ----------
FROM base AS gateway

# Deps before COPY source for layer caching
COPY docker/workers/requirements-gateway.txt /tmp/requirements.txt
RUN --mount=type=cache,target=/root/.cache/pip \
    PIP_CACHE_DIR=/root/.cache/pip PIP_NO_CACHE_DIR=0 pip install -r /tmp/requirements.txt

RUN mkdir -p /app && chown app:app /app

WORKDIR /app

COPY --chown=app:app workers/common/ /app/workers/common/
COPY --chown=app:app workers/gateway/ /app/workers/gateway/

ENV GATEWAY_MODULE=workers.gateway

# Запекаем version в образ (§4/§8): ARG объявлены В stage gateway (иначе не видны).
# APP_VER → ENV (ws_client читает os.getenv); WORKER_BUILD → /app/.i (ws_client читает файл).
ARG APP_VER=0
ARG WORKER_BUILD=0
ARG GATEWAY_GIT_SHA=unknown
ARG GATEWAY_SOURCE_STATE=unknown
ENV APP_VER=${APP_VER}
ENV GATEWAY_GIT_SHA=${GATEWAY_GIT_SHA}
ENV GATEWAY_SOURCE_STATE=${GATEWAY_SOURCE_STATE}
LABEL org.opencontainers.image.revision=${GATEWAY_GIT_SHA} \
      org.opencontainers.image.source-state=${GATEWAY_SOURCE_STATE}
RUN printf '%s' "${WORKER_BUILD}" > /app/.i

USER app

HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD python3 -c "import redis; print('ok')" || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["sh", "-c", "python3 -m ${GATEWAY_MODULE}"]
