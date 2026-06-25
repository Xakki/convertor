FROM scratch

COPY workers/ai/ /app/
COPY docker/workers/requirements-ai.txt /app/requirements-ai.txt


