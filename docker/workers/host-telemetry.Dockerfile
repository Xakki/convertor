FROM python:3.12-slim
RUN useradd -u 1000 -m collector
WORKDIR /app
COPY workers/host_telemetry /app/workers/host_telemetry
USER collector
ENTRYPOINT ["python3", "-m", "workers.host_telemetry"]
