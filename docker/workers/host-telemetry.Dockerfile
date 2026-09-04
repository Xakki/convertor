FROM python:3.12-slim
RUN useradd -u 1000 -m collector
WORKDIR /app
COPY docker/workers/requirements-host-telemetry.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt && rm /tmp/requirements.txt
COPY workers/host_telemetry /app/workers/host_telemetry
# Keep release provenance in image metadata; public tags remain APP_VER/latest.
ARG APP_VER=0
ARG WORKER_BUILD=0
ARG GIT_REVISION=unknown
ARG SOURCE_STATE=unknown
ARG IMAGE_REPOSITORY=worker-host-telemetry
ENV APP_VER=${APP_VER} GIT_REVISION=${GIT_REVISION} SOURCE_STATE=${SOURCE_STATE} IMAGE_REPOSITORY=${IMAGE_REPOSITORY}
LABEL org.opencontainers.image.version=${APP_VER} \
      org.opencontainers.image.revision=${GIT_REVISION} \
      org.opencontainers.image.source-state=${SOURCE_STATE} \
      org.opencontainers.image.title=${IMAGE_REPOSITORY}
USER collector
ENTRYPOINT ["python3", "-m", "workers.host_telemetry"]
