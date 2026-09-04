FROM python:3.12-slim
RUN useradd -u 1000 -m collector
WORKDIR /app
COPY docker/workers/requirements-host-telemetry.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt && rm /tmp/requirements.txt
COPY workers/host_telemetry /app/workers/host_telemetry
USER collector
ENTRYPOINT ["python3", "-m", "workers.host_telemetry"]
