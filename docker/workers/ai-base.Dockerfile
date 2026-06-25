# syntax=docker/dockerfile:1.7
# AI worker BASE — published to Harbor as worker-ai-base:latest.
# Pure code artifact (FROM scratch): no Python, no OS, no deps.
# Working images (ai.cpu, ai.cuda) pull this via COPY --from and bring
# their own OS + Python + ML stack. This removes the slim→CUDA Python conflict.
#
# Build: make build-ai-base
# Push:  make push-ai-base

FROM scratch

# Worker code (namespace package: workers/ai/__init__.py; 'workers' is PEP 420 implicit)
COPY workers/ai/ /app/workers/ai/

# Requirements shipped alongside the code so working images don't need the build context
COPY docker/workers/requirements-ai-base.txt docker/workers/requirements-ai-ml.txt /app/
