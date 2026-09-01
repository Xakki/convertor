# syntax=docker/dockerfile:1.7
FROM python:3.12-slim
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1
RUN apt-get update && apt-get install -y --no-install-recommends \
        tini \
        ca-certificates \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* \
    && useradd -m -u 1000 app
COPY docker/workers/requirements-api.txt /tmp/requirements.txt
RUN pip install -r /tmp/requirements.txt
WORKDIR /app
COPY --chown=app:app workers/common/ /app/workers/common/
COPY --chown=app:app workers/api/ /app/workers/api/
COPY --chown=app:app worker-api.yaml /app/worker-api.yaml

# Match the worker image version contract: runtime metadata is available both
# through environment variables and the build-counter file read by ws_client.
ARG APP_VER=0
ARG WORKER_BUILD=0
ARG GIT_REVISION=unknown
ARG SOURCE_STATE=unknown
ARG IMAGE_REPOSITORY=unknown
ENV APP_VER=${APP_VER} \
    APP_VERSION=${APP_VER} \
    WORKER_BUILD=${WORKER_BUILD} \
    GIT_REVISION=${GIT_REVISION} \
    SOURCE_STATE=${SOURCE_STATE} \
    IMAGE_REPOSITORY=${IMAGE_REPOSITORY}
LABEL org.opencontainers.image.version=${APP_VER} \
      org.opencontainers.image.revision=${GIT_REVISION} \
      org.opencontainers.image.source-state=${SOURCE_STATE} \
      org.opencontainers.image.repository=${IMAGE_REPOSITORY}
RUN printf '%s' "${WORKER_BUILD}" > /app/.i

USER app

# Validate the shipped catalog and schema without contacting the provider or
# exposing credentials in the health command/output.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD python3 -c "from pathlib import Path; from workers.api.config import load_catalog; load_catalog(Path('/app/worker-api.yaml'))" || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["python3", "-m", "workers.api"]
